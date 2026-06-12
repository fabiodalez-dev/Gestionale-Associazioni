<?php

namespace App\Http\Controllers\Comunicazione;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comunicazione\NewsletterRequest;
use App\Models\Annuncio;
use App\Models\Associazione;
use App\Models\NewsletterInviata;
use App\Services\Comunicazione\NewsletterService;
use App\Services\Email\EmailLayout;
use App\Services\Email\EmailService;
use App\Services\Email\RecipientSelector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Newsletter da annunci pubblicati (f.8 piano Gestione 2):
 * composizione con selezione annunci, anteprima brandizzata,
 * invio in coda ai soci attivi senza opt-out e storico invii.
 */
class NewsletterController extends Controller
{
    public function __construct(
        private readonly NewsletterService $newsletter,
        private readonly EmailService $emailService,
        private readonly RecipientSelector $recipients,
    ) {}

    public function index(): View
    {
        Gate::authorize('viewAny', NewsletterInviata::class);

        $associazione = $this->associazione();

        return view('comunicazione.newsletter.index', [
            'associazione' => $associazione,
            'isConfigured' => $this->emailService->isConfigured($associazione),
            'annunciPubblicati' => Annuncio::query()
                ->pubblicati()
                ->orderByDesc('in_evidenza')
                ->orderByDesc('pubblicato_dal')
                ->get(),
            'destinatariCount' => $this->recipients->count($associazione->id, 'all'),
            'storico' => NewsletterInviata::query()
                ->with('inviataDa')
                ->orderByDesc('inviata_at')
                ->paginate(10),
        ]);
    }

    /**
     * Anteprima HTML della newsletter con il layout email brandizzato
     * (aperta in una nuova scheda dal form di composizione).
     */
    public function anteprima(NewsletterRequest $request, EmailLayout $layout): Response|RedirectResponse
    {
        Gate::authorize('send', NewsletterInviata::class);

        $associazione = $this->associazione();
        $data = $request->validated();

        $annunci = $this->newsletter->annunciPubblicati($associazione->id, $data['annunci']);

        if ($annunci->isEmpty()) {
            return back()
                ->withInput()
                ->with('warning', 'Nessuno degli annunci selezionati risulta pubblicato.');
        }

        $html = $layout->wrap(
            $this->newsletter->componiHtml($annunci, $data['titolo']),
            $associazione
        );

        return response($html);
    }

    public function store(NewsletterRequest $request): RedirectResponse
    {
        Gate::authorize('send', NewsletterInviata::class);

        $associazione = $this->associazione();

        if (! $this->emailService->isConfigured($associazione)) {
            return back()
                ->withInput()
                ->with('danger', 'SMTP non configurato. Configura le impostazioni email prima di inviare.');
        }

        $data = $request->validated();
        $annunci = $this->newsletter->annunciPubblicati($associazione->id, $data['annunci']);

        if ($annunci->isEmpty()) {
            return back()
                ->withInput()
                ->with('warning', 'Nessuno degli annunci selezionati risulta pubblicato.');
        }

        $inviata = $this->newsletter->invia($associazione, $annunci, $data['titolo'], $request->user());

        if ($inviata->inviata_a === 0) {
            return redirect()
                ->route('newsletter.index')
                ->with('warning', 'Newsletter registrata, ma nessun socio attivo con email (senza opt-out) da raggiungere.');
        }

        return redirect()
            ->route('newsletter.index')
            ->with('success', "Newsletter accodata a {$inviata->inviata_a} soci. Verrà inviata dalla coda di invio.");
    }

    private function associazione(): Associazione
    {
        return Associazione::query()->findOrFail(session('associazione_id'));
    }
}
