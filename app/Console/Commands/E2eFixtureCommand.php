<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Adempimento;
use App\Models\Annuncio;
use App\Models\Associazione;
use App\Models\Attrezzatura;
use App\Models\Carica;
use App\Models\ContattoEsterno;
use App\Models\Documento;
use App\Models\Evento;
use App\Models\FeedbackEvento;
use App\Models\Mansione;
use App\Models\Movimento;
use App\Models\PaymentGatewaySetting;
use App\Models\Prenotazione;
use App\Models\PrenotazioneSpazio;
use App\Models\PrestitoAttrezzatura;
use App\Models\Progetto;
use App\Models\Quota;
use App\Models\RichiestaIscrizione;
use App\Models\Riunione;
use App\Models\RiunionePartecipante;
use App\Models\Socio;
use App\Models\Spazio;
use App\Models\Sponsor;
use App\Models\Tessera;
use App\Models\Turno;
use App\Models\TurnoAssegnazione;
use App\Models\Utente;
use App\Models\VotazioneRiunione;
use App\Services\Economia\PrimaNotaService;
use App\Services\Iscrizioni\ApprovazioneIscrizioneService;
use App\Services\Prenotazioni\BigliettoService;
use App\Services\Prenotazioni\PrenotazioneService;
use App\Services\Soci\NumeroProgressivoService;
use Database\Seeders\RolesSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Fixture per la suite E2E Playwright (tests/e2e). Attivo SOLO con
 * E2E_FIXTURES=1 nell'ambiente: mai disponibile in produzione.
 *
 * Output sempre JSON su stdout (consumato dagli helper TypeScript).
 */
class E2eFixtureCommand extends Command
{
    protected $signature = 'e2e:fixture {azione} {--email=} {--password=} {--titolo=} {--capienza=} {--prezzo=0} {--posti-prenotati=0} {--passato=0} {--evento=}
        {--richiede-approvazione=0} {--iscrizioni-chiuse=0} {--apertura-futura=0}
        {--tessera=} {--scaduta=0} {--senza-tessera=0} {--con-quota=0}
        {--aperte=} {--modalita=} {--intro=} {--config=}
        {--genera-tessera=0} {--motivo=}
        {--solo-soci=0} {--socio=} {--quota=} {--codice-fiscale=} {--data-nascita=} {--quota-scadenza=}
        {--categoria=}
        {--turno=} {--auto-conferma=0} {--posti=} {--mansione=} {--spazio=}
        {--quantita=} {--tutto-in-prestito=0}
        {--progetto=} {--importo=} {--tipo=} {--budget=}
        {--riunione=} {--svolta=0} {--con-carica=0}
        {--tag=} {--anno=} {--mese=} {--livello=} {--visibile=1}';

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
            'crea-socio-con-tessera' => $this->creaSocioConTessera(),
            'link-verifica-tessera' => $this->linkVerificaTessera(),
            'set-password-socio' => $this->setPasswordSocio(),
            'configura-form-iscrizione' => $this->configuraFormIscrizione(),
            'stato-richiesta' => $this->statoRichiesta(),
            'approva-richiesta' => $this->approvaRichiesta(),
            'rifiuta-richiesta' => $this->rifiutaRichiesta(),
            'collega-account-socio' => $this->collegaAccountSocio(),
            'paga-quota' => $this->pagaQuota(),
            'link-feedback' => $this->linkFeedback(),
            'conta-serie' => $this->contaSerie(),
            'stato-socio' => $this->statoSocio(),
            'crea-documento-pubblico' => $this->creaDocumentoPubblico(),
            'crea-convocazione' => $this->creaConvocazione(),
            // ---- Organizzazione 3 (round 3) ----
            'crea-mansione' => $this->creaMansione(),
            'crea-turno' => $this->creaTurno(),
            'stato-turno' => $this->statoTurno(),
            'marca-volontario' => $this->marcaVolontario(),
            'crea-spazio' => $this->creaSpazio(),
            'crea-prenotazione-spazio' => $this->creaPrenotazioneSpazio(),
            'crea-attrezzatura' => $this->creaAttrezzatura(),
            'crea-progetto' => $this->creaProgetto(),
            'crea-movimento-progetto' => $this->creaMovimentoProgetto(),
            'crea-riunione' => $this->creaRiunione(),
            'stato-riunione' => $this->statoRiunione(),
            'seed-adempimenti' => $this->seedAdempimenti(),
            'crea-contatto-esterno' => $this->creaContattoEsterno(),
            'crea-sponsor' => $this->creaSponsor(),
            'referral-socio' => $this->referralSocio(),
            'crea-evento-galleria' => $this->creaEventoGalleria(),
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
            'richiede_approvazione' => (bool) $this->option('richiede-approvazione'),
            'solo_soci' => (bool) $this->option('solo-soci'),
            'iscrizioni_aperte' => ! $this->option('iscrizioni-chiuse'),
            'iscrizioni_dal' => $this->option('apertura-futura') ? now()->addDays(3) : null,
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

