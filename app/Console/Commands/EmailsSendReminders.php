<?php

namespace App\Console\Commands;

use App\Models\Associazione;
use App\Models\EmailLog;
use App\Models\EmailQueue;
use App\Models\Quota;
use App\Models\Socio;
use App\Models\Tessera;
use App\Services\Email\EmailLayout;
use App\Services\Email\EmailPlaceholders;
use App\Services\Email\EmailService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Unifica i cron legacy send_reminders.php + send_renewal_reminders.php (§11):
 * promemoria tessere in scadenza (con link rinnovo) e quote non pagate,
 * con finestra anti-duplicato unica e configurabile.
 *
 * Accoda soltanto: l'invio effettivo è demandato a emails:process-queue.
 */
class EmailsSendReminders extends Command
{
    protected $signature = 'emails:send-reminders
        {--window=30 : Finestra anti-duplicato in giorni (per socio e tipo promemoria)}';

    protected $description = 'Accoda i promemoria di scadenza tessere e quote per tutte le associazioni';

    public function handle(EmailService $emailService, EmailLayout $layout): int
    {
        $window = max(1, (int) $this->option('window'));
        $totale = 0;

        $associazioni = Associazione::query()
            ->where('attiva', true)
            ->whereHas('smtpSetting')
            ->get();

        foreach ($associazioni as $associazione) {
            if (! $emailService->isConfigured($associazione)) {
                continue;
            }

            $accodate = $this->remindTessere($associazione, $emailService, $layout, $window)
                + $this->remindQuote($associazione, $emailService, $layout, $window);

            $totale += $accodate;
            $this->line(sprintf('[%s] %s: %d promemoria accodati.', now()->format('Y-m-d H:i:s'), $associazione->nome, $accodate));
        }

        $this->info("Totale promemoria accodati: {$totale}.");

        return self::SUCCESS;
    }

    /**
     * Promemoria tessere in scadenza, con link rinnovo (token 30 giorni).
     */
    private function remindTessere(Associazione $associazione, EmailService $emailService, EmailLayout $layout, int $window): int
    {
        $settings = $emailService->settings($associazione);

        if ($settings === null || ! $settings->auto_scadenza_tessera) {
            return 0;
        }

        $template = $emailService->getTemplate($associazione->id, 'scadenza_tessera');

        if ($template === null || ! $template->attivo) {
            return 0;
        }

        $giorni = $associazione->tesseramento()->giorni_notifica_scadenza;
        $deadline = today()->addDays($giorni);

        $tessere = Tessera::forAssociazione($associazione->id)
            ->with('socio')
            ->where('stato', 'Attiva')
            ->whereBetween('data_scadenza', [today(), $deadline])
            ->whereHas('socio', fn ($q) => $this->eligibleSocio($q))
            ->get();

        $batchId = (string) Str::uuid();
        $count = 0;
        $notified = [];

        foreach ($tessere as $tessera) {
            $socio = $tessera->socio;

            if (! $socio instanceof Socio || isset($notified[$socio->id]) || $this->alreadyReminded($associazione->id, $socio->id, 'scadenza_tessera', $window)) {
                continue;
            }

            // Token rinnovo (sovrascrive il precedente), validità 30 giorni
            $socio->forceFill([
                'rinnovo_token' => bin2hex(random_bytes(32)),
                'rinnovo_token_expires' => now()->addDays(30),
            ])->saveQuietly();

            $linkRinnovo = url('/preiscrizione').'?assoc='.$associazione->id.'&token='.$socio->rinnovo_token;

            $values = EmailPlaceholders::buildValues($associazione, $socio, [
                'NUMERO_TESSERA' => (string) $tessera->numero_tessera,
                'ANNO_VALIDITA' => (string) $tessera->anno_validita,
                'DATA_SCADENZA' => $tessera->data_scadenza->format('d/m/Y'),
                'LINK_RINNOVO' => $linkRinnovo,
                'LINK_PAGAMENTO' => '', // gestito dal modulo pagamenti quando disponibile
            ]);

            $rendered = $emailService->renderTemplate($associazione->id, 'scadenza_tessera', $values);

            if ($rendered === null) {
                continue;
            }

            $emailService->queueEmail(
                $associazione->id,
                $socio->email,
                trim($socio->nome.' '.$socio->cognome),
                $rendered['subject'],
                $layout->wrap($rendered['body'], $associazione, $socio),
                $socio->id,
                $batchId,
                'scadenza_tessera'
            );

            $notified[$socio->id] = true;
            $count++;
        }

        return $count;
    }

    /**
     * Promemoria quote non pagate (incluse le scadute), finestra fissa 30 giorni.
     */
    private function remindQuote(Associazione $associazione, EmailService $emailService, EmailLayout $layout, int $window): int
    {
        $settings = $emailService->settings($associazione);

        if ($settings === null || ! $settings->auto_scadenza_quota) {
            return 0;
        }

        $template = $emailService->getTemplate($associazione->id, 'scadenza_quota');

        if ($template === null || ! $template->attivo) {
            return 0;
        }

        $quote = Quota::forAssociazione($associazione->id)
            ->with('socio')
            ->whereNull('data_pagamento')
            ->where('data_scadenza', '<=', today()->addDays(30))
            ->whereHas('socio', fn ($q) => $this->eligibleSocio($q))
            ->get();

        $batchId = (string) Str::uuid();
        $count = 0;
        $notified = [];

        foreach ($quote as $quota) {
            $socio = $quota->socio;

            if (! $socio instanceof Socio || isset($notified[$socio->id]) || $this->alreadyReminded($associazione->id, $socio->id, 'scadenza_quota', $window)) {
                continue;
            }

            $values = EmailPlaceholders::buildValues($associazione, $socio, [
                'IMPORTO' => number_format((float) $quota->importo, 2, ',', '.'),
                'ANNO' => (string) $quota->anno,
                'DATA_SCADENZA' => $quota->data_scadenza->format('d/m/Y'),
                'LINK_PAGAMENTO' => '',
            ]);

            $rendered = $emailService->renderTemplate($associazione->id, 'scadenza_quota', $values);

            if ($rendered === null) {
                continue;
            }

            $emailService->queueEmail(
                $associazione->id,
                $socio->email,
                trim($socio->nome.' '.$socio->cognome),
                $rendered['subject'],
                $layout->wrap($rendered['body'], $associazione, $socio),
                $socio->id,
                $batchId,
                'scadenza_quota'
            );

            $notified[$socio->id] = true;
            $count++;
        }

        return $count;
    }

    /**
     * Filtro soci eleggibili: attivi, con email, non opt-out.
     */
    private function eligibleSocio($query): void
    {
        $query->where('stato', 'Attivo')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->where(function ($q) {
                $q->where('email_opt_out', false)->orWhereNull('email_opt_out');
            });
    }

    /**
     * Anti-duplicato: già notificato (email_log) o già in coda pending
     * (correzione edge case 8 del legacy: con SMTP rotto il cron riaccodava
     * duplicati ogni giorno perché il log restava vuoto).
     */
    private function alreadyReminded(string $associazioneId, string $socioId, string $templateCodice, int $window): bool
    {
        $logged = EmailLog::forAssociazione($associazioneId)
            ->where('socio_id', $socioId)
            ->where('template_codice', $templateCodice)
            ->where('sent_at', '>=', today()->subDays($window))
            ->exists();

        if ($logged) {
            return true;
        }

        return EmailQueue::forAssociazione($associazioneId)
            ->where('socio_id', $socioId)
            ->where('template_codice', $templateCodice)
            ->where('status', EmailQueue::STATUS_PENDING)
            ->exists();
    }
}
