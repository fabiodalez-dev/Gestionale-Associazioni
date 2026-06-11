<?php

namespace App\Http\Controllers\Email;

use App\Http\Controllers\Controller;
use App\Http\Requests\Email\SaveSmtpSettingsRequest;
use App\Http\Requests\Email\SendTestEmailRequest;
use App\Models\Associazione;
use App\Models\EmailLog;
use App\Models\SmtpSetting;
use App\Services\Email\DefaultEmailTemplates;
use App\Services\Email\EmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class EmailImpostazioniController extends Controller
{
    public function __construct(private readonly EmailService $emailService) {}

    public function index(): View
    {
        Gate::authorize('viewAny', SmtpSetting::class);

        $associazione = $this->associazione();
        $settings = SmtpSetting::query()->first();

        $stats = [
            'inviate_oggi' => EmailLog::query()
                ->where('status', EmailLog::STATUS_SENT)
                ->whereDate('sent_at', today())
                ->count(),
            'inviate_ultima_ora' => EmailLog::query()
                ->where('status', EmailLog::STATUS_SENT)
                ->where('sent_at', '>=', now()->subHour())
                ->count(),
            'fallite_totali' => EmailLog::query()
                ->where('status', EmailLog::STATUS_FAILED)
                ->count(),
        ];

        return view('email.impostazioni', [
            'associazione' => $associazione,
            'settings' => $settings,
            'stats' => $stats,
            'isConfigured' => $this->emailService->isConfigured($associazione),
        ]);
    }

    public function store(SaveSmtpSettingsRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $settings = SmtpSetting::query()->first();
        $isNew = $settings === null;

        $attributes = [
            'smtp_host' => $data['smtp_host'],
            'smtp_port' => (int) $data['smtp_port'],
            'smtp_user' => $data['smtp_user'] ?? '',
            'smtp_encryption' => $data['smtp_encryption'],
            'from_email' => $data['from_email'],
            'from_name' => $data['from_name'] ?? '',
            'reply_to_email' => $data['reply_to_email'] ?? null,
            'reply_to_name' => $data['reply_to_name'] ?? null,
            'max_per_hour' => (int) $data['max_per_hour'],
            'auto_benvenuto' => $request->boolean('auto_benvenuto'),
            'auto_scadenza_tessera' => $request->boolean('auto_scadenza_tessera'),
            'auto_scadenza_quota' => $request->boolean('auto_scadenza_quota'),
            'auto_rinnovo_tessera' => $request->boolean('auto_rinnovo_tessera'),
            'auto_pagamento_quota' => $request->boolean('auto_pagamento_quota'),
        ];

        // Campo password vuoto = mantieni la precedente (obbligatoria al primo salvataggio)
        if (! empty($data['smtp_pass'])) {
            $attributes['smtp_pass_encrypted'] = $data['smtp_pass'];
        }

        if ($isNew) {
            $associazioneId = (string) session('associazione_id');
            $attributes['associazione_id'] = $associazioneId;
            SmtpSetting::create($attributes);

            // Seed idempotente dei template di default al primo salvataggio (come il legacy)
            DefaultEmailTemplates::seedFor($associazioneId);
        } else {
            $settings->update($attributes);
        }

        return redirect()
            ->route('email-impostazioni.index')
            ->with('success', 'Impostazioni email salvate con successo.');
    }

    /**
     * Email di test SMTP (AJAX): bypassa il rate limit, non viene loggata.
     * Risponde sempre HTTP 200 con success nel payload (come il legacy).
     */
    public function test(SendTestEmailRequest $request): JsonResponse
    {
        $associazione = $this->associazione();

        if (! $this->emailService->isConfigured($associazione)) {
            return response()->json(['success' => false, 'error' => 'SMTP non configurato.']);
        }

        $result = $this->emailService->sendTestEmail($associazione, $request->validated('test_email'));

        return response()->json([
            'success' => $result['success'],
            'error' => $result['error'],
            'message' => $result['success'] ? 'Email di test inviata con successo!' : null,
        ]);
    }

    private function associazione(): Associazione
    {
        return Associazione::query()->findOrFail(session('associazione_id'));
    }
}
