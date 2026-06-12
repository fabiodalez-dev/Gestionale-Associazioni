<?php

namespace App\Http\Controllers\Comunicazione;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comunicazione\SondaggioRequest;
use App\Models\Sondaggio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Sondaggi ai soci — CRUD admin con risultati aggregati (f.11).
 */
class SondaggioController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Sondaggio::class);

        return view('comunicazione.sondaggi.index', [
            'sondaggi' => Sondaggio::query()
                ->withCount('risposte')
                ->orderByDesc('created_at')
                ->paginate(20),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Sondaggio::class);

        return view('comunicazione.sondaggi.form', ['sondaggio' => new Sondaggio]);
    }

    public function store(SondaggioRequest $request): RedirectResponse
    {
        Gate::authorize('create', Sondaggio::class);

        $sondaggio = Sondaggio::create($request->validated());

        return redirect()
            ->route('sondaggi.show', $sondaggio)
            ->with('success', 'Sondaggio creato con successo.');
    }

    /**
     * Risultati aggregati con barre percentuali.
     */
    public function show(Sondaggio $sondaggio): View
    {
        Gate::authorize('view', $sondaggio);

        $risultati = $sondaggio->risultati();
        $totale = array_sum($risultati);

        return view('comunicazione.sondaggi.show', [
            'sondaggio' => $sondaggio,
            'risultati' => $risultati,
            'totaleVoti' => $totale,
        ]);
    }

    public function edit(Sondaggio $sondaggio): View
    {
        Gate::authorize('update', $sondaggio);

        return view('comunicazione.sondaggi.form', ['sondaggio' => $sondaggio]);
    }

    public function update(SondaggioRequest $request, Sondaggio $sondaggio): RedirectResponse
    {
        Gate::authorize('update', $sondaggio);

        $sondaggio->update($request->validated());

        return redirect()
            ->route('sondaggi.show', $sondaggio)
            ->with('success', 'Sondaggio aggiornato con successo.');
    }

    public function destroy(Sondaggio $sondaggio): RedirectResponse
    {
        Gate::authorize('delete', $sondaggio);

        $sondaggio->delete();

        return redirect()
            ->route('sondaggi.index')
            ->with('success', 'Sondaggio eliminato (con le relative risposte).');
    }
}
