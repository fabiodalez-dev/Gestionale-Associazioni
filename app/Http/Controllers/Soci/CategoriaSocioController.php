<?php

namespace App\Http\Controllers\Soci;

use App\Http\Controllers\Controller;
use App\Http\Requests\Soci\CategoriaSocioRequest;
use App\Models\CategoriaSocio;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Configurazione categorie di socio (spec soci §4) — solo admin.
 */
class CategoriaSocioController extends Controller
{
    use AuthorizesRequests;

    public function index(): View
    {
        $this->authorize('viewAny', CategoriaSocio::class);

        return view('soci.categorie.index', [
            'categorie' => CategoriaSocio::query()->withCount('soci')->orderBy('nome')->get(),
        ]);
    }

    public function store(CategoriaSocioRequest $request): RedirectResponse
    {
        $this->authorize('create', CategoriaSocio::class);

        CategoriaSocio::create($request->validated());

        return redirect()->route('categorie-socio.index')->with('success', 'Categoria creata.');
    }

    public function update(CategoriaSocioRequest $request, CategoriaSocio $categoriaSocio): RedirectResponse
    {
        $this->authorize('update', $categoriaSocio);

        $categoriaSocio->update($request->validated());

        return redirect()->route('categorie-socio.index')->with('success', 'Categoria aggiornata.');
    }

    public function destroy(CategoriaSocio $categoriaSocio): RedirectResponse
    {
        $this->authorize('delete', $categoriaSocio);

        $categoriaSocio->delete();

        return redirect()->route('categorie-socio.index')->with('success', 'Categoria eliminata.');
    }
}
