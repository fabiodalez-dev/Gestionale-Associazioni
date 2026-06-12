<?php

namespace App\Http\Controllers\EventiVita;

use App\Http\Controllers\Controller;
use App\Http\Requests\EventiVita\EventoRicorrenteRequest;
use App\Models\Evento;
use App\Services\EventiVita\SerieEventiService;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Creazione di eventi ricorrenti (f.8): form dedicato e generazione
 * delle occorrenze come eventi figli con serie_id condiviso.
 */
class EventoRicorrenteController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected SerieEventiService $serie) {}

    public function create(): View
    {
        $this->authorize('create', Evento::class);

        return view('eventi-vita.ricorrente-create', [
            'frequenze' => SerieEventiService::FREQUENZE,
            'maxOccorrenze' => SerieEventiService::MAX_OCCORRENZE,
        ]);
    }

    public function store(EventoRicorrenteRequest $request): RedirectResponse
    {
        $this->authorize('create', Evento::class);

        $occorrenze = $this->serie->creaSerie(
            $request->datiEvento(),
            (string) $request->validated('frequenza'),
            Carbon::parse($request->validated('fino_al'))->endOfDay(),
        );

        $totale = count($occorrenze);

        return redirect()
            ->route('eventi.index')
            ->with('success', "Serie ricorrente creata: {$totale} ".($totale === 1 ? 'evento' : 'eventi').' in calendario.');
    }
}
