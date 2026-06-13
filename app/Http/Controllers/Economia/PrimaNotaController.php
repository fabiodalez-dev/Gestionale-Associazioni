<?php

namespace App\Http\Controllers\Economia;

use App\Http\Controllers\Controller;
use App\Http\Requests\Economia\SalvaMovimentoRequest;
use App\Models\CategoriaMovimento;
use App\Models\Movimento;
use App\Models\Progetto;
use App\Services\Economia\PrimaNotaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Prima nota (f.13 del piano "Gestione 2"): CRUD dei movimenti manuali
 * con saldo progressivo e categorie seedate per tenant al primo accesso.
 * I movimenti generati automaticamente dai pagamenti (origine presente)
 * sono in sola lettura: non si modificano né si eliminano per mantenere
 * la coerenza con gli incassi reali.
 */
class PrimaNotaController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected PrimaNotaService $primaNota) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Movimento::class);

        // Categorie seedate per tenant al primo accesso (f.13)
        $tenantId = (string) (session('associazione_id') ?? $request->user()?->associazione_id);

        if ($tenantId !== '') {
            $this->primaNota->seedCategorie($tenantId);
        }

        $query = $this->applicaFiltri(Movimento::query(), $request)
            ->with(['categoria', 'origine', 'progetto'])
            ->orderByDesc('data')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $movimenti = $query->paginate(25)->withQueryString();

        // Saldo progressivo (sul set filtrato): il saldo della prima riga
        // della pagina è il totale meno le righe più recenti (pagine precedenti)
        $saldoTotale = $this->sommaConSegno($this->applicaFiltri(Movimento::query(), $request));

        $offset = ($movimenti->currentPage() - 1) * $movimenti->perPage();

        $sommaPrecedenti = $offset > 0
            ? $this->applicaFiltri(Movimento::query(), $request)
                ->orderByDesc('data')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit($offset)
                ->get(['tipo', 'importo'])
                ->sum(fn (Movimento $m) => $m->importoConSegno())
            : 0.0;

        $saldoCorrente = $saldoTotale - $sommaPrecedenti;
        $saldi = [];

        foreach ($movimenti as $movimento) {
            $saldi[$movimento->id] = $saldoCorrente;
            $saldoCorrente -= $movimento->importoConSegno();
        }

        return view('economia.prima-nota.index', [
            'movimenti' => $movimenti,
            'saldi' => $saldi,
            'saldoTotale' => $saldoTotale,
            'totaleEntrate' => (float) $this->applicaFiltri(Movimento::query(), $request)->entrate()->sum('importo'),
            'totaleUscite' => (float) $this->applicaFiltri(Movimento::query(), $request)->uscite()->sum('importo'),
            'categorie' => CategoriaMovimento::query()->orderBy('tipo')->orderBy('nome')->get(),
            // f.13 piano "Organizzazione 3": progetti del tenant per select e filtro
            'progetti' => Progetto::query()->orderBy('nome')->get(['id', 'nome']),
            'filtri' => [
                'dal' => $request->query('dal'),
                'al' => $request->query('al'),
                'tipo' => $request->query('tipo'),
                'categoria_id' => $request->query('categoria_id'),
                'progetto_id' => $request->query('progetto_id'),
            ],
        ]);
    }

    public function store(SalvaMovimentoRequest $request): RedirectResponse
    {
        $this->authorize('create', Movimento::class);

        Movimento::create([
            'tipo' => $request->validated('tipo'),
            'importo' => $request->validated('importo'),
            'data' => $request->validated('data'),
            'categoria_id' => $request->validated('categoria_id'),
            'descrizione' => $request->validated('descrizione'),
            'metodo' => $request->validated('metodo'),
            'progetto_id' => $request->validated('progetto_id'),
            'registrato_da' => $request->user()?->id,
        ]);

        return redirect()->route('prima-nota.index')
            ->with('success', 'Movimento registrato.');
    }

    public function update(SalvaMovimentoRequest $request, Movimento $movimento): RedirectResponse
    {
        $this->authorize('update', $movimento);

        if ($movimento->origine_id !== null) {
            return redirect()->route('prima-nota.index')
                ->with('error', 'I movimenti generati automaticamente dai pagamenti non sono modificabili.');
        }

        $movimento->update($request->validated());

        return redirect()->route('prima-nota.index')
            ->with('success', 'Movimento aggiornato.');
    }

    public function destroy(Movimento $movimento): RedirectResponse
    {
        $this->authorize('delete', $movimento);

        if ($movimento->origine_id !== null) {
            return redirect()->route('prima-nota.index')
                ->with('error', 'I movimenti generati automaticamente dai pagamenti non sono eliminabili.');
        }

        $movimento->delete();

        return redirect()->route('prima-nota.index')
            ->with('success', 'Movimento eliminato.');
    }

    /**
     * Filtri comuni: periodo, tipo, categoria.
     *
     * @param  Builder<Movimento>  $query
     * @return Builder<Movimento>
     */
    protected function applicaFiltri(Builder $query, Request $request): Builder
    {
        if (filled($request->query('dal'))) {
            $query->whereDate('data', '>=', (string) $request->query('dal'));
        }

        if (filled($request->query('al'))) {
            $query->whereDate('data', '<=', (string) $request->query('al'));
        }

        if (in_array($request->query('tipo'), Movimento::TIPI, true)) {
            $query->where('tipo', $request->query('tipo'));
        }

        if (filled($request->query('categoria_id'))) {
            $query->where('categoria_id', (string) $request->query('categoria_id'));
        }

        // f.13 piano "Organizzazione 3": filtro per progetto imputato
        if (filled($request->query('progetto_id'))) {
            $query->where('progetto_id', (string) $request->query('progetto_id'));
        }

        return $query;
    }

    /**
     * Somma con segno (entrate − uscite) del set filtrato.
     *
     * @param  Builder<Movimento>  $query
     */
    protected function sommaConSegno(Builder $query): float
    {
        $entrate = (float) (clone $query)->entrate()->sum('importo');
        $uscite = (float) (clone $query)->uscite()->sum('importo');

        return $entrate - $uscite;
    }
}
