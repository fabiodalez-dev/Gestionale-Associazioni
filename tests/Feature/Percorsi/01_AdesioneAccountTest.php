<?php

namespace Tests\Feature\Percorsi;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\CampoPersonalizzato;
use App\Models\Quota;
use App\Models\RichiestaIscrizione;
use App\Models\RichiestaModificaDati;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\Utente;
use App\Models\ValoreCampoPersonalizzato;
use App\Notifications\Accounts\BenvenutoAccount;
use App\Notifications\Accounts\VerificaEmailAccount;
use App\Notifications\Iscrizioni\ConfermaRichiestaInviataNotification;
use App\Notifications\Iscrizioni\IscrizioneApprovataNotification;
use App\Notifications\Iscrizioni\IscrizioneRifiutataNotification;
use App\Notifications\Iscrizioni\RichiestaIscrizioneRicevutaNotification;
use chillerlan\QRCode\QRCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Percorsi reali — ADESIONE E ACCOUNT.
 *
 * Ogni test segue passo per passo quello che succede davvero in
 * un'associazione: la persona sul sito pubblico (/a/{slug}), l'admin nel
 * pannello, il socio nell'area soci. Più richieste HTTP in sequenza, con
 * verifiche sul database, sulle notifiche inviate e sul contenuto delle
 * pagine che ciascun attore vede.
 */
