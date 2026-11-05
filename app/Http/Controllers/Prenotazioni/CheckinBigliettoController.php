<?php

namespace App\Http\Controllers\Prenotazioni;

use App\Http\Controllers\Controller;
use App\Http\Requests\Prenotazioni\CheckinBigliettoRequest;
use App\Models\Prenotazione;
use App\Services\EventiVita\PresenzeSociService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Check-in dei biglietti (prenotazioni) dallo scanner admin.
 *
 * - confermata con posti da far entrare → registra tutti i posti rimanenti
 *   (posti_entrati = posti), checkin_at al primo ingresso, origine
 *   "biglietto" e checkin_utente_id; la risposta indica quanti posti far
 *   entrare (posti_registrati: meno di posti se il socio è già entrato con
 *   la tessera);
 * - già usato per tutti i posti → success con already_checked_in e orario
 *   (warning, non errore);
 * - stati non validi (lista_attesa, in_attesa_pagamento, annullata, scaduta)
 *   → errore con motivo.
 *
 * Il lookup usa il global scope tenant (BelongsToAssociazione): un biglietto
 * di un'altra associazione risulta semplicemente non trovato (404).
 */
class CheckinBigliettoController extends Controller
{
    /**
     * Scansione del biglietto: registra i posti ancora da far entrare o
     * risponde "già utilizzato" / "non valido" con il motivo.
     */
    public function __invoke(CheckinBigliettoRequest $request, PresenzeSociService $presenze): JsonResponse
    {
        $dati = $request->validated();

        $query = Prenotazione::query()->with([
            'evento' => fn ($q) => $q->withoutGlobalScope('associazione'),
            'account' => fn ($q) => $q->withoutGlobalScope('associazione'),
        ]);

        if (! empty($dati['qr_token'])) {
            $query->where('qr_token', strtolower((string) $dati['qr_token']));
        } else {
            $query->where('codice', strtoupper(trim((string) $dati['codice'])));
        }

        /** @var Prenotazione|null $prenotazione */
        $prenotazione = $query->first();

        if ($prenotazione === null) {
            return response()->json([
                'success' => false,
                'error' => 'Biglietto non trovato.',
                'code' => 'biglietto_not_found',
            ], 404);
        }

        // Scanner con evento selezionato: il biglietto deve essere di quell'evento
        if (! empty($dati['evento_id']) && $prenotazione->evento_id !== $dati['evento_id']) {
            return response()->json([
                'success' => false,
                'error' => 'Il biglietto appartiene a un altro evento'
                    .($prenotazione->evento !== null ? ': '.$prenotazione->evento->titolo : '').'.',
                'code' => 'evento_mismatch',
                'biglietto' => $this->biglietto($prenotazione),
            ], 409);
        }

        // Evento eliminato (nel cestino): nessun ingresso per una data annullata
        if ($prenotazione->evento === null) {
            return response()->json([
                'success' => false,
                'error' => 'Evento annullato: il biglietto non è più valido.',
                'code' => 'evento_annullato',
                'biglietto' => $this->biglietto($prenotazione),
            ], 409);
        }

        if ($prenotazione->stato !== Prenotazione::STATO_CONFERMATA) {
            return response()->json([
                'success' => false,
                'error' => $this->motivoStato($prenotazione->stato),
                'code' => 'stato_non_valido',
                'stato' => $prenotazione->stato,
                'biglietto' => $this->biglietto($prenotazione),
            ], 409);
        }

        if ($prenotazione->isEntrataCompleta()) {
            return $this->giaUtilizzato($prenotazione);
        }

        $adesso = now();
        $utenteId = $request->user()?->getAuthIdentifier();

        // Check-in e registrazione del socio collegato all'account tra i
        // partecipanti confermati nella stessa transazione: se la seconda
        // fallisce il biglietto non risulta usato e la scansione si ripete.
        // La riga è riletta con lock (stesso ordine del check-in con la
        // tessera: prenotazione, poi partecipante): con due scansioni
        // simultanee dello stesso QR una sola registra l'ingresso, e i posti
        // da far entrare sono quelli rimasti dopo un eventuale check-in
        // concorrente con la tessera del socio
        $postiRegistrati = DB::transaction(function () use ($prenotazione, $adesso, $utenteId, $presenze): int {
            /** @var Prenotazione|null $bloccata */
            $bloccata = Prenotazione::query()
                ->withoutGlobalScope('associazione')
                ->whereKey($prenotazione->id)
                ->lockForUpdate()
                ->first();

            if ($bloccata === null || $bloccata->stato !== Prenotazione::STATO_CONFERMATA) {
                return 0;
            }

            $daEntrare = $bloccata->postiDaEntrare();

            if ($daEntrare === 0) {
                return 0;
            }

            // La scansione fa entrare tutti i posti rimanenti; su un ingresso
            // già iniziato (socio entrato con la tessera) checkin_at resta il
            // primo ingresso. L'origine diventa "biglietto": il check-in non
            // si annulla più con la partecipazione del socio
            $bloccata->forceFill([
                'checkin_at' => $bloccata->checkin_at ?? $adesso,
                'posti_entrati' => (int) $bloccata->posti,
                'checkin_origine' => Prenotazione::CHECKIN_ORIGINE_BIGLIETTO,
                'checkin_utente_id' => $utenteId,
            ])->save();

            $presenze->registraSociDellePrenotazioni($prenotazione->evento, [$prenotazione->id]);

            return $daEntrare;
        });

        // Dati aggiornati (anche dopo una corsa persa)
        $prenotazione->refresh();

        if ($postiRegistrati === 0) {
            if ($prenotazione->stato !== Prenotazione::STATO_CONFERMATA) {
                return response()->json([
                    'success' => false,
                    'error' => $this->motivoStato($prenotazione->stato),
                    'code' => 'stato_non_valido',
                    'stato' => $prenotazione->stato,
                    'biglietto' => $this->biglietto($prenotazione),
                ], 409);
            }

            return $this->giaUtilizzato($prenotazione);
        }

        $posti = (int) $prenotazione->posti;
        $giaEntrati = $posti - $postiRegistrati;

        return response()->json([
            'success' => true,
            'already_checked_in' => false,
            'message' => $giaEntrati > 0
                ? 'Check-in registrato: '.$this->posti($postiRegistrati).' da far entrare, '.$this->posti($giaEntrati).' già '.($giaEntrati === 1 ? 'entrato' : 'entrati').'.'
                : 'Check-in registrato.',
            'checkin_at' => $prenotazione->checkin_at?->toIso8601String(),
            'posti_registrati' => $postiRegistrati,
            'biglietto' => $this->biglietto($prenotazione),
        ]);
    }

