<?php

namespace App\Services\Soci;

use App\Models\EmailQueue;
use App\Models\EmailTemplate;
use App\Models\Socio;
use Illuminate\Support\Str;

/**
 * Email di benvenuto best-effort (spec soci §1.3 punto 11):
 * accodata in email_queue solo se SMTP configurato + flag auto_benvenuto attivo
 * + template 'benvenuto' esistente e attivo. Gli errori vengono solo riportati,
 * mai propagati (sempre fuori transazione).
 */
class EmailBenvenutoService
{
    public const TEMPLATE_CODICE = 'benvenuto';

    public function accoda(Socio $socio): bool
    {
        try {
            $associazione = $socio->associazione;

            if ($associazione === null || blank($socio->email)) {
                return false;
            }

            $smtp = $associazione->smtpSetting;

            if ($smtp === null || blank($smtp->smtp_host) || ! $smtp->auto_benvenuto) {
                return false;
            }

            $template = EmailTemplate::allTenants()
                ->where('associazione_id', $associazione->id)
                ->where('codice', self::TEMPLATE_CODICE)
                ->where('attivo', true)
                ->first();

            if ($template === null) {
                return false;
            }

            $placeholders = [
                '{{nome}}' => (string) $socio->nome,
                '{{cognome}}' => (string) $socio->cognome,
                '{{nome_completo}}' => $socio->nome_completo,
                '{{numero_socio}}' => (string) $socio->numero_socio,
                '{{associazione}}' => (string) $associazione->nome,
                '{{email}}' => (string) $socio->email,
            ];

            EmailQueue::create([
                'associazione_id' => $associazione->id,
                'batch_id' => (string) Str::uuid(),
                'socio_id' => $socio->id,
                'to_email' => $socio->email,
                'to_name' => $socio->nome_completo,
                'subject' => strtr((string) $template->oggetto, $placeholders) ?: 'Benvenuto',
                'body_html' => strtr((string) $template->corpo_html, $placeholders),
                'template_codice' => self::TEMPLATE_CODICE,
                'status' => 'pending',
            ]);

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }
}