class Percorso01_AdesioneAccountTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Lampione-Viola-Treno-42';

    private const CF_VALIDO = 'RSSMRA85T10A562S';

    private Associazione $associazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        // Honeypot spento: qui simulo persone vere, non bot
        config(['honeypot.enabled' => false]);

        // Nel test client tutti gli attori condividono lo stesso IP: il
        // rate limiting per IP (coperto da AuthPubblicaTest) qui farebbe
        // solo rumore scambiando persone diverse per un unico abusatore
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->associazione = Associazione::factory()->create([
            'nome' => 'Circolo Arci La Lanterna',
            'frontend_pubblico_attivo' => true,
            'costo_tessera' => 25.00,
        ]);

        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Anna',
            'cognome' => 'Presidente',
        ]);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('tenant.pubblico');

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function urlTenant(string $path = ''): string
    {
        return '/a/'.$this->associazione->slug.($path !== '' ? '/'.ltrim($path, '/') : '');
    }

    /**
     * @return array<string, string>
     */
    private function datiRegistrazione(array $override = []): array
    {
        return array_merge([
            'nome' => 'Giulia',
            'cognome' => 'Verdi',
            'email' => 'giulia.verdi@example.com',
            'telefono' => '3331234567',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'privacy' => '1',
        ], $override);
    }

    /**
     * @return array<string, mixed> dati validi per il modulo di iscrizione di default
     */
    private function datiIscrizione(array $override = []): array
    {
        return array_merge([
            'telefono' => '3331234567',
            'codice_fiscale' => self::CF_VALIDO,
            'data_nascita' => '1985-12-10',
            'citta' => 'Milano',
        ], $override);
    }

    /**
     * Persona che si registra sul sito, chiude la scheda, apre dal telefono
     * il link ricevuto per email e poi accede con le sue credenziali: come
     * farebbe chiunque, non con un forceFill.
     */
    private function registraEVerifica(array $override = []): Account
    {
        $dati = $this->datiRegistrazione($override);

        $this->post($this->urlTenant('registrati'), $dati)
            ->assertRedirect($this->urlTenant('verifica-email'));

        /** @var Account $account */
        $account = Account::allTenants()->where('email', $dati['email'])->firstOrFail();

        // Chiude il browser senza verificare
        $this->post($this->urlTenant('logout'));
        $this->assertGuest('account');

        // Dal telefono apre il link dell'email: verificata, invitata ad accedere
        $this->get($this->linkDiVerifica($account))
            ->assertRedirect($this->urlTenant('login'))
            ->assertSessionHas('success');

        $account->refresh();
        $this->assertNotNull($account->email_verified_at, 'L\'email dovrebbe risultare verificata dopo il click sul link.');

        $this->post($this->urlTenant('login'), [
            'email' => $dati['email'],
            'password' => $dati['password'],
        ])->assertRedirect($this->urlTenant());

        $this->assertAuthenticatedAs($account, 'account');

        return $account->fresh();
    }

    /**
     * Recupera il link "Verifica email" dall'email effettivamente inviata
     * all'account (Notification::fake).
     */
    private function linkDiVerifica(Account $account): string
    {
        $link = null;

        Notification::assertSentTo(
            $account,
            VerificaEmailAccount::class,
            function (VerificaEmailAccount $notifica, array $canali, Account $destinatario) use (&$link): bool {
                $link = $notifica->toMail($destinatario)->actionUrl;

                return true;
            }
        );

        $this->assertIsString($link, 'L\'email di verifica deve contenere il link di conferma.');

        return $link;
    }

    private function socioDiAccount(Account $account): Socio
    {
        $this->assertNotNull($account->fresh()->socio_id, 'L\'account dovrebbe essere collegato a un socio.');

        return Socio::allTenants()->findOrFail($account->fresh()->socio_id);
    }

    // ------------------------------------------------------------------
    // 1. Registrazione, verifica email, accesso, dashboard
    // ------------------------------------------------------------------

    public function test_percorso_1_una_persona_si_registra_verifica_l_email_accede_e_vede_la_dashboard(): void
    {
        // Giulia arriva sul sito pubblico e apre la pagina di registrazione
        $this->get($this->urlTenant('registrati'))
            ->assertOk()
            ->assertSee('name="email"', false)
            ->assertSee('name="privacy"', false);

        // Compila il modulo: nasce l'account, è loggata ma non verificata
        $this->post($this->urlTenant('registrati'), $this->datiRegistrazione())
            ->assertRedirect($this->urlTenant('verifica-email'))
            ->assertSessionHas('success');

        /** @var Account $account */
        $account = Account::allTenants()->where('email', 'giulia.verdi@example.com')->firstOrFail();

        $this->assertSame($this->associazione->id, $account->associazione_id);
        $this->assertSame('Giulia', $account->nome);
        $this->assertSame('Verdi', $account->cognome);
        $this->assertNull($account->email_verified_at);
        $this->assertNotNull($account->privacy_accettata_at);
        $this->assertTrue(Hash::check(self::PASSWORD, $account->password));
        $this->assertAuthenticatedAs($account, 'account');

        // La pagina di avviso la invita a controllare la casella
        $this->get($this->urlTenant('verifica-email'))
            ->assertOk()
            ->assertSee('Verifica il tuo indirizzo email');

        // Finché non verifica, l'area riservata la rimanda all'avviso
        $this->get($this->urlTenant('account'))
            ->assertRedirect($this->urlTenant('verifica-email'));

        // Apre l'email ricevuta e clicca sul link
        $link = $this->linkDiVerifica($account);
        $this->assertStringContainsString($this->urlTenant('verifica-email/'.$account->id), $link);

        $this->get($link)
            ->assertRedirect($this->urlTenant())
            ->assertSessionHas('success', 'Email verificata con successo!');

        $this->assertNotNull($account->fresh()->email_verified_at);
        Notification::assertSentTo($account, BenvenutoAccount::class);

        // Esce e rientra con le credenziali scelte
        $this->post($this->urlTenant('logout'))->assertRedirect($this->urlTenant());
        $this->assertGuest('account');

        $this->post($this->urlTenant('login'), [
            'email' => 'giulia.verdi@example.com',
            'password' => self::PASSWORD,
        ])->assertRedirect($this->urlTenant());

        $this->assertAuthenticatedAs($account->fresh(), 'account');
        $this->assertNotNull($account->fresh()->ultimo_accesso_at);

        // La dashboard la saluta per nome e le propone di diventare socia
        $this->get($this->urlTenant('account'))
            ->assertOk()
            ->assertSee('Ciao, Giulia!')
            ->assertSee('Diventa socio')
            ->assertSee($this->urlTenant('iscriviti'));
    }

    // ------------------------------------------------------------------
    // 2. Modulo di iscrizione con i campi configurati
    // ------------------------------------------------------------------

    public function test_percorso_2_da_account_verificato_compila_il_modulo_di_iscrizione_e_gli_admin_vengono_avvisati(): void
    {
        $secondoAdmin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $adminDisattivo = Utente::factory()->adminAssociazione()->disattivo()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        // L'associazione ha configurato il modulo: telefono e data di nascita
        // obbligatori, città facoltativa, più il campo "Professione"
        $professione = CampoPersonalizzato::create([
            'associazione_id' => $this->associazione->id,
            'nome_campo' => 'Professione',
            'tipo_campo' => 'text',
            'ordine' => 0,
        ]);

        $this->associazione->update([
            'modalita_iscrizione' => Associazione::MODALITA_PREISCRIZIONE,
            'iscrizione_form_config' => [
                'intro_html' => '<p>Compila i dati per entrare nel circolo</p>',
                'campi_standard' => [
                    ['campo' => 'telefono', 'visibile' => true, 'obbligatorio' => true],
                    ['campo' => 'data_nascita', 'visibile' => true, 'obbligatorio' => true],
                    ['campo' => 'citta', 'visibile' => true, 'obbligatorio' => false],
                    ['campo' => 'codice_fiscale', 'visibile' => false, 'obbligatorio' => false],
                ],
                'campi_personalizzati' => [$professione->id],
            ],
        ]);

        $account = $this->registraEVerifica();

        // Giulia apre il modulo: vede l'intro e i campi configurati, non quelli nascosti
        $this->get($this->urlTenant('iscriviti'))
            ->assertOk()
            ->assertSee('Compila i dati per entrare nel circolo')
            ->assertSee('name="telefono"', false)
            ->assertSee('name="data_nascita"', false)
            ->assertSee('name="citta"', false)
            ->assertSee('Professione')
            ->assertDontSee('name="codice_fiscale"', false)
            // Nome, cognome ed email arrivano dall'account: non li richiede
            ->assertDontSee('name="nome"', false);

        // Prova a inviare senza il telefono obbligatorio: errore chiaro
        $this->from($this->urlTenant('iscriviti'))
            ->post($this->urlTenant('iscriviti'), [
                'data_nascita' => '1985-12-10',
                'citta' => 'Milano',
            ])
            ->assertRedirect($this->urlTenant('iscriviti'))
            ->assertSessionHasErrors('telefono');

        $this->assertSame(0, RichiestaIscrizione::allTenants()->count());

        // Completa e invia
        $this->post($this->urlTenant('iscriviti'), [
            'telefono' => '3331234567',
            'data_nascita' => '1985-12-10',
            'citta' => 'Milano',
            'custom_field' => [$professione->id => 'Insegnante'],
        ])
            ->assertRedirect($this->urlTenant('iscriviti'))
            ->assertSessionHas('success');

        // Nasce la richiesta in attesa, collegata all'account
        $richiesta = RichiestaIscrizione::allTenants()->where('account_id', $account->id)->sole();
        $this->assertSame(RichiestaIscrizione::STATO_IN_ATTESA, $richiesta->stato);
        $this->assertNull($richiesta->gestita_da);
        $this->assertSame('Insegnante', $richiesta->dati_form['custom_field'][$professione->id]);

        // Il socio preiscritto ha l'anagrafica dell'account + i dati del form
        $socio = Socio::allTenants()->findOrFail($richiesta->socio_id);
        $this->assertSame(Socio::STATO_IN_ATTESA, $socio->stato);
        $this->assertSame('Giulia', $socio->nome);
        $this->assertSame('Verdi', $socio->cognome);
        $this->assertSame('giulia.verdi@example.com', $socio->email);
        $this->assertSame('3331234567', $socio->telefono);
        $this->assertSame('1985-12-10', $socio->data_nascita->toDateString());
        $this->assertSame('Milano', $socio->citta);
        $this->assertNotNull($socio->numero_socio);
        $this->assertSame(
            'Insegnante',
            ValoreCampoPersonalizzato::query()->where('socio_id', $socio->id)->where('campo_id', $professione->id)->sole()->valore
        );

        // L'account NON è ancora socio: deciderà l'admin
        $this->assertNull($account->fresh()->socio_id);
        $this->assertNotNull($account->fresh()->richiesta_socio_at);

        // Gli admin attivi ricevono la notifica, quello disattivo no;
        // Giulia riceve la conferma di ricezione
        Notification::assertSentTo(
            [$this->admin, $secondoAdmin],
            RichiestaIscrizioneRicevutaNotification::class,
            fn (RichiestaIscrizioneRicevutaNotification $n) => $n->richiesta->is($richiesta)
        );
        Notification::assertNotSentTo($adminDisattivo, RichiestaIscrizioneRicevutaNotification::class);
        Notification::assertSentTo($account, ConfermaRichiestaInviataNotification::class);

        // Da adesso il modulo le mostra lo stato, e così la dashboard
        $this->get($this->urlTenant('iscriviti'))
            ->assertOk()
            ->assertSee('Richiesta in attesa di approvazione');

        $this->get($this->urlTenant('account'))
            ->assertOk()
            ->assertSee('Richiesta in attesa');
    }

    // ------------------------------------------------------------------
    // 3. L'admin vede i dati e approva con tessera e quota
    // ------------------------------------------------------------------

    public function test_percorso_3_l_admin_vede_i_dati_della_persona_e_approva_con_tessera_e_quota(): void
    {
        $professione = CampoPersonalizzato::create([
            'associazione_id' => $this->associazione->id,
            'nome_campo' => 'Professione',
            'tipo_campo' => 'text',
            'ordine' => 0,
        ]);

        $this->associazione->update([
            'iscrizione_form_config' => [
                'campi_standard' => [
                    ['campo' => 'telefono', 'visibile' => true, 'obbligatorio' => true],
                    ['campo' => 'data_nascita', 'visibile' => true, 'obbligatorio' => true],
                    ['campo' => 'citta', 'visibile' => true, 'obbligatorio' => true],
                ],
                'campi_personalizzati' => [$professione->id],
            ],
        ]);

        // Giulia si registra, verifica e invia la richiesta
        $account = $this->registraEVerifica();

        $this->post($this->urlTenant('iscriviti'), [
            'telefono' => '3331234567',
            'data_nascita' => '1985-12-10',
            'citta' => 'Milano',
            'custom_field' => [$professione->id => 'Insegnante'],
        ])->assertSessionHas('success');

        $richiesta = RichiestaIscrizione::allTenants()->where('account_id', $account->id)->sole();

        // Anna, presidente, apre le richieste di iscrizione: vede subito
        // CHI chiede di entrare, senza aprire altre schede
        $pagina = $this->actingAs($this->admin)->get('/richieste-iscrizione');

        $pagina->assertOk()
            ->assertSee('Verdi')
            ->assertSee('Giulia')
            ->assertSee('giulia.verdi@example.com')
            ->assertSee('3331234567')
            ->assertSee('10/12/1985')
            ->assertSee('Milano')
            ->assertSee('Professione')
            ->assertSee('Insegnante')
            ->assertSee(route('richieste-iscrizione.approva', $richiesta))
            ->assertSee(route('richieste-iscrizione.rifiuta', $richiesta));

        // Approva generando subito la tessera e la quota dell'anno
        $this->actingAs($this->admin)
            ->post(route('richieste-iscrizione.approva', $richiesta), [
                'genera_tessera' => '1',
                'genera_quota' => '1',
                'delibera_numero' => '7/'.now()->year,
                'delibera_data' => now()->toDateString(),
            ])
            ->assertRedirect('/richieste-iscrizione')
            ->assertSessionHas('success');

        // Richiesta chiusa da Anna, con la delibera
        $richiesta->refresh();
        $this->assertSame(RichiestaIscrizione::STATO_APPROVATA, $richiesta->stato);
        $this->assertSame($this->admin->id, $richiesta->gestita_da);
        $this->assertNotNull($richiesta->gestita_at);
        $this->assertSame('7/'.now()->year, $richiesta->delibera_numero);

        // Socio attivo e account collegato
        $socio = $this->socioDiAccount($account);
        $this->assertSame(Socio::STATO_ATTIVO, $socio->stato);
        $this->assertSame($richiesta->socio_id, $socio->id);

        // Tessera dell'anno corrente, attiva
        $tessera = Tessera::allTenants()->where('socio_id', $socio->id)->where('anno_validita', now()->year)->sole();
        $this->assertSame(Tessera::STATO_ATTIVA, $tessera->stato);

        // Quota dell'anno da pagare, con l'importo configurato
        $quota = Quota::allTenants()->where('socio_id', $socio->id)->where('anno', now()->year)->sole();
        $this->assertSame(25.0, (float) $quota->importo);
        $this->assertNull($quota->data_pagamento);

        // Email di benvenuto a Giulia con numero tessera e link di pagamento
        Notification::assertSentTo(
            $account,
            IscrizioneApprovataNotification::class,
            fn (IscrizioneApprovataNotification $n) => $n->numeroTessera === $tessera->numero_tessera
                && $n->linkPagamentoQuota !== null
        );

        // Lo storico admin registra l'esito con la delibera
        $this->actingAs($this->admin)->get('/richieste-iscrizione')
            ->assertOk()
            ->assertSee('Approvata')
            ->assertSee('Delibera n. 7/'.now()->year)
            ->assertSee('Anna Presidente');

        // Giulia, dalla sua area, è socia: vede tessera e quota da pagare
        $this->actingAs($account->fresh(), 'account')
            ->get($this->urlTenant('account'))
            ->assertOk()
            ->assertSee('Sei socio')
            ->assertDontSee('Diventa socio');

        $this->get($this->urlTenant('account/tessera'))
            ->assertOk()
            ->assertSee($tessera->numero_tessera)
            ->assertSee('Paga ora');
    }

    // ------------------------------------------------------------------
    // 4. L'admin rifiuta con motivo, il richiedente può riprovare
    // ------------------------------------------------------------------

    public function test_percorso_4_l_admin_rifiuta_con_motivo_e_la_persona_puo_inviare_una_nuova_richiesta(): void
    {
        $account = $this->registraEVerifica();

        $this->post($this->urlTenant('iscriviti'), $this->datiIscrizione())
            ->assertSessionHas('success');

        $richiesta = RichiestaIscrizione::allTenants()->where('account_id', $account->id)->sole();
        $socioPreiscrittoId = $richiesta->socio_id;
        $this->assertNotNull($socioPreiscrittoId);

        // Anna prova a rifiutare senza motivo: non si può
        $this->actingAs($this->admin)
            ->from('/richieste-iscrizione')
            ->post(route('richieste-iscrizione.rifiuta', $richiesta))
            ->assertRedirect('/richieste-iscrizione')
            ->assertSessionHasErrors('motivo');

        $this->assertSame(RichiestaIscrizione::STATO_IN_ATTESA, $richiesta->fresh()->stato);

        // Rifiuta spiegando il perché
        $this->actingAs($this->admin)
            ->post(route('richieste-iscrizione.rifiuta', $richiesta), [
                'motivo' => 'Il codice fiscale non corrisponde al documento inviato',
            ])
            ->assertRedirect('/richieste-iscrizione')
            ->assertSessionHas('success');

        $richiesta->refresh();
        $this->assertSame(RichiestaIscrizione::STATO_RIFIUTATA, $richiesta->stato);
        $this->assertSame('Il codice fiscale non corrisponde al documento inviato', $richiesta->motivo_rifiuto);
        $this->assertSame($this->admin->id, $richiesta->gestita_da);

        // Il socio preiscritto sparisce del tutto, l'account resta e può riprovare
        $this->assertDatabaseMissing('soci', ['id' => $socioPreiscrittoId]);
        $this->assertNotNull(Account::allTenants()->find($account->id));
        $this->assertNull($account->fresh()->socio_id);
        $this->assertNull($account->fresh()->richiesta_socio_at);

        // Giulia riceve l'email con il motivo
        Notification::assertSentTo(
            $account,
            IscrizioneRifiutataNotification::class,
            fn (IscrizioneRifiutataNotification $n) => $n->richiesta->motivo_rifiuto === 'Il codice fiscale non corrisponde al documento inviato'
        );

        // Nella sua area vede il rifiuto, il motivo e l'invito a riprovare
        $this->actingAs($account->fresh(), 'account')
            ->get($this->urlTenant('account'))
            ->assertOk()
            ->assertSee('Richiesta rifiutata')
            ->assertSee('Il codice fiscale non corrisponde al documento inviato')
            ->assertSee('Invia una nuova richiesta');

        // Il modulo è di nuovo compilabile e ricorda il motivo
        $this->get($this->urlTenant('iscriviti'))
            ->assertOk()
            ->assertSee('Il codice fiscale non corrisponde al documento inviato')
            ->assertSee('name="telefono"', false);

        // Riprova con i dati corretti: nasce una seconda richiesta in attesa
        $this->post($this->urlTenant('iscriviti'), $this->datiIscrizione(['codice_fiscale' => 'VRDGLI85T50F205P']))
            ->assertRedirect($this->urlTenant('iscriviti'))
            ->assertSessionHas('success');

        $richieste = RichiestaIscrizione::allTenants()->where('account_id', $account->id)->get();
        $this->assertCount(2, $richieste);
        $this->assertSame(RichiestaIscrizione::STATO_RIFIUTATA, $richieste->firstWhere('id', $richiesta->id)->stato);

        $nuova = $richieste->where('id', '!=', $richiesta->id)->sole();
        $this->assertSame(RichiestaIscrizione::STATO_IN_ATTESA, $nuova->stato);

        $nuovoSocio = Socio::allTenants()->findOrFail($nuova->socio_id);
        $this->assertSame(Socio::STATO_IN_ATTESA, $nuovoSocio->stato);
        $this->assertSame('VRDGLI85T50F205P', $nuovoSocio->codice_fiscale);

        // Un solo socio con quell'email nel tenant: niente doppioni
        $this->assertSame(1, Socio::allTenants()->where('email', 'giulia.verdi@example.com')->count());
    }

    // ------------------------------------------------------------------
    // 5. Modalità iscrizione "diretta"
    // ------------------------------------------------------------------

    public function test_percorso_5_in_modalita_diretta_la_persona_diventa_socia_subito_senza_admin(): void
    {
        $this->associazione->update(['modalita_iscrizione' => Associazione::MODALITA_DIRETTA]);

        $account = $this->registraEVerifica();

        // Invia il modulo: viene portata direttamente alla sua tessera
        $this->post($this->urlTenant('iscriviti'), $this->datiIscrizione())
            ->assertRedirect($this->urlTenant('account/tessera'))
            ->assertSessionHas('success');

        // La richiesta nasce già approvata, senza nessun admin dietro
        $richiesta = RichiestaIscrizione::allTenants()->where('account_id', $account->id)->sole();
        $this->assertSame(RichiestaIscrizione::STATO_APPROVATA, $richiesta->stato);
        $this->assertNull($richiesta->gestita_da);
        $this->assertNotNull($richiesta->gestita_at);

        // Socio attivo e collegato all'account
        $socio = $this->socioDiAccount($account);
        $this->assertSame(Socio::STATO_ATTIVO, $socio->stato);
        $this->assertSame($richiesta->socio_id, $socio->id);
        $this->assertSame(self::CF_VALIDO, $socio->codice_fiscale);

        // La quota dell'anno nasce subito, da pagare
        $quota = Quota::allTenants()->where('socio_id', $socio->id)->where('anno', now()->year)->sole();
        $this->assertSame(25.0, (float) $quota->importo);

        // Nessuna richiesta in attesa per l'admin, ma lo storico lo racconta
        $this->actingAs($this->admin)->get('/richieste-iscrizione')
            ->assertOk()
            ->assertSee('Nessuna richiesta in attesa')
            ->assertSee('Iscrizione diretta')
            ->assertSee('giulia.verdi@example.com');

        // Giulia riceve la conferma che l'iscrizione è già attiva
        Notification::assertSentTo(
            $account,
            ConfermaRichiestaInviataNotification::class,
            fn (ConfermaRichiestaInviataNotification $n) => $n->richiesta->isApprovata()
        );
        Notification::assertNotSentTo($account, IscrizioneApprovataNotification::class);

        // Dalla sua area è socia a tutti gli effetti: vede la quota dell'anno da pagare
        $this->actingAs($account->fresh(), 'account')
            ->get($this->urlTenant('account/tessera'))
            ->assertOk()
            ->assertSee('Quota Associativa')
            ->assertSee('25,00')
            ->assertSee('Da pagare');

        $this->get($this->urlTenant('iscriviti'))
            ->assertRedirect($this->urlTenant('account/tessera'));
    }

    // ------------------------------------------------------------------
    // 6. Doppioni: email già usata, richiesta doppia
    // ------------------------------------------------------------------

    public function test_percorso_6_email_gia_usata_e_richiesta_doppia_danno_errori_chiari_senza_duplicati(): void
    {
        $account = $this->registraEVerifica();
        $this->post($this->urlTenant('logout'));

        // Qualcuno prova a registrarsi di nuovo con la stessa email
        $this->from($this->urlTenant('registrati'))
            ->post($this->urlTenant('registrati'), $this->datiRegistrazione(['nome' => 'Impostore']))
            ->assertRedirect($this->urlTenant('registrati'))
            ->assertSessionHasErrors(['email' => 'Esiste già un account registrato con questo indirizzo email.']);

        $this->assertSame(1, Account::allTenants()->where('email', 'giulia.verdi@example.com')->count());
        $this->assertSame('Giulia', $account->fresh()->nome);
        $this->assertGuest('account');

        // Giulia rientra e invia la richiesta di iscrizione
        $this->post($this->urlTenant('login'), [
            'email' => 'giulia.verdi@example.com',
            'password' => self::PASSWORD,
        ])->assertRedirect($this->urlTenant());

        $this->post($this->urlTenant('iscriviti'), $this->datiIscrizione())
            ->assertSessionHas('success');

        // Per sbaglio la invia una seconda volta (doppio click, refresh...)
        $this->post($this->urlTenant('iscriviti'), $this->datiIscrizione())
            ->assertRedirect($this->urlTenant('iscriviti'))
            ->assertSessionHasErrors('iscrizione');

        $this->assertStringContainsString('già', mb_strtolower((string) session('errors')->first('iscrizione')));

        $this->assertSame(1, RichiestaIscrizione::allTenants()->where('account_id', $account->id)->count());
        $this->assertSame(1, Socio::allTenants()->where('email', 'giulia.verdi@example.com')->count());

        // Un ospite senza account prova a iscriversi con la stessa email
        $this->post($this->urlTenant('logout'));

        $this->from($this->urlTenant('iscriviti'))
            ->post($this->urlTenant('iscriviti'), $this->datiIscrizione([
                'nome' => 'Giulia',
                'cognome' => 'Verdi',
                'email' => 'giulia.verdi@example.com',
                'privacy' => '1',
            ]))
            ->assertRedirect($this->urlTenant('iscriviti'))
            ->assertSessionHasErrors('iscrizione');

        $this->assertSame(1, RichiestaIscrizione::allTenants()->count());
        $this->assertSame(1, Socio::allTenants()->count());

        // L'admin vede UNA sola richiesta in attesa
        $this->actingAs($this->admin)->get('/richieste-iscrizione')
            ->assertOk()
            ->assertSeeInOrder(['In attesa di approvazione', '1']);
    }

    // ------------------------------------------------------------------
    // 7. Socio esistente che si registra sul sito
    // ------------------------------------------------------------------

    public function test_percorso_7_un_socio_gia_in_anagrafica_si_registra_e_l_admin_collega_l_account(): void
    {
        // Anna aveva già inserito Giulia tra i soci, con la sua email
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Giulia',
            'cognome' => 'Verdi',
            'email' => 'giulia.verdi@example.com',
            'numero_socio' => '0042',
        ]);

        // Giulia si registra sul sito con la stessa email: l'account nasce
        // ma NON viene collegato da solo (sarebbe un furto di identità facile)
        $account = $this->registraEVerifica();
        $this->assertNull($account->socio_id);

        $this->get($this->urlTenant('account'))
            ->assertOk()
            ->assertSee('Diventa socio');

        // Se prova a iscriversi dal modulo, l'app le dice che è già socia
        $this->from($this->urlTenant('iscriviti'))
            ->post($this->urlTenant('iscriviti'), $this->datiIscrizione())
            ->assertRedirect($this->urlTenant('iscriviti'))
            ->assertSessionHasErrors('iscrizione');

        $this->assertSame(1, Socio::allTenants()->where('email', 'giulia.verdi@example.com')->count());

        // Anna apre la scheda dell'account: l'app suggerisce il match per email
        $this->actingAs($this->admin)
            ->get(route('accounts.show', $account))
            ->assertOk()
            ->assertSee('Match suggerito per email')
            ->assertSee('Verdi Giulia')
            ->assertSee('0042')
            ->assertSee(route('accounts.collega-socio', $account));

        // La lista account segnala che esiste un account non collegato
        $this->actingAs($this->admin)
            ->get('/accounts')
            ->assertOk()
            ->assertSee('giulia.verdi@example.com');

        // Anna conferma il collegamento
        $this->actingAs($this->admin)
            ->post(route('accounts.collega-socio', $account), ['socio_id' => $socio->id])
            ->assertRedirect(route('accounts.show', $account))
            ->assertSessionHas('success');

        $this->assertSame($socio->id, $account->fresh()->socio_id);

        // Un secondo collegamento sullo stesso account viene bloccato
        $altroSocio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)
            ->from(route('accounts.show', $account))
            ->post(route('accounts.collega-socio', $account), ['socio_id' => $altroSocio->id])
            ->assertSessionHasErrors('socio_id');

        $this->assertSame($socio->id, $account->fresh()->socio_id);

        // Giulia torna nella sua area: è socia, "diventa socio" non compare più
        $this->actingAs($account->fresh(), 'account')
            ->get($this->urlTenant('account'))
            ->assertOk()
            ->assertSee('Sei socio')
            ->assertSee('Tessera &amp; quote', false)
            ->assertDontSee('Diventa socio');

        $this->get($this->urlTenant('iscriviti'))
            ->assertRedirect($this->urlTenant('account/tessera'));

        $this->get($this->urlTenant('account/diventa-socio'))
            ->assertOk()
            ->assertSee('Sei già socio');

        $this->get($this->urlTenant('account/tessera'))->assertOk();
    }

    // ------------------------------------------------------------------
    // 8. Modifica dati dall'area soci
    // ------------------------------------------------------------------

    public function test_percorso_8_il_socio_chiede_la_modifica_dei_dati_e_l_admin_approva_poi_rifiuta(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci(self::PASSWORD)->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Marco',
            'cognome' => 'Bianchi',
            'email' => 'marco.bianchi@example.com',
            'telefono' => '3331112222',
            'citta' => 'Bergamo',
            'indirizzo' => 'Via Vecchia 1',
        ]);

        // Marco entra nell'area soci con le sue credenziali
        $this->post(route('area-soci.login.attempt'), [
            'email' => 'marco.bianchi@example.com',
            'password' => self::PASSWORD,
        ])->assertRedirect(route('area-soci.dashboard'));

        $this->assertAuthenticatedAs($socio, 'socio');

        // Apre "Richiedi modifica dati": il form è precompilato con i dati attuali
        $this->get(route('area-soci.modifica-dati'))
            ->assertOk()
            ->assertSee('Richiedi modifica dati')
            ->assertSee('3331112222')
            ->assertSee('Bergamo');

        // Ha cambiato casa e numero: propone le modifiche
        $this->post(route('area-soci.modifica-dati.store'), [
            'nome' => 'Marco',
            'telefono' => '3339998888',
            'citta' => 'Brescia',
            'indirizzo' => 'Via Nuova 12',
        ])
            ->assertRedirect(route('area-soci.modifica-dati'))
            ->assertSessionHas('success');

        $richiesta = RichiestaModificaDati::withoutGlobalScopes()->where('socio_id', $socio->id)->sole();
        $this->assertSame(RichiestaModificaDati::STATO_IN_ATTESA, $richiesta->stato);
        $this->assertEquals(
            ['telefono' => '3339998888', 'indirizzo' => 'Via Nuova 12', 'citta' => 'Brescia'],
            $richiesta->proposte
        );

        // La scheda non cambia finché l'admin non decide
        $this->assertSame('3331112222', $socio->fresh()->telefono);
        $this->assertSame('Bergamo', $socio->fresh()->citta);

        // Non può inviarne un'altra finché questa è in sospeso
        $this->post(route('area-soci.modifica-dati.store'), ['citta' => 'Cremona'])
            ->assertRedirect(route('area-soci.modifica-dati'))
            ->assertSessionHas('error');

        $this->assertSame(1, RichiestaModificaDati::withoutGlobalScopes()->where('socio_id', $socio->id)->count());

        // Anna vede il diff attuale → proposto e approva
        $this->actingAs($this->admin)->get('/richieste-modifica-dati')
            ->assertOk()
            ->assertSee('Marco Bianchi')
            ->assertSee('3331112222')
            ->assertSee('3339998888')
            ->assertSee('Bergamo')
            ->assertSee('Brescia')
            ->assertSee('Via Nuova 12');

        $this->actingAs($this->admin)
            ->post(route('richieste-modifica-dati.approva', $richiesta))
            ->assertRedirect('/richieste-modifica-dati')
            ->assertSessionHas('success');

        $socio->refresh();
        $this->assertSame('3339998888', $socio->telefono);
        $this->assertSame('Brescia', $socio->citta);
        $this->assertSame('Via Nuova 12', $socio->indirizzo);
        $this->assertSame('Marco', $socio->nome);

        $richiesta->refresh();
        $this->assertSame(RichiestaModificaDati::STATO_APPROVATA, $richiesta->stato);
        $this->assertSame($this->admin->id, $richiesta->gestita_da);

        $this->assertDatabaseHas('storico_attivita_socio', [
            'socio_id' => $socio->id,
            'tipo_attivita' => 'Modifica Dati',
            'utente_id' => $this->admin->id,
        ]);

        // Variante: Marco chiede di cambiare l'email con una già di un altro socio
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'segreteria@example.com',
        ]);

        $this->actingAs($socio, 'socio')
            ->from(route('area-soci.modifica-dati'))
            ->post(route('area-soci.modifica-dati.store'), ['email' => 'segreteria@example.com'])
            ->assertSessionHasErrors('email');

        // Poi propone una data di nascita che Anna non può verificare: rifiuto con motivo
        $this->actingAs($socio, 'socio')
            ->post(route('area-soci.modifica-dati.store'), ['data_nascita' => '1970-01-01'])
            ->assertSessionHas('success');

        $seconda = RichiestaModificaDati::withoutGlobalScopes()
            ->where('socio_id', $socio->id)
            ->where('stato', RichiestaModificaDati::STATO_IN_ATTESA)
            ->sole();

        $this->actingAs($this->admin)
            ->post(route('richieste-modifica-dati.rifiuta', $seconda), [
                'motivo' => 'Serve una copia del documento di identità',
            ])
            ->assertRedirect('/richieste-modifica-dati')
            ->assertSessionHas('success');

        $this->assertSame(RichiestaModificaDati::STATO_RIFIUTATA, $seconda->fresh()->stato);
        $this->assertNotSame('1970-01-01', $socio->fresh()->data_nascita?->toDateString());

        // Marco legge il motivo nella sua area
        $this->actingAs($socio, 'socio')
            ->get(route('area-soci.modifica-dati'))
            ->assertOk()
            ->assertSee('Serve una copia del documento di identità');
    }

    // ------------------------------------------------------------------
    // 9. Privacy: export e cancellazione dall'area account
    // ------------------------------------------------------------------

    public function test_percorso_9_il_socio_esporta_i_propri_dati_e_cancella_l_account_dall_area_privacy(): void
    {
        $account = $this->registraEVerifica();

        $this->post($this->urlTenant('iscriviti'), $this->datiIscrizione())
            ->assertSessionHas('success');

        // La pagina privacy promette export JSON e cancellazione definitiva
        $this->get($this->urlTenant('account/privacy'))
            ->assertOk()
            ->assertSee('Scarica i miei dati (JSON)')
            ->assertSee('Cancella definitivamente il mio account')
            ->assertSee(route('pubblico.account.privacy.export', $this->associazione->slug))
            ->assertSee(route('pubblico.account.privacy.cancella', $this->associazione->slug));

        // Export: un file JSON con i suoi dati, scaricabile
        $export = $this->get($this->urlTenant('account/privacy/export'));

        $export->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="dati-account-'.$this->associazione->slug.'.json"')
            ->assertJsonPath('associazione', 'Circolo Arci La Lanterna')
            ->assertJsonPath('account.nome', 'Giulia')
            ->assertJsonPath('account.cognome', 'Verdi')
            ->assertJsonPath('account.email', 'giulia.verdi@example.com')
            ->assertJsonPath('account.telefono', '3331234567')
            ->assertJsonPath('account.socio_collegato', false)
            ->assertJsonPath('prenotazioni', []);

        $this->assertNotNull($export->json('account.email_verificata_at'));
        $this->assertNotNull($export->json('account.privacy_accettata_at'));
        $this->assertNotNull($export->json('account.richiesta_socio_at'));

        // Cancellazione con la password sbagliata: niente succede
        $this->from($this->urlTenant('account/privacy'))
            ->post($this->urlTenant('account/privacy/cancella'), ['password' => 'non-e-questa'])
            ->assertRedirect($this->urlTenant('account/privacy'))
            ->assertSessionHasErrors('password');

        $this->assertFalse($account->fresh()->trashed());
        $this->assertAuthenticatedAs($account->fresh(), 'account');

        // Con la password giusta: anonimizzata, sloggata, a casa
        $this->post($this->urlTenant('account/privacy/cancella'), ['password' => self::PASSWORD])
            ->assertRedirect($this->urlTenant())
            ->assertSessionHas('success');

        $this->assertGuest('account');

        /** @var Account $anonimo */
        $anonimo = Account::allTenants()->withTrashed()->findOrFail($account->id);
        $this->assertTrue($anonimo->trashed());
        $this->assertSame('Account', $anonimo->nome);
        $this->assertSame('Anonimizzato', $anonimo->cognome);
        $this->assertStringEndsWith('@anonimo.invalid', $anonimo->email);
        $this->assertNull($anonimo->telefono);
        $this->assertNull($anonimo->email_verified_at);
        $this->assertFalse($anonimo->attivo);
        $this->assertFalse(Hash::check(self::PASSWORD, $anonimo->password));

        // Non può più entrare, né con la vecchia email né con quella anonima
        $this->from($this->urlTenant('login'))
            ->post($this->urlTenant('login'), ['email' => 'giulia.verdi@example.com', 'password' => self::PASSWORD])
            ->assertSessionHasErrors('email');
        $this->assertGuest('account');

        // L'email è libera: la stessa persona potrà registrarsi di nuovo
        $this->post($this->urlTenant('registrati'), $this->datiRegistrazione())
            ->assertRedirect($this->urlTenant('verifica-email'));

        $this->assertSame(
            1,
            Account::allTenants()->where('email', 'giulia.verdi@example.com')->whereNull('deleted_at')->count()
        );

        // Lato admin la richiesta resta, ma senza più l'identità di Giulia
        $this->actingAs($this->admin)->get('/richieste-iscrizione')
            ->assertOk()
            ->assertSee('Anonimizzato')
            ->assertDontSee('Giulia');
    }

    // ------------------------------------------------------------------
    // 10. Account disattivato/riattivato, invito QR
    // ------------------------------------------------------------------

    public function test_percorso_10_account_disattivato_non_entra_riattivato_torna_e_il_qr_di_invito_porta_all_iscrizione(): void
    {
        $account = $this->registraEVerifica();

        // Giulia è dentro e naviga tranquilla
        $this->get($this->urlTenant('account'))->assertOk();

        // Anna la disattiva dal pannello
        $this->actingAs($this->admin)
            ->patch(route('accounts.disattiva', $account))
            ->assertRedirect(route('accounts.show', $account))
            ->assertSessionHas('success', 'Account disattivato.');

        $this->assertFalse($account->fresh()->attivo);

        $this->actingAs($this->admin)
            ->get(route('accounts.show', $account))
            ->assertOk()
            ->assertSee('Disattivato')
            ->assertSee(route('accounts.riattiva', $account));

        // La sessione già aperta di Giulia viene chiusa al primo click
        $this->actingAs($account->fresh(), 'account')
            ->get($this->urlTenant('account'))
            ->assertRedirect($this->urlTenant('login'));

        $this->assertGuest('account');

        // E con le credenziali giuste non entra comunque
        $this->from($this->urlTenant('login'))
            ->post($this->urlTenant('login'), [
                'email' => 'giulia.verdi@example.com',
                'password' => self::PASSWORD,
            ])
            ->assertRedirect($this->urlTenant('login'))
            ->assertSessionHasErrors(['email' => 'Credenziali non valide.']);

        $this->assertGuest('account');

        // Anna la riattiva
        $this->actingAs($this->admin)
            ->patch(route('accounts.riattiva', $account))
            ->assertRedirect(route('accounts.show', $account))
            ->assertSessionHas('success', 'Account riattivato.');

        $this->assertTrue($account->fresh()->attivo);

        // Giulia torna ad accedere
        $this->post($this->urlTenant('login'), [
            'email' => 'giulia.verdi@example.com',
            'password' => self::PASSWORD,
        ])->assertRedirect($this->urlTenant());

        $this->assertAuthenticatedAs($account->fresh(), 'account');
        $this->get($this->urlTenant('account'))->assertOk()->assertSee('Ciao, Giulia!');

        // Anna scarica il QR di invito da stampare per la festa del circolo
        $qr = $this->actingAs($this->admin)->get(route('configurazioni.invito-qr', ['download' => 1]));

        $qr->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Disposition', 'attachment; filename="invito-'.$this->associazione->slug.'.png"');

        // Il QR, letto con il telefono, porta al modulo di iscrizione del mini-sito
        $linkInvito = (new QRCode)->readFromBlob($qr->getContent())->data;
        $this->assertSame(route('pubblico.iscriviti', $this->associazione), $linkInvito);

        // Un passante senza account lo scansiona: trova il modulo completo
        // di anagrafica e consenso privacy, pronto per la preiscrizione
        $this->post($this->urlTenant('logout'));

        $this->get($linkInvito)
            ->assertOk()
            ->assertSee('name="nome"', false)
            ->assertSee('name="cognome"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="privacy"', false)
            ->assertSee('name="telefono"', false);

        // E la compila: la preiscrizione nasce senza account, in attesa dell'admin
        $this->post($linkInvito, $this->datiIscrizione([
            'nome' => 'Luca',
            'cognome' => 'Neri',
            'email' => 'luca.neri@example.com',
            'codice_fiscale' => 'NRELCU90A01F205X',
            'privacy' => '1',
        ]))
            ->assertRedirect($this->urlTenant())
            ->assertSessionHas('success');

        $richiesta = RichiestaIscrizione::allTenants()->whereNull('account_id')->sole();
        $this->assertSame(RichiestaIscrizione::STATO_IN_ATTESA, $richiesta->stato);

        $preiscritto = Socio::allTenants()->findOrFail($richiesta->socio_id);
        $this->assertSame('Luca', $preiscritto->nome);
        $this->assertSame('luca.neri@example.com', $preiscritto->email);
        $this->assertSame(Socio::STATO_IN_ATTESA, $preiscritto->stato);

        Notification::assertSentTo($this->admin, RichiestaIscrizioneRicevutaNotification::class);
        Notification::assertSentOnDemand(
            ConfermaRichiestaInviataNotification::class,
            fn (ConfermaRichiestaInviataNotification $n, array $canali, $notifiable) => $notifiable->routes['mail'] === 'luca.neri@example.com'
        );

        $this->actingAs($this->admin)->get('/richieste-iscrizione')
            ->assertOk()
            ->assertSee('Neri')
            ->assertSee('luca.neri@example.com');
    }
}