    /**
     * Crea un Socio 'Attivo' con (di default) una tessera per l'anno
     * corrente. Opzioni: --password (abilita il login area-soci),
     * --scaduta=1 (tessera dell'anno scorso, scadenza passata),
     * --senza-tessera=1, --con-quota=1 (quota da pagare anno corrente).
     *
     * @return array<string, mixed>
     */
    private function creaSocioConTessera(): array
    {
        $associazione = Associazione::where('slug', 'e2e')->firstOrFail();
        $email = (string) $this->option('email');
        if ($email === '') {
            return ['error' => 'serve --email'];
        }

        $socio = Socio::withoutGlobalScopes()->create([
            'associazione_id' => $associazione->id,
            'numero_socio' => app(NumeroProgressivoService::class)->prossimoNumeroSocio($associazione->id),
            'nome' => 'Socio',
            'cognome' => 'E2E',
            'email' => $email,
            'codice_fiscale' => $this->option('codice-fiscale') ?: null,
            'data_nascita' => $this->option('data-nascita') ?: null,
            'data_iscrizione' => now()->toDateString(),
            'stato' => Socio::STATO_ATTIVO,
            'privacy_consenso' => true,
            // Cast 'hashed' sul model: si assegna la password in chiaro
            'password_hash' => $this->option('password') ?: null,
        ]);

        $tessera = null;
        if (! $this->option('senza-tessera')) {
            $scaduta = (bool) $this->option('scaduta');
            $tessera = Tessera::withoutGlobalScopes()->create([
                'associazione_id' => $associazione->id,
                'socio_id' => $socio->id,
                'numero_tessera' => app(NumeroProgressivoService::class)->prossimoNumeroTessera($associazione->id),
                'anno_validita' => $scaduta ? now()->year - 1 : now()->year,
                'data_emissione' => $scaduta ? now()->subYear()->toDateString() : now()->toDateString(),
                'data_scadenza' => $scaduta ? now()->subMonths(3)->toDateString() : now()->endOfYear()->toDateString(),
                'stato' => Tessera::STATO_ATTIVA,
                'tipo_scadenza' => Tessera::TIPO_SCADENZA_SOLARE,
            ]);
        }

        $quota = null;
        if ($this->option('con-quota')) {
            $quota = Quota::withoutGlobalScopes()->create([
                'associazione_id' => $associazione->id,
                'socio_id' => $socio->id,
                'anno' => now()->year,
                'importo' => 25.00,
                'data_scadenza' => $this->option('quota-scadenza') ?: now()->endOfYear()->toDateString(),
                'stato' => Quota::STATO_DA_PAGARE,
                'tipo' => 'Quota Annuale',
            ]);
        }

        return [
            'socio_id' => $socio->id,
            'numero_socio' => $socio->numero_socio,
            'tessera_id' => $tessera?->id,
            'numero_tessera' => $tessera?->numero_tessera,
            'quota_id' => $quota?->id,
        ];
    }

    /**
     * URL pubblico di verifica della tessera --tessera (lo stesso percorso
     * contenuto nei QR: l'UUID funge da capability token).
     *
     * @return array<string, mixed>
     */
    private function linkVerificaTessera(): array
    {
        $tesseraId = (string) $this->option('tessera');
        if ($tesseraId === '') {
            return ['error' => 'serve --tessera'];
        }

        return ['url' => route('tessere.verifica', ['tessera' => $tesseraId])];
    }

    /**
     * Imposta la password del socio --email (cast 'hashed' sul model),
     * come farebbe il flusso set-password dell'area soci.
     *
     * @return array<string, mixed>
     */
    private function setPasswordSocio(): array
    {
        $socio = Socio::withoutGlobalScopes()
            ->where('email', (string) $this->option('email'))
            ->first();

        if ($socio === null) {
            return ['error' => 'socio non trovato'];
        }

        $socio->forceFill([
            'password_hash' => $this->option('password') ?: 'SocioE2e!2026',
        ])->save();

        return ['ok' => true, 'socio_id' => $socio->id];
    }

    /**
     * Configura il modulo di iscrizione del tenant e2e:
     * --aperte=0|1, --modalita=preiscrizione|diretta,
     * --intro="<p>...</p>", --config='{json iscrizione_form_config}'.
     * Le opzioni omesse ripristinano il default (config null, aperte,
     * preiscrizione) così ogni test parte da uno stato noto.
     *
     * @return array<string, mixed>
     */
    private function configuraFormIscrizione(): array
    {
        $associazione = Associazione::where('slug', 'e2e')->firstOrFail();

        $config = null;
        if (is_string($this->option('config')) && $this->option('config') !== '') {
            $config = json_decode((string) $this->option('config'), true);
            if (! is_array($config)) {
                return ['error' => 'config non è JSON valido'];
            }
        }

        if (is_string($this->option('intro')) && $this->option('intro') !== '') {
            $config = ($config ?? []) + ['intro_html' => (string) $this->option('intro')];
        }

        $associazione->iscrizioni()->aggiorna([
            'iscrizioni_socio_aperte' => $this->option('aperte') === null || $this->option('aperte') === ''
                ? true
                : (bool) (int) $this->option('aperte'),
            'modalita_iscrizione' => in_array($this->option('modalita'), ['preiscrizione', 'diretta'], true)
                ? $this->option('modalita')
                : 'preiscrizione',
            'iscrizione_form_config' => $config,
        ]);

        return ['ok' => true, 'associazione_id' => $associazione->id];
    }

