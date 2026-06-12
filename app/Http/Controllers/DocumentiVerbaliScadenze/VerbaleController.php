<?php

namespace App\Http\Controllers\DocumentiVerbaliScadenze;

use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentiVerbaliScadenze\SalvaVerbaleRequest;
use App\Http\Requests\DocumentiVerbaliScadenze\StoreVerbaleFirmatarioRequest;
use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Socio;
use App\Models\Utente;
use App\Models\Verbale;
use App\Models\VerbaleFirmatario;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Verbali di riunioni/assemblee (spec §2):
 * CRUD + workflow di approvazione one-shot, firmatari strutturati (estensione §6.2),
 * export PDF del singolo verbale (estensione §6.4), cestino (miglioramento 11).
 *
 * Irrigidimenti rispetto al legacy:
 * - la modifica di un verbale approvato è riservata agli admin e azzera l'approvazione;
 * - l'approvazione è riservata agli admin;
 * - delete/update verificano l'esistenza del record (404 se assente).
 */
class VerbaleController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Verbale::class);

        $search = trim((string) $request->query('search', ''));
        $type = (string) $request->query('type', 'all');
        $annoFiltro = is_numeric($request->query('anno')) ? (int) $request->query('anno') : null;

        $verbali = Verbale::query()
            ->with(['approvatoDa', 'firmatari.socio', 'firmatari.utente', 'evento'])
            // Ricerca full-text sul contenuto del verbale (f.17): titolo,
            // partecipanti, ordine del giorno e deliberazioni
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($q) use ($search) {
                    $q->where('titolo', 'like', "%{$search}%")
                        ->orWhere('presenti', 'like', "%{$search}%")
                        ->orWhere('ordine_del_giorno', 'like', "%{$search}%")
                        ->orWhere('deliberazioni', 'like', "%{$search}%");
                });
            })
            ->when($type !== '' && $type !== 'all', fn ($q) => $q->where('tipo_riunione', $type))
            ->when($annoFiltro !== null, fn ($q) => $q->where('anno', $annoFiltro))
            ->orderByDesc('data_riunione')
            ->orderByDesc('numero')
            ->paginate(25)
            ->withQueryString();

        // Edit flow legacy: ?edit=<uuid> precompila il form nel modal auto-aperto
        $verbaleInModifica = null;
        if ($request->filled('edit')) {
            $verbaleInModifica = Verbale::query()->find($request->query('edit'));
        }

        $soci = Socio::query()->orderBy('cognome')->orderBy('nome')->get(['id', 'nome', 'cognome']);
        $utenti = Utente::query()
            ->where('associazione_id', session('associazione_id'))
            ->orderBy('nome')
            ->get(['id', 'nome', 'cognome']);

        // Eventi del tenant per il collegamento opzionale verbale↔evento (f.17)
        $eventi = Evento::query()
            ->orderByDesc('data_evento')
            ->limit(200)
            ->get(['id', 'titolo', 'data_evento']);

        return view('documenti-verbali-scadenze.verbali.index', [
            'verbali' => $verbali,
            'verbaleInModifica' => $verbaleInModifica,
            'search' => $search,
            'type' => $type,
            'annoFiltro' => $annoFiltro,
            'anni' => $this->anniDisponibili(),
            'tipiRiunione' => Verbale::TIPI_RIUNIONE,
            'soci' => $soci,
            'utenti' => $utenti,
            'eventi' => $eventi,
        ]);
    }

    public function store(SalvaVerbaleRequest $request): RedirectResponse
    {
        // Numerazione automatica per anno, es. 3/2026 (f.17): il numero è
        // assegnato in transazione; l'unique a DB su associazione+anno+numero
        // protegge dalle scritture concorrenti.
        $verbale = DB::transaction(function () use ($request): Verbale {
            $verbale = new Verbale($request->validated());

            $anno = (int) $verbale->data_riunione->year;
            $verbale->anno = $anno;
            $verbale->numero = Verbale::prossimoNumero($anno);
            $verbale->save();

            return $verbale;
        });

        return redirect()
            ->route('verbali.index')
            ->with('success', "Verbale n. {$verbale->numero_completo} aggiunto con successo!");
    }

    public function update(SalvaVerbaleRequest $request, Verbale $verbale): RedirectResponse
    {
        $eraApprovato = (bool) $verbale->approvato;

        $verbale->fill($request->validated());

        // Se la data cambia anno, il verbale viene rinumerato nel nuovo anno (f.17)
        $nuovoAnno = (int) $verbale->data_riunione->year;
        if ($verbale->anno !== $nuovoAnno) {
            $verbale->anno = $nuovoAnno;
            $verbale->numero = Verbale::prossimoNumero($nuovoAnno);
        }

        // Irrigidimento spec §6: la modifica azzera l'approvazione
        if ($eraApprovato) {
            $verbale->azzeraApprovazione();
        }

        $verbale->save();

        $messaggio = $eraApprovato
            ? 'Verbale aggiornato con successo! L\'approvazione è stata azzerata: il verbale va riapprovato.'
            : 'Verbale aggiornato con successo!';

        return redirect()->route('verbali.index')->with('success', $messaggio);
    }

    /**
     * Approvazione one-shot (spec §2.3 F2): solo admin, solo se non già approvato.
     */
    public function approva(Request $request, Verbale $verbale): RedirectResponse
    {
        $this->authorize('approve', $verbale);

        if ($verbale->approvato) {
            return redirect()->route('verbali.index')->with('warning', 'Il verbale è già approvato.');
        }

        $verbale->update([
            'approvato' => true,
            'approvato_da' => $request->user()->id,
            'data_approvazione' => now()->toDateString(),
        ]);

        return redirect()->route('verbali.index')->with('success', 'Verbale approvato con successo!');
    }

    /**
     * Anni con verbali numerati (per il filtro della lista, f.17).
     *
     * @return list<int>
     */
    private function anniDisponibili(): array
    {
        return Verbale::query()
            ->whereNotNull('anno')
            ->distinct()
            ->orderByDesc('anno')
            ->pluck('anno')
            ->map(fn ($anno): int => (int) $anno)
            ->all();
    }

    public function destroy(Verbale $verbale): RedirectResponse
    {
        $this->authorize('delete', $verbale);

        $verbale->delete();

        return redirect()->route('verbali.index')->with('success', 'Verbale spostato nel cestino.');
    }

    public function cestino(): View
    {
        $this->authorize('viewAny', Verbale::class);

        $verbali = Verbale::onlyTrashed()
            ->orderByDesc('deleted_at')
            ->paginate(25);

        return view('documenti-verbali-scadenze.verbali.cestino', [
            'verbali' => $verbali,
        ]);
    }

    public function ripristina(string $id): RedirectResponse
    {
        /** @var Verbale $verbale */
        $verbale = Verbale::onlyTrashed()->findOrFail($id);

        $this->authorize('restore', $verbale);

        $verbale->restore();

        return redirect()->route('verbali.index')->with('success', 'Verbale ripristinato con successo!');
    }

    public function eliminaDefinitivamente(string $id): RedirectResponse
    {
        /** @var Verbale $verbale */
        $verbale = Verbale::onlyTrashed()->findOrFail($id);

        $this->authorize('forceDelete', $verbale);

        $verbale->forceDelete();

        return redirect()->route('verbali.cestino')->with('success', 'Verbale eliminato definitivamente.');
    }

    /**
     * Export PDF del singolo verbale (estensione §6.4): template dedicato
     * con intestazione associazione e riquadro firme.
     */
    public function pdf(Verbale $verbale): Response
    {
        $this->authorize('view', $verbale);

        $verbale->load(['approvatoDa', 'firmatari.socio', 'firmatari.utente', 'evento']);

        $associazione = Associazione::query()->find($verbale->associazione_id);

        $pdf = Pdf::loadView('documenti-verbali-scadenze.verbali.pdf', [
            'verbale' => $verbale,
            'associazione' => $associazione,
        ]);

        $nomeFile = 'verbale-'.str($verbale->titolo)->slug()->limit(60, '').'-'.$verbale->data_riunione->format('Y-m-d').'.pdf';

        return $pdf->download($nomeFile);
    }

    // ----- Firmatari strutturati (estensione §6.2) -----

    public function storeFirmatario(StoreVerbaleFirmatarioRequest $request, Verbale $verbale): RedirectResponse
    {
        $verbale->firmatari()->create([
            'associazione_id' => $verbale->associazione_id,
            'socio_id' => $request->validated('socio_id'),
            'utente_id' => $request->validated('utente_id'),
            'nome' => $request->validated('nome'),
            'ruolo' => $request->validated('ruolo'),
        ]);

        return redirect()->route('verbali.index')->with('success', 'Firmatario aggiunto con successo!');
    }

    /**
     * Registra la firma del firmatario (firmato_at = adesso).
     */
    public function firmaFirmatario(Request $request, Verbale $verbale, VerbaleFirmatario $firmatario): RedirectResponse
    {
        $this->authorize('update', $verbale);
        abort_if($firmatario->verbale_id !== $verbale->id, 404);

        if ($firmatario->firmato_at === null) {
            $firmatario->update(['firmato_at' => now()]);
        }

        return redirect()->route('verbali.index')->with('success', 'Firma registrata con successo!');
    }

    public function destroyFirmatario(Verbale $verbale, VerbaleFirmatario $firmatario): RedirectResponse
    {
        $this->authorize('update', $verbale);
        abort_if($firmatario->verbale_id !== $verbale->id, 404);

        $firmatario->delete();

        return redirect()->route('verbali.index')->with('success', 'Firmatario rimosso con successo!');
    }
}
