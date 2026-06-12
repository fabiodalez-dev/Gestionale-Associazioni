<?php

namespace App\Http\Controllers\Membri;

use App\Http\Controllers\Controller;
use App\Http\Requests\Membri\ProponiModificaDatiSocioRequest;
use App\Models\RichiestaModificaDati;
use App\Models\Socio;
use App\Services\Membri\ModificaDatiService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * AREA SOCI — "Richiedi modifica dati" (f.19 — piano "Gestione 2"):
 * il socio propone modifiche ai propri dati anagrafici; le proposte
 * restano in attesa finché un admin non le approva/rifiuta.
 */
class ModificaDatiSocioController extends Controller
{
    public function __construct(private readonly ModificaDatiService $modifiche) {}

    public function show(Request $request): View
    {
        /** @var Socio $socio */
        $socio = $request->user('socio');

        return view('area-soci.modifica-dati', [
            'socio' => $socio,
            'service' => $this->modifiche,
            'richiestaPendente' => $this->richiestePerSocio($socio)->inAttesa()->first(),
            'richieste' => $this->richiestePerSocio($socio)->orderByDesc('created_at')->limit(10)->get(),
        ]);
    }

    public function store(ProponiModificaDatiSocioRequest $request): RedirectResponse
    {
        /** @var Socio $socio */
        $socio = $request->socioTarget();

        if ($this->modifiche->haRichiestaPendente($socio)) {
            return redirect()
                ->route('area-soci.modifica-dati')
                ->with('error', 'Hai già una richiesta in attesa: attendi che venga gestita prima di inviarne un\'altra.');
        }

        $proposte = $this->modifiche->proposteDaInput($socio, $request->validated());

        if ($proposte === []) {
            return redirect()
                ->route('area-soci.modifica-dati')
                ->with('error', 'Nessuna modifica rilevata: i valori inseriti coincidono con quelli attuali.');
        }

        $this->modifiche->proponi($socio, $socio, $proposte);

        return redirect()
            ->route('area-soci.modifica-dati')
            ->with('success', 'Richiesta inviata: l\'associazione la esaminerà al più presto.');
    }

    /**
     * Richieste del socio autenticato (qualunque sia l'origine).
     *
     * @return Builder<RichiestaModificaDati>
     */
    private function richiestePerSocio(Socio $socio)
    {
        return RichiestaModificaDati::withoutGlobalScopes()
            ->where('associazione_id', $socio->associazione_id)
            ->where('socio_id', $socio->id);
    }
}
