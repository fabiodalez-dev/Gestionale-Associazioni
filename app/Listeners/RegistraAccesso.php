<?php

namespace App\Listeners;

use App\Models\Accesso;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Audit accessi (f.25 del piano "Gestione 2"): subscriber sugli eventi di
 * autenticazione di TUTTE le guard ('web', 'socio', 'account') che scrive
 * una riga in `accessi` per ogni Login, Failed e Logout.
 *
 * Best-effort by design: un errore di scrittura dell'audit non deve MAI
 * bloccare il flusso di autenticazione (try/catch con log applicativo).
 */
class RegistraAccesso
{
    public function registraLogin(Login $event): void
    {
        $this->registra($event->guard, $event->user, Accesso::ESITO_LOGIN);
    }

    public function registraFallito(Failed $event): void
    {
        // Su tentativo fallito $event->user è valorizzato solo se l'email
        // esiste (password errata): in quel caso tenant e morph sono noti.
        $emailTentata = isset($event->credentials['email'])
            ? (string) $event->credentials['email']
            : null;

        $this->registra($event->guard, $event->user, Accesso::ESITO_FALLITO, $emailTentata);
    }

    public function registraLogout(Logout $event): void
    {
        $this->registra($event->guard, $event->user, Accesso::ESITO_LOGOUT);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'registraLogin',
            Failed::class => 'registraFallito',
            Logout::class => 'registraLogout',
        ];
    }

    protected function registra(string $guard, ?Authenticatable $user, string $esito, ?string $emailTentata = null): void
    {
        try {
            $model = $user instanceof Model ? $user : null;

            Accesso::create([
                'associazione_id' => $model?->getAttribute('associazione_id'),
                'guard' => $guard,
                'autenticabile_type' => $model?->getMorphClass(),
                'autenticabile_id' => $model?->getKey(),
                'email_tentata' => $emailTentata ?? $model?->getAttribute('email'),
                'ip' => request()->ip(),
                'user_agent' => str(request()->userAgent() ?? '')->limit(490)->toString() ?: null,
                'esito' => $esito,
            ]);
        } catch (\Throwable $e) {
            // L'audit non deve mai impedire login/logout (es. tabella non
            // ancora migrata o DB momentaneamente non disponibile)
            Log::warning('RegistraAccesso: scrittura audit accessi fallita', [
                'guard' => $guard,
                'esito' => $esito,
                'errore' => $e->getMessage(),
            ]);
        }
    }
}
