<?php

namespace App\Services\Email;

use App\Models\Associazione;
use App\Models\SmtpSetting;
use App\Models\Socio;
use Illuminate\Notifications\Messages\MailMessage;
use Throwable;

/**
 * Instrada le MailMessage delle Notifiche sul mailer SMTP del tenant
 * quando configurato (host + from_email presenti), registrandolo a
 * runtime nella config dei mailer; altrimenti lascia il mailer di
 * default dell'applicazione (fallback).
 *
 * Dal piano Vita sociale (f.18) applica anche il layout email
 * brandizzato per tenant (vista condivisa emails.layout: logo, nome,
 * colore, footer con link di disiscrizione quando c'è un socio).
 * Fallback pulito: senza logo resta il solo nome; senza associazione
 * la notifica mantiene il rendering markdown di default di Laravel.
 *
 * Funziona anche con le notifiche in coda: la registrazione avviene
 * dentro toMail(), eseguito sul worker al momento dell'invio.
 */
class TenantNotificationMailer
{
    /** Colore brand di default quando il tenant non ne definisce uno. */
    public const COLORE_DEFAULT = '#FF7B11';

    /**
     * Applica (se possibile) il layout brandizzato e il mailer SMTP
     * del tenant alla MailMessage.
     */
    public static function applica(MailMessage $message, ?Associazione $associazione, ?Socio $socio = null): MailMessage
    {
        if ($associazione === null) {
            return $message;
        }

        $message = static::brandizza($message, $associazione, $socio);

        $settings = $associazione->smtpSetting;

        if (
            ! $settings instanceof SmtpSetting
            || blank($settings->smtp_host)
            || blank($settings->from_email)
        ) {
            // SMTP del tenant non configurato: mailer di default
            return $message;
        }

        $nome = 'tenant-'.$associazione->id;

        config(["mail.mailers.{$nome}" => TenantMailer::mailerConfig($settings)]);

        return $message
            ->mailer($nome)
            ->from($settings->from_email, $settings->from_name !== '' ? $settings->from_name : null);
    }

    /**
     * Applica il layout email brandizzato (f.18): vista condivisa
     * emails.layout (+ variante plain-text) con logo/nome/colore del
     * tenant. Fail-safe: non sovrascrive una vista già impostata e in
     * caso di errore lascia la MailMessage intatta (markdown default).
     */
    public static function brandizza(MailMessage $message, ?Associazione $associazione, ?Socio $socio = null): MailMessage
    {
        if ($associazione === null || ! empty($message->view)) {
            return $message;
        }

        try {
            // Link di disiscrizione solo per soci persistiti (il token
            // viene generato e salvato lazy da EmailPlaceholders)
            $unsubscribeUrl = $socio !== null && $socio->exists
                ? EmailPlaceholders::unsubscribeUrl($socio)
                : null;

            return $message->view(
                ['html' => 'emails.layout', 'text' => 'emails.layout-text'],
                [
                    'brandNome' => filled($associazione->nome) ? (string) $associazione->nome : (string) config('app.name'),
                    'brandLogo' => filled($associazione->logo_url) ? (string) $associazione->logo_url : null,
                    'brandColore' => static::colore($associazione),
                    'unsubscribeUrl' => $unsubscribeUrl,
                    'annoCorrente' => now()->format('Y'),
                ]
            );
        } catch (Throwable $e) {
            report($e);

            return $message;
        }
    }

    /**
     * Colore brand del tenant: usa un'eventuale colonna/attributo
     * colore_email dell'associazione, altrimenti il colore di default.
     */
    protected static function colore(Associazione $associazione): string
    {
        $colore = (string) ($associazione->getAttribute('colore_email') ?? '');

        return preg_match('/^#[0-9a-fA-F]{6}$/', $colore) === 1 ? $colore : self::COLORE_DEFAULT;
    }
}
