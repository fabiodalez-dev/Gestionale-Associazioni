<?php

namespace App\Http\Controllers\Prenotazioni;

use App\Http\Controllers\Controller;
use App\Models\Prenotazione;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Verifica pubblica del biglietto via QR (pattern verifica-tessera).
 *
 * Il QR del biglietto contiene un URL firmato (APP_KEY) con il qr_token
 * (64 hex, capability token): firma mancante o manomessa → biglietto
 * trattato come non valido. La pagina è pubblica e NON espone dati
 * personali completi (solo nome e iniziale del cognome).
 *
 * Stati: valida | gia_usato (con orario) | non_valida (con motivo).
 */
class VerificaBigliettoController extends Controller
{
    public function __invoke(Request $request, string $token): View
    {
        [$stato, $prenotazione, $motivo] = $this->verifica($request, $token);

        return view('pubblico.biglietti.verifica', [
            'stato' => $stato,
            'prenotazione' => $prenotazione,
            'motivo' => $motivo,
            'nominativo' => $prenotazione !== null ? $this->nominativoAbbreviato($prenotazione) : null,
        ]);
    }

    /**
     * @return array{0: string, 1: Prenotazione|null, 2: string|null}
     */
    private function verifica(Request $request, string $token): array
    {
        // Firma assente o manomessa → non valida (il QR generato dall'app
        // contiene sempre l'URL firmato: un link costruito a mano non basta)
        if (! $request->hasValidSignature()) {
            return ['non_valida', null, 'Link di verifica non valido o manomesso.'];
        }

        try {
            // Verifica cross-tenant per design: chiunque inquadri il QR
            // deve poter vedere l'esito, senza sessione né tenant.
            /** @var Prenotazione|null $prenotazione */
            $prenotazione = Prenotazione::allTenants()
                ->where('qr_token', $token)
                ->with([
                    'evento' => fn ($q) => $q->withoutGlobalScope('associazione'),
                    'account' => fn ($q) => $q->withoutGlobalScope('associazione'),
                    'associazione',
                ])
                ->first();
        } catch (\Throwable $e) {
            report($e);

            return ['non_valida', null, 'Biglietto non trovato.'];
        }

        if ($prenotazione === null) {
            return ['non_valida', null, 'Biglietto non trovato.'];
        }

        if ($prenotazione->stato !== Prenotazione::STATO_CONFERMATA) {
            return ['non_valida', $prenotazione, $this->motivoStato($prenotazione->stato)];
        }

        if ($prenotazione->checkin_at !== null) {
            return ['gia_usato', $prenotazione, null];
        }

        return ['valida', $prenotazione, null];
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
     * Nome e iniziale del cognome: la pagina è pubblica, niente dati
     * personali completi.
     */
    private function nominativoAbbreviato(Prenotazione $prenotazione): string
    {
        $nome = (string) $prenotazione->account?->nome;
        $cognome = (string) $prenotazione->account?->cognome;

        return trim($nome.' '.($cognome !== '' ? mb_substr($cognome, 0, 1).'.' : ''));
    }
}
