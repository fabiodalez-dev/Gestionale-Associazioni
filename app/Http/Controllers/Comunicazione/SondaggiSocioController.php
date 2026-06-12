<?php

namespace App\Http\Controllers\Comunicazione;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comunicazione\VotoSondaggioRequest;
use App\Models\Socio;
use App\Models\Sondaggio;
use App\Services\Comunicazione\SondaggiVotoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Sondaggi nell'area soci (f.11): elenco dei sondaggi aperti (inclusi
 * i solo_soci) con voto; chi ha già votato vede i risultati aggregati.
 * Tenant del socio autenticato via global scope (guard 'socio').
 */
class SondaggiSocioController extends Controller
{
    public function __construct(
        private readonly SondaggiVotoService $voto,
    ) {}

    public function index(Request $request): View
    {
        /** @var Socio $socio */
        $socio = $request->user('socio');

        $sondaggi = Sondaggio::query()
            ->aperti()
            ->orderByDesc('aperto_dal')
            ->get()
            ->map(fn (Sondaggio $sondaggio) => [
                'sondaggio' => $sondaggio,
                'risposta' => $this->voto->rispostaDi($sondaggio, $socio),
                'risultati' => $sondaggio->risultati(),
            ]);

        return view('comunicazione.area-soci.sondaggi', [
            'socio' => $socio,
            'sondaggi' => $sondaggi,
        ]);
    }

    public function vota(VotoSondaggioRequest $request, Sondaggio $sondaggio): RedirectResponse
    {
        /** @var Socio $socio */
        $socio = $request->user('socio');

        $esito = $this->voto->vota($sondaggio, $socio, (int) $request->validated('opzione'));

        return redirect()
            ->route('area-soci.sondaggi')
            ->with(...$this->messaggio($esito));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function messaggio(string $esito): array
    {
        return match ($esito) {
            SondaggiVotoService::ESITO_OK => ['success', 'Grazie! Il tuo voto è stato registrato.'],
            SondaggiVotoService::ESITO_GIA_VOTATO => ['warning', 'Hai già votato questo sondaggio.'],
            SondaggiVotoService::ESITO_CHIUSO => ['warning', 'Il sondaggio non è aperto al voto.'],
            default => ['danger', 'Voto non valido.'],
        };
    }
}
