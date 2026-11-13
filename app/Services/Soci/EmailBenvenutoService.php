<?php

namespace App\Services\Soci;

use App\Models\EmailQueue;
use App\Models\EmailTemplate;
use App\Models\Socio;
use App\Services\Email\EmailPlaceholders;
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

    /**
     * Accoda l'email di benvenuto (best-effort): false se manca l'email del
     * socio, l'SMTP o l'invio automatico è disattivato.
     */
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

            // Placeholder legacy {{nome}} (compatibilità con template storici):
            // valori HTML-escaped come quelli di EmailPlaceholders, perché
            // finiscono in body_html (nell'oggetto le entità vengono decodificate).
            $escape = fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            $placeholders = [
                '{{nome}}' => $escape($socio->nome),
                '{{cognome}}' => $escape($socio->cognome),
                '{{nome_completo}}' => $escape($socio->nome_completo),
                '{{numero_socio}}' => $escape($socio->numero_socio),
                '{{associazione}}' => $escape($associazione->nome),
                '{{email}}' => $escape($socio->email),
            ];

            // Placeholder dell'editor template ({NOME}, {NOME_ASSOCIAZIONE}, ...):
            // stessa sintassi di EmailPlaceholders usata da comunicazioni e coda.
            // L'oggetto è testo semplice: le entità vengono decodificate.
            $valori = EmailPlaceholders::buildValues($associazione, $socio);
            $oggetto = html_entity_decode(
                EmailPlaceholders::render(strtr((string) $template->oggetto, $placeholders), $valori),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );
            $corpo = EmailPlaceholders::render(strtr((string) $template->corpo_html, $placeholders), $valori);

            EmailQueue::create([
                'associazione_id' => $associazione->id,
                'batch_id' => (string) Str::uuid(),
                'socio_id' => $socio->id,
                'to_email' => $socio->email,
                'to_name' => $socio->nome_completo,
                'subject' => $oggetto !== '' ? $oggetto : 'Benvenuto',
                'body_html' => $corpo,
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
