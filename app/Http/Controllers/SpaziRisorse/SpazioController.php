<?php

namespace App\Http\Controllers\SpaziRisorse;

use App\Http\Controllers\Controller;
use App\Http\Requests\SpaziRisorse\SpazioRequest;
use App\Models\Sede;
use App\Models\Spazio;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CRUD spazi (f.7 piano "Organizzazione 3"): anagrafica delle sale/locali
 * prenotabili internamente (f.8), collegabili a eventi (f.11), turni e
 * attrezzature.
 *
 * Isolamento tenant: il global scope BelongsToAssociazione filtra lettura,
 * binding e scrittura; un id di un altro tenant produce 404.
 */
class SpazioController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Spazio::class);

        $search = trim((string) $request->query('search', ''));

        $spazi = Spazio::query()
            ->with('sede')
            ->withCount(['prenotazioni', 'attrezzature'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('nome', 'like', "%{$search}%")
                        ->orWhere('descrizione', 'like', "%{$search}%");
                });
            })
            ->orderBy('nome')
            ->paginate(25)
            ->withQueryString();

        // ?edit={id}: form precompilato; id altrui/inesistente → nessun errore
        $editSpazio = null;
        if ($request->filled('edit')) {
            $editSpazio = Spazio::query()->find($request->query('edit'));
        }

        return view('spazi-risorse.spazi.index', [
            'spazi' => $spazi,
            'editSpazio' => $editSpazio,
            'sedi' => Sede::query()->orderBy('nome')->get(),
            'search' => $search,
        ]);
    }

    public function store(SpazioRequest $request): RedirectResponse
    {
        $this->authorize('create', Spazio::class);

        Spazio::create($request->validated());

        return redirect()
            ->route('spazi.index')
            ->with('success', 'Spazio creato con successo.');
    }

    public function update(SpazioRequest $request, Spazio $spazio): RedirectResponse
    {
        $this->authorize('update', $spazio);

        $spazio->update($request->validated());

        return redirect()
            ->route('spazi.index')
            ->with('success', 'Spazio aggiornato con successo.');
    }

    public function destroy(Spazio $spazio): RedirectResponse
    {
        $this->authorize('delete', $spazio);

        $spazio->delete();

        return redirect()
            ->route('spazi.index')
            ->with('success', 'Spazio eliminato con successo.');
    }
}
