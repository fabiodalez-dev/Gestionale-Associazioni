<?php

namespace App\Http\Controllers\Economia;

use App\Http\Controllers\Controller;
use App\Http\Requests\Economia\AggiornaBudgetEventoRequest;
use App\Http\Requests\Economia\StoreSpesaEventoRequest;
use App\Models\Evento;
use App\Models\Prenotazione;
use App\Models\SpesaEvento;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Economia dell'evento (f.17 del piano "Gestione 2"): budget previsto,
 * voci di spesa, incassi dalle prenotazioni pagate e margine.
 * Autorizzazione: EventoPolicy (eventi.view / eventi.update).
 */
class EconomiaEventoController extends Controller
{
    use AuthorizesRequests;

    public function show(Evento $evento): View
    {
        $this->authorize('view', $evento);

        $spese = SpesaEvento::query()
            ->where('evento_id', $evento->id)
            ->orderByDesc('data')
            ->orderByDesc('created_at')
            ->get();

        $totaleSpese = (float) $spese->sum('importo');

        $incassi = (float) Prenotazione::query()
            ->where('evento_id', $evento->id)
            ->whereNotNull('pagata_at')
            ->sum('importo_totale');

        return view('economia.eventi.economia', [
            'evento' => $evento,
            'spese' => $spese,
            'totaleSpese' => $totaleSpese,
            'incassi' => $incassi,
            'margine' => $incassi - $totaleSpese,
        ]);
    }

    public function aggiornaBudget(AggiornaBudgetEventoRequest $request, Evento $evento): RedirectResponse
    {
        $this->authorize('update', $evento);

        $evento->update(['budget' => $request->validated('budget')]);

        return redirect()->route('eventi.economia.show', $evento)
            ->with('success', 'Budget aggiornato.');
    }

    public function storeSpesa(StoreSpesaEventoRequest $request, Evento $evento): RedirectResponse
    {
        $this->authorize('update', $evento);

        SpesaEvento::create([
            'associazione_id' => $evento->associazione_id,
            'evento_id' => $evento->id,
            'descrizione' => $request->validated('descrizione'),
            'importo' => $request->validated('importo'),
            'data' => $request->validated('data'),
        ]);

        return redirect()->route('eventi.economia.show', $evento)
            ->with('success', 'Spesa registrata.');
    }

    public function destroySpesa(SpesaEvento $spesa): RedirectResponse
    {
        // Autorizzazione sull'evento padre (la spesa non ha policy propria)
        /** @var Evento $evento */
        $evento = Evento::query()->findOrFail($spesa->evento_id);

        $this->authorize('update', $evento);

        $spesa->delete();

        return redirect()->route('eventi.economia.show', $evento)
            ->with('success', 'Spesa eliminata.');
    }
}
