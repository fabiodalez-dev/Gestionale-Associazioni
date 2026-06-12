<?php

namespace App\Http\Controllers\Operativita;

use App\Http\Controllers\Controller;
use App\Http\Requests\Operativita\StoreNotaSocioRequest;
use App\Models\NotaSocio;
use App\Models\Socio;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;

/**
 * Note interne sulla scheda socio (f.3 del piano "Gestione 2"): appunti
 * manuali degli admin con pin opzionale, distinti dallo storico automatico.
 * Tenant isolation: il binding di {socio} e la verifica nota↔socio passano
 * dai global scope (id di altri tenant → 404).
 */
class NotaSocioController extends Controller
{
    use AuthorizesRequests;

    public function store(StoreNotaSocioRequest $request, Socio $socio): RedirectResponse
    {
        $this->authorize('create', NotaSocio::class);

        NotaSocio::create([
            'associazione_id' => $socio->associazione_id,
            'socio_id' => $socio->id,
            'autore_id' => $request->user()->id,
            'testo' => $request->validated('testo'),
            'fissata' => $request->boolean('fissata'),
        ]);

        return redirect()
            ->route('soci.show', ['socio' => $socio, 'tab' => 'note'])
            ->with('success', 'Nota aggiunta.');
    }

    /**
     * Fissa/sgancia la nota in cima alla timeline (pin, f.3).
     */
    public function togglePin(Socio $socio, NotaSocio $nota): RedirectResponse
    {
        $this->authorize('update', $nota);

        abort_unless($nota->socio_id === $socio->id, 404);

        $nota->update(['fissata' => ! $nota->fissata]);

        return redirect()
            ->route('soci.show', ['socio' => $socio, 'tab' => 'note'])
            ->with('success', $nota->fissata ? 'Nota fissata in cima.' : 'Nota sganciata.');
    }

    public function destroy(Socio $socio, NotaSocio $nota): RedirectResponse
    {
        $this->authorize('delete', $nota);

        abort_unless($nota->socio_id === $socio->id, 404);

        $nota->delete();

        return redirect()
            ->route('soci.show', ['socio' => $socio, 'tab' => 'note'])
            ->with('success', 'Nota eliminata.');
    }
}
