<?php

namespace App\Http\Controllers\DocumentiVerbaliScadenze;

use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentiVerbaliScadenze\StoreDocumentoSocioRequest;
use App\Models\Documento;
use App\Models\DocumentoSocio;
use App\Models\Socio;
use App\Support\Media\NomeFileSicuro;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documenti del fascicolo socio (estensione §6.1 della spec):
 * upload per singolo socio, storage privato medialibrary,
 * download autenticato + link firmato temporaneo (miglioramento 16).
 */
class DocumentoSocioController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', DocumentoSocio::class);

        $socioId = (string) $request->query('socio_id', '');
        $search = trim((string) $request->query('search', ''));

        $documenti = DocumentoSocio::query()
            ->with(['socio', 'caricatoDa'])
            ->when($socioId !== '' && $socioId !== 'all', fn ($q) => $q->where('socio_id', $socioId))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($q) use ($search) {
                    $q->where('nome_file', 'like', "%{$search}%")
                        ->orWhere('descrizione', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        $soci = Socio::query()
            ->orderBy('cognome')
            ->orderBy('nome')
            ->get(['id', 'nome', 'cognome', 'numero_socio']);

        return view('documenti-verbali-scadenze.documenti-socio.index', [
            'documenti' => $documenti,
            'soci' => $soci,
            'socioId' => $socioId,
            'search' => $search,
        ]);
    }

    public function store(StoreDocumentoSocioRequest $request): RedirectResponse
    {
        $file = $request->file('document_file');

        try {
            DB::transaction(function () use ($request, $file) {
                $documento = DocumentoSocio::create([
                    'socio_id' => $request->validated('socio_id'),
                    'nome_file' => $file->getClientOriginalName(),
                    'percorso_file' => '',
                    'descrizione' => $request->validated('descrizione'),
                    'data_scadenza' => $request->validated('data_scadenza'),
                    'caricato_da' => $request->user()->id,
                ]);

                $media = $documento
                    ->addMedia($file)
                    ->usingFileName(NomeFileSicuro::per($file, '', Documento::ESTENSIONI_PERMESSE))
                    ->toMediaCollection(DocumentoSocio::COLLEZIONE_FILE);

                $documento->update(['percorso_file' => $media->getPathRelativeToRoot()]);
            });
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Errore durante il salvataggio. Riprova più tardi.');
        }

        return redirect()
            ->route('documenti-socio.index', ['socio_id' => $request->validated('socio_id')])
            ->with('success', 'Documento caricato con successo!');
    }

    public function download(DocumentoSocio $documento): BinaryFileResponse|StreamedResponse
    {
        $this->authorize('view', $documento);

        return $this->rispostaDownload($documento);
    }

    public function linkTemporaneo(DocumentoSocio $documento): RedirectResponse
    {
        $this->authorize('view', $documento);

        $url = URL::temporarySignedRoute(
            'documenti-socio.download-pubblico',
            now()->addMinutes(DocumentoController::MINUTI_LINK_TEMPORANEO),
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
        /** @var DocumentoSocio $documento */
        $documento = DocumentoSocio::allTenants()->findOrFail($id);

        return $this->rispostaDownload($documento);
    }

    /**
     * Risposta di download: media library se presente, altrimenti fallback
     * sul percorso_file del disk privato (record migrati dal legacy o
     * caricati dal modulo soci, che usa Storage diretto).
     */
    private function rispostaDownload(DocumentoSocio $documento): BinaryFileResponse|StreamedResponse
    {
        $media = $documento->getFirstMedia(DocumentoSocio::COLLEZIONE_FILE);

        if ($media !== null) {
            return response()->download($media->getPath(), $documento->nome_file);
        }

        if ($documento->percorso_file !== '' && Storage::disk('local')->exists($documento->percorso_file)) {
            return Storage::disk('local')->download($documento->percorso_file, $documento->nome_file);
        }

        abort(404, 'File non trovato.');
    }

    public function destroy(DocumentoSocio $documento): RedirectResponse
    {
        $this->authorize('delete', $documento);

        $documento->delete();

        return back()->with('success', 'Documento spostato nel cestino.');
    }

    public function ripristina(string $id): RedirectResponse
    {
        /** @var DocumentoSocio $documento */
        $documento = DocumentoSocio::onlyTrashed()->findOrFail($id);

        $this->authorize('restore', $documento);

        $documento->restore();

        return back()->with('success', 'Documento ripristinato con successo!');
    }
}
