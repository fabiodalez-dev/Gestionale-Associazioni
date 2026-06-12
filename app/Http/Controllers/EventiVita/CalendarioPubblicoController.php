<?php

namespace App\Http\Controllers\EventiVita;

use App\Http\Controllers\Controller;
use App\Models\Associazione;
use App\Models\Evento;
use App\Services\EventiVita\IcsService;
use App\Services\EventiVita\VitaSocialeService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Calendario eventi del mini-sito pubblico (f.7): vista mensile dei soli
 * eventi PUBBLICATI, export .ics del singolo evento e feed calendario.ics
 * dell'intero tenant (nessun token: contiene solo i pubblicati).
 */
class CalendarioPubblicoController extends Controller
{
    public function __construct(protected IcsService $ics) {}

    /**
     * GET /a/{slug}/calendario — griglia mensile pubblica.
     */
    public function show(Request $request, Associazione $associazione): View
    {
        $mese = CalendarioEventiController::risolviMese((string) $request->query('mese', ''));

        $eventi = Evento::query()
            ->where('associazione_id', $associazione->id)
            ->pubblicati()
            ->whereBetween('data_evento', [
                $mese->copy()->startOfMonth(),
                $mese->copy()->endOfMonth(),
            ])
            ->orderBy('data_evento')
            ->get();

        return view('eventi-vita.pubblico.calendario', [
            'associazione' => $associazione,
            'mese' => $mese,
            'eventiPerGiorno' => VitaSocialeService::eventiPerGiorno($eventi),
            'settimane' => CalendarioEventiController::grigliaMese($mese),
            'mesePrecedente' => $mese->copy()->subMonth()->format('Y-m'),
            'meseSuccessivo' => $mese->copy()->addMonth()->format('Y-m'),
        ]);
    }

    /**
     * GET /a/{slug}/calendario.ics — feed iCalendar degli eventi
     * pubblicati (dagli ultimi 3 mesi in poi, per dare un feed stabile
     * ai client che si sincronizzano).
     */
    public function feed(Associazione $associazione): Response
    {
        $eventi = Evento::query()
            ->where('associazione_id', $associazione->id)
            ->pubblicati()
            ->where('data_evento', '>=', now()->subMonths(3))
            ->orderBy('data_evento')
            ->get();

        return $this->rispostaIcs(
            $this->ics->calendario($eventi, $associazione),
            'calendario.ics'
        );
    }

    /**
     * GET /a/{slug}/eventi/{evento}/evento.ics — .ics del singolo evento
     * pubblicato (404 uniforme per bozze/archiviati e altri tenant).
     */
    public function evento(Associazione $associazione, string $evento): Response
    {
        $evento = Evento::query()
            ->where('associazione_id', $associazione->id)
            ->pubblicati()
            ->findOrFail($evento);

        return $this->rispostaIcs(
            $this->ics->evento($evento, $associazione),
            $this->ics->filename($evento)
        );
    }

    protected function rispostaIcs(string $contenuto, string $filename): Response
    {
        return response($contenuto, 200, [
            'Content-Type' => 'text/calendar; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
