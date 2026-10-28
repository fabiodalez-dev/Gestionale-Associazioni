<?php

namespace App\Http\Controllers\Struttura;

use App\Http\Controllers\Controller;
use App\Models\Adempimento;
use App\Models\ManualeEsecuzione;
use App\Models\ManualeEsecuzionePasso;
use App\Models\ManualePagina;
use App\Models\Utente;
use App\Services\Struttura\ManualeService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Activitylog\Models\Activity;

/**
 * Manuale operativo interno (issue #12): pagine per sezione con ricerca,
 * modifica con storico delle revisioni, modelli iniziali, checklist avviate
 * dalle pagine. Governance: ManualePaginaPolicy (manuale.*).
 */
class ManualeController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ManualeService $manuale,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', ManualePagina::class);

        $search = trim((string) $request->query('search', ''));

        $pagine = ManualePagina::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('titolo', 'like', "%{$search}%")
                ->orWhere('contenuto', 'like', "%{$search}%")
                ->orWhere('sezione', 'like', "%{$search}%")))
            ->orderBy('sezione')
            ->orderBy('ordine')
            ->orderBy('titolo')
            ->get(['id', 'sezione', 'titolo', 'visibilita', 'passi', 'revisionata_at']);

        $inCorso = ManualeEsecuzione::query()
            ->whereNull('completata_at')
            ->with('pagina:id,titolo')
            ->withCount(['passi', 'passi as passi_fatti_count' => fn ($q) => $q->whereNotNull('completato_at')])
            ->latest()
            ->limit(10)
            ->get();

        return view('struttura.manuale.index', [
            'perSezione' => $pagine->groupBy('sezione'),
            'search' => $search,
            'inCorso' => $inCorso,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', ManualePagina::class);

        return view('struttura.manuale.form', ['pagina' => new ManualePagina, 'sezioni' => $this->sezioni(), 'utenti' => $this->utenti()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', ManualePagina::class);

        $pagina = ManualePagina::create($this->dati($request) + ['revisionata_at' => now()]);

        return redirect()->route('manuale.show', $pagina)->with('success', 'Pagina creata.');
    }

    public function show(ManualePagina $pagina): View
    {
        $this->authorize('view', $pagina);

        $pagina->load('responsabile');

        $esecuzioni = $pagina->esecuzioni()
            ->withCount(['passi', 'passi as passi_fatti_count' => fn ($q) => $q->whereNotNull('completato_at')])
            ->latest()
            ->limit(10)
            ->get();

        $revisioni = Activity::query()
            ->where('subject_type', $pagina->getMorphClass())
            ->where('subject_id', $pagina->getKey())
            ->with('causer')
            ->latest()
            ->limit(20)
            ->get();

        return view('struttura.manuale.show', [
            'pagina' => $pagina,
            'esecuzioni' => $esecuzioni,
            'revisioni' => $revisioni,
            'adempimenti' => Adempimento::query()->where('manuale_pagina_id', $pagina->id)->get(['id', 'titolo', 'mese_scadenza']),
        ]);
    }

    public function edit(ManualePagina $pagina): View
    {
        $this->authorize('update', $pagina);

        return view('struttura.manuale.form', ['pagina' => $pagina, 'sezioni' => $this->sezioni(), 'utenti' => $this->utenti()]);
    }

    public function update(Request $request, ManualePagina $pagina): RedirectResponse
    {
        $this->authorize('update', $pagina);

        $pagina->update($this->dati($request) + ['revisionata_at' => now()]);

        return redirect()->route('manuale.show', $pagina)->with('success', 'Pagina aggiornata.');
    }

    public function destroy(ManualePagina $pagina): RedirectResponse
    {
        $this->authorize('delete', $pagina);

        $pagina->delete();

        return redirect()->route('manuale.index')->with('success', 'Pagina eliminata.');
    }

    public function modelli(): RedirectResponse
    {
        $this->authorize('create', ManualePagina::class);

        $create = $this->manuale->importaModelli();

        return redirect()->route('manuale.index')->with('success', $create > 0
            ? "Aggiunte {$create} procedure di esempio: adattale alla tua associazione."
            : 'Le procedure di esempio sono già presenti.');
    }

    public function avvia(Request $request, ManualePagina $pagina): RedirectResponse
    {
        $this->authorize('view', $pagina);

        $dati = $request->validate(['titolo' => ['nullable', 'string', 'max:200']]);

        try {
            $esecuzione = $this->manuale->avvia($pagina, filled($dati['titolo'] ?? null) ? $dati['titolo'] : $pagina->titolo.' — '.now()->format('d/m/Y'), $request->user());
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('manuale.show', $pagina)->with('error', $e->getMessage());
        }

        return redirect()->route('manuale.esecuzioni.show', $esecuzione)->with('success', 'Checklist avviata.');
    }

    public function esecuzione(ManualeEsecuzione $esecuzione): View
    {
        $this->authorize('view', $esecuzione->pagina);

        $esecuzione->load(['pagina', 'avviataDa', 'passi.completatoDa']);

        return view('struttura.manuale.esecuzione', ['esecuzione' => $esecuzione]);
    }

    public function passo(Request $request, ManualeEsecuzione $esecuzione, ManualeEsecuzionePasso $passo): RedirectResponse
    {
        $this->authorize('view', $esecuzione->pagina);
        abort_unless($passo->esecuzione_id === $esecuzione->id, 404);

        $dati = $request->validate([
            'completato' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->manuale->aggiornaPasso($passo, (bool) $dati['completato'], $dati['note'] ?? null, $request->user());

        return redirect()->route('manuale.esecuzioni.show', $esecuzione);
    }

    /**
     * @return array<string, mixed>
     */
    private function dati(Request $request): array
    {
        $dati = $request->validate([
            'sezione' => ['required', 'string', 'max:100'],
            'titolo' => ['required', 'string', 'max:200'],
            'contenuto' => ['nullable', 'string', 'max:100000'],
            'passi' => ['nullable', 'string', 'max:20000'],
            'visibilita' => ['required', Rule::in(array_keys(ManualePagina::VISIBILITA))],
            'responsabile_utente_id' => ['nullable', 'uuid', Rule::exists('utenti', 'id')->where('associazione_id', session('associazione_id') ?? $request->user()->associazione_id)],
            'ordine' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        // Un passo per riga
        $passi = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($dati['passi'] ?? '')) ?: []), fn ($p) => $p !== ''));

        return [
            'sezione' => trim($dati['sezione']),
            'titolo' => trim($dati['titolo']),
            'contenuto' => $dati['contenuto'] ?? null,
            'passi' => $passi === [] ? null : $passi,
            'visibilita' => $dati['visibilita'],
            'responsabile_utente_id' => $dati['responsabile_utente_id'] ?? null,
            'ordine' => (int) ($dati['ordine'] ?? 0),
        ];
    }

    /**
     * @return list<string>
     */
    private function sezioni(): array
    {
        return ManualePagina::query()->distinct()->orderBy('sezione')->pluck('sezione')->all();
    }

    /**
     * @return Collection<int, Utente>
     */
    private function utenti(): Collection
    {
        return Utente::query()
            ->where('associazione_id', session('associazione_id') ?? auth()->user()?->associazione_id)
            ->where('attivo', true)
            ->orderBy('cognome')
            ->get(['id', 'nome', 'cognome', 'email']);
    }
}
