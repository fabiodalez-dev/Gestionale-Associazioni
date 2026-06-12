<?php

namespace App\Http\Controllers\Comunicazione;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comunicazione\AuguriConfigRequest;
use App\Models\Associazione;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Configurazioni di comunicazione del tenant (f.9 piano Gestione 2):
 * toggle degli auguri di compleanno automatici. Stessa autorizzazione
 * delle altre configurazioni (AssociazionePolicy::manageSettings).
 */
class ConfigurazioniComunicazioneController extends Controller
{
    public function update(AuguriConfigRequest $request): RedirectResponse
    {
        $associazione = Associazione::query()->findOrFail(session('associazione_id'));

        Gate::authorize('manageSettings', $associazione);

        $associazione->forceFill([
            'auguri_automatici' => $request->validated('auguri_automatici'),
        ])->save();

        return redirect()
            ->route('configurazioni.index', ['section' => 'comunicazione'])
            ->with('success', 'Impostazioni di comunicazione aggiornate.');
    }
}
