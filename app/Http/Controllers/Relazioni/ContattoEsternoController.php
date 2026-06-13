<?php

namespace App\Http\Controllers\Relazioni;

use App\Http\Controllers\Controller;
use App\Http\Requests\Relazioni\ContattoEsternoRequest;
use App\Models\ContattoEsterno;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Mini-CRM contatti esterni (f.21 del piano "Organizzazione 3"): elenco con
 * ricerca testuale (nome/referente/email) e filtro per tipo, CRUD completo.
 * Autorizzazione: ContattoEsternoPolicy (famiglia struttura.*).
 */
class ContattoEsternoController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', ContattoEsterno::class);

        $ricerca = trim((string) $request->query('q', ''));

        $tipo = (string) $request->query('tipo', 'tutti');
        if (! in_array($tipo, [...ContattoEsterno::TIPI, 'tutti'], true)) {
            $tipo = 'tutti';
        }

        $contatti = ContattoEsterno::query()
            ->when($tipo !== 'tutti', fn ($q) => $q->delTipo($tipo))
            ->when($ricerca !== '', function ($q) use ($ricerca) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $ricerca).'%';
                $q->where(function ($sub) use ($like) {
                    $sub->where('nome', 'like', $like)
                        ->orWhere('referente', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            })
            ->orderBy('nome')
            ->paginate(20)
            ->withQueryString();

        return view('relazioni.contatti-esterni.index', [
            'contatti' => $contatti,
            'ricerca' => $ricerca,
            'tipo' => $tipo,
        ]);
    }

    public function store(ContattoEsternoRequest $request): RedirectResponse
    {
        $this->authorize('create', ContattoEsterno::class);

        ContattoEsterno::create($request->datiContatto());

        return redirect()
            ->route('contatti-esterni.index')
            ->with('success', 'Contatto creato.');
    }

    public function update(ContattoEsternoRequest $request, ContattoEsterno $contattiEsterni): RedirectResponse
    {
        $this->authorize('update', $contattiEsterni);

        $contattiEsterni->update($request->datiContatto());

        return redirect()
            ->route('contatti-esterni.index')
            ->with('success', 'Contatto aggiornato.');
    }

    public function destroy(ContattoEsterno $contattiEsterni): RedirectResponse
    {
        $this->authorize('delete', $contattiEsterni);

        $contattiEsterni->delete();

        return redirect()
            ->route('contatti-esterni.index')
            ->with('success', 'Contatto eliminato.');
    }
}
