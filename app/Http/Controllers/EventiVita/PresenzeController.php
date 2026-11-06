<?php

namespace App\Http\Controllers\EventiVita;

use App\Http\Controllers\Controller;
use App\Http\Requests\EventiVita\PresenzeBulkRequest;
use App\Models\Evento;
use App\Models\EventoPartecipante;
use App\Models\Prenotazione;
use App\Services\EventiVita\PresenzeSociService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Presenze di un evento (f.9):
 * - pagina admin con registrazione manuale in blocco (checkbox multipli)
 *   delle prenotazioni confermate, complementare al check-in QR;
 * - foglio presenze PDF stampabile (prenotati + partecipanti soci) con
 *   riga firma.
 */
class PresenzeController extends Controller
{
    use AuthorizesRequests;

    public function index(Evento $evento): View
    {
        $this->authorize('view', $evento);

        return view('eventi-vita.presenze', [
            'evento' => $evento,
            'prenotazioni' => $this->prenotazioniConfermate($evento),
        ]);
    }

    /**
     * Registrazione presenze in blocco: le prenotazioni CONFERMATE
     * selezionate risultano entrate per tutti i posti rimanenti (origine
     * "presenze_blocco"; checkin_at al primo ingresso, conservato se il socio
     * era già entrato con la tessera). Gli id estranei (altro evento/tenant,
     * stati diversi, già entrate per intero) vengono ignorati dalle UPDATE
     * scoped. I soci collegati agli account presenti vengono registrati tra
     * i partecipanti (PresenzeSociService).
     */
    public function store(PresenzeBulkRequest $request, Evento $evento, PresenzeSociService $presenze): RedirectResponse
    {
        $this->authorize('update', $evento);

        // Check-in e registrazione dei soci nella stessa transazione: se la
        // seconda fallisce le prenotazioni non risultano segnate a metà
        $registrate = DB::transaction(function () use ($request, $evento, $presenze): int {
            $registrate = $presenze->registraPostiRimanenti(
                $evento,
                $request->validated('prenotazioni'),
                Prenotazione::CHECKIN_ORIGINE_PRESENZE_BLOCCO,
                $request->user()?->id,
            );

            // I soci collegati agli account presenti risultano anche tra i
            // partecipanti confermati, come dopo il check-in con la tessera
            $presenze->registraSociDellePrenotazioni($evento, $request->validated('prenotazioni'));

            return $registrate;
        });

        return redirect()
            ->route('eventi.presenze.index', $evento)
            ->with('success', "Presenze registrate: {$registrate}.");
    }

    /**
     * Foglio presenze PDF: prenotazioni confermate (nominativo, posti,
     * codice, check-in) e partecipanti soci, ognuno con riga firma.
     */
    public function foglio(Request $request, Evento $evento): Response
    {
        $this->authorize('view', $evento);

        $partecipanti = $evento->partecipanti()
            ->where('stato_partecipazione', EventoPartecipante::STATO_CONFERMATO)
            ->with('socio')
            ->join('soci', 'soci.id', '=', 'eventi_partecipanti.socio_id')
            ->orderBy('soci.cognome')
            ->orderBy('soci.nome')
            ->select('eventi_partecipanti.*')
            ->get();

        $pdf = Pdf::loadView('eventi-vita.pdf.foglio-presenze', [
            'evento' => $evento,
            'associazione' => $evento->associazione,
            'prenotazioni' => $this->prenotazioniConfermate($evento),
            'partecipanti' => $partecipanti,
        ])
            ->setPaper('a4', 'portrait')
            ->setOption('defaultFont', 'DejaVu Sans');

        $filename = 'foglio-presenze-'.str($evento->titolo ?: 'evento')->slug().'.pdf';

        return $pdf->stream($filename);
    }

    /**
     * Prenotazioni confermate dell'evento ordinate per cognome/nome
     * dell'account (withTrashed: anche gli account soft-deleted restano
     * leggibili sul foglio).
     *
     * @return Collection<int, Prenotazione>
     */
    protected function prenotazioniConfermate(Evento $evento): Collection
    {
        return Prenotazione::query()
            ->where('evento_id', $evento->id)
            ->where('stato', Prenotazione::STATO_CONFERMATA)
            ->with(['account' => fn ($q) => $q->withoutGlobalScope('associazione')->withTrashed()])
            ->get()
            ->sortBy(fn (Prenotazione $p): string => mb_strtolower(
                trim($p->account->cognome.' '.$p->account->nome)
            ))
            ->values();
    }
}
