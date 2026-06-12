<?php

namespace App\Http\Controllers\Rinnovi;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rinnovi\InviaCampagnaRinnoviRequest;
use App\Models\Associazione;
use App\Models\Tessera;
use App\Services\Rinnovi\CampagnaRinnoviService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Campagna rinnovi {anno corrente} (f.2): pagina admin con i soci attivi
 * senza tessera dell'anno, stato per socio (inviata/pagata/rinnovata),
 * contatori e invio massivo delle email con link di pagamento personale.
 *
 * Permessi: area tessere (TesseraPolicy — la campagna emette tessere
 * tramite il pagamento delle quote).
 */
class CampagnaRinnoviController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly CampagnaRinnoviService $campagna) {}

    public function index(): View|RedirectResponse
    {
        $this->authorize('viewAny', Tessera::class);

        $associazione = $this->associazioneCorrente();

        if ($associazione === null) {
            return redirect()->route('dashboard')->with('warning', 'Seleziona prima un\'associazione.');
        }

        $anno = (int) now()->year;
        $soci = $this->campagna->sociDaRinnovare($associazione, $anno);

        return view('rinnovi.campagna', [
            'associazione' => $associazione,
            'anno' => $anno,
            'soci' => $soci,
            'contatori' => $this->campagna->contatori($associazione, $anno, $soci),
        ]);
    }

    public function invia(InviaCampagnaRinnoviRequest $request): RedirectResponse
    {
        $associazione = $this->associazioneCorrente();

        if ($associazione === null) {
            return redirect()->route('dashboard')->with('warning', 'Seleziona prima un\'associazione.');
        }

        $anno = (int) now()->year;

        /** @var list<string> $socioIds */
        $socioIds = $request->validated('socio_ids');

        $esito = $this->campagna->inviaMassivo($associazione, $anno, $socioIds);

        $messaggio = "Campagna rinnovi {$anno}: {$esito['accodate']} email accodate.";
        if ($esito['saltati'] > 0) {
            $messaggio .= " {$esito['saltati']} soci saltati (senza email, opt-out o già rinnovati).";
        }

        return redirect()->route('campagna-rinnovi.index')->with('success', $messaggio);
    }

    private function associazioneCorrente(): ?Associazione
    {
        $id = session('associazione_id');

        return $id ? Associazione::query()->find($id) : null;
    }
}
