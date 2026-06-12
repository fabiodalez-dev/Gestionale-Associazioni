<?php

namespace App\Http\Controllers\Rinnovi;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rinnovi\GeneraAperturaAnnoRequest;
use App\Models\Associazione;
use App\Models\Quota;
use App\Services\Rinnovi\AperturaAnnoService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Apertura anno sociale (f.5): pagina admin "Apri anno {anno}" con
 * anteprima (soci attivi, quote da generare, importi per tipo socio)
 * e generazione bulk idempotente delle quote del nuovo anno.
 *
 * Permessi: area quote (QuotaPolicy).
 */
class AperturaAnnoController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly AperturaAnnoService $apertura) {}

    public function index(Request $request): View|RedirectResponse
    {
        $this->authorize('viewAny', Quota::class);

        $associazione = $this->associazioneCorrente();

        if ($associazione === null) {
            return redirect()->route('dashboard')->with('warning', 'Seleziona prima un\'associazione.');
        }

        $anno = $this->annoRichiesto($request->input('anno'));

        return view('rinnovi.apertura-anno', [
            'associazione' => $associazione,
            'anno' => $anno,
            'annoProssimo' => (int) now()->year + 1,
            'annoCorrente' => (int) now()->year,
            'anteprima' => $this->apertura->anteprima($associazione, $anno),
        ]);
    }

    public function genera(GeneraAperturaAnnoRequest $request): RedirectResponse
    {
        $associazione = $this->associazioneCorrente();

        if ($associazione === null) {
            return redirect()->route('dashboard')->with('warning', 'Seleziona prima un\'associazione.');
        }

        $anno = (int) $request->validated('anno');

        $create = $this->apertura->apri($associazione, $anno);

        return redirect()
            ->route('apertura-anno.index', ['anno' => $anno])
            ->with('success', $create > 0
                ? "Anno {$anno} aperto: generate {$create} quote per i soci attivi."
                : "Nessuna quota da generare per l'anno {$anno}: tutti i soci attivi hanno già la quota.");
    }

    /**
     * Anno richiesto (default: anno prossimo), confinato a un range sensato.
     */
    private function annoRichiesto(mixed $input): int
    {
        $default = (int) now()->year + 1;
        $anno = is_numeric($input) ? (int) $input : $default;

        return ($anno >= 2000 && $anno <= 2100) ? $anno : $default;
    }

    private function associazioneCorrente(): ?Associazione
    {
        $id = session('associazione_id');

        return $id ? Associazione::query()->find($id) : null;
    }
}
