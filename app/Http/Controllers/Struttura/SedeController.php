<?php

namespace App\Http\Controllers\Struttura;

use App\Http\Controllers\Controller;
use App\Http\Requests\Struttura\SalvaSedeRequest;
use App\Models\Sede;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;

/**
 * CRUD sedi del tenant corrente, dentro la pagina configurazioni (spec §2.2).
 */
class SedeController extends Controller
{
    /**
     * Crea o aggiorna (se presente id) una sede, sempre scoped al tenant.
     */
    public function save(SalvaSedeRequest $request): RedirectResponse
    {
        $associazione = $request->associazioneCorrente();
        $dati = $request->validated();

        if (! empty($dati['id'])) {
            $sede = Sede::query()
                ->forAssociazione($associazione->id) // doppia condizione id+tenant (spec §2.2)
                ->where('id', $dati['id'])
                ->firstOrFail();

            $sede->update(collect($dati)->except('id')->all());
            $messaggio = 'Sede aggiornata con successo.';
        } else {
            Sede::create([
                'associazione_id' => $associazione->id,
                ...collect($dati)->except('id')->all(),
            ]);
            $messaggio = 'Sede creata con successo.';
        }

        return redirect()
            ->route('configurazioni.index', ['section' => 'sedi'])
            ->with('success', $messaggio);
    }

    public function destroy(Request $request, string $sede): RedirectResponse
    {
        $associazione = \App\Models\Associazione::findOrFail(Session::get('associazione_id'));
        Gate::authorize('manageSettings', $associazione);

        $eliminata = Sede::query()
            ->forAssociazione($associazione->id)
            ->where('id', $sede)
            ->first();

        if ($eliminata) {
            $eliminata->delete();
        }

        return redirect()
            ->route('configurazioni.index', ['section' => 'sedi'])
            ->with(
                $eliminata ? 'success' : 'warning',
                $eliminata ? 'Sede eliminata.' : 'Sede non trovata.'
            );
    }
}
