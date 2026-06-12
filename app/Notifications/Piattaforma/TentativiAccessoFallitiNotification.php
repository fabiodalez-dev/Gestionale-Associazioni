<?php

namespace App\Notifications\Piattaforma;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * Alert sicurezza (f.25 del piano "Gestione 2"): inviata agli admin quando
 * la stessa email accumula 5 tentativi di accesso falliti in 10 minuti
 * (throttled: un solo alert per email per finestra).
 *
 * Canali database + mail; la mail esce col layout brandizzato del tenant
 * (TenantNotificationMailer, f.18 del round 1).
 */
class TentativiAccessoFallitiNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $email,
        public int $tentativi,
        public int $finestraMinuti,
        public string $guard,
        public ?string $ip = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->error()
            ->subject("Allarme sicurezza: {$this->tentativi} accessi falliti per {$this->email}")
            ->greeting('Attenzione!')
            ->line("Sono stati registrati {$this->tentativi} tentativi di accesso falliti negli ultimi {$this->finestraMinuti} minuti per l'email {$this->email}.")
            ->line('Area interessata: '.(['web' => 'pannello admin', 'socio' => 'area soci', 'account' => 'frontend pubblico'][$this->guard] ?? $this->guard)
                .($this->ip !== null ? " — ultimo IP: {$this->ip}." : '.'))
            ->line('Se l\'attività non è riconducibile a un utente legittimo, valuta di verificare l\'account interessato e l\'audit completo degli accessi.');

        if (Route::has('audit-accessi.index')) {
            $message->action('Apri l\'audit accessi', route('audit-accessi.index', ['email' => $this->email]));
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => 'tentativi_accesso_falliti',
            'email' => $this->email,
            'tentativi' => $this->tentativi,
            'finestra_minuti' => $this->finestraMinuti,
            'guard' => $this->guard,
            'ip' => $this->ip,
        ];
    }
}
