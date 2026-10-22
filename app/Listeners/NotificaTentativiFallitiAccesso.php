<?php

namespace App\Listeners;

use App\Models\Accesso;
use App\Models\Utente;
use App\Notifications\Piattaforma\TentativiAccessoFallitiNotification;
use App\Support\AccountTentativoAccesso;
use Illuminate\Auth\Events\Failed;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Alert sicurezza (f.25 del piano "Gestione 2"): dopo 5 tentativi di accesso
 * falliti in 10 minuti sulla stessa email (su qualsiasi guard), notifica gli
 * admin del tenant (o i super_admin se il tenant non è determinabile).
 *
 * Throttled: un solo alert per email per finestra (Cache::add).
 *
 * Listener auto-scoperto (app/Listeners): viene registrato PRIMA del
 * subscriber RegistraAccesso (Event::subscribe in AppServiceProvider::boot),
 * quindi al momento del conteggio la riga del tentativo corrente non è
 * ancora in tabella e viene sommata a parte. Se l'ordine cambiasse, il
 * worst case è un alert un tentativo in anticipo, comunque throttled.
 *
 * Best-effort come l'audit: un errore qui non deve MAI bloccare il login.
 */
class NotificaTentativiFallitiAccesso
{
    public const SOGLIA = 5;

    public const FINESTRA_MINUTI = 10;

    public function handle(Failed $event): void
    {
        try {
            // Stessa forma usata da RegistraAccesso in email_tentata
            // (trim + minuscolo), così il conteggio nella finestra non si
            // aggira alternando maiuscole o spazi
            $email = AccountTentativoAccesso::emailNormalizzata($event->credentials['email'] ?? null);

            if ($email === '') {
                return;
            }

            // Tentativi falliti già registrati nella finestra + quello corrente
            $tentativi = Accesso::query()
                ->fallitiRecentiPerEmail($email, self::FINESTRA_MINUTI)
                ->count() + 1;

            if ($tentativi < self::SOGLIA) {
                return;
            }

            // Throttle: un solo alert per email per finestra
            $chiave = 'piattaforma.alert-accessi.'.sha1(strtolower($email));
            if (! Cache::add($chiave, true, now()->addMinutes(self::FINESTRA_MINUTI))) {
                return;
            }

            $destinatari = $this->destinatari($event);

            if ($destinatari->isEmpty()) {
                return;
            }

            Notification::send($destinatari, new TentativiAccessoFallitiNotification(
                email: $email,
                tentativi: $tentativi,
                finestraMinuti: self::FINESTRA_MINUTI,
                guard: $event->guard,
                ip: request()->ip(),
            ));
        } catch (\Throwable $e) {
            Log::warning('NotificaTentativiFallitiAccesso: invio alert fallito', [
                'errore' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Admin del tenant dell'utente bersaglio, se determinabile (l'evento
     * Failed valorizza $user quando l'email esiste); altrimenti super_admin.
     *
     * @return Collection<int, Utente>
     */
    protected function destinatari(Failed $event)
    {
        $account = AccountTentativoAccesso::risolvi($event);
        $tenantId = $account instanceof Model
            ? $account->getAttribute('associazione_id')
            : null;

        if ($tenantId !== null) {
            return Utente::query()
                ->where('associazione_id', $tenantId)
                ->where('ruolo', Utente::RUOLO_ADMIN_ASSOCIAZIONE)
                ->where('attivo', true)
                ->get();
        }

        return Utente::query()
            ->where('ruolo', Utente::RUOLO_SUPER_ADMIN)
            ->where('attivo', true)
            ->get();
    }
}