    /**
     * Stato dell'ultima richiesta di iscrizione dell'account --email
     * (con lo stato del socio collegato, per le assertion E2E).
     *
     * @return array<string, mixed>
     */
    private function statoRichiesta(): array
    {
        $account = $this->trovaAccount();
        if ($account === null) {
            return ['error' => 'account non trovato'];
        }

        $richiesta = RichiestaIscrizione::withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->latest()
            ->first();

        if ($richiesta === null) {
            return ['error' => 'nessuna richiesta trovata'];
        }

        $socio = $richiesta->socio_id !== null
            ? Socio::withoutGlobalScopes()->find($richiesta->socio_id)
            : null;

        return [
            'stato' => $richiesta->stato,
            'motivo_rifiuto' => $richiesta->motivo_rifiuto,
            'socio_stato' => $socio?->stato,
            'numero_socio' => $socio?->numero_socio,
            'account_socio_id' => $account->fresh()?->socio_id,
            'referral_codice' => $richiesta->referral_codice,
        ];
    }

    /**
     * Approva (come admin e2e) l'ultima richiesta in attesa dell'account
     * --email; --genera-tessera=1 emette anche la tessera dell'anno.
     *
     * @return array<string, mixed>
     */
    private function approvaRichiesta(): array
    {
        $richiesta = $this->richiestaInAttesa();
        if ($richiesta === null) {
            return ['error' => 'nessuna richiesta in attesa'];
        }

        $admin = Utente::where('email', 'admin-e2e@example.com')->firstOrFail();

        $richiesta = app(ApprovazioneIscrizioneService::class)
            ->approva($richiesta, $admin, (bool) $this->option('genera-tessera'));

        return ['ok' => true, 'richiesta_id' => $richiesta->id];
    }

    /**
     * Rifiuta (come admin e2e) l'ultima richiesta in attesa dell'account
     * --email con il motivo --motivo.
     *
     * @return array<string, mixed>
     */
    private function rifiutaRichiesta(): array
    {
        $richiesta = $this->richiestaInAttesa();
        if ($richiesta === null) {
            return ['error' => 'nessuna richiesta in attesa'];
        }

        $admin = Utente::where('email', 'admin-e2e@example.com')->firstOrFail();

        $richiesta = app(ApprovazioneIscrizioneService::class)
            ->rifiuta($richiesta, $admin, (string) ($this->option('motivo') ?: 'Motivo di test E2E'));

        return ['ok' => true, 'richiesta_id' => $richiesta->id];
    }

    /**
     * Collega l'account --email al socio --socio (stesso tenant e2e):
     * abilita rinnovo dall'area account ed eventi solo_soci.
     *
     * @return array<string, mixed>
     */
    private function collegaAccountSocio(): array
    {
        $account = $this->trovaAccount();
        if ($account === null) {
            return ['error' => 'account non trovato'];
        }

        $socio = Socio::withoutGlobalScopes()->find((string) $this->option('socio'));
        if ($socio === null) {
            return ['error' => 'socio non trovato'];
        }

        $account->forceFill(['socio_id' => $socio->id])->save();

        return ['ok' => true, 'account_id' => $account->id, 'socio_id' => $socio->id];
    }

    /**
     * Registra come pagata la quota --quota (data_pagamento oggi, metodo
     * Contanti), come farebbe la registrazione manuale dell'admin. Serve
     * ai test sulla ricevuta numerata scaricabile dalle aree riservate.
     * Come in produzione (PaymentService), il pagamento genera anche il
     * movimento automatico di prima nota (f.13), idempotente.
     *
     * @return array<string, mixed>
     */
    private function pagaQuota(): array
    {
        $quota = Quota::withoutGlobalScopes()->find((string) $this->option('quota'));
        if ($quota === null) {
            return ['error' => 'quota non trovata'];
        }

        $quota->forceFill([
            'stato' => Quota::STATO_PAGATA,
            'data_pagamento' => now()->toDateString(),
            'metodo_pagamento' => 'Contanti',
        ])->save();

        $movimento = app(PrimaNotaService::class)->registraIncassoQuotaBestEffort($quota->id);

        return ['ok' => true, 'quota_id' => $quota->id, 'movimento_id' => $movimento?->id];
    }

    /**
     * Prepara una richiesta di feedback post-evento per l'account --email
     * sull'evento --evento: prenotazione confermata + check-in + riga
     * feedback con token, e ritorna l'URL FIRMATO del form pubblico
     * (lo stesso link che arriverebbe via email).
     *
     * @return array<string, mixed>
     */
    private function linkFeedback(): array
    {
        $account = $this->trovaAccount();
        if ($account === null) {
            return ['error' => 'account non trovato'];
        }

        $evento = Evento::withoutGlobalScopes()->find((string) $this->option('evento'));
        if ($evento === null) {
            return ['error' => 'evento non trovato'];
        }

        $prenotazione = app(PrenotazioneService::class)->crea($account, $evento, 1);
        $prenotazione->forceFill(['checkin_at' => now()])->save();

        $feedback = FeedbackEvento::create([
            'associazione_id' => $prenotazione->associazione_id,
            'evento_id' => $prenotazione->evento_id,
            'prenotazione_id' => $prenotazione->id,
            'token' => FeedbackEvento::generaToken(),
            'inviato_at' => now(),
        ]);

        return [
            'url' => URL::signedRoute('feedback-eventi.show', ['token' => $feedback->token]),
            'token' => $feedback->token,
            'prenotazione_id' => $prenotazione->id,
        ];
    }

