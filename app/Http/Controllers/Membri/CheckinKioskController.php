<?php

namespace App\Http\Controllers\Membri;

use App\Http\Controllers\Controller;
use App\Models\Evento;
use App\Models\EventoPartecipante;
use App\Models\Prenotazione;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * Check-in kiosk fullscreen per evento (f.21 — piano "Gestione 2"):
 * pagina dedicata a un TOTEM all'ingresso, senza sidebar, con scanner
 * html5-qrcode continuo, esito grande verde/rosso con auto-reset 3s e
 * contatore presenze live. Riusa gli endpoint di check-in esistenti
 * (tessere.checkin.store per le tessere, biglietti.checkin per le
 * prenotazioni del frontend pubblico).
 *
 * Rotta admin (auth + tenant) con ScannerSecurityHeaders al posto degli
 * header globali (camera=() bloccherebbe la fotocamera).
 */
class CheckinKioskController extends Controller
{
    use AuthorizesRequests;

    public function show(Evento $evento): View
    {
        $this->authorize('view', $evento);

        return view('membri.checkin-kiosk', [
            'evento' => $evento,
            'presenze' => $this->presenze($evento),
        ]);
    }

    /**
     * Contatore presenze live (polling JSON dal kiosk).
     */
    public function contatore(Evento $evento): JsonResponse
    {
        $this->authorize('view', $evento);

        return response()->json(['presenze' => $this->presenze($evento)]);
    }

    /**
     * Presenze registrate: partecipanti soci confermati (check-in tessera
     * o registrazione manuale) + posti delle prenotazioni con check-in.
     */
    private function presenze(Evento $evento): int
    {
        $partecipanti = EventoPartecipante::query()
            ->where('evento_id', $evento->id)
            ->where('stato_partecipazione', EventoPartecipante::STATO_CONFERMATO)
            ->count();

        $postiPrenotazioni = (int) Prenotazione::query()
            ->where('evento_id', $evento->id)
            ->whereNotNull('checkin_at')
            ->sum('posti');

        return $partecipanti + $postiPrenotazioni;
    }
}
