<?php

namespace App\Http\Controllers\Prenotazioni;

use App\Http\Controllers\Controller;
use App\Http\Requests\Prenotazioni\CheckinBigliettoRequest;
use App\Models\Prenotazione;
use Illuminate\Http\JsonResponse;

/**
 * Check-in dei biglietti (prenotazioni) dallo scanner admin.
 *
 * - confermata senza check-in → registra checkin_at + checkin_utente_id;
 * - già usato → success con already_checked_in e orario (warning, non errore);
 * - stati non validi (lista_attesa, in_attesa_pagamento, annullata, scaduta)
 *   → errore con motivo.
 *
 * Il lookup usa il global scope tenant (BelongsToAssociazione): un biglietto
 * di un'altra associazione risulta semplicemente non trovato (404).
 */
class CheckinBigliettoController extends Controller
{
    public function __invoke(CheckinBigliettoRequest $request): JsonResponse
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

        if ($prenotazione->stato !== Prenotazione::STATO_CONFERMATA) {
            return response()->json([
                'success' => false,
                'error' => $this->motivoStato($prenotazione->stato),
                'code' => 'stato_non_valido',
                'stato' => $prenotazione->stato,
                'biglietto' => $this->biglietto($prenotazione),
            ], 409);
        }

        $checkinPrecedente = $prenotazione->checkin_at;

        if ($checkinPrecedente !== null) {
            return response()->json([
                'success' => true,
                'already_checked_in' => true,
                'message' => 'Biglietto già utilizzato alle '.$checkinPrecedente->format('H:i').' del '.$checkinPrecedente->format('d/m/Y').'.',
                'checkin_at' => $checkinPrecedente->toIso8601String(),
                'biglietto' => $this->biglietto($prenotazione),
            ]);
        }

        $adesso = now();

        $prenotazione->update([
            'checkin_at' => $adesso,
            'checkin_utente_id' => $request->user()?->getAuthIdentifier(),
        ]);

        return response()->json([
            'success' => true,
            'already_checked_in' => false,
            'message' => 'Check-in registrato.',
            'checkin_at' => $adesso->toIso8601String(),
            'biglietto' => $this->biglietto($prenotazione),
        ]);
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
            'stato' => $prenotazione->stato,
            'evento_titolo' => $prenotazione->evento?->titolo,
            'account_nome' => $prenotazione->account?->nome,
            'account_cognome' => $prenotazione->account?->cognome,
        ];
    }
}
