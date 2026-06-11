<?php

namespace App\Http\Controllers\Soci;

use App\Http\Controllers\Controller;
use App\Http\Requests\Soci\UploadDocumentoSocioRequest;
use App\Models\DocumentoSocio;
use App\Models\Socio;
use App\Services\Soci\SocioActivityLogger;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documenti del socio (spec soci §10.2, tab Documenti).
 * A differenza del legacy: storage privato con path relativo,
 * download e cancellazione implementati con verifica tenant.
 */
class DocumentoSocioController extends Controller
{
    use AuthorizesRequests;

    private const DISK = 'local';

    public function __construct(private readonly SocioActivityLogger $activityLogger) {}

    public function store(UploadDocumentoSocioRequest $request, Socio $socio): RedirectResponse
    {
        $this->authorize('update', $socio);

        $file = $request->file('file');
        $percorso = $file->store(
            "documenti-soci/{$socio->associazione_id}/{$socio->id}",
            self::DISK
        );

        $documento = DocumentoSocio::create([
            'socio_id' => $socio->id,
            'associazione_id' => $socio->associazione_id,
            'nome_file' => $file->getClientOriginalName(),
            'percorso_file' => $percorso,
            'descrizione' => $request->validated('descrizione'),
            'data_scadenza' => $request->validated('data_scadenza'),
            'caricato_da' => $request->user()->id,
        ]);

        $this->activityLogger->log(
            $socio,
            SocioActivityLogger::TIPO_CARICAMENTO_DOCUMENTO,
            "Caricato documento: {$documento->nome_file}",
            ['documento_id' => $documento->id]
        );

        return redirect()
            ->route('soci.show', [$socio, 'tab' => 'documenti'])
            ->with('success', 'Documento caricato con successo.');
    }

    public function download(Socio $socio, DocumentoSocio $documento): StreamedResponse
    {
        $this->authorize('view', $socio);

        abort_unless($documento->socio_id === $socio->id, 404);
        abort_unless(Storage::disk(self::DISK)->exists($documento->percorso_file), 404);

        return Storage::disk(self::DISK)->download($documento->percorso_file, $documento->nome_file);
    }

    public function destroy(Socio $socio, DocumentoSocio $documento): RedirectResponse
    {
        $this->authorize('update', $socio);

        abort_unless($documento->socio_id === $socio->id, 404);

        // Soft delete (miglioramento 11): il file resta sul disco finché il
        // documento non viene eliminato definitivamente dal cestino documenti.
        $documento->delete();

        return redirect()
            ->route('soci.show', [$socio, 'tab' => 'documenti'])
            ->with('success', 'Documento eliminato.');
    }
}
