<?php

namespace App\Http\Controllers\Membri;

use App\Http\Controllers\Controller;
use App\Http\Requests\Membri\MembroNucleoRequest;
use App\Http\Requests\Membri\NucleoRequest;
use App\Models\Nucleo;
use App\Models\Socio;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Nuclei familiari (f.20 — piano "Gestione 2"):
 * - vista raggruppata "Soci per nucleo" (lista nuclei con membri);
 * - crea nucleo / aggiungi-rimuovi membri dalla scheda socio;
 * - capofamiglia indicato su ogni nucleo.
 *
 * Autorizzazione: NucleoPolicy (soci.view / soci.update); il vincolo
 * tenant è garantito dal global scope BelongsToAssociazione.
 */
class NucleoController extends Controller
{
    use AuthorizesRequests;

    /**
     * Vista raggruppata per nucleo (link "Vista per nuclei" dalla lista soci).
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Nucleo::class);

        $search = trim((string) $request->query('search', ''));

        $nuclei = Nucleo::query()
            ->with([
                'capofamiglia' => fn ($q) => $q->withTrashed(),
                'membri' => fn ($q) => $q->orderBy('cognome')->orderBy('nome'),
            ])
            ->when($search !== '', fn ($q) => $q->where('nome', 'like', "%{$search}%"))
            ->orderBy('nome')
            ->paginate(25)
            ->appends(array_filter(['search' => $search]));

        return view('membri.nuclei.index', [
            'nuclei' => $nuclei,
            'search' => $search,
            'sociSenzaNucleo' => Socio::query()->whereNull('nucleo_id')->count(),
        ]);
    }

    /**
     * Crea il nucleo e vi assegna subito il capofamiglia (se indicato).
     */
    public function store(NucleoRequest $request): RedirectResponse
    {
        $this->authorize('create', Nucleo::class);

        $capofamiglia = $this->socioDelTenant($request->validated('capofamiglia_id'));

        $nucleo = Nucleo::create([
            'associazione_id' => $capofamiglia !== null ? $capofamiglia->associazione_id : Socio::currentAssociazioneId(),
            'nome' => $request->validated('nome'),
            'capofamiglia_id' => $capofamiglia?->id,
        ]);

        $capofamiglia?->forceFill(['nucleo_id' => $nucleo->id])->save();

        return back()->with('success', "Nucleo «{$nucleo->nome}» creato.");
    }

    /**
     * Rinomina il nucleo / cambia capofamiglia (il nuovo capofamiglia
     * entra nel nucleo se non ne fa già parte).
     */
    public function update(NucleoRequest $request, Nucleo $nucleo): RedirectResponse
    {
        $this->authorize('update', $nucleo);

        $capofamiglia = $this->socioDelTenant($request->validated('capofamiglia_id'), $nucleo->associazione_id);

        $nucleo->update([
            'nome' => $request->validated('nome'),
            'capofamiglia_id' => $capofamiglia?->id,
        ]);

        if ($capofamiglia !== null && $capofamiglia->nucleo_id !== $nucleo->id) {
            $capofamiglia->forceFill(['nucleo_id' => $nucleo->id])->save();
        }

        return back()->with('success', 'Nucleo aggiornato.');
    }

    /**
     * Scioglie il nucleo: i membri vengono sganciati (nucleo_id NULL).
     */
    public function destroy(Nucleo $nucleo): RedirectResponse
    {
        $this->authorize('delete', $nucleo);

        $nome = $nucleo->nome;

        Socio::withoutGlobalScopes()
            ->where('nucleo_id', $nucleo->id)
            ->update(['nucleo_id' => null]);

        $nucleo->delete();

        return back()->with('success', "Nucleo «{$nome}» sciolto: i soci non sono stati modificati.");
    }

    /**
     * Aggiunge un socio al nucleo (lo sposta se apparteneva a un altro).
     */
    public function aggiungiMembro(MembroNucleoRequest $request, Nucleo $nucleo): RedirectResponse
    {
        $this->authorize('update', $nucleo);

        $socio = $this->socioDelTenant($request->validated('socio_id'), $nucleo->associazione_id);

        if ($socio === null) {
            return back()->with('error', 'Socio non trovato.');
        }

        $socio->forceFill(['nucleo_id' => $nucleo->id])->save();

        return back()->with('success', "{$socio->nome_completo} aggiunto al nucleo «{$nucleo->nome}».");
    }

    /**
     * Rimuove un socio dal nucleo (se era capofamiglia, il nucleo resta
     * senza capofamiglia).
     */
    public function rimuoviMembro(Nucleo $nucleo, Socio $socio): RedirectResponse
    {
        $this->authorize('update', $nucleo);

        if ($socio->nucleo_id !== $nucleo->id) {
            return back()->with('error', 'Il socio non fa parte di questo nucleo.');
        }

        $socio->forceFill(['nucleo_id' => null])->save();

        if ($nucleo->capofamiglia_id === $socio->id) {
            $nucleo->update(['capofamiglia_id' => null]);
        }

        return back()->with('success', "{$socio->nome_completo} rimosso dal nucleo «{$nucleo->nome}».");
    }

    /**
     * Socio del tenant corrente (o del tenant indicato), NULL se assente.
     */
    private function socioDelTenant(?string $socioId, ?string $associazioneId = null): ?Socio
    {
        if (blank($socioId)) {
            return null;
        }

        return Socio::query()
            ->when($associazioneId !== null, fn ($q) => $q->withoutGlobalScopes()->where('associazione_id', $associazioneId))
            ->find($socioId);
    }
}
