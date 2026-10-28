<?php

namespace App\Http\Controllers\AreaSoci;

use App\Http\Controllers\Controller;
use App\Models\DichiarazioneInteressi;
use App\Models\Socio;
use App\Services\Struttura\ConflittiService;
use App\Services\Struttura\ConflittoNonValidoException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Dichiarazione annuale sui conflitti di interesse nell'area soci (issue #11):
 * la presenta chi ricopre una carica nell'anno; resta modificabile durante
 * l'anno ed è visibile solo al socio e alla segreteria.
 */
class DichiarazioneInteressiController extends Controller
{
    public function __construct(
        private readonly ConflittiService $conflitti,
    ) {}

    public function show(Request $request): View
    {
        /** @var Socio $socio */
        $socio = $request->user('socio');
        $anno = (int) now()->year;

        return view('area-soci.dichiarazione-interessi', [
            'socio' => $socio,
            'anno' => $anno,
            'titolare' => $this->conflitti->titolari($socio->associazione, $anno)->contains('id', $socio->id),
            'dichiarazione' => DichiarazioneInteressi::query()->where('socio_id', $socio->id)->where('anno', $anno)->first(),
            'precedenti' => DichiarazioneInteressi::query()->where('socio_id', $socio->id)->where('anno', '<', $anno)->orderByDesc('anno')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $dati = $request->validate([
            'nessun_conflitto' => ['nullable', 'boolean'],
            'interessi' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $this->conflitti->dichiara($request->user('socio'), (int) now()->year, (bool) ($dati['nessun_conflitto'] ?? false), $dati['interessi'] ?? null);
        } catch (ConflittoNonValidoException $e) {
            return redirect()->route('area-soci.dichiarazione-interessi')->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('area-soci.dichiarazione-interessi')->with('success', 'Dichiarazione registrata. Grazie!');
    }
}
