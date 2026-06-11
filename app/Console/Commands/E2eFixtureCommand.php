<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\Evento;
use App\Models\PaymentGatewaySetting;
use App\Models\Prenotazione;
use App\Models\Utente;
use App\Services\Prenotazioni\BigliettoService;
use App\Services\Prenotazioni\PrenotazioneService;
use Database\Seeders\RolesSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;

/**
 * Fixture per la suite E2E Playwright (tests/e2e). Attivo SOLO con
 * E2E_FIXTURES=1 nell'ambiente: mai disponibile in produzione.
 *
 * Output sempre JSON su stdout (consumato dagli helper TypeScript).
 */
class E2eFixtureCommand extends Command
{
    protected $signature = 'e2e:fixture {azione} {--email=} {--password=} {--titolo=} {--capienza=} {--prezzo=0} {--posti-prenotati=0} {--passato=0} {--evento=}';

    protected $description = 'Fixture deterministiche per i test E2E (richiede E2E_FIXTURES=1)';

    public function handle(): int
    {
        if (! config('app.e2e_fixtures')) {
            $this->error(json_encode(['error' => 'E2E_FIXTURES non attivo']));

            return self::FAILURE;
        }

        $risultato = match ($this->argument('azione')) {
            'reset' => $this->reset(),
            'verifica-account' => $this->verificaAccount(),
            'link-verifica' => $this->linkVerifica(),
            'crea-evento' => $this->creaEvento(),
            'crea-account' => $this->creaAccount(),
            'stato-prenotazioni' => $this->statoPrenotazioni(),
            'annulla-prenotazione' => $this->annullaPrenotazione(),
            'link-biglietto' => $this->linkBiglietto(),
            'sposta-evento-passato' => $this->spostaEventoPassato(),
            'configura-gateway' => $this->configuraGateway(),
            'azzera-throttle' => $this->azzeraThrottle(),
            default => ['error' => 'azione sconosciuta'],
        };

        $this->line((string) json_encode($risultato));

        return isset($risultato['error']) ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function reset(): array
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        Artisan::call('db:seed', ['--class' => RolesSeeder::class, '--force' => true]);

        $associazione = Associazione::create([
            'nome' => 'Associazione E2E',
            'email' => 'info@e2e.test',
            'slug' => 'e2e',
            'frontend_pubblico_attivo' => true,
            'descrizione_pubblica' => 'Associazione di test per la suite end-to-end.',
            'attiva' => true,
        ]);

        $admin = Utente::create([
            'nome' => 'Admin',
            'cognome' => 'E2E',
            'email' => 'admin-e2e@example.com',
            'password_hash' => Hash::make('AdminE2e!2026'),
            'ruolo' => 'admin_associazione',
            'associazione_id' => $associazione->id,
            'attivo' => true,
        ]);
        $admin->assignRole('admin_associazione');

        $superAdmin = Utente::create([
            'nome' => 'Super',
            'cognome' => 'E2E',
            'email' => 'super-e2e@example.com',
            'password_hash' => Hash::make('SuperE2e!2026'),
            'ruolo' => 'super_admin',
            'attivo' => true,
        ]);
        $superAdmin->assignRole('super_admin');

        $account = Account::create([
            'associazione_id' => $associazione->id,
            'nome' => 'Vera',
            'cognome' => 'Verificata',
            'email' => 'verificata@example.com',
            'password' => Hash::make('AccountE2e2026'),
            'email_verified_at' => now(),
            'attivo' => true,
            'privacy_accettata_at' => now(),
        ]);

        // Associazione con frontend DISATTIVO (per il test 404)
        Associazione::create([
            'nome' => 'Associazione Spenta',
            'email' => 'spenta@e2e.test',
            'slug' => 'spenta',
            'frontend_pubblico_attivo' => false,
            'attiva' => true,
        ]);

        return [
            'associazione_id' => $associazione->id,
            'slug' => 'e2e',
            'admin_email' => $admin->email,
            'account_email' => $account->email,
        ];
    }

    /** @return array<string, mixed> */
    private function verificaAccount(): array
    {
        $account = $this->trovaAccount();
        if (! $account) {
            return ['error' => 'account non trovato'];
        }

        $account->forceFill(['email_verified_at' => now()])->save();

        return ['ok' => true, 'account_id' => $account->id];
    }

    /** @return array<string, mixed> */
    private function linkVerifica(): array
    {
        $account = $this->trovaAccount();
        if (! $account) {
            return ['error' => 'account non trovato'];
        }

        $url = URL::temporarySignedRoute('pubblico.verifica-email.verifica', now()->addMinutes(60), [
            'associazione' => $account->associazione->slug,
            'id' => $account->id,
            'hash' => sha1($account->getEmailForVerification()),
        ]);

        return ['url' => $url];
    }

    /** @return array<string, mixed> */
    private function creaEvento(): array
    {
        $associazione = Associazione::where('slug', 'e2e')->firstOrFail();
        $capienza = $this->option('capienza');

        $evento = Evento::withoutGlobalScopes()->create([
            'associazione_id' => $associazione->id,
            'titolo' => $this->option('titolo') ?: 'Evento E2E '.now()->timestamp,
            'descrizione' => 'Evento generato dalla suite E2E.',
            'data_evento' => $this->option('passato') ? now()->subDays(7) : now()->addDays(10)->setTime(21, 0),
            'luogo' => 'Sala E2E',
            'stato_pubblicazione' => 'pubblicato',
            'capienza' => $capienza !== null ? (int) $capienza : null,
            'prezzo' => (float) $this->option('prezzo'),
            'lista_attesa_attiva' => true,
        ]);

        // Pre-occupa posti con prenotazioni confermate di account fittizi
        $daOccupare = (int) $this->option('posti-prenotati');
        $servizio = app(PrenotazioneService::class);
        for ($i = 1; $i <= $daOccupare; $i++) {
            $occupante = Account::withoutGlobalScopes()->firstOrCreate(
                ['associazione_id' => $associazione->id, 'email' => "occupante{$i}@example.com"],
                ['nome' => 'Occupante', 'cognome' => "Numero{$i}", 'password' => Hash::make('AccountE2e2026'), 'email_verified_at' => now(), 'attivo' => true, 'privacy_accettata_at' => now()]
            );
            $servizio->crea($occupante, $evento->fresh(), 1);
        }

        return ['evento_id' => $evento->id, 'titolo' => $evento->titolo];
    }

