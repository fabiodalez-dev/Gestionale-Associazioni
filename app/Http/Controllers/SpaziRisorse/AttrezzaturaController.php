<?php

namespace App\Http\Controllers\SpaziRisorse;

use App\Http\Controllers\Controller;
use App\Http\Requests\SpaziRisorse\AttrezzaturaRequest;
use App\Models\Attrezzatura;
use App\Models\Spazio;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Inventario attrezzature (f.9 piano "Organizzazione 3"): CRUD con ricerca
 * (nome/descrizione/ubicazione) e filtro per stato. La disponibilità per i
 * prestiti (f.10) è calcolata sul modello (quantita - prestiti attivi).
 *
 * Isolamento tenant garantito dal global scope BelongsToAssociazione.
 */
class AttrezzaturaController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Attrezzatura::class);

        $search = trim((string) $request->query('search', ''));
        $stato = (string) $request->query('stato', '');
        $stato = in_array($stato, Attrezzatura::STATI, true) ? $stato : '';

        $attrezzature = Attrezzatura::query()
            ->with('spazio')
            ->withSum(['prestiti as quantita_in_prestito' => fn ($q) => $q->whereNull('restituito_at')], 'quantita')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('nome', 'like', "%{$search}%")
                        ->orWhere('descrizione', 'like', "%{$search}%")
                        ->orWhere('ubicazione', 'like', "%{$search}%");
                });
            })
            ->when($stato !== '', fn ($query) => $query->where('stato', $stato))
            ->orderBy('nome')
            ->paginate(25)
            ->withQueryString();

        $editAttrezzatura = null;
        if ($request->filled('edit')) {
            $editAttrezzatura = Attrezzatura::query()->find($request->query('edit'));
        }

        return view('spazi-risorse.attrezzature.index', [
            'attrezzature' => $attrezzature,
            'editAttrezzatura' => $editAttrezzatura,
            'spazi' => Spazio::query()->orderBy('nome')->get(),
            'search' => $search,
            'statoAttivo' => $stato,
            'stati' => Attrezzatura::STATI,
        ]);
    }

    public function store(AttrezzaturaRequest $request): RedirectResponse
    {
        $this->authorize('create', Attrezzatura::class);

        Attrezzatura::create($request->validated());

        return redirect()
            ->route('attrezzature.index')
            ->with('success', 'Attrezzatura aggiunta all\'inventario.');
    }

    public function update(AttrezzaturaRequest $request, Attrezzatura $attrezzatura): RedirectResponse
    {
        $this->authorize('update', $attrezzatura);

        $validated = $request->validated();

        // La quantità non può scendere sotto il totale già in prestito
        $inPrestito = $attrezzatura->quantitaInPrestito();
        if ((int) $validated['quantita'] < $inPrestito) {
            return redirect()
                ->back()
                ->withInput()
                ->withErrors([
                    'quantita' => "Non puoi ridurre la quantità a {$validated['quantita']}: "
                        ."ci sono {$inPrestito} pezzi attualmente in prestito.",
                ]);
        }

        $attrezzatura->update($validated);

        return redirect()
            ->route('attrezzature.index')
            ->with('success', 'Attrezzatura aggiornata con successo.');
    }

    public function destroy(Attrezzatura $attrezzatura): RedirectResponse
    {
        $this->authorize('delete', $attrezzatura);

        // Blocco: non eliminare attrezzature con prestiti ancora attivi
        if ($attrezzatura->quantitaInPrestito() > 0) {
            return redirect()
                ->route('attrezzature.index')
                ->with('error', 'Impossibile eliminare: ci sono prestiti ancora attivi per questa attrezzatura.');
        }

        $attrezzatura->delete();

        return redirect()
            ->route('attrezzature.index')
            ->with('success', 'Attrezzatura eliminata con successo.');
    }
}
