<?php

namespace App\Http\Controllers\Soci;

use App\Http\Controllers\Controller;
use App\Http\Requests\Soci\SalvaFiltroPresetRequest;
use App\Models\SavedFilter;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;

/**
 * Preset filtri lista soci (spec soci §11): salvataggio con upsert su
 * (associazione, utente, scope, name) ed eliminazione di preset propri o condivisi.
 */
class SavedFilterController extends Controller
{
    use AuthorizesRequests;

    public function store(SalvaFiltroPresetRequest $request): RedirectResponse
    {
        $this->authorize('create', SavedFilter::class);

        $preset = SavedFilter::updateOrCreate(
            [
                'associazione_id' => \App\Models\Socio::currentAssociazioneId(),
                'user_id' => $request->boolean('condiviso') ? null : $request->user()->id,
                'scope' => 'soci',
                'name' => $request->validated('preset_name'),
            ],
            ['params_json' => $request->paramsFiltro()]
        );

        return redirect()
            ->route('soci.index', ['preset_id' => $preset->id])
            ->with('success', "Preset \"{$preset->name}\" salvato.");
    }

    public function destroy(SavedFilter $filtro): RedirectResponse
    {
        $this->authorize('delete', $filtro);

        $filtro->delete();

        return redirect()
            ->route('soci.index')
            ->with('success', 'Preset eliminato.');
    }
}
