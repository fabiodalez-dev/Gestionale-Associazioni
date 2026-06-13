<?php

namespace App\Http\Controllers\Relazioni;

use App\Http\Controllers\Controller;
use App\Http\Requests\Relazioni\SponsorRequest;
use App\Models\ContattoEsterno;
use App\Models\Sponsor;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Sponsor & partner (f.22 del piano "Organizzazione 3"): gestione admin dei
 * sostenitori con livello, periodo e visibilità sul mini-sito pubblico
 * (sezione "I nostri sostenitori" gestita dal RelazioniPublicComposer).
 * Autorizzazione: SponsorPolicy (famiglia struttura.*).
 */
class SponsorController extends Controller
{
    use AuthorizesRequests;

    public function index(): View
    {
        $this->authorize('viewAny', Sponsor::class);

        $sponsor = Sponsor::query()
            ->with('contattoEsterno')
            ->orderByRaw($this->ordineLivelloSql())
            ->orderBy('nome')
            ->paginate(20);

        return view('relazioni.sponsor.index', [
            'sponsor' => $sponsor,
            'contatti' => ContattoEsterno::query()
                ->orderBy('nome')
                ->get(['id', 'nome', 'tipo']),
        ]);
    }

    public function store(SponsorRequest $request): RedirectResponse
    {
        $this->authorize('create', Sponsor::class);

        Sponsor::create($request->datiSponsor());

        return redirect()
            ->route('sponsor.index')
            ->with('success', 'Sponsor creato.');
    }

    public function update(SponsorRequest $request, Sponsor $sponsor): RedirectResponse
    {
        $this->authorize('update', $sponsor);

        $sponsor->update($request->datiSponsor());

        return redirect()
            ->route('sponsor.index')
            ->with('success', 'Sponsor aggiornato.');
    }

    public function destroy(Sponsor $sponsor): RedirectResponse
    {
        $this->authorize('delete', $sponsor);

        $sponsor->delete();

        return redirect()
            ->route('sponsor.index')
            ->with('success', 'Sponsor eliminato.');
    }

    /**
     * Ordinamento per importanza del livello (oro → partner) compatibile
     * sia con SQLite (test) sia con MySQL.
     */
    private function ordineLivelloSql(): string
    {
        return 'CASE livello '
            ."WHEN '".Sponsor::LIVELLO_ORO."' THEN 1 "
            ."WHEN '".Sponsor::LIVELLO_ARGENTO."' THEN 2 "
            ."WHEN '".Sponsor::LIVELLO_BRONZO."' THEN 3 "
            .'ELSE 4 END';
    }
}