    /** @return array<string, mixed> */
    private function creaAccount(): array
    {
        $associazione = Associazione::where('slug', 'e2e')->firstOrFail();
        $email = (string) $this->option('email');
        if ($email === '') {
            return ['error' => 'serve --email'];
        }

        $account = Account::withoutGlobalScopes()->updateOrCreate(
            ['associazione_id' => $associazione->id, 'email' => $email],
            [
                'nome' => 'Account', 'cognome' => 'E2E',
                'password' => Hash::make($this->option('password') ?: 'AccountE2e2026'),
                'email_verified_at' => now(), 'attivo' => true, 'privacy_accettata_at' => now(),
            ]
        );

        return ['account_id' => $account->id];
    }

    /** @return array<string, mixed> */
    private function statoPrenotazioni(): array
    {
        $email = (string) $this->option('email');
        $prenotazioni = Prenotazione::withoutGlobalScopes()
            ->whereHas('account', fn ($q) => $q->withoutGlobalScopes()->where('email', $email))
            ->orderBy('created_at')
            ->get(['codice', 'stato', 'posti', 'qr_token'])
            ->toArray();

        return ['prenotazioni' => $prenotazioni];
    }

    /**
     * Annulla (come admin) la prenotazione attiva più recente dell'account
     * --email, opzionalmente scopata sull'evento --evento. La promozione
     * della lista d'attesa avviene nel service, come in produzione.
     *
     * @return array<string, mixed>
     */
    private function annullaPrenotazione(): array
    {
        $account = $this->trovaAccount();
        if (! $account) {
            return ['error' => 'account non trovato'];
        }

        $prenotazione = Prenotazione::withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->when($this->option('evento'), fn ($q) => $q->where('evento_id', $this->option('evento')))
            ->whereIn('stato', [
                Prenotazione::STATO_CONFERMATA,
                Prenotazione::STATO_IN_ATTESA_PAGAMENTO,
                Prenotazione::STATO_LISTA_ATTESA,
            ])
            ->orderByDesc('created_at')
            ->first();

        if ($prenotazione === null) {
            return ['error' => 'nessuna prenotazione attiva da annullare'];
        }

        $admin = Utente::where('email', 'admin-e2e@example.com')->firstOrFail();

        app(PrenotazioneService::class)
            ->annulla($prenotazione, $admin);

        return ['ok' => true, 'prenotazione_id' => $prenotazione->id];
    }

    /**
     * URL pubblico firmato di verifica del biglietto della prenotazione
     * più recente dell'account --email.
     *
     * @return array<string, mixed>
     */
    private function linkBiglietto(): array
    {
        $account = $this->trovaAccount();
        if (! $account) {
            return ['error' => 'account non trovato'];
        }

        $prenotazione = Prenotazione::withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->orderByDesc('created_at')
            ->first();

        if ($prenotazione === null) {
            return ['error' => 'nessuna prenotazione trovata'];
        }

        return [
            'url' => app(BigliettoService::class)->signedUrl($prenotazione),
            'codice' => $prenotazione->codice,
        ];
    }

    /**
     * Sposta nel passato la data dell'evento --evento (per i test sullo
     * storico: la prenotazione resta intatta).
     *
     * @return array<string, mixed>
     */
    private function spostaEventoPassato(): array
    {
        $evento = Evento::withoutGlobalScopes()->find((string) $this->option('evento'));
        if ($evento === null) {
            return ['error' => 'evento non trovato'];
        }

        $evento->forceFill([
            'data_evento' => now()->subDays(3),
            'data_fine' => null,
        ])->save();

        return ['ok' => true, 'evento_id' => $evento->id];
    }

    /**
     * Abilita Stripe (chiavi fittizie) per il tenant e2e: la pagina di
     * pagamento mostra la scelta del gateway. Nessuna chiamata reale
     * a Stripe nei test (il checkout non viene mai avviato).
     *
     * @return array<string, mixed>
     */
    private function configuraGateway(): array
    {
        $associazione = Associazione::where('slug', 'e2e')->firstOrFail();

        PaymentGatewaySetting::withoutGlobalScopes()->updateOrCreate(
            ['associazione_id' => $associazione->id],
            [
                'stripe_enabled' => true,
                'stripe_publishable_key' => 'pk_test_e2e_finta',
                'stripe_secret_key_encrypted' => 'sk_test_e2e_finta',
                'paypal_enabled' => false,
                'valuta' => 'EUR',
            ]
        );

        return ['ok' => true];
    }

    /**
     * Azzera i contatori del rate limiter (cache file condivisa col server):
     * la suite fa molte POST per minuto dallo stesso IP e i throttle di
     * produzione (5-10/min) scatterebbero tra un test e l'altro.
     *
     * @return array<string, mixed>
     */
    private function azzeraThrottle(): array
    {
        Cache::flush();

        return ['ok' => true];
    }

    private function trovaAccount(): ?Account
    {
        return Account::withoutGlobalScopes()
            ->where('email', (string) $this->option('email'))
            ->first();
    }
}
