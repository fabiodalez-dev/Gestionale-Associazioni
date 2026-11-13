<?php

namespace App\Console\Commands;

use App\Models\Utente;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

/**
 * Recupero dell'accesso di un amministratore da riga di comando (come
 * reset_credentials.php della vecchia app): senza email elenca gli
 * amministratori, con l'email genera un link di reimpostazione della password.
 * Con --azzera-2fa toglie invece l'autenticazione a due fattori (telefono
 * perso, codici di recupero finiti): al login successivo un super admin
 * viene riportato alla pagina profilo per riattivarla.
 */
class AdminRecuperaAccesso extends Command
{
    protected $signature = 'admin:recupera-accesso
        {email? : Email dell\'utente a cui generare il link}
        {--azzera-2fa : Disattiva l\'autenticazione a due fattori dell\'utente invece di generare il link}';

    protected $description = 'Elenca gli amministratori, genera un link per reimpostare la password di un utente o ne azzera la 2FA';

    public function handle(): int
    {
        $email = $this->argument('email');

        if (! is_string($email) || $email === '') {
            $amministratori = Utente::query()
                ->whereIn('ruolo', [Utente::RUOLO_SUPER_ADMIN, Utente::RUOLO_ADMIN_ASSOCIAZIONE])
                ->with('associazione:id,nome')
                ->orderBy('ruolo')->orderBy('email')
                ->get();

            if ($amministratori->isEmpty()) {
                $this->warn('Nessun amministratore trovato. Usa l\'installer o crea un utente.');

                return self::SUCCESS;
            }

            $this->table(['Email', 'Nome', 'Ruolo', 'Associazione', 'Attivo'], $amministratori->map(fn (Utente $u) => [
                $u->email,
                $u->nome_completo,
                $u->ruolo === Utente::RUOLO_SUPER_ADMIN ? 'Super admin' : 'Amministratore',
                $u->associazione_id !== null ? (string) $u->getRelation('associazione')?->nome : '—',
                $u->attivo ? 'sì' : 'no',
            ])->all());
            $this->line('Per generare un link: php artisan admin:recupera-accesso <email>');
            $this->line('Per azzerare la 2FA: php artisan admin:recupera-accesso <email> --azzera-2fa');

            return self::SUCCESS;
        }

        $utente = Utente::query()->where('email', mb_strtolower(trim($email)))->first();
        if ($utente === null) {
            $this->error('Nessun utente con email '.$email.'.');

            return self::FAILURE;
        }

        if ($this->option('azzera-2fa')) {
            return $this->azzera2fa($utente);
        }

        // createToken() è del broker concreto, non del contratto restituito dal facade
        /** @var PasswordBroker $broker */
        $broker = Password::broker('utenti');
        $token = $broker->createToken($utente);
        $url = route('password.reset', ['token' => $token, 'email' => $utente->email]);
        $minuti = (int) config('auth.passwords.utenti.expire', 60);

        $this->info('Link per reimpostare la password di '.$utente->email.' (valido '.$minuti.' minuti):');
        $this->line($url);
        if (! $utente->attivo) {
            $this->warn('Attenzione: l\'account è disattivato. Riattivalo prima, altrimenti l\'accesso resta bloccato.');
        }

        return self::SUCCESS;
    }

    private function azzera2fa(Utente $utente): int
    {
        if ($utente->two_factor_secret === null && $utente->two_factor_confirmed_at === null) {
            $this->info('L\'utente '.$utente->email.' non ha la 2FA attiva: niente da azzerare.');

            return self::SUCCESS;
        }

        $utente->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        Log::warning('admin:recupera-accesso: 2FA azzerata da riga di comando', ['utente_id' => $utente->id]);

        $this->info('Autenticazione a due fattori azzerata per '.$utente->email.'.');
        if ($utente->isSuperAdmin() && (bool) config('sicurezza.super_admin_2fa_obbligatoria', true)) {
            $this->line('Al prossimo accesso dovrà riattivarla dalla pagina profilo prima di usare il gestionale.');
        }
        if (! $utente->attivo) {
            $this->warn('Attenzione: l\'account è disattivato. Riattivalo prima, altrimenti l\'accesso resta bloccato.');
        }

        return self::SUCCESS;
    }
}
