<?php

namespace App\Http\Controllers\Soci;

use App\Http\Controllers\Controller;
use App\Http\Requests\Soci\TipoSocioRequest;
use App\Models\TipoSocio;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Configurazione tipi di socio (spec soci §3) — solo admin.
 */
class TipoSocioController extends Controller
{
    use AuthorizesRequests;

    public function index(): View
    {
        $this->authorize('viewAny', TipoSocio::class);

        return view('soci.tipi.index', [
            'tipi' => TipoSocio::query()->withCount('soci')->orderBy('nome')->get(),
        ]);
    }

    public function store(TipoSocioRequest $request): RedirectResponse
    {
        $this->authorize('create', TipoSocio::class);

        TipoSocio::create($request->validated());

        return redirect()->route('tipi-socio.index')->with('success', 'Tipo socio creato.');
    }

    public function update(TipoSocioRequest $request, TipoSocio $tipoSocio): RedirectResponse
    {
        $this->authorize('update', $tipoSocio);

        $tipoSocio->update($request->validated());

        return redirect()->route('tipi-socio.index')->with('success', 'Tipo socio aggiornato.');
    }

    public function destroy(TipoSocio $tipoSocio): RedirectResponse
    {
        $this->authorize('delete', $tipoSocio);

        // FK soci.tipo_socio_id è nullOnDelete: i soci restano senza tipo
        $tipoSocio->delete();

        return redirect()->route('tipi-socio.index')->with('success', 'Tipo socio eliminato.');
    }
}