    /**
     * Occorrenze della serie ricorrente individuata dal --titolo
     * (assertion E2E sulla generazione bulk, f.8).
     *
     * @return array<string, mixed>
     */
    private function contaSerie(): array
    {
        $eventi = Evento::withoutGlobalScopes()
            ->where('titolo', (string) $this->option('titolo'))
            ->orderBy('data_evento')
            ->get(['id', 'serie_id', 'data_evento']);

        return [
            'occorrenze' => $eventi->count(),
            'serie_id' => $eventi->first()?->serie_id,
            'primo_evento_id' => $eventi->first()?->id,
            'date' => $eventi->map(fn (Evento $e) => $e->data_evento->toDateString())->all(),
        ];
    }

    /**
     * Stato corrente del socio --email (assertion E2E su cessazioni e merge).
     *
     * @return array<string, mixed>
     */
    private function statoSocio(): array
    {
        $socio = Socio::withoutGlobalScopes()
            ->where('email', (string) $this->option('email'))
            ->orderByDesc('created_at')
            ->first();

        if ($socio === null) {
            return ['error' => 'socio non trovato'];
        }

        return [
            'socio_id' => $socio->id,
            'stato' => $socio->stato,
            'numero_socio' => $socio->numero_socio,
        ];
    }

    /**
     * Crea un documento pubblicato sulla pagina Trasparenza del tenant e2e
     * (addendum): file fisico sul disk 'local' (fallback percorso_file)
     * con --categoria (default 'bilancio') e --titolo come nome file.
     *
     * @return array<string, mixed>
     */
    private function creaDocumentoPubblico(): array
    {
        $associazione = Associazione::where('slug', 'e2e')->firstOrFail();

        $categoria = (string) ($this->option('categoria') ?: 'bilancio');
        if (! in_array($categoria, Documento::CATEGORIE_PUBBLICHE, true)) {
            return ['error' => 'categoria non valida'];
        }

        $nomeFile = (string) ($this->option('titolo') ?: 'documento-e2e-'.now()->timestamp.'.pdf');
        $contenuto = '%PDF-1.4 documento fittizio della suite E2E '.now()->timestamp;
        $percorso = 'e2e-trasparenza/'.Str::uuid().'.pdf';

        Storage::disk('local')->put($percorso, $contenuto);

        $documento = Documento::withoutGlobalScopes()->create([
            'associazione_id' => $associazione->id,
            'nome_file' => $nomeFile,
            'percorso_file' => $percorso,
            'descrizione' => 'Documento di trasparenza generato dalla suite E2E.',
            'dimensione_kb' => (int) ceil(strlen($contenuto) / 1024),
            'pubblico' => true,
            'categoria_pubblica' => $categoria,
        ]);

        return [
            'documento_id' => $documento->id,
            'nome_file' => $documento->nome_file,
            'categoria' => $categoria,
        ];
    }

    /**
     * Crea una convocazione di assemblea pubblicata con evento collegato
     * (pubblicato, futuro) per il tenant e2e (addendum).
     *
     * @return array<string, mixed>
     */
    private function creaConvocazione(): array
    {
        $associazione = Associazione::where('slug', 'e2e')->firstOrFail();

        $evento = Evento::withoutGlobalScopes()->create([
            'associazione_id' => $associazione->id,
            'titolo' => 'Assemblea E2E '.now()->timestamp,
            'descrizione' => 'Evento assemblea generato dalla suite E2E.',
            'data_evento' => now()->addDays(12)->setTime(21, 0),
            'luogo' => 'Sede E2E',
            'stato_pubblicazione' => 'pubblicato',
            'iscrizioni_aperte' => true,
        ]);

        $annuncio = Annuncio::withoutGlobalScopes()->create([
            'associazione_id' => $associazione->id,
            'titolo' => (string) ($this->option('titolo') ?: 'Convocazione assemblea E2E '.now()->timestamp),
            'tipo' => Annuncio::TIPO_CONVOCAZIONE,
            'corpo_html' => '<p>Si convoca l\'assemblea dei soci (suite E2E).</p>',
            'data_assemblea' => now()->addDays(12)->setTime(21, 0),
            'luogo_assemblea' => 'Sede E2E, Via dei Test 1',
            'ordine_del_giorno' => "1. Bilancio\n2. Varie ed eventuali",
            'evento_id' => $evento->id,
            'pubblicato_dal' => now()->subDay(),
        ]);

        return [
            'annuncio_id' => $annuncio->id,
            'titolo' => $annuncio->titolo,
            'evento_id' => $evento->id,
            'evento_titolo' => $evento->titolo,
        ];
    }

    /* =====================================================================
     | Organizzazione 3 (round 3) — fixture per i moduli A–E
     ===================================================================== */

