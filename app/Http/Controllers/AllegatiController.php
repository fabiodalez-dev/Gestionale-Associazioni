<?php

namespace App\Http\Controllers;

use App\Models\Progetto;
use App\Models\Riunione;
use App\Models\Verbale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Allegati di verbali, riunioni e progetti (trait HaAllegati). Il modello si
 * risolve con lo scope di tenant attivo; elenco e download richiedono
 * "view", caricamento ed eliminazione "update" sulla policy del modello.
 */
class AllegatiController extends Controller
{
    /** tipo nell'URL => [classe, etichetta, rotta della scheda] */
    public const TIPI = [
        'verbali' => [Verbale::class, 'Verbale', 'verbali.index'],
        'riunioni' => [Riunione::class, 'Riunione', 'riunioni.show'],
        'progetti' => [Progetto::class, 'Progetto', 'progetti.show'],
    ];

    public const ESTENSIONI = 'pdf,doc,docx,odt,xls,xlsx,ods,ppt,pptx,odp,txt,csv,jpg,jpeg,png,webp,zip';

    public function index(string $tipo, string $id): View
    {
        $modello = $this->modello($tipo, $id);
        Gate::authorize('view', $modello);

        return view('allegati.index', [
            'tipo' => $tipo,
            'modello' => $modello,
            'etichetta' => self::TIPI[$tipo][1],
            'titolo' => $this->titolo($modello),
            'urlScheda' => self::TIPI[$tipo][2] === 'verbali.index' ? route('verbali.index') : route(self::TIPI[$tipo][2], $modello),
        ]);
    }

    public function store(Request $request, string $tipo, string $id): RedirectResponse
    {
        $modello = $this->modello($tipo, $id);
        Gate::authorize('update', $modello);

        $request->validate([
            'file' => ['required', 'file', 'mimes:'.self::ESTENSIONI, 'max:20480'],
        ], [
            'file.mimes' => 'Tipo di file non ammesso.',
            'file.max' => 'Il file supera i 20 MB.',
        ]);

        $file = $request->file('file');
        $modello->addMedia($file)
            ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
            ->withCustomProperties(['caricato_da' => $request->user()?->getAuthIdentifier()])
            ->toMediaCollection(Verbale::COLLEZIONE_ALLEGATI);

        return back()->with('success', 'Allegato caricato.');
    }

    public function download(string $tipo, string $id, int $media): StreamedResponse
    {
        $modello = $this->modello($tipo, $id);
        Gate::authorize('view', $modello);

        $allegato = $this->allegato($modello, $media);

        return response()->streamDownload(function () use ($allegato): void {
            $stream = $allegato->stream();
            fpassthru($stream);
            fclose($stream);
        }, $allegato->file_name, ['Content-Type' => $allegato->mime_type ?: 'application/octet-stream']);
    }

    public function destroy(string $tipo, string $id, int $media): RedirectResponse
    {
        $modello = $this->modello($tipo, $id);
        Gate::authorize('update', $modello);

        $this->allegato($modello, $media)->delete();

        return back()->with('success', 'Allegato eliminato.');
    }

    /**
     * @return Model&HasMedia
     */
    private function modello(string $tipo, string $id): Model
    {
        abort_unless(array_key_exists($tipo, self::TIPI), 404);

        /** @var class-string<Model&HasMedia> $classe */
        $classe = self::TIPI[$tipo][0];

        $modello = $classe::query()->findOrFail($id);
        abort_unless($modello instanceof HasMedia, 404);

        return $modello;
    }

    private function allegato(Model&HasMedia $modello, int $id): Media
    {
        $media = $modello->getMedia(Verbale::COLLEZIONE_ALLEGATI)->firstWhere('id', $id);
        abort_if($media === null, 404);

        return $media;
    }

    private function titolo(Model $modello): string
    {
        return (string) ($modello->getAttribute('titolo') ?? $modello->getAttribute('nome') ?? '');
    }
}
