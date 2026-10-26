<?php

namespace App\Http\Controllers\Struttura;

use App\Http\Controllers\Controller;
use App\Models\Riunione;
use App\Models\VotazioneRiunione;
use App\Services\Struttura\VotoAssembleaService;
use App\Services\Struttura\VotoNonValidoException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Voto online in assemblea, lato segreteria (issue #8): apertura del voto su
 * un punto (palese o segreto) e chiusura con eventuali voti espressi in sala.
 * Governance: RiunionePolicy (riunioni.update).
 */
class VotazioneOnlineController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly VotoAssembleaService $voto,
    ) {}

    public function apri(Request $request, Riunione $riunione): RedirectResponse
    {
        $this->authorize('update', $riunione);

        $dati = $request->validate([
            'punto_indice' => ['required', 'integer', 'min:0'],
            'segreta' => ['nullable', 'boolean'],
        ]);

        try {
            $this->voto->apri($riunione, (int) $dati['punto_indice'], (bool) ($dati['segreta'] ?? false));
        } catch (VotoNonValidoException $e) {
            return redirect()->route('riunioni.show', $riunione)->with('error', $e->getMessage());
        }

        return redirect()
            ->route('riunioni.show', $riunione)
            ->with('success', 'Voto aperto: i soci presenti possono votare dall\'area soci.');
    }

    public function chiudi(Request $request, Riunione $riunione, VotazioneRiunione $votazione): RedirectResponse
    {
        $this->authorize('update', $riunione);

        abort_unless($votazione->riunione_id === $riunione->id, 404);

        $sala = $request->validate([
            'favorevoli' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'contrari' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'astenuti' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        try {
            $votazione = $this->voto->chiudi($votazione, array_map('intval', array_filter($sala, fn ($v) => $v !== null)));
        } catch (VotoNonValidoException $e) {
            return redirect()->route('riunioni.show', $riunione)->with('error', $e->getMessage());
        }

        return redirect()
            ->route('riunioni.show', $riunione)
            ->with('success', sprintf(
                'Voto chiuso: %s (favorevoli %d, contrari %d, astenuti %d).',
                $votazione->esito,
                $votazione->favorevoli,
                $votazione->contrari,
                $votazione->astenuti,
            ));
    }
}
