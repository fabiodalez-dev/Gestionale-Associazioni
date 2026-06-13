<?php

namespace App\Http\Controllers\Relazioni;

use App\Http\Controllers\Controller;
use App\Http\Requests\Relazioni\GalleriaUploadRequest;
use App\Models\Evento;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\View\View;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Galleria foto eventi (f.24 del piano "Organizzazione 3"): tab admin di
 * upload/eliminazione foto (medialibrary, collection 'galleria', max 20 con
 * conversione thumb) e toggle della visibilità pubblica (galleria_pubblica).
 * La griglia pubblica sulla pagina dell'evento è iniettata dal composer.
 * Autorizzazione: EventoPolicy (eventi.view per il tab, eventi.update per le
 * modifiche).
 */
class GalleriaEventoController extends Controller
{
    use AuthorizesRequests;

    public function index(Evento $evento): View
    {
        $this->authorize('view', $evento);

        return view('eventi.galleria.index', [
            'evento' => $evento,
            'foto' => $evento->getMedia(Evento::COLLEZIONE_GALLERIA),
            'maxFoto' => Evento::GALLERIA_MAX_FOTO,
        ]);
    }

    public function store(GalleriaUploadRequest $request, Evento $evento): RedirectResponse
    {
        $this->authorize('update', $evento);

        $disponibili = Evento::GALLERIA_MAX_FOTO - $evento->getMedia(Evento::COLLEZIONE_GALLERIA)->count();

        if ($disponibili <= 0) {
            return back()->withErrors([
                'foto' => 'La galleria ha già raggiunto il massimo di '.Evento::GALLERIA_MAX_FOTO.' foto.',
            ]);
        }

        /** @var array<int, UploadedFile> $files */
        $files = array_slice($request->file('foto'), 0, $disponibili);

        foreach ($files as $file) {
            $evento->addMedia($file)->toMediaCollection(Evento::COLLEZIONE_GALLERIA);
        }

        return redirect()
            ->route('eventi.galleria.index', $evento)
            ->with('success', count($files) === 1 ? 'Foto caricata.' : count($files).' foto caricate.');
    }

    public function destroy(Evento $evento, Media $media): RedirectResponse
    {
        $this->authorize('update', $evento);

        // Difesa in profondità: la foto deve appartenere alla galleria di
        // QUESTO evento (model binding non scopa la media al genitore).
        abort_unless(
            $media->model_type === Evento::class
                && $media->model_id === $evento->id
                && $media->collection_name === Evento::COLLEZIONE_GALLERIA,
            404
        );

        $media->delete();

        return redirect()
            ->route('eventi.galleria.index', $evento)
            ->with('success', 'Foto eliminata.');
    }

    public function toggleVisibilita(Evento $evento): RedirectResponse
    {
        $this->authorize('update', $evento);

        $evento->update(['galleria_pubblica' => ! $evento->galleria_pubblica]);

        return redirect()
            ->route('eventi.galleria.index', $evento)
            ->with('success', $evento->galleria_pubblica
                ? 'Galleria ora visibile sulla pagina pubblica dell\'evento.'
                : 'Galleria nascosta dalla pagina pubblica.');
    }
}
