<?php

namespace App\Services\Email;

use App\Models\SmtpSetting;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Mailer dinamico per tenant: costruisce a runtime un mailer SMTP
 * dalla configurazione di smtp_settings dell'associazione.
 *
 * Mapping encryption legacy → Symfony Mailer:
 * - 'tls'  → STARTTLS (scheme smtp, auto-tls attivo);
 * - 'ssl'  → SMTPS (scheme smtps);
 * - 'none' → nessuna crittografia e auto-TLS disattivato.
 */
class TenantMailer
{
    /**
     * Invia una singola email HTML con la configurazione SMTP del tenant.
     *
     * @param  array<string, string>  $headers  header testuali extra (es. List-Unsubscribe)
     * @return array{success: bool, error: string|null}
     */
    public function send(
        SmtpSetting $settings,
        string $toEmail,
        string $toName,
        string $subject,
        string $bodyHtml,
        array $headers = []
    ): array {
        try {
            $mailer = Mail::build(self::mailerConfig($settings));

            $mailer->html($bodyHtml, function (Message $message) use ($settings, $toEmail, $toName, $subject, $headers): void {
                $message->to($toEmail, $toName !== '' ? $toName : null)
                    ->subject($subject)
                    ->from($settings->from_email, $settings->from_name !== '' ? $settings->from_name : null);

                if (! empty($settings->reply_to_email)) {
                    $message->replyTo($settings->reply_to_email, $settings->reply_to_name ?: null);
                }

                foreach ($headers as $name => $value) {
                    $message->getHeaders()->addTextHeader($name, $value);
                }
            });

            return ['success' => true, 'error' => null];
        } catch (Throwable $e) {
            // Messaggio SMTP grezzo (mai stack trace): salvato in error_message e mostrato all'admin nel test
            report($e);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Config Laravel del mailer SMTP per il tenant. Pubblico e statico:
     * riusato da TenantNotificationMailer per registrare a runtime un
     * mailer nominale da usare nelle Notifiche (frontend pubblico).
     *
     * @return array<string, mixed>
     */
    public static function mailerConfig(SmtpSetting $settings): array
    {
        $config = [
            'transport' => 'smtp',
            'host' => $settings->smtp_host,
            'port' => $settings->smtp_port,
            'username' => $settings->smtp_user,
            'password' => (string) $settings->smtp_pass_encrypted, // decifrata dal cast 'encrypted'
            'timeout' => 30,
        ];

        if ($settings->smtp_encryption === 'ssl') {
            $config['scheme'] = 'smtps';
        } elseif ($settings->smtp_encryption === 'none') {
            $config['scheme'] = 'smtp';
            $config['auto_tls'] = false;
        }

        return $config;
    }
}
