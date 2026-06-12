<?php

namespace App\Http\Controllers\EventiVita;

use App\Http\Controllers\Controller;
use App\Http\Requests\EventiVita\SerieUpdateRequest;
use App\Models\Evento;
use App\Services\EventiVita\SerieEventiService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Gestione dell'intera serie ricorrente (f.8): elenco occorrenze,
 * aggiornamento dei campi comuni, annullo di tutta la serie.
 * La singola occorrenza usa il CRUD standard del modulo eventi.
 *
 * {evento} è una qualsiasi occorrenza della serie (binding tenant-scoped:
 * id di un altro tenant → 404 uniforme); senza serie_id → 404.
 */
class SerieEventoController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected SerieEventiService $serie) {}

    public function show(Evento $evento): View
    {
        $this->authorize('view', $evento);

        abort_if($evento->serie_id === null, 404);

        $occorrenze = Evento::query()
            ->dellaSerie($evento->serie_id)
            ->withCount('prenotazioni')
            ->orderBy('data_evento')
            ->get();

        return view('eventi-vita.serie', [
            'evento' => $evento,
            'occorrenze' => $occorrenze,
        ]);
    }

    /**
     * Propaga i campi comuni a tutte le occorrenze della serie.
     */
    public function update(SerieUpdateRequest $request, Evento $evento): RedirectResponse
    {
        $this->authorize('update', $evento);

        abort_if($evento->serie_id === null, 404);

        $aggiornate = $this->serie->aggiornaSerie($evento, $request->validated());

        return redirect()
            ->route('eventi.serie.show', $evento)
            ->with('success', "Serie aggiornata: {$aggiornate} ".($aggiornate === 1 ? 'occorrenza' : 'occorrenze').'.');
    }

    /**
     * Annulla (elimina) TUTTE le occorrenze della serie.
     */
    public function destroy(Evento $evento): RedirectResponse
    {
        $this->authorize('delete', $evento);

        abort_if($evento->serie_id === null, 404);

        $eliminate = $this->serie->eliminaSerie($evento);

        return redirect()
            ->route('eventi.index')
            ->with('success', "Serie annullata: {$eliminate} ".($eliminate === 1 ? 'evento eliminato' : 'eventi eliminati').'.');
    }
}
