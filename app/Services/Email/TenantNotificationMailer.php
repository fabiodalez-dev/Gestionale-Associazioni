<?php

namespace App\Services\Email;

use App\Models\Associazione;
use App\Models\SmtpSetting;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Instrada le MailMessage delle Notifiche sul mailer SMTP del tenant
 * quando configurato (host + from_email presenti), registrandolo a
 * runtime nella config dei mailer; altrimenti lascia il mailer di
 * default dell'applicazione (fallback).
 *
 * Funziona anche con le notifiche in coda: la registrazione avviene
 * dentro toMail(), eseguito sul worker al momento dell'invio.
 */
class TenantNotificationMailer
{
    /**
     * Applica (se possibile) il mailer SMTP del tenant alla MailMessage.
     */
    public static function applica(MailMessage $message, ?Associazione $associazione): MailMessage
    {
        if ($associazione === null) {
            return $message;
        }

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
}