    /**
     * Tutti i posti del biglietto risultano già entrati: warning con
     * l'orario del primo ingresso, non errore.
     */
    private function giaUtilizzato(Prenotazione $prenotazione): JsonResponse
    {
        $checkin = $prenotazione->checkin_at;

        return response()->json([
            'success' => true,
            'already_checked_in' => true,
            'message' => 'Biglietto già utilizzato alle '.$checkin?->format('H:i').' del '.$checkin?->format('d/m/Y').'.',
            'checkin_at' => $checkin?->toIso8601String(),
            'posti_registrati' => 0,
            'biglietto' => $this->biglietto($prenotazione),
        ]);
    }

    /**
     * Etichetta "1 posto" / "N posti" per i messaggi dello scanner.
     */
    private function posti(int $posti): string
    {
        return $posti === 1 ? '1 posto' : $posti.' posti';
    }

    private function motivoStato(string $stato): string
    {
        return match ($stato) {
            Prenotazione::STATO_IN_ATTESA_PAGAMENTO => 'Pagamento non ancora completato: il biglietto non è valido.',
            Prenotazione::STATO_LISTA_ATTESA => 'Prenotazione in lista d\'attesa: non è un biglietto valido.',
            Prenotazione::STATO_ANNULLATA => 'Prenotazione annullata.',
            Prenotazione::STATO_SCADUTA => 'Prenotazione scaduta: pagamento non effettuato nei termini.',
            default => 'Biglietto non valido.',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function biglietto(Prenotazione $prenotazione): array
    {
        return [
            'codice' => $prenotazione->codice,
            'posti' => (int) $prenotazione->posti,
            'posti_entrati' => (int) $prenotazione->posti_entrati,
            'posti_da_entrare' => $prenotazione->postiDaEntrare(),
            'stato' => $prenotazione->stato,
            'evento_titolo' => $prenotazione->evento?->titolo,
            'account_nome' => $prenotazione->account?->nome,
            'account_cognome' => $prenotazione->account?->cognome,
        ];
    }
}
