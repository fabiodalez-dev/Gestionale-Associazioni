<?php

namespace App\Http\Controllers\Soci;

use App\Http\Controllers\Controller;
use App\Http\Requests\Soci\GruppoDinamicoRequest;
use App\Models\GruppoDinamico;
use App\Models\Socio;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Configurazione gruppi dinamici (spec soci §7) — solo admin.
 * Un gruppo è un filtro salvato {colonna: valore} applicato live in lista soci.
 */
class GruppoDinamicoController extends Controller
{
    use AuthorizesRequests;

    public function index(): View
    {
        $this->authorize('viewAny', GruppoDinamico::class);

        return view('soci.gruppi.index', [
            'gruppi' => GruppoDinamico::query()->orderBy('nome_gruppo')->get(),
            'stati' => Socio::STATI,
        ]);
    }

    public function store(GruppoDinamicoRequest $request): RedirectResponse
    {
        $this->authorize('create', GruppoDinamico::class);

        GruppoDinamico::create([
            'nome_gruppo' => $request->validated('nome_gruppo'),
            'descrizione' => $request->validated('descrizione'),
            'filtri_json' => $request->filtriJson(),
        ]);

        return redirect()->route('config-gruppi.index')->with('success', 'Gruppo dinamico creato.');
    }

    public function update(GruppoDinamicoRequest $request, GruppoDinamico $gruppo): RedirectResponse
    {
        $this->authorize('update', $gruppo);

        $gruppo->update([
            'nome_gruppo' => $request->validated('nome_gruppo'),
            'descrizione' => $request->validated('descrizione'),
            'filtri_json' => $request->filtriJson(),
        ]);

        return redirect()->route('config-gruppi.index')->with('success', 'Gruppo dinamico aggiornato.');
    }

    public function destroy(GruppoDinamico $gruppo): RedirectResponse
    {
        $this->authorize('delete', $gruppo);

        $gruppo->delete();

        return redirect()->route('config-gruppi.index')->with('success', 'Gruppo dinamico eliminato.');
    }
}
