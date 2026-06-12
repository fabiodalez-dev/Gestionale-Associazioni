<?php

namespace App\Http\Controllers\FrontendPubblico;

use App\Http\Controllers\Controller;
use App\Models\Associazione;
use App\Models\Documento;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pagina "Trasparenza" del mini-sito pubblico (addendum "Convocazioni
 * assemblee + Trasparenza"): elenco dei documenti marcati come pubblici
 * dall'admin, raggruppati per categoria ('bilancio' per prima), con
 * download anonimo SOLO per i documenti pubblici del tenant (404 uniforme
 * negli altri casi — niente link firmati: la pubblicazione è esplicita).
 */
class TrasparenzaController extends Controller
{
    public function index(Associazione $associazione): View
    {
        $documenti = Documento::query()
            ->where('associazione_id', $associazione->id)
            ->pubblici()
            ->orderByDesc('created_at')
            ->get();

        // Raggruppa per categoria nell'ordine di CATEGORIE_PUBBLICHE (bilancio prima);
        // categorie non riconosciute finiscono in coda sotto 'altro'.
        $gruppi = collect(Documento::CATEGORIE_PUBBLICHE)
            ->mapWithKeys(fn (string $categoria) => [$categoria => collect()]);

        foreach ($documenti as $documento) {
            $categoria = in_array($documento->categoria_pubblica, Documento::CATEGORIE_PUBBLICHE, true)
                ? $documento->categoria_pubblica
                : 'altro';
            $gruppi[$categoria]->push($documento);
        }

        return view('pubblico.trasparenza', [
            'associazione' => $associazione,
            'gruppi' => $gruppi->filter(fn ($docs) => $docs->isNotEmpty()),
        ]);
    }

    /**
     * Download anonimo di un documento pubblico del tenant.
     * 404 uniforme se il documento non esiste, non è pubblico
     * o appartiene a un altro tenant.
     */
    public function download(Associazione $associazione, string $documento): BinaryFileResponse|StreamedResponse
    {
        /** @var Documento $doc */
        $doc = Documento::allTenants()
            ->where('associazione_id', $associazione->id)
            ->pubblici()
            ->findOrFail($documento);

        $media = $doc->getFirstMedia(Documento::COLLEZIONE_FILE);

        if ($media !== null) {
            return response()->download($media->getPath(), $doc->nome_file);
        }

        if ($doc->percorso_file !== '' && Storage::disk('local')->exists($doc->percorso_file)) {
            return Storage::disk('local')->download($doc->percorso_file, $doc->nome_file);
        }

        abort(404, 'File non trovato.');
    }
}
