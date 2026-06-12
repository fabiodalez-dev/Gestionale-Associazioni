<?php

namespace App\Http\Controllers\EventiVita;

use App\Http\Controllers\Controller;
use App\Models\Evento;
use App\Services\EventiVita\IcsService;
use App\Services\EventiVita\VitaSocialeService;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Calendario eventi mensile in admin (f.7): griglia lun–dom del mese
 * richiesto (?mese=YYYY-MM, default corrente) con gli eventi del tenant
 * cliccabili. Da qui si raggiungono anche le azioni di serie (f.8).
 */
class CalendarioEventiController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Evento::class);

        $mese = self::risolviMese((string) $request->query('mese', ''));

        $eventi = Evento::query()
            ->whereBetween('data_evento', [
                $mese->copy()->startOfMonth(),
                $mese->copy()->endOfMonth(),
            ])
            ->orderBy('data_evento')
            ->get();

        return view('eventi-vita.calendario', [
            'mese' => $mese,
            'eventiPerGiorno' => VitaSocialeService::eventiPerGiorno($eventi),
            'settimane' => self::grigliaMese($mese),
            'mesePrecedente' => $mese->copy()->subMonth()->format('Y-m'),
            'meseSuccessivo' => $mese->copy()->addMonth()->format('Y-m'),
        ]);
    }

    /**
     * Export .ics del singolo evento dall'admin (anche bozze: il binding
     * tenant-scoped garantisce il 404 uniforme cross-tenant).
     */
    public function ics(Evento $evento, IcsService $ics): Response
    {
        $this->authorize('view', $evento);

        return response($ics->evento($evento, $evento->associazione), 200, [
            'Content-Type' => 'text/calendar; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$ics->filename($evento).'"',
        ]);
    }

    /**
     * Mese richiesto (YYYY-MM) o corrente se assente/malformato.
     */
    public static function risolviMese(string $input): Carbon
    {
        if (preg_match('/^\d{4}-\d{2}$/', $input) === 1) {
            return Carbon::createFromFormat('Y-m-d', $input.'-01')->startOfMonth();
        }

        return now()->startOfMonth();
    }

    /**
     * Griglia del mese: settimane complete lun→dom che coprono il mese.
     *
     * @return list<list<Carbon>>
     */
    public static function grigliaMese(Carbon $mese): array
    {
        $giorno = $mese->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
        $fine = $mese->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);

        $settimane = [];
        $settimana = [];

        while ($giorno->lte($fine)) {
            $settimana[] = $giorno->copy();

            if (count($settimana) === 7) {
                $settimane[] = $settimana;
                $settimana = [];
            }

            $giorno->addDay();
        }

        return $settimane;
    }
}
