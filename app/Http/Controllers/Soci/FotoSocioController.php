<?php

namespace App\Http\Controllers\Soci;

use App\Http\Controllers\Controller;
use App\Models\Socio;
use App\Support\Media\NomeFileSicuro;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Foto del socio (medialibrary, disco privato): caricamento e rimozione con
 * "update", visualizzazione in formato tessera con "view".
 */
class FotoSocioController extends Controller
{
    public function store(Request $request, Socio $socio): RedirectResponse
    {
        Gate::authorize('update', $socio);

        $request->validate([
            'foto' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=150,min_height=150'],
        ], [
            'foto.image' => 'La foto deve essere un\'immagine JPG, PNG o WebP.',
            'foto.mimes' => 'La foto deve essere un\'immagine JPG, PNG o WebP.',
            'foto.max' => 'La foto supera i 5 MB.',
            'foto.dimensions' => 'La foto deve essere di almeno 150×150 pixel.',
        ]);

        $socio->addMedia($request->file('foto'))
            ->usingFileName(NomeFileSicuro::per($request->file('foto'), 'foto-', NomeFileSicuro::IMMAGINI, 'jpg'))
            ->toMediaCollection(Socio::COLLEZIONE_FOTO);

        return redirect()->route('soci.show', $socio)->with('success', 'Foto aggiornata.');
    }

    public function show(Socio $socio): BinaryFileResponse
    {
        Gate::authorize('view', $socio);

        $percorso = $socio->fotoPercorso();
        abort_if($percorso === null, 404);

        return response()->file($percorso, ['Cache-Control' => 'private, max-age=300']);
    }

    public function destroy(Socio $socio): RedirectResponse
    {
        Gate::authorize('update', $socio);

        $socio->clearMediaCollection(Socio::COLLEZIONE_FOTO);

        return redirect()->route('soci.show', $socio)->with('success', 'Foto rimossa.');
    }
}
