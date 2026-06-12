<?php

namespace App\Http\Controllers\Comunicazione;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comunicazione\AnnuncioRequest;
use App\Models\Annuncio;
use App\Services\Email\EmailHtmlSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Bacheca annunci — CRUD admin (f.7 piano Gestione 2).
 * Il corpo HTML viene purificato al salvataggio (EmailHtmlSanitizer,
 * stessa pulizia centralizzata dei template email).
 */
class AnnuncioController extends Controller
{
    public function __construct(
        private readonly EmailHtmlSanitizer $sanitizer,
    ) {}

    public function index(): View
    {
        Gate::authorize('viewAny', Annuncio::class);

        return view('comunicazione.annunci.index', [
            'annunci' => Annuncio::query()
                ->orderByDesc('in_evidenza')
                ->orderByDesc('created_at')
                ->paginate(20),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Annuncio::class);

        return view('comunicazione.annunci.form', ['annuncio' => new Annuncio]);
    }

    public function store(AnnuncioRequest $request): RedirectResponse
    {
        Gate::authorize('create', Annuncio::class);

        $data = $request->validated();
        $data['corpo_html'] = $this->sanitizer->clean($data['corpo_html']);
        $data['creato_da'] = $request->user()?->id;

        Annuncio::create($data);

        return redirect()
            ->route('annunci.index')
            ->with('success', 'Annuncio creato con successo.');
    }

    public function edit(Annuncio $annuncio): View
    {
        Gate::authorize('update', $annuncio);

        return view('comunicazione.annunci.form', ['annuncio' => $annuncio]);
    }

    public function update(AnnuncioRequest $request, Annuncio $annuncio): RedirectResponse
    {
        Gate::authorize('update', $annuncio);

        $data = $request->validated();
        $data['corpo_html'] = $this->sanitizer->clean($data['corpo_html']);

        $annuncio->update($data);

        return redirect()
            ->route('annunci.index')
            ->with('success', 'Annuncio aggiornato con successo.');
    }

    public function destroy(Annuncio $annuncio): RedirectResponse
    {
        Gate::authorize('delete', $annuncio);

        $annuncio->delete();

        return redirect()
            ->route('annunci.index')
            ->with('success', 'Annuncio eliminato.');
    }
}
