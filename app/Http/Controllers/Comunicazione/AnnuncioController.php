<?php

namespace App\Http\Controllers\Comunicazione;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comunicazione\AnnuncioRequest;
use App\Models\Annuncio;
use App\Models\Evento;
use App\Models\Socio;
use App\Notifications\Comunicazione\ConvocazioneAssembleaNotification;
use App\Services\Email\EmailHtmlSanitizer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;

/**
 * Bacheca annunci — CRUD admin (f.7 piano Gestione 2).
 * Il corpo HTML viene purificato al salvataggio (EmailHtmlSanitizer,
 * stessa pulizia centralizzata dei template email).
 *
 * Addendum convocazioni: il form gestisce anche il tipo 'convocazione'
 * (data/luogo/OdG, evento collegato opzionale) e l'invio della
 * convocazione via email IN CODA ai soci attivi del tenant.
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

        return view('comunicazione.annunci.form', [
            'annuncio' => new Annuncio,
            'eventiFuturi' => $this->eventiFuturi(),
        ]);
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

        return view('comunicazione.annunci.form', [
            'annuncio' => $annuncio,
            'eventiFuturi' => $this->eventiFuturi($annuncio),
        ]);
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

    /**
     * Invia la convocazione di assemblea via email (notifica in coda) a
     * tutti i soci Attivi del tenant con email e senza opt-out, poi marca
     * convocazione_inviata_at. Il bottone in lista chiede ri-conferma se
     * la convocazione risulta già inviata.
     */
    public function inviaConvocazione(Annuncio $annuncio): RedirectResponse
    {
        Gate::authorize('update', $annuncio);

        if (! $annuncio->isConvocazione() || ! $annuncio->isPubblicato()) {
            return redirect()
                ->route('annunci.index')
                ->with('error', 'Solo le convocazioni pubblicate possono essere inviate ai soci.');
        }

        $soci = Socio::query()
            ->where('stato', Socio::STATO_ATTIVO)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->where('email_opt_out', false)
            ->get();

        // Notifica ShouldQueue: l'invio effettivo avviene sul worker
        Notification::send($soci, new ConvocazioneAssembleaNotification($annuncio));

        $annuncio->forceFill(['convocazione_inviata_at' => now()])->save();

        return redirect()
            ->route('annunci.index')
            ->with('success', "Convocazione inviata a {$soci->count()} soci.");
    }

    /**
     * Eventi futuri del tenant selezionabili come evento collegato della
     * convocazione (+ l'eventuale evento già collegato, anche se passato,
     * per non perderlo in modifica).
     *
     * @return Collection<int, Evento>
     */
    private function eventiFuturi(?Annuncio $annuncio = null): Collection
    {
        return Evento::query()
            ->where(function ($q) use ($annuncio) {
                $q->where('data_evento', '>=', now());

                if ($annuncio?->evento_id !== null) {
                    $q->orWhere('id', $annuncio->evento_id);
                }
            })
            ->orderBy('data_evento')
            ->get();
    }
}
