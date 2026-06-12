<?php

namespace App\Http\Controllers\EventiVita;

use App\Http\Controllers\Controller;
use App\Http\Requests\EventiVita\PresenzeBulkRequest;
use App\Models\Evento;
use App\Models\EventoPartecipante;
use App\Models\Prenotazione;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
     * Registrazione presenze in blocco: marca il check-in (now) sulle
     * prenotazioni CONFERMATE selezionate non ancora arrivate. Gli id
     * estranei (altro evento/tenant, stati diversi, già check-in)
     * vengono ignorati dalla UPDATE scoped.
     */
    public function store(PresenzeBulkRequest $request, Evento $evento): RedirectResponse
    {
        $this->authorize('update', $evento);

        $registrate = Prenotazione::query()
            ->where('evento_id', $evento->id)
            ->whereIn('id', $request->validated('prenotazioni'))
            ->where('stato', Prenotazione::STATO_CONFERMATA)
            ->whereNull('checkin_at')
            ->update([
                'checkin_at' => now(),
                'checkin_utente_id' => $request->user()?->id,
            ]);

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
