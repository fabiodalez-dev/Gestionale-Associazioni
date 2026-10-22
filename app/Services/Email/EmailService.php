<?php

namespace App\Services\Email;

use App\Models\Associazione;
use App\Models\EmailLog;
use App\Models\EmailQueue;
use App\Models\EmailTemplate;
use App\Models\SmtpSetting;
use App\Models\Socio;
use Illuminate\Support\Str;

/**
 * Porting Laravel della classe EmailService legacy: configurazione SMTP,
 * invio, accodamento, rate limiting, template e logging — sempre con scope
 * tenant esplicito (funziona anche in contesto cron/job senza sessione).
 *
 * Correzioni rispetto al legacy:
 * - claim atomico delle righe in elaborazione (no doppi invii concorrenti);
 * - recupero righe bloccate in 'sending' da oltre 15 minuti;
 * - backoff esponenziale tra i retry (next_attempt_at);
 * - body_html e queue_id salvati in email_log (niente JOIN euristico).
 */
class EmailService
{
    /** Minuti dopo i quali una riga 'sending' è considerata bloccata */
    public const STUCK_SENDING_MINUTES = 15;

    public function __construct(
        private readonly TenantMailer $mailer,
    ) {}

    // ── Configurazione ───────────────────────────────────────────────

    /**
     * Configurato = riga presente E smtp_host E from_email non vuoti
     * (user/password non richiesti, come nel legacy).
     */
    public function isConfigured(Associazione $associazione): bool
    {
        $settings = $this->settings($associazione);

        return $settings !== null
            && $settings->smtp_host !== ''
            && $settings->from_email !== '';
    }

    public function settings(Associazione $associazione): ?SmtpSetting
    {
        $settings = $associazione->smtpSetting;

        return $settings instanceof SmtpSetting ? $settings : null;
    }

    // ── Rate limiting ────────────────────────────────────────────────

    /**
     * Finestra mobile di 60 minuti per tenant: COUNT degli esiti 'sent'
     * in email_log nell'ultima ora < max_per_hour (<= 0 = illimitato).
     */
    public function checkRateLimit(Associazione $associazione): bool
    {
        $maxPerHour = (int) ($this->settings($associazione)->max_per_hour ?? 100);

        if ($maxPerHour <= 0) {
            return true;
        }

        $count = EmailLog::forAssociazione($associazione->id)
            ->where('status', EmailLog::STATUS_SENT)
            ->where('sent_at', '>=', now()->subHour())
            ->count();

        return $count < $maxPerHour;
    }

    // ── Invio ────────────────────────────────────────────────────────

    /**
     * Email di test SMTP: bypassa il rate limit, non viene loggata;
     * su successo aggiorna is_verified e last_test_at.
     *
     * @return array{success: bool, error: string|null}
     */
    public function sendTestEmail(Associazione $associazione, string $toEmail): array
    {
        $settings = $this->settings($associazione);

        if ($settings === null || ! $this->isConfigured($associazione)) {
            return ['success' => false, 'error' => 'SMTP non configurato.'];
        }

        $fromName = $settings->from_name !== '' ? $settings->from_name : 'Associazione';

        $result = $this->mailer->send(
            $settings,
            $toEmail,
            '',
            'Test SMTP — '.$fromName,
            '<h2>Test riuscito!</h2><p>La configurazione SMTP funziona correttamente.</p>'
        );

        if ($result['success']) {
            $settings->forceFill([
                'is_verified' => true,
                'last_test_at' => now(),
            ])->save();
        }

        return $result;
    }

    // ── Accodamento ──────────────────────────────────────────────────

    /**
     * Accoda una singola email (subject/body già renderizzati).
     */
    public function queueEmail(
        string $associazioneId,
        string $toEmail,
        string $toName,
        string $subject,
        string $bodyHtml,
        ?string $socioId = null,
        ?string $batchId = null,
        ?string $templateCodice = null,
        int $priority = 5
    ): EmailQueue {
        return EmailQueue::create([
            'associazione_id' => $associazioneId,
            'batch_id' => $batchId ?? (string) Str::uuid(),
            'socio_id' => $socioId,
            'to_email' => $toEmail,
            'to_name' => $toName,
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'template_codice' => $templateCodice,
            'priority' => $priority,
            'status' => EmailQueue::STATUS_PENDING,
        ]);
    }

    // ── Elaborazione coda ────────────────────────────────────────────

