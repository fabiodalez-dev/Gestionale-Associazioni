<?php

namespace App\Http\Controllers\DocumentiVerbaliScadenze;

use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentiVerbaliScadenze\StoreDocumentoRequest;
use App\Models\Documento;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documenti dell'associazione (spec §1).
 *
 * Correzioni rispetto al legacy (spec §6):
 * - storage privato (medialibrary, disk 'local'), niente file accessibili per URL;
 * - download via rotta autenticata con Policy oppure link firmato temporaneo;
 * - caricato_da finalmente valorizzato con l'utente corrente;
 * - rollback compensativo in transazione;
 * - filtri ricerca+categoria unificati in un'unica query string;
 * - soft delete + cestino (miglioramento 11) e paginazione server-side.
 */
class DocumentoController extends Controller
{
    use AuthorizesRequests;

    /** Minuti di validità del link di download firmato temporaneo. */
    public const MINUTI_LINK_TEMPORANEO = 60;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Documento::class);

        $search = trim((string) $request->query('search', ''));
        $category = (string) $request->query('category', 'all');

        $query = Documento::query()
            ->with('caricatoDa')
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($q) use ($search) {
                    $q->where('nome_file', 'like', "%{$search}%")
                        ->orWhere('descrizione', 'like', "%{$search}%");
                });
            })
            ->when($category !== '' && $category !== 'all', fn ($q) => $q->where('categoria', $category))
            ->orderByDesc('created_at');

        $documenti = $query->paginate(25)->withQueryString();

        // Folksonomy: solo le categorie effettivamente usate dal tenant
        $categorie = Documento::query()
            ->whereNotNull('categoria')
            ->where('categoria', '!=', '')
            ->distinct()
            ->orderBy('categoria')
            ->pluck('categoria');

        return view('documenti-verbali-scadenze.documenti.index', [
            'documenti' => $documenti,
            'categorie' => $categorie,
            'search' => $search,
            'category' => $category,
        ]);
    }

    public function store(StoreDocumentoRequest $request): RedirectResponse
    {
        $file = $request->file('document_file');

        try {
            DB::transaction(function () use ($request, $file) {
                $documento = Documento::create([
                    'nome_file' => $file->getClientOriginalName(),
                    'percorso_file' => '', // valorizzato dopo l'aggiunta del media
                    'descrizione' => $request->validated('descrizione'),
                    'categoria' => $request->validated('categoria'),
                    'dimensione_kb' => (int) ceil($file->getSize() / 1024),
                    'caricato_da' => $request->user()->id,
                ]);

                // Nome fisico random (mai il nome utente): previene path traversal e collisioni
                $media = $documento
                    ->addMedia($file)
                    ->usingFileName(Str::uuid().'.'.strtolower($file->getClientOriginalExtension()))
                    ->toMediaCollection(Documento::COLLEZIONE_FILE);

                $documento->update(['percorso_file' => $media->getPathRelativeToRoot()]);
            });
        } catch (\Throwable $e) {
            // Rollback compensativo: la transazione annulla il record; medialibrary
            // rimuove i file del media cancellato. Mai esporre l'errore all'utente.
            report($e);

            return back()->with('error', 'Errore durante il salvataggio. Riprova più tardi.');
        }

        return redirect()->route('documenti.index')->with('success', 'Documento caricato con successo!');
    }

    public function download(Request $request, Documento $documento): BinaryFileResponse|StreamedResponse
    {
        $this->authorize('view', $documento);

        return $this->rispostaDownload($documento);
    }

    /**
     * Genera un link di download firmato temporaneo condivisibile
     * (miglioramento 16: URL temporanei al posto dei file pubblici legacy).
     */
    public function linkTemporaneo(Request $request, Documento $documento): RedirectResponse
    {
        $this->authorize('view', $documento);

        $url = URL::temporarySignedRoute(
            'documenti.download-pubblico',
            now()->addMinutes(self::MINUTI_LINK_TEMPORANEO),
            ['id' => $documento->id]
        );

        return back()->with('link_temporaneo', $url)
            ->with('link_temporaneo_nome', $documento->nome_file);
    }

    /**
     * Download pubblico via URL firmato temporaneo (rotta senza auth, middleware 'signed').
     */
    public function downloadPubblico(string $id): BinaryFileResponse|StreamedResponse
    {
        /** @var Documento $documento */
        $documento = Documento::allTenants()->findOrFail($id);

        return $this->rispostaDownload($documento);
    }

    /**
     * Risposta di download: media library se presente, altrimenti fallback
     * sul percorso_file del disk privato (record migrati dal legacy).
     */
    private function rispostaDownload(Documento $documento): BinaryFileResponse|StreamedResponse
    {
        $media = $documento->getFirstMedia(Documento::COLLEZIONE_FILE);

        if ($media !== null) {
            return response()->download($media->getPath(), $documento->nome_file);
        }

        if ($documento->percorso_file !== '' && Storage::disk('local')->exists($documento->percorso_file)) {
            return Storage::disk('local')->download($documento->percorso_file, $documento->nome_file);
        }

        abort(404, 'File non trovato.');
    }

    /**
     * Sposta il documento nel cestino (soft delete, miglioramento 11):
     * il file fisico resta finché non viene eliminato definitivamente.
     */
    public function destroy(Documento $documento): RedirectResponse
    {
        $this->authorize('delete', $documento);

        $documento->delete();

        return redirect()->route('documenti.index')->with('success', 'Documento spostato nel cestino.');
    }

    public function cestino(): View
    {
        $this->authorize('viewAny', Documento::class);

        $documenti = Documento::onlyTrashed()
            ->orderByDesc('deleted_at')
            ->paginate(25);

        return view('documenti-verbali-scadenze.documenti.cestino', [
            'documenti' => $documenti,
        ]);
    }

    public function ripristina(string $id): RedirectResponse
    {
        /** @var Documento $documento */
        $documento = Documento::onlyTrashed()->findOrFail($id);

        $this->authorize('restore', $documento);

        $documento->restore();

        return redirect()->route('documenti.index')->with('success', 'Documento ripristinato con successo!');
    }

    public function eliminaDefinitivamente(string $id): RedirectResponse
    {
        /** @var Documento $documento */
        $documento = Documento::onlyTrashed()->findOrFail($id);

        $this->authorize('forceDelete', $documento);

        // Prima il DB, poi i file (medialibrary li rimuove al forceDelete);
        // un file già assente su disco non è un errore (comportamento legacy F2).
        $documento->forceDelete();

        return redirect()->route('documenti.cestino')->with('success', 'Documento eliminato definitivamente.');
    }
}