    /** Crea una mansione del tenant e2e. @return array<string, mixed> */
    private function creaMansione(): array
    {
        $associazione = Associazione::where('slug', 'e2e')->firstOrFail();

        $mansione = Mansione::withoutGlobalScopes()->create([
            'associazione_id' => $associazione->id,
            'nome' => (string) ($this->option('titolo') ?: 'Mansione E2E '.now()->timestamp),
            'descrizione' => 'Mansione generata dalla suite E2E.',
        ]);

        return ['mansione_id' => $mansione->id, 'nome' => $mansione->nome];
    }

    /**
     * Crea un turno aperto futuro del tenant e2e (f.3). Opzioni:
     * --titolo, --posti (default 2), --auto-conferma=1, --mansione=ID,
     * --spazio=ID, --progetto=ID.
     *
     * @return array<string, mixed>
     */
    private function creaTurno(): array
    {
        $associazione = Associazione::where('slug', 'e2e')->firstOrFail();

        $turno = Turno::withoutGlobalScopes()->create([
            'associazione_id' => $associazione->id,
            'titolo' => (string) ($this->option('titolo') ?: 'Turno E2E '.now()->timestamp),
            'data' => now()->addDays(5)->toDateString(),
            'ora_inizio' => '09:00',
            'ora_fine' => '12:00',
            'posti' => $this->option('posti') !== null ? (int) $this->option('posti') : 2,
            'mansione_id' => $this->option('mansione') ?: null,
            'spazio_id' => $this->option('spazio') ?: null,
            'progetto_id' => $this->option('progetto') ?: null,
            'note' => 'Turno generato dalla suite E2E.',
            'stato' => Turno::STATO_APERTO,
            'auto_conferma' => (bool) $this->option('auto-conferma'),
        ]);

        return ['turno_id' => $turno->id, 'titolo' => $turno->titolo];
    }

