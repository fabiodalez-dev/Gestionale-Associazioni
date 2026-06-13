<?php

namespace App\Http\Controllers\VolontariTurni;

use App\Http\Controllers\Controller;
use App\Http\Requests\VolontariTurni\AggiornaProfiloVolontarioRequest;
use App\Models\Mansione;
use App\Models\Socio;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Volontari (f.1/f.2 del piano "Organizzazione 3"): elenco dei soci
 * volontari con filtro per mansione e disponibilità, più l'aggiornamento
 * del profilo volontario (flag, disponibilità, mansioni) dalla scheda socio.
 */
class VolontarioController extends Controller
{
    use AuthorizesRequests;

    /**
     * Elenco volontari con filtro per mansione (f.2): pagina dedicata che
     * non tocca la lista soci del modulo soci.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Socio::class);

        $mansioneId = (string) $request->query('mansione', '');
        $soloAttivi = $request->query('attivi', '1') === '1';

        $query = Socio::query()
            ->with('mansioni:id,nome')
            ->when($soloAttivi, fn ($q) => $q->where('volontario_attivo', true))
            ->orderBy('cognome')
            ->orderBy('nome');

        if ($mansioneId !== '' && Mansione::query()->whereKey($mansioneId)->exists()) {
            $query->whereHas('mansioni', fn ($q) => $q->where('mansioni.id', $mansioneId));
        } else {
            $mansioneId = '';
        }

        $soci = $query->paginate(25)->withQueryString();

        return view('volontari-turni.volontari.index', [
            'soci' => $soci,
            'mansioni' => Mansione::query()->orderBy('nome')->get(['id', 'nome']),
            'mansioneId' => $mansioneId,
            'soloAttivi' => $soloAttivi,
        ]);
    }

    /**
     * Aggiorna il profilo volontario dalla scheda socio (f.1/f.2).
     */
    public function update(AggiornaProfiloVolontarioRequest $request, Socio $socio): RedirectResponse
    {
        $this->authorize('update', $socio);

        $socio->update([
            'volontario_attivo' => $request->boolean('volontario_attivo'),
            'disponibilita' => $request->disponibilitaNormalizzata(),
        ]);

        $socio->mansioni()->sync($request->mansioniValide());

        return redirect()
            ->route('soci.show', [$socio, 'tab' => 'volontariato'])
            ->with('success', 'Profilo volontario aggiornato.');
    }
}