    /**
     * Elabora fino a $batchSize email del tenant.
     *
     * @return array{sent: int, failed: int, remaining: int}
     */
    public function processQueue(Associazione $associazione, int $batchSize = 20, ?string $sentBy = null): array
    {
        $sent = 0;
        $failed = 0;

        $this->recoverStuckSending($associazione->id);

        $settings = $this->settings($associazione);

        if ($settings !== null && $this->isConfigured($associazione)) {
            $items = EmailQueue::forAssociazione($associazione->id)
                ->whereIn('status', [EmailQueue::STATUS_PENDING, EmailQueue::STATUS_FAILED])
                ->whereColumn('attempts', '<', 'max_attempts')
                ->where(function ($q) {
                    $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
                })
                ->orderBy('priority')
                ->orderBy('created_at')
                ->limit($batchSize)
                ->get();

            foreach ($items as $item) {
                if (! $this->checkRateLimit($associazione)) {
                    break; // le restanti rimangono in coda
                }

                // Claim atomico: se un'elaborazione concorrente l'ha già presa, salta
                $claimed = EmailQueue::forAssociazione($associazione->id)
                    ->whereKey($item->id)
                    ->whereIn('status', [EmailQueue::STATUS_PENDING, EmailQueue::STATUS_FAILED])
                    ->update([
                        'status' => EmailQueue::STATUS_SENDING,
                        'attempts' => $item->attempts + 1,
                        'last_attempt_at' => now(),
                    ]);

                if ($claimed === 0) {
                    continue;
                }

                $item->refresh();

                $result = $this->mailer->send(
                    $settings,
                    $item->to_email,
                    (string) $item->to_name,
                    $item->subject,
                    $item->body_html,
                    $this->unsubscribeHeaders($associazione->id, $item->socio_id)
                );

                if ($result['success']) {
                    $item->forceFill([
                        'status' => EmailQueue::STATUS_SENT,
                        'sent_at' => now(),
                        'error_message' => null,
                        'next_attempt_at' => null,
                    ])->save();

                    $this->logOutcome($item, EmailLog::STATUS_SENT, null, $sentBy);
                    $sent++;
                } else {
                    $failed++;

                    if ($item->attempts >= $item->max_attempts) {
                        // Esito definitivo: failed + log
                        $item->forceFill([
                            'status' => EmailQueue::STATUS_FAILED,
                            'error_message' => $result['error'],
                            'next_attempt_at' => null,
                        ])->save();

                        $this->logOutcome($item, EmailLog::STATUS_FAILED, $result['error'], $sentBy);
                    } else {
                        // Retry con backoff esponenziale: 5, 10, 20... minuti
                        $delayMinutes = 5 * (2 ** max(0, $item->attempts - 1));

                        $item->forceFill([
                            'status' => EmailQueue::STATUS_PENDING,
                            'error_message' => $result['error'],
                            'next_attempt_at' => now()->addMinutes(min($delayMinutes, 120)),
                        ])->save();
                    }
                }
            }
        }

        $remaining = EmailQueue::forAssociazione($associazione->id)
            ->where('status', EmailQueue::STATUS_PENDING)
            ->whereColumn('attempts', '<', 'max_attempts')
            ->count();

        return ['sent' => $sent, 'failed' => $failed, 'remaining' => $remaining];
    }

    /**
     * Riporta a 'pending' le righe rimaste in 'sending' da oltre 15 minuti
     * (processo morto a metà invio).
     */
    public function recoverStuckSending(string $associazioneId): int
    {
        return EmailQueue::forAssociazione($associazioneId)
            ->where('status', EmailQueue::STATUS_SENDING)
            ->where('last_attempt_at', '<', now()->subMinutes(self::STUCK_SENDING_MINUTES))
            ->update(['status' => EmailQueue::STATUS_PENDING]);
    }

    // ── Template ─────────────────────────────────────────────────────

    public function getTemplate(string $associazioneId, string $codice): ?EmailTemplate
    {
        return EmailTemplate::forAssociazione($associazioneId)
            ->where('codice', $codice)
            ->first();
    }

    /**
     * Renderizza oggetto e corpo del template con i placeholder.
     * NULL se il template non esiste o non è attivo.
     *
     * @param  array<string, string>  $placeholders
     * @return array{subject: string, body: string}|null
     */
    public function renderTemplate(string $associazioneId, string $codice, array $placeholders): ?array
    {
        $template = $this->getTemplate($associazioneId, $codice);

        if ($template === null || ! $template->attivo) {
            return null;
        }

        return [
            'subject' => EmailPlaceholders::render($template->oggetto, $placeholders),
            'body' => EmailPlaceholders::render($template->corpo_html, $placeholders),
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Header List-Unsubscribe per i destinatari collegati a un socio (miglioria §11).
     *
     * @return array<string, string>
     */
    private function unsubscribeHeaders(string $associazioneId, ?string $socioId): array
    {
        if ($socioId === null) {
            return [];
        }

        $socio = Socio::forAssociazione($associazioneId)->whereKey($socioId)->first();

        if ($socio === null || empty($socio->email)) {
            return [];
        }

        return [
            'List-Unsubscribe' => '<'.EmailPlaceholders::unsubscribeUrl($socio).'>',
        ];
    }

    /**
     * Registra in email_log solo gli esiti definitivi (sent / failed esauriti i tentativi).
     */
    private function logOutcome(EmailQueue $item, string $status, ?string $error, ?string $sentBy): void
    {
        EmailLog::create([
            'associazione_id' => $item->associazione_id,
            'batch_id' => $item->batch_id,
            'queue_id' => $item->id,
            'socio_id' => $item->socio_id,
            'to_email' => $item->to_email,
            'to_name' => $item->to_name ?? '',
            'subject' => $item->subject,
            'template_codice' => $item->template_codice,
            'body_html' => $item->body_html,
            'status' => $status,
            'error_message' => $error,
            'sent_by' => $sentBy,
            'sent_at' => now(),
        ]);
    }
}