    /**
     * Stato delle assegnazioni del turno --turno (assertion E2E su
     * candidatura/conferma e ore registrate).
     *
     * @return array<string, mixed>
     */
    private function statoTurno(): array
    {
        $turno = Turno::withoutGlobalScopes()->find((string) $this->option('turno'));
        if ($turno === null) {
            return ['error' => 'turno non trovato'];
        }

        $assegnazioni = TurnoAssegnazione::withoutGlobalScopes()
            ->where('turno_id', $turno->id)
            ->with(['socio' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderBy('created_at')
            ->get()
            ->map(fn (TurnoAssegnazione $a) => [
                'socio_email' => $a->socio?->email,
                'stato' => $a->stato,
                'ore_effettive' => $a->ore_effettive,
                'motivo_rinuncia' => $a->motivo_rinuncia,
            ])
            ->all();

        return [
            'turno_id' => $turno->id,
            'stato' => $turno->stato,
            'assegnazioni' => $assegnazioni,
        ];
    }

    /**
     * Marca il socio --email come volontario attivo con disponibilità piena
     * (così compare tra i suggeriti, f.1/f.3).
     *
     * @return array<string, mixed>
     */
    private function marcaVolontario(): array
    {
        $socio = Socio::withoutGlobalScopes()
            ->where('email', (string) $this->option('email'))
            ->first();
        if ($socio === null) {
            return ['error' => 'socio non trovato'];
        }

        $socio->forceFill([
            'volontario_attivo' => true,
            'disponibilita' => [
                'lun' => ['mattina', 'pomeriggio', 'sera'],
                'mar' => ['mattina', 'pomeriggio', 'sera'],
                'mer' => ['mattina', 'pomeriggio', 'sera'],
                'gio' => ['mattina', 'pomeriggio', 'sera'],
                'ven' => ['mattina', 'pomeriggio', 'sera'],
                'sab' => ['mattina', 'pomeriggio', 'sera'],
                'dom' => ['mattina', 'pomeriggio', 'sera'],
            ],
        ])->save();

        if ($this->option('mansione')) {
            $socio->mansioni()->syncWithoutDetaching([(string) $this->option('mansione')]);
        }

        return ['ok' => true, 'socio_id' => $socio->id];
    }

    /** Crea uno spazio attivo del tenant e2e (f.7). @return array<string, mixed> */
    private function creaSpazio(): array
    {
        $associazione = Associazione::where('slug', 'e2e')->firstOrFail();

        $spazio = Spazio::withoutGlobalScopes()->create([
            'associazione_id' => $associazione->id,
            'nome' => (string) ($this->option('titolo') ?: 'Sala E2E '.now()->timestamp),
            'descrizione' => 'Spazio generato dalla suite E2E.',
            'capienza' => 30,
            'attivo' => true,
        ]);

        return ['spazio_id' => $spazio->id, 'nome' => $spazio->nome];
    }

    /**
     * Crea una prenotazione esistente sullo spazio --spazio in un orario
     * noto (per testare il conflitto di overlap, f.8). Ritorna l'orario.
     *
     * @return array<string, mixed>
     */
    private function creaPrenotazioneSpazio(): array
    {
        $associazione = Associazione::where('slug', 'e2e')->firstOrFail();
        $spazio = Spazio::withoutGlobalScopes()->find((string) $this->option('spazio'));
        if ($spazio === null) {
            return ['error' => 'spazio non trovato'];
        }

        // Slot fisso: domani 10:00–12:00 (locale app), così il test può
        // tentare un overlap 11:00–13:00.
        $dal = now()->addDay()->setTime(10, 0);
        $al = now()->addDay()->setTime(12, 0);

        $prenotazione = PrenotazioneSpazio::withoutGlobalScopes()->create([
            'associazione_id' => $associazione->id,
            'spazio_id' => $spazio->id,
            'titolo' => (string) ($this->option('titolo') ?: 'Prova Coro'),
            'dal' => $dal,
            'al' => $al,
            'note' => 'Prenotazione esistente generata dalla suite E2E.',
        ]);

        return [
            'prenotazione_id' => $prenotazione->id,
            'titolo' => $prenotazione->titolo,
            'dal' => $dal->format('Y-m-d\TH:i'),
            'al' => $al->format('Y-m-d\TH:i'),
            // Overlap suggerito per il test (11:00–13:00 dello stesso giorno)
            'overlap_dal' => now()->addDay()->setTime(11, 0)->format('Y-m-d\TH:i'),
            'overlap_al' => now()->addDay()->setTime(13, 0)->format('Y-m-d\TH:i'),
        ];
    }

    /**
     * Crea un'attrezzatura in inventario (f.9). Con --tutto-in-prestito=1
     * registra un prestito attivo che esaurisce la disponibilità (f.10):
     * il form prestiti la mostrerà come 0 disponibili. Serve un --socio.
     *
     * @return array<string, mixed>
     */
    private function creaAttrezzatura(): array
    {
        $associazione = Associazione::where('slug', 'e2e')->firstOrFail();
        $quantita = $this->option('quantita') !== null ? (int) $this->option('quantita') : 2;

        $attrezzatura = Attrezzatura::withoutGlobalScopes()->create([
            'associazione_id' => $associazione->id,
            'nome' => (string) ($this->option('titolo') ?: 'Proiettore E2E '.now()->timestamp),
            'descrizione' => 'Attrezzatura generata dalla suite E2E.',
            'quantita' => $quantita,
            'stato' => Attrezzatura::STATO_BUONO,
        ]);

        if ($this->option('tutto-in-prestito')) {
            $socio = Socio::withoutGlobalScopes()->find((string) $this->option('socio'));
            if ($socio === null) {
                return ['error' => 'serve --socio per --tutto-in-prestito'];
            }

            PrestitoAttrezzatura::withoutGlobalScopes()->create([
                'associazione_id' => $associazione->id,
                'attrezzatura_id' => $attrezzatura->id,
                'socio_id' => $socio->id,
                'quantita' => $quantita,
                'dal' => now()->toDateString(),
                'restituzione_prevista' => now()->addWeek()->toDateString(),
                'note' => 'Prestito che esaurisce la disponibilità (E2E).',
            ]);
        }

        return [
            'attrezzatura_id' => $attrezzatura->id,
            'nome' => $attrezzatura->nome,
            'disponibile' => $attrezzatura->fresh()->quantitaDisponibile(),
        ];
    }

    /**
     * Crea un progetto del tenant e2e (f.12). Con --budget imposta il budget.
     *
     * @return array<string, mixed>
     */
    private function creaProgetto(): array
    {
        $associazione = Associazione::where('slug', 'e2e')->firstOrFail();
        $admin = Utente::where('email', 'admin-e2e@example.com')->firstOrFail();

        $progetto = Progetto::withoutGlobalScopes()->create([
            'associazione_id' => $associazione->id,
            'nome' => (string) ($this->option('titolo') ?: 'Progetto E2E '.now()->timestamp),
            'descrizione' => 'Progetto generato dalla suite E2E.',
            'dal' => now()->startOfYear()->toDateString(),
            'al' => now()->endOfYear()->toDateString(),
            'responsabile_id' => $admin->id,
            'stato' => Progetto::STATO_ATTIVO,
            'budget' => $this->option('budget') !== null ? (float) $this->option('budget') : null,
        ]);

        return ['progetto_id' => $progetto->id, 'nome' => $progetto->nome];
    }

    /**
     * Aggiunge un movimento di prima nota imputato al progetto --progetto
     * (f.13). --tipo=entrata|uscita (default entrata), --importo (default 100).
     *
     * @return array<string, mixed>
     */
    private function creaMovimentoProgetto(): array
    {
        $associazione = Associazione::where('slug', 'e2e')->firstOrFail();
        $progetto = Progetto::withoutGlobalScopes()->find((string) $this->option('progetto'));
        if ($progetto === null) {
            return ['error' => 'progetto non trovato'];
        }

        $tipo = in_array($this->option('tipo'), [Movimento::TIPO_ENTRATA, Movimento::TIPO_USCITA], true)
            ? (string) $this->option('tipo')
            : Movimento::TIPO_ENTRATA;

        $movimento = Movimento::withoutGlobalScopes()->create([
            'associazione_id' => $associazione->id,
            'tipo' => $tipo,
            'importo' => $this->option('importo') !== null ? (float) $this->option('importo') : 100.0,
            'data' => now()->toDateString(),
            'progetto_id' => $progetto->id,
            'descrizione' => (string) ($this->option('titolo') ?: 'Contributo progetto E2E'),
            'metodo' => 'Contanti',
        ]);

        return ['movimento_id' => $movimento->id, 'progetto_id' => $progetto->id, 'tipo' => $tipo];
    }

    /**
     * Crea una riunione del direttivo (f.17-18). Con --svolta=1 lo stato è
     * "svolta" (abilita votazioni e bozza verbale) e, con un partecipante
     * confermato, la generazione del verbale include i presenti. Con
     * --con-carica=1 crea un socio con carica attiva (per testare la
     * convocazione che genera partecipanti dagli intestatari delle cariche).
     *
     * @return array<string, mixed>
     */
    private function creaRiunione(): array
    {
        $associazione = Associazione::where('slug', 'e2e')->firstOrFail();
        $svolta = (bool) $this->option('svolta');

        $riunione = Riunione::withoutGlobalScopes()->create([
            'associazione_id' => $associazione->id,
            'titolo' => (string) ($this->option('titolo') ?: 'Direttivo E2E '.now()->timestamp),
            'data_ora' => $svolta ? now()->subDay()->setTime(21, 0) : now()->addDays(7)->setTime(21, 0),
            'luogo' => 'Sede E2E',
            'odg' => [
                ['punto' => 'Approvazione bilancio', 'descrizione' => 'Voto sul bilancio annuale.'],
                ['punto' => 'Varie ed eventuali', 'descrizione' => ''],
            ],
            'stato' => $svolta ? Riunione::STATO_SVOLTA : Riunione::STATO_PIANIFICATA,
        ]);

        $caricaSocioId = null;
        if ($this->option('con-carica') || $svolta) {
            $socio = Socio::withoutGlobalScopes()->create([
                'associazione_id' => $associazione->id,
                'numero_socio' => app(NumeroProgressivoService::class)->prossimoNumeroSocio($associazione->id),
                'nome' => 'Presidente',
                'cognome' => 'E2E',
                'email' => 'presidente-'.now()->timestamp.'@example.com',
                'data_iscrizione' => now()->toDateString(),
                'stato' => Socio::STATO_ATTIVO,
                'privacy_consenso' => true,
            ]);
            $caricaSocioId = $socio->id;

            Carica::withoutGlobalScopes()->create([
                'associazione_id' => $associazione->id,
                'socio_id' => $socio->id,
                'ruolo' => Carica::RUOLI[0],
                'inizio' => now()->subMonth()->toDateString(),
                'fine' => null,
            ]);

            if ($svolta) {
                // Partecipante confermato → presente nel verbale generato
                RiunionePartecipante::withoutGlobalScopes()->create([
                    'associazione_id' => $associazione->id,
                    'riunione_id' => $riunione->id,
                    'socio_id' => $socio->id,
                    'carica_ruolo' => Carica::RUOLI[0],
                    'stato' => RiunionePartecipante::STATO_CONFERMATO,
                ]);
            }
        }

        return [
            'riunione_id' => $riunione->id,
            'titolo' => $riunione->titolo,
            'stato' => $riunione->stato,
            'carica_socio_id' => $caricaSocioId,
        ];
    }

    /**
     * Stato della riunione --riunione: presenza/assenza di verbale e
     * conteggio votazioni (assertion E2E su bozza verbale, f.18).
     *
     * @return array<string, mixed>
     */
    private function statoRiunione(): array
    {
        $riunione = Riunione::withoutGlobalScopes()
            ->with('verbale')
            ->find((string) $this->option('riunione'));
        if ($riunione === null) {
            return ['error' => 'riunione non trovata'];
        }

        $votazioni = VotazioneRiunione::withoutGlobalScopes()
            ->where('riunione_id', $riunione->id)
            ->count();

        return [
            'riunione_id' => $riunione->id,
            'stato' => $riunione->stato,
            'verbale_id' => $riunione->verbale_id,
            'verbale_numero' => $riunione->verbale?->numero_completo,
            'votazioni' => $votazioni,
            'partecipanti' => RiunionePartecipante::withoutGlobalScopes()->where('riunione_id', $riunione->id)->count(),
        ];
    }

    /**
     * Crea un adempimento ricorrente attivo del tenant e2e (f.20).
     * --mese (1-12, default mese corrente).
     *
     * @return array<string, mixed>
     */
    private function seedAdempimenti(): array
    {
        $associazione = Associazione::where('slug', 'e2e')->firstOrFail();

        $adempimento = Adempimento::withoutGlobalScopes()->create([
            'associazione_id' => $associazione->id,
            'titolo' => (string) ($this->option('titolo') ?: 'Assicurazione volontari E2E '.now()->timestamp),
            'descrizione' => 'Adempimento generato dalla suite E2E.',
            'mese_scadenza' => $this->option('mese') !== null ? (int) $this->option('mese') : (int) now()->format('n'),
            'attivo' => true,
        ]);

        return ['adempimento_id' => $adempimento->id, 'titolo' => $adempimento->titolo];
    }

    /**
     * Crea un contatto esterno (mini-CRM, f.21). --tipo (default fornitore),
     * --tag (CSV, opzionale).
     *
     * @return array<string, mixed>
     */
    private function creaContattoEsterno(): array
    {
        $associazione = Associazione::where('slug', 'e2e')->firstOrFail();

        $tipo = in_array($this->option('tipo'), ContattoEsterno::TIPI, true)
            ? (string) $this->option('tipo')
            : ContattoEsterno::TIPO_FORNITORE;

        $tag = null;
        if (is_string($this->option('tag')) && $this->option('tag') !== '') {
            $tag = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('tag')))));
        }

        $contatto = ContattoEsterno::withoutGlobalScopes()->create([
            'associazione_id' => $associazione->id,
            'tipo' => $tipo,
            'nome' => (string) ($this->option('titolo') ?: 'Contatto E2E '.now()->timestamp),
            'referente' => 'Mario Rossi',
            'email' => 'contatto-'.now()->timestamp.'@example.com',
            'telefono' => '0123456789',
            'note' => 'Contatto generato dalla suite E2E.',
            'tag' => $tag,
        ]);

        return ['contatto_id' => $contatto->id, 'nome' => $contatto->nome, 'tipo' => $tipo];
    }

    /**
     * Crea uno sponsor visibile sul sito e nel periodo (f.22), così da
     * comparire in "I nostri sostenitori" sulla home pubblica.
     * --livello (default oro), --visibile (default 1).
     *
     * @return array<string, mixed>
     */
    private function creaSponsor(): array
    {
        $associazione = Associazione::where('slug', 'e2e')->firstOrFail();

        $livello = in_array($this->option('livello'), Sponsor::LIVELLI, true)
            ? (string) $this->option('livello')
            : Sponsor::LIVELLO_ORO;

        $sponsor = Sponsor::withoutGlobalScopes()->create([
            'associazione_id' => $associazione->id,
            'nome' => (string) ($this->option('titolo') ?: 'Sponsor E2E '.now()->timestamp),
            'livello' => $livello,
            'dal' => now()->subMonth()->toDateString(),
            'al' => now()->addMonth()->toDateString(),
            'visibile_sul_sito' => $this->option('visibile') === null || (bool) (int) $this->option('visibile'),
            'sito_url' => 'https://example.com',
        ]);

        return ['sponsor_id' => $sponsor->id, 'nome' => $sponsor->nome, 'livello' => $livello];
    }

    /**
     * Restituisce il codice referral del socio --socio (f.23), per
     * costruire il link /a/{slug}/iscriviti?ref=CODICE.
     *
     * @return array<string, mixed>
     */
    private function referralSocio(): array
    {
        $socio = Socio::withoutGlobalScopes()->find((string) $this->option('socio'));
        if ($socio === null) {
            return ['error' => 'socio non trovato'];
        }

        return [
            'socio_id' => $socio->id,
            'codice_referral' => $socio->codice_referral,
        ];
    }

    /**
     * Crea un evento (passato) con galleria pubblica attiva e una foto
     * caricata via medialibrary (f.24): la pagina pubblica dell'evento
     * mostrerà la griglia "Galleria foto".
     *
     * @return array<string, mixed>
     */
    private function creaEventoGalleria(): array
    {
        $associazione = Associazione::where('slug', 'e2e')->firstOrFail();

        $evento = Evento::withoutGlobalScopes()->create([
            'associazione_id' => $associazione->id,
            'titolo' => (string) ($this->option('titolo') ?: 'Festa E2E '.now()->timestamp),
            'descrizione' => 'Evento con galleria generato dalla suite E2E.',
            'data_evento' => now()->subDays(10)->setTime(21, 0),
            'luogo' => 'Sala E2E',
            'stato_pubblicazione' => 'pubblicato',
            'iscrizioni_aperte' => false,
            'galleria_pubblica' => true,
        ]);

        // PNG reale 600x400 generato con GD: dimensioni sufficienti perché la
        // conversione 'thumb' (width 400, non-queued) vada a buon fine.
        $tmp = tempnam(sys_get_temp_dir(), 'e2e_galleria').'.png';
        if (function_exists('imagecreatetruecolor')) {
            $img = imagecreatetruecolor(600, 400);
            $colore = imagecolorallocate($img, 255, 123, 17); // brand orange
            imagefilledrectangle($img, 0, 0, 600, 400, $colore);
            imagepng($img, $tmp);
            imagedestroy($img);
        } else {
            // Fallback: PNG 1x1 (la vista pubblica ripiega sull'originale)
            file_put_contents($tmp, base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
            ));
        }

        $evento->addMedia($tmp)
            ->usingFileName('foto-e2e-'.now()->timestamp.'.png')
            ->toMediaCollection(Evento::COLLEZIONE_GALLERIA);

        return [
            'evento_id' => $evento->id,
            'titolo' => $evento->titolo,
            'foto' => $evento->getMedia(Evento::COLLEZIONE_GALLERIA)->count(),
        ];
    }

    private function richiestaInAttesa(): ?RichiestaIscrizione
    {
        $account = $this->trovaAccount();
        if ($account === null) {
            return null;
        }

        return RichiestaIscrizione::withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->where('stato', RichiestaIscrizione::STATO_IN_ATTESA)
            ->latest()
            ->first();
    }

    private function trovaAccount(): ?Account
    {
        return Account::withoutGlobalScopes()
            ->where('email', (string) $this->option('email'))
            ->first();
    }
}
