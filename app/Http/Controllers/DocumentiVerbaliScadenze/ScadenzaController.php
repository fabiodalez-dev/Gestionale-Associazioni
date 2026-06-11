<?php

namespace App\Http\Controllers\DocumentiVerbaliScadenze;

use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentiVerbaliScadenze\SalvaScadenzaRequest;
use App\Models\Scadenza;
use App\Models\Utente;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Scadenze/promemoria dell'associazione (spec §3):
 * CRUD + completamento rapido, 4 filtri + ricerca unificati in un'unica
 * query string (fix del difetto legacy), ordinamento composito
 * stato → priorità → data, statistiche sul result-set filtrato,
 * export CSV che rispetta i filtri attivi (estensione §6.4).
 *
 * Miglioramenti: completata_da/completata_at tracciati, promemoria email reali
 * via comando schedulato (scadenze:invia-promemoria).
 */
class ScadenzaController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Scadenza::class);

        $filtri = $this->filtri($request);
        $query = $this->queryFiltrata($filtri);

        // Statistiche sul result-set filtrato (spec §3.3 F5):
        // la card "In Scadenza" usa la soglia fissa di 7 giorni del legacy
        $stats = [
            'critiche' => (clone $query)->where('priorita', 'Critica')->where('stato', Scadenza::STATO_ATTIVA)->count(),
            'in_scadenza' => (clone $query)->where('stato', Scadenza::STATO_ATTIVA)
                ->whereDate('data_scadenza', '<=', Carbon::today()->addDays(7))
                ->count(),
            'attive' => (clone $query)->where('stato', Scadenza::STATO_ATTIVA)->count(),
            'completate' => (clone $query)->where('stato', Scadenza::STATO_COMPLETATA)->count(),
        ];

        // Ordinamento composito legacy (spec §3.3 F4): stato → priorità → data
        $scadenze = $query
            ->with(['assegnatoA', 'completataDa'])
            ->orderByRaw("CASE stato WHEN 'Attiva' THEN 1 WHEN 'Annullata' THEN 2 ELSE 3 END")
            ->orderByRaw("CASE priorita WHEN 'Critica' THEN 1 WHEN 'Alta' THEN 2 WHEN 'Media' THEN 3 ELSE 4 END")
            ->orderBy('data_scadenza')
            ->paginate(25)
            ->withQueryString();

        // Edit flow legacy: ?edit=<uuid> precompila il form nel modal auto-aperto
        $scadenzaInModifica = null;
        if ($request->filled('edit')) {
            $scadenzaInModifica = Scadenza::query()->find($request->query('edit'));
        }

        // Dropdown assegnazione: solo utenti admin del tenant (spec §3.2)
        $utenti = Utente::query()
            ->where('associazione_id', session('associazione_id'))
            ->orderBy('nome')
            ->get(['id', 'nome', 'cognome', 'email']);

        return view('documenti-verbali-scadenze.scadenze.index', array_merge($filtri, [
            'scadenze' => $scadenze,
            'scadenzaInModifica' => $scadenzaInModifica,
            'stats' => $stats,
            'utenti' => $utenti,
            'tipi' => Scadenza::TIPI,
            'priorita' => Scadenza::PRIORITA,
            'stati' => Scadenza::STATI,
        ]));
    }

    public function store(SalvaScadenzaRequest $request): RedirectResponse
    {
        Scadenza::create($request->validated());

        return redirect()->route('scadenze.index')->with('success', 'Scadenza aggiunta con successo!');
    }

    public function update(SalvaScadenzaRequest $request, Scadenza $scadenza): RedirectResponse
    {
        $dati = $request->validated();

        // Riattivazione via edit: azzera il tracciamento completamento e il promemoria inviato
        if (($dati['stato'] ?? null) === Scadenza::STATO_ATTIVA && ! $scadenza->isAttiva()) {
            $dati['completata_da'] = null;
            $dati['completata_at'] = null;
            $dati['promemoria_inviato_at'] = null;
        }

        $scadenza->update($dati);

        return redirect()->route('scadenze.index')->with('success', 'Scadenza aggiornata con successo!');
    }

    /**
     * Completamento rapido (spec §3.3 F2) + tracciamento chi/quando (estensione §6.5).
     */
    public function completa(Request $request, Scadenza $scadenza): RedirectResponse
    {
        $this->authorize('complete', $scadenza);

        if (! $scadenza->isAttiva()) {
            return redirect()->route('scadenze.index')->with('warning', 'Solo le scadenze attive possono essere completate.');
        }

        $scadenza->update([
            'stato' => Scadenza::STATO_COMPLETATA,
            'completata_da' => $request->user()->id,
            'completata_at' => now(),
        ]);

        return redirect()->route('scadenze.index')->with('success', 'Scadenza completata con successo!');
    }

    public function destroy(Scadenza $scadenza): RedirectResponse
    {
        $this->authorize('delete', $scadenza);

        $scadenza->delete();

        return redirect()->route('scadenze.index')->with('success', 'Scadenza eliminata con successo!');
    }

    /**
     * Export CSV dell'elenco corrente, rispettando i filtri attivi (estensione §6.4).
     */
    public function esportaCsv(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', Scadenza::class);

        $scadenze = $this->queryFiltrata($this->filtri($request))
            ->with(['assegnatoA'])
            ->orderByRaw("CASE stato WHEN 'Attiva' THEN 1 WHEN 'Annullata' THEN 2 ELSE 3 END")
            ->orderByRaw("CASE priorita WHEN 'Critica' THEN 1 WHEN 'Alta' THEN 2 WHEN 'Media' THEN 3 ELSE 4 END")
            ->orderBy('data_scadenza')
            ->get();

        return response()->streamDownload(function () use ($scadenze) {
            $out = fopen('php://output', 'w');
            // BOM UTF-8 per compatibilità Excel
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Titolo', 'Descrizione', 'Data Scadenza', 'Tipo', 'Priorità', 'Stato', 'Assegnato a', 'Promemoria (giorni)'], ';');

            foreach ($scadenze as $scadenza) {
                fputcsv($out, [
                    $scadenza->titolo,
                    (string) $scadenza->descrizione,
                    $scadenza->data_scadenza->format('d/m/Y'),
                    $scadenza->tipo_scadenza,
                    $scadenza->priorita,
                    $scadenza->stato,
                    $scadenza->assegnatoA->nome_completo ?? 'Non assegnato',
                    $scadenza->promemoria_giorni,
                ], ';');
            }

            fclose($out);
        }, 'scadenze-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array{search: string, status: string, type: string, priority: string}
     */
    private function filtri(Request $request): array
    {
        return [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', 'all'),
            'type' => (string) $request->query('type', 'all'),
            'priority' => (string) $request->query('priority', 'all'),
        ];
    }

    /**
     * @param  array{search: string, status: string, type: string, priority: string}  $filtri
     * @return Builder<Scadenza>
     */
    private function queryFiltrata(array $filtri): Builder
    {
        return Scadenza::query()
            ->when($filtri['search'] !== '', function ($q) use ($filtri) {
                $q->where(function ($q) use ($filtri) {
                    $q->where('titolo', 'like', "%{$filtri['search']}%")
                        ->orWhere('descrizione', 'like', "%{$filtri['search']}%");
                });
            })
            ->when($filtri['status'] !== 'all' && $filtri['status'] !== '', fn ($q) => $q->where('stato', $filtri['status']))
            ->when($filtri['type'] !== 'all' && $filtri['type'] !== '', fn ($q) => $q->where('tipo_scadenza', $filtri['type']))
            ->when($filtri['priority'] !== 'all' && $filtri['priority'] !== '', fn ($q) => $q->where('priorita', $filtri['priority']));
    }
}
