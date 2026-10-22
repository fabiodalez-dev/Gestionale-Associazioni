<?php

namespace App\Notifications\Privacy;

use App\Http\Controllers\Privacy\DatiPersonaliController;
use App\Models\Account;
use App\Models\Socio;
use App\Services\Email\TenantNotificationMailer;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Email con il link all'archivio dei dati personali (config
 * personal-data-export.notification). Link firmato, con scadenza pari alla
 * conservazione dell'archivio, verso l'area del socio o dell'account: il
 * download richiede anche di essere autenticati come titolare.
 */
class DatiPersonaliPronti extends Notification
{
    public Carbon $scadenza;

    public function __construct(public string $zipFilename)
    {
        $this->scadenza = now()->addDays((int) config('personal-data-export.delete_after_days'));
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var Socio|Account $notifiable */
        $associazione = $notifiable->associazione()->withoutGlobalScopes()->first();
        $url = DatiPersonaliController::urlDownload($notifiable, $this->zipFilename, $this->scadenza);

        $message = (new MailMessage)
            ->subject('I tuoi dati personali — '.($associazione->nome ?? config('app.name')))
            ->greeting('Ciao '.$notifiable->nome.',')
            ->line('l\'archivio con i tuoi dati personali che hai richiesto è pronto. Contiene un file JSON, un riepilogo PDF e i documenti caricati.')
            ->action('Scarica i tuoi dati', $url)
            ->line('Per scaricarlo devi accedere con il tuo account. Il link scade il '.$this->scadenza->format('d/m/Y \a\l\l\e H:i').', poi l\'archivio viene cancellato.')
            ->line('Se non hai fatto tu questa richiesta, contatta l\'associazione.');

        return TenantNotificationMailer::applica($message, $associazione, $notifiable instanceof Socio ? $notifiable : null);
    }
}
