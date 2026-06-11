<?php

namespace App\Http\Controllers\Email;

use App\Http\Controllers\Controller;
use App\Http\Requests\Email\StoreComunicazioneRequest;
use App\Models\Associazione;
use App\Models\CategoriaSocio;
use App\Models\EmailLog;
use App\Models\EmailQueue;
use App\Models\EmailTemplate;
use App\Models\GruppoDinamico;
use App\Models\Sede;
use App\Models\Tag;
use App\Services\Email\EmailHtmlSanitizer;
use App\Services\Email\EmailLayout;
use App\Services\Email\EmailPlaceholders;
use App\Services\Email\EmailService;
use App\Services\Email\RecipientSelector;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ComunicazioniController extends Controller
{
    public function __construct(
        private readonly EmailService $emailService,
        private readonly RecipientSelector $recipients,
    ) {}

    public function index(): View
    {
        Gate::authorize('viewAny', EmailQueue::class);

        $associazione = $this->associazione();

        return view('email.comunicazioni', [
            'associazione' => $associazione,
            'isConfigured' => $this->emailService->isConfigured($associazione),
            'templates' => EmailTemplate::query()->where('attivo', true)->orderBy('nome')->get(),
            'categorie' => CategoriaSocio::query()->orderBy('nome')->get(),
            'sedi' => Sede::query()->orderBy('nome')->get(),
            'tags' => Tag::query()->orderBy('nome_tag')->get(),
            'gruppi' => GruppoDinamico::query()->orderBy('nome_gruppo')->get(),
            'placeholders' => EmailPlaceholders::forCodice('comunicazione'),
            'recipientCount' => $this->recipients->count($associazione->id, 'all'),
            'recentBatches' => $this->recentBatches(),
        ]);
    }

    /**
     * Conteggio destinatari aggiornato al cambio filtro (AJAX, miglioria §11).
     */
    public function count(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', EmailQueue::class);

        $type = (string) $request->query('recipient_type', 'all');

        if (! in_array($type, RecipientSelector::TYPES, true)) {
            $type = 'all';
        }

        $count = $this->recipients->count(
            (string) session('associazione_id'),
            $type,
            $request->query('recipient_filter_id')
        );

        return response()->json(['success' => true, 'count' => $count]);
    }

    /**
     * Invio massivo: rendering per-socio (placeholder personalizzati, incluso
     * il link disiscrizione) e accodamento in email_queue con batch unico.
     */
    public function store(StoreComunicazioneRequest $request, EmailHtmlSanitizer $sanitizer, EmailLayout $layout): RedirectResponse
    {
        $associazione = $this->associazione();

        if (! $this->emailService->isConfigured($associazione)) {
            return back()
                ->withInput()
                ->with('danger', 'SMTP non configurato. Configura le impostazioni email prima di inviare.');
        }

        $data = $request->validated();

        $recipients = $this->recipients->recipients(
            $associazione->id,
            $data['recipient_type'],
            $data['recipient_filter_id'] ?? null
        );

        if ($recipients->isEmpty()) {
            return back()
                ->withInput()
                ->with('warning', 'Nessun destinatario trovato per i criteri selezionati.');
        }

        // Miglioramento 7: corpo sanitizzato una sola volta, prima del rendering per-socio
        $bodyTemplate = $sanitizer->clean($data['corpo_html']);
        $subjectTemplate = $data['subject'];
        $batchId = (string) Str::uuid();

        DB::transaction(function () use ($recipients, $associazione, $subjectTemplate, $bodyTemplate, $batchId, $layout): void {
            foreach ($recipients as $socio) {
                $values = EmailPlaceholders::buildValues($associazione, $socio);

                $this->emailService->queueEmail(
                    $associazione->id,
                    $socio->email,
                    trim($socio->nome.' '.$socio->cognome),
                    EmailPlaceholders::render($subjectTemplate, $values),
                    $layout->wrap(EmailPlaceholders::render($bodyTemplate, $values), $associazione, $socio),
                    $socio->id,
                    $batchId,
                    'comunicazione' // fisso, anche per comunicazioni libere (come il legacy)
                );
            }
        });

        return redirect()
            ->route('comunicazioni.index')
            ->with('success', $recipients->count().' email accodate con successo! Verranno inviate dalla coda di invio.');
    }

    /**
     * Ultime 10 aggregazioni per batch dallo storico (sezione "Invii Recenti").
     *
     * @return Collection<int, EmailLog>
     */
    private function recentBatches()
    {
        return EmailLog::query()
            ->whereNotNull('batch_id')
            ->groupBy('batch_id')
            ->selectRaw("batch_id, MIN(sent_at) as prima_data, MIN(subject) as oggetto, COUNT(*) as totale, SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as inviate, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as fallite")
            ->orderByDesc('prima_data')
            ->limit(10)
            ->get();
    }

    private function associazione(): Associazione
    {
        return Associazione::query()->findOrFail(session('associazione_id'));
    }
}
