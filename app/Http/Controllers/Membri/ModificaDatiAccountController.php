<?php

namespace App\Http\Controllers\Membri;

use App\Http\Controllers\Controller;
use App\Http\Requests\Membri\ProponiModificaDatiAccountRequest;
use App\Models\Account;
use App\Models\Associazione;
use App\Models\RichiestaModificaDati;
use App\Models\Socio;
use App\Services\Membri\ModificaDatiService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * AREA ACCOUNT (/a/{slug}/account) — "Richiedi modifica dati" (f.19):
 * l'account COLLEGATO a un socio propone modifiche ai dati anagrafici
 * del socio collegato. Gli account non collegati ricevono 404.
 */
class ModificaDatiAccountController extends Controller
{
    public function __construct(private readonly ModificaDatiService $modifiche) {}

    public function show(Request $request, Associazione $associazione): View
    {
        $account = $request->user('account');
        $socio = $this->socioCollegato($account);

        abort_if($socio === null, 404);

        return view('pubblico.account.modifica-dati', [
            'associazione' => $associazione,
            'account' => $account,
            'socio' => $socio,
            'service' => $this->modifiche,
            'richiestaPendente' => $this->richiestePerSocio($socio)->inAttesa()->first(),
            'richieste' => $this->richiestePerSocio($socio)->orderByDesc('created_at')->limit(10)->get(),
        ]);
    }

    public function store(ProponiModificaDatiAccountRequest $request, Associazione $associazione): RedirectResponse
    {
        /** @var Account $account */
        $account = $request->user('account');

        /** @var Socio $socio */
        $socio = $request->socioTarget();

        if ($this->modifiche->haRichiestaPendente($socio)) {
            return redirect()
                ->route('pubblico.account.modifica-dati', ['associazione' => $associazione->slug])
                ->with('error', 'C\'è già una richiesta in attesa: attendi che venga gestita prima di inviarne un\'altra.');
        }

        $proposte = $this->modifiche->proposteDaInput($socio, $request->validated());

        if ($proposte === []) {
            return redirect()
                ->route('pubblico.account.modifica-dati', ['associazione' => $associazione->slug])
                ->with('error', 'Nessuna modifica rilevata: i valori inseriti coincidono con quelli attuali.');
        }

        $this->modifiche->proponi($socio, $account, $proposte);

        return redirect()
            ->route('pubblico.account.modifica-dati', ['associazione' => $associazione->slug])
            ->with('success', 'Richiesta inviata: l\'associazione la esaminerà al più presto.');
    }

    private function socioCollegato(?Account $account): ?Socio
    {
        if ($account === null || $account->socio_id === null) {
            return null;
        }

        return Socio::withoutGlobalScopes()
            ->where('associazione_id', $account->associazione_id)
            ->find($account->socio_id);
    }

    /**
     * @return Builder<RichiestaModificaDati>
     */
    private function richiestePerSocio(Socio $socio)
    {
        return RichiestaModificaDati::withoutGlobalScopes()
            ->where('associazione_id', $socio->associazione_id)
            ->where('socio_id', $socio->id);
    }
}
