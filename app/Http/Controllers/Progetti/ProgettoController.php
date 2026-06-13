<?php

namespace App\Http\Controllers\Progetti;

use App\Http\Controllers\Controller;
use App\Http\Requests\Progetti\ProgettoRequest;
use App\Models\Progetto;
use App\Models\Utente;
use App\Services\Progetti\ProgettoReportService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Gestione progetti dell'associazione (f.12 del piano "Organizzazione 3"):
 * CRUD + pagina dettaglio che aggrega rendiconto economico (f.13), eventi e
 * turni collegati (f.14) e milestone (f.16). Famiglia permessi struttura.*
 * (ProgettoPolicy). Tenant garantito dal global scope BelongsToAssociazione.
 */
class ProgettoController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly ProgettoReportService $report) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Progetto::class);

        $query = Progetto::query()->withCount([
            'milestones',
            'milestones as milestones_completate_count' => fn ($q) => $q->whereNotNull('completata_at'),
            'eventi',
            'turni',
        ]);

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where('nome', 'like', '%'.$search.'%');
        }

        $stato = (string) $request->query('stato', '');
        if (in_array($stato, Progetto::STATI, true)) {
            $query->where('stato', $stato);
        }

        $progetti = $query->orderByRaw("CASE stato WHEN 'attivo' THEN 0 WHEN 'bozza' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('progetti.index', [
            'progetti' => $progetti,
            'filtri' => ['search' => $search, 'stato' => $stato],
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Progetto::class);

        return view('progetti.create', [
            'responsabili' => $this->responsabili(),
        ]);
    }

    public function store(ProgettoRequest $request): RedirectResponse
    {
        $this->authorize('create', Progetto::class);

        $progetto = Progetto::create($request->validated());

        return redirect()->route('progetti.show', $progetto)
            ->with('success', 'Progetto creato.');
    }

    public function show(Progetto $progetto): View
    {
        $this->authorize('view', $progetto);

        $progetto->load([
            'responsabile',
            'milestones' => fn ($q) => $q->orderBy('ordine')->orderBy('scadenza'),
            'eventi' => fn ($q) => $q->orderByDesc('data_evento'),
            'turni' => fn ($q) => $q->orderByDesc('data'),
        ]);

        return view('progetti.show', [
            'progetto' => $progetto,
            'rendiconto' => $this->report->rendiconto($progetto),
            'oreVolontariato' => $this->report->oreVolontariato($progetto),
        ]);
    }

    public function edit(Progetto $progetto): View
    {
        $this->authorize('update', $progetto);

        return view('progetti.edit', [
            'progetto' => $progetto,
            'responsabili' => $this->responsabili(),
        ]);
    }

    public function update(ProgettoRequest $request, Progetto $progetto): RedirectResponse
    {
        $this->authorize('update', $progetto);

        $progetto->update($request->validated());

        return redirect()->route('progetti.show', $progetto)
            ->with('success', 'Progetto aggiornato.');
    }

    public function destroy(Progetto $progetto): RedirectResponse
    {
        $this->authorize('delete', $progetto);

        $progetto->delete();

        return redirect()->route('progetti.index')
            ->with('success', 'Progetto eliminato.');
    }

    /**
     * Utenti del tenant corrente, candidati responsabili del progetto (f.12).
     *
     * @return Collection<int, Utente>
     */
    private function responsabili(): Collection
    {
        $tenantId = session('associazione_id') ?? auth()->user()?->associazione_id;

        return Utente::query()
            ->where('associazione_id', $tenantId)
            ->orderBy('nome')
            ->orderBy('cognome')
            ->get();
    }
}
