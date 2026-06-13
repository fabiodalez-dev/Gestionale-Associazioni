<?php

namespace App\Http\Controllers\Progetti;

use App\Http\Controllers\Controller;
use App\Http\Requests\Progetti\MilestoneRequest;
use App\Models\MilestoneProgetto;
use App\Models\Progetto;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;

/**
 * Milestone di progetto (f.16): checklist con barra avanzamento. La gestione
 * delle milestone è un aggiornamento del progetto, quindi richiede
 * l'abilità update sul progetto (ProgettoPolicy). Il tenant e il legame al
 * progetto sono imposti dalla route + sessione, mai dal client.
 */
class MilestoneController extends Controller
{
    use AuthorizesRequests;

    public function store(MilestoneRequest $request, Progetto $progetto): RedirectResponse
    {
        $this->authorize('update', $progetto);

        $progetto->milestones()->create([
            'associazione_id' => $progetto->associazione_id,
            'titolo' => $request->validated('titolo'),
            'scadenza' => $request->validated('scadenza'),
            'ordine' => $request->validated('ordine') ?? 0,
        ]);

        return redirect()->route('progetti.show', $progetto)
            ->with('success', 'Milestone aggiunta.');
    }

    public function update(MilestoneRequest $request, Progetto $progetto, MilestoneProgetto $milestone): RedirectResponse
    {
        $this->authorize('update', $progetto);
        $this->verificaAppartenenza($progetto, $milestone);

        $milestone->update([
            'titolo' => $request->validated('titolo'),
            'scadenza' => $request->validated('scadenza'),
            'ordine' => $request->validated('ordine') ?? $milestone->ordine,
        ]);

        return redirect()->route('progetti.show', $progetto)
            ->with('success', 'Milestone aggiornata.');
    }

    /**
     * Spunta/de-spunta una milestone (toggle completata_at).
     */
    public function toggle(Progetto $progetto, MilestoneProgetto $milestone): RedirectResponse
    {
        $this->authorize('update', $progetto);
        $this->verificaAppartenenza($progetto, $milestone);

        $milestone->update([
            'completata_at' => $milestone->completata_at === null ? now() : null,
        ]);

        return redirect()->route('progetti.show', $progetto)
            ->with('success', $milestone->completata_at !== null ? 'Milestone completata.' : 'Milestone riaperta.');
    }

    public function destroy(Progetto $progetto, MilestoneProgetto $milestone): RedirectResponse
    {
        $this->authorize('update', $progetto);
        $this->verificaAppartenenza($progetto, $milestone);

        $milestone->delete();

        return redirect()->route('progetti.show', $progetto)
            ->with('success', 'Milestone eliminata.');
    }

    /**
     * La milestone deve appartenere al progetto della route (oltre al tenant,
     * già garantito dal global scope su entrambi i model): evita di operare
     * su una milestone di un altro progetto dello stesso tenant.
     */
    private function verificaAppartenenza(Progetto $progetto, MilestoneProgetto $milestone): void
    {
        abort_unless($milestone->progetto_id === $progetto->id, 404);
    }
}
