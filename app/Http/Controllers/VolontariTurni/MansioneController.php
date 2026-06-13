<?php

namespace App\Http\Controllers\VolontariTurni;

use App\Http\Controllers\Controller;
use App\Http\Requests\VolontariTurni\StoreMansioneRequest;
use App\Models\Mansione;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Gestione mansioni/competenze del volontariato (f.2 del piano
 * "Organizzazione 3"): tassonomia di configurazione del tenant.
 */
class MansioneController extends Controller
{
    use AuthorizesRequests;

    public function index(): View
    {
        $this->authorize('viewAny', Mansione::class);

        $mansioni = Mansione::query()
            ->withCount('soci')
            ->orderBy('nome')
            ->get();

        return view('volontari-turni.mansioni.index', [
            'mansioni' => $mansioni,
        ]);
    }

    public function store(StoreMansioneRequest $request): RedirectResponse
    {
        $this->authorize('create', Mansione::class);

        Mansione::create([
            'nome' => $request->validated('nome'),
            'descrizione' => $request->validated('descrizione'),
        ]);

        return redirect()
            ->route('mansioni.index')
            ->with('success', 'Mansione creata.');
    }

    public function update(StoreMansioneRequest $request, Mansione $mansione): RedirectResponse
    {
        $this->authorize('update', $mansione);

        $mansione->update([
            'nome' => $request->validated('nome'),
            'descrizione' => $request->validated('descrizione'),
        ]);

        return redirect()
            ->route('mansioni.index')
            ->with('success', 'Mansione aggiornata.');
    }

    public function destroy(Mansione $mansione): RedirectResponse
    {
        $this->authorize('delete', $mansione);

        $mansione->delete();

        return redirect()
            ->route('mansioni.index')
            ->with('success', 'Mansione eliminata.');
    }
}
