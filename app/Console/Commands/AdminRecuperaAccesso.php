<?php

namespace App\Console\Commands;

use App\Models\Utente;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Password;

/**
 * Recupero dell'accesso di un amministratore da riga di comando (come
 * reset_credentials.php della vecchia app): senza email elenca gli
 * amministratori, con l'email genera un link di reimpostazione della password.
 */
class AdminRecuperaAccesso extends Command
{
    protected $signature = 'admin:recupera-accesso {email? : Email dell\'utente a cui generare il link}';

    protected $description = 'Elenca gli amministratori o genera un link per reimpostare la password di un utente';

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

            return self::SUCCESS;
        }

        $utente = Utente::query()->where('email', mb_strtolower(trim($email)))->first();
        if ($utente === null) {
            $this->error('Nessun utente con email '.$email.'.');

            return self::FAILURE;
        }

        $token = Password::broker('utenti')->createToken($utente);
        $url = route('password.reset', ['token' => $token, 'email' => $utente->email]);
        $minuti = (int) config('auth.passwords.utenti.expire', 60);

        $this->info('Link per reimpostare la password di '.$utente->email.' (valido '.$minuti.' minuti):');
        $this->line($url);
        if (! $utente->attivo) {
            $this->warn('Attenzione: l\'account è disattivato. Riattivalo prima, altrimenti l\'accesso resta bloccato.');
        }

        return self::SUCCESS;
    }
}
