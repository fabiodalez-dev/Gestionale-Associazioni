<?php

namespace Tests\Feature\Piattaforma;

use App\Mail\Piattaforma\EsitoRegistrazioneMail;
use App\Mail\Piattaforma\NuovaRichiestaRegistrazioneMail;
use App\Mail\Piattaforma\VerificaEmailRegistrazioneMail;
use App\Models\Associazione;
use App\Models\RichiestaRegistrazione;
use App\Models\Sede;
use App\Models\Socio;
use App\Models\Utente;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Percorso completo di un'associazione sulla piattaforma:
 * iscrizione dal sito (con revisione, automatica o solo dal super admin),
 * conferma dell'email, approvazione, primo accesso dell'amministratore, import dei soci da Excel,
 * iscrizioni dei soci dal modulo pubblico e loro approvazione.
 */
class PercorsoAssociazioneTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD_ADMIN = 'Lanterna-Velluto-Fiume-42';

    private Utente $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);
        config(['honeypot.enabled' => false]);
        Mail::fake();

        $this->superAdmin = Utente::factory()->create([
            'ruolo' => Utente::RUOLO_SUPER_ADMIN,
            'associazione_id' => null,
            'email' => 'super@piattaforma.test',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function moduloRegistrazione(array $override = []): array
    {
        return array_merge([
            'nome' => 'Polisportiva Aurora',
            'tipo' => 'ASD',
            'email' => 'segreteria@aurora.test',
            'telefono' => '051 123456',
            'codice_fiscale' => '91234560375',
            'citta' => 'Bologna',
            'provincia' => 'bo',
            'numero_soci_stimato' => 350,
            'messaggio' => 'Arriviamo da fogli Excel, vorremmo gestire tessere e quote online.',
            'admin_nome' => 'Marta',
            'admin_cognome' => 'Ferraresi',
            'admin_email' => 'marta.ferraresi@aurora.test',
            'admin_password' => self::PASSWORD_ADMIN,
            'privacy' => '1',
        ], $override);
    }

    private function fileSoci(): UploadedFile
    {
        $foglio = new Spreadsheet;
        $foglio->getActiveSheet()->fromArray([
            ['nome', 'cognome', 'email', 'codice_fiscale', 'telefono', 'data_nascita', 'citta'],
            ['Giulia', 'Bianchi', 'giulia.bianchi@mail.test', 'BNCGLI88C52A944I', '3331112233', '1988-03-12', 'Bologna'],
            ['Luca', 'Ferri', 'luca.ferri@mail.test', 'FRRLCU09A01A944P', '3332223344', '2009-01-01', 'Bologna'],
            ['Sara', 'Conti', 'sara.conti@mail.test', 'CNTSRA90B41E289R', '', '1990-02-01', 'Imola'],
            // Riga non valida: manca il cognome, va scartata e riportata
            ['Senza', '', 'senza.cognome@mail.test', '', '', '', ''],
        ]);
        $percorso = tempnam(sys_get_temp_dir(), 'soci').'.xlsx';
        (new Xlsx($foglio))->save($percorso);

        return new UploadedFile($percorso, 'soci-aurora.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /**
     * Login reale dal modulo di accesso (stessa strada di un utente).
     */
    private function accedi(string $email, string $password): void
    {
        $this->esci();
        $this->post('/login', ['email' => $email, 'password' => $password])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs(Utente::query()->withoutGlobalScopes()->where('email', $email)->sole());
    }

    /**
     * Apre il link di conferma ricevuto via email dall'amministratore.
     */
    private function confermaEmail(string $email): TestResponse
    {
        $url = null;
        Mail::assertSent(VerificaEmailRegistrazioneMail::class, function (VerificaEmailRegistrazioneMail $m) use ($email, &$url) {
            if ($m->hasTo($email)) {
                $url = $m->url;
            }

            return $m->hasTo($email);
        });
        $this->assertNotNull($url);

        return $this->get((string) $url);
    }

    /**
     * Come un nuovo visitatore: nessuna sessione né utente dal passo precedente.
     */
    private function esci(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    public function test_percorso_completo_con_revisione_del_super_admin(): void
    {
        config(['piattaforma.registrazione_associazioni' => 'revisione']);

        // 1. L'associazione trova il link sulla pagina di accesso e compila il modulo
        $this->get('/login')->assertOk()->assertSee(route('registrazione.create'), false);
        $this->get('/registrati')->assertOk()->assertSee('Iscrivi la tua associazione')->assertSee('Invia la richiesta');

        $this->post('/registrati', $this->moduloRegistrazione())->assertRedirect(route('registrazione.inviata'));
        $this->followingRedirects()->get(route('registrazione.inviata'))->assertOk();

        $richiesta = RichiestaRegistrazione::query()->sole();
        $this->assertSame(RichiestaRegistrazione::STATO_IN_ATTESA, $richiesta->stato);
        $this->assertSame('BO', $richiesta->provincia);
        $this->assertNotSame(self::PASSWORD_ADMIN, $richiesta->admin_password_hash, 'La password non va salvata in chiaro');
        $this->assertSame(0, Associazione::query()->count(), 'Con la revisione l\'associazione non esiste ancora');

        // Finché l'email non è confermata il super admin non viene disturbato e non può approvare
        Mail::assertNotSent(NuovaRichiestaRegistrazioneMail::class);
        $this->actingAs($this->superAdmin)->get(route('richieste-registrazione.index'))->assertOk()->assertDontSee('Arriviamo da fogli Excel');
        $this->post(route('richieste-registrazione.approva', $richiesta))->assertSessionHas('warning');
        $this->assertSame(0, Associazione::query()->count());
        $this->esci();

        // Clic sul link ricevuto via email: ora la richiesta arriva al super admin
        $this->confermaEmail('marta.ferraresi@aurora.test')->assertOk()->assertSee('Email confermata');
        $this->assertNotNull($richiesta->refresh()->email_verificata_at);
        Mail::assertSent(NuovaRichiestaRegistrazioneMail::class, fn ($m) => $m->hasTo('super@piattaforma.test'));

        // Un secondo invio con la stessa email è bloccato finché la prima è in attesa
        $this->post('/registrati', $this->moduloRegistrazione(['admin_email' => 'altra@aurora.test']))->assertSessionHasErrors('email');
        // Prima dell'approvazione l'amministratore non può accedere
        $this->post('/login', ['email' => 'marta.ferraresi@aurora.test', 'password' => self::PASSWORD_ADMIN]);
        $this->assertGuest();

        // 2. Il super admin vede la richiesta (sidebar, avvisi, elenco) e la approva
        $this->actingAs($this->superAdmin)->get(route('dashboard'))->assertOk()
            ->assertSee('Richieste di iscrizione')
            ->assertSee('associazione chiede di iscriversi');
        $this->get(route('richieste-registrazione.index'))->assertOk()
            ->assertSee('Polisportiva Aurora')
            ->assertSee('marta.ferraresi@aurora.test')
            ->assertSee('Arriviamo da fogli Excel');

        $this->post(route('richieste-registrazione.approva', $richiesta))->assertRedirect(route('richieste-registrazione.index'));

        $associazione = Associazione::query()->sole();
        $this->assertSame('Polisportiva Aurora', $associazione->nome);
        $this->assertSame('polisportiva-aurora', $associazione->slug);
        $this->assertTrue((bool) $associazione->attiva);
        $this->assertSame(1, Sede::query()->withoutGlobalScopes()->where('associazione_id', $associazione->id)->count());
        $this->assertSame(RichiestaRegistrazione::STATO_APPROVATA, $richiesta->refresh()->stato);
        $this->assertSame($this->superAdmin->id, $richiesta->gestita_da);
        Mail::assertSent(EsitoRegistrazioneMail::class, fn ($m) => $m->hasTo('marta.ferraresi@aurora.test'));

        $admin = Utente::query()->withoutGlobalScopes()->where('email', 'marta.ferraresi@aurora.test')->sole();
        $this->assertSame(Utente::RUOLO_ADMIN_ASSOCIAZIONE, $admin->ruolo);
        $this->assertSame($associazione->id, $admin->associazione_id);
        $this->assertTrue($admin->hasRole(Utente::RUOLO_ADMIN_ASSOCIAZIONE));

        // Una seconda approvazione non crea duplicati
        $this->post(route('richieste-registrazione.approva', $richiesta))->assertSessionHas('warning');
        $this->assertSame(1, Associazione::query()->count());

        // 3. L'amministratore accede con la password scelta in fase di iscrizione
        $this->accedi('marta.ferraresi@aurora.test', self::PASSWORD_ADMIN);
        $this->get(route('dashboard'))->assertOk()
            ->assertSee('Polisportiva Aurora')
            ->assertSee('Per iniziare: configura');

        // 4. Carica i soci da Excel: 3 righe valide importate, 1 scartata
        $this->get(route('soci.import'))->assertOk();
        $this->post(route('soci.import.store'), ['file' => $this->fileSoci()])
            ->assertRedirect(route('soci.import'))
            ->assertSessionHas('warning', fn ($m) => str_contains($m, '3 soci importati') && str_contains($m, '1 righe scartate'));

        $importati = Socio::query()->withoutGlobalScopes()->where('associazione_id', $associazione->id)->orderBy('cognome')->get();
        $this->assertSame(['Bianchi', 'Conti', 'Ferri'], $importati->pluck('cognome')->all());
        $this->assertSame([Socio::STATO_ATTIVO], $importati->pluck('stato')->unique()->values()->all());
        $this->assertCount(3, $importati->pluck('numero_socio')->filter()->unique());

        // 5. Due persone si iscrivono dal modulo pubblico di pre-iscrizione
        $this->esci();
        $this->get('/preiscrizione/'.$associazione->id)->assertOk()->assertSee('Polisportiva Aurora');
        foreach ([['Elena', 'Marchetti'], ['Paolo', 'De Luca']] as [$nome, $cognome]) {
            $this->post('/preiscrizione/'.$associazione->id, [
                'nome' => $nome,
                'cognome' => $cognome,
                'email' => strtolower(str_replace(' ', '', $nome.'.'.$cognome)).'@mail.test',
                'data_nascita' => '1995-06-15',
                'privacy_consenso' => '1',
            ])->assertSessionHasNoErrors()->assertRedirect('/preiscrizione/'.$associazione->id);
        }
        $inAttesa = Socio::query()->withoutGlobalScopes()->where('associazione_id', $associazione->id)->where('stato', Socio::STATO_IN_ATTESA)->get();
        $this->assertCount(2, $inAttesa);

        // 6. L'amministratore trova le pre-iscrizioni e ne approva una
        $this->accedi('marta.ferraresi@aurora.test', self::PASSWORD_ADMIN);
        $this->get(route('soci.index', ['status' => 'pending', 'tessera_stato' => 'all']))->assertOk()
            ->assertSee('Marchetti')
            ->assertSee('De Luca')
            ->assertDontSee('Bianchi');

        $elena = $inAttesa->firstWhere('cognome', 'Marchetti');
        $this->post(route('soci.approva', $elena))->assertRedirect();
        $this->assertSame(Socio::STATO_IN_ATTESA_DI_PAGAMENTO, $elena->refresh()->stato);

        // Paga la quota in segreteria: l'amministratore conferma e il socio diventa attivo
        $this->post(route('soci.conferma-pagamento', $elena), [
            'importo' => '25.00',
            'metodo' => 'contanti',
            'data_pagamento' => now()->toDateString(),
            'tipo' => 'Quota Associativa',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(Socio::STATO_ATTIVO, $elena->refresh()->stato);
        $this->assertTrue($elena->quote()->where('stato', 'Pagata')->where('importo', 25)->exists());

        $this->get(route('soci.index', ['tessera_stato' => 'all']))->assertOk()
            ->assertSee('Bianchi')->assertSee('Ferri')->assertSee('Conti')->assertSee('Marchetti')->assertSee('De Luca');
        $this->assertSame(5, Socio::query()->withoutGlobalScopes()->where('associazione_id', $associazione->id)->count());
        $this->assertSame(4, Socio::query()->withoutGlobalScopes()->where('associazione_id', $associazione->id)->where('stato', Socio::STATO_ATTIVO)->count());

        // 7. I dati restano dell'associazione: un'altra associazione non li vede
        // Nomi fissi: un nome casuale potrebbe contenere i cognomi cercati
        $altra = Associazione::factory()->create(['nome' => 'Circolo Lontano']);
        $adminAltra = Utente::factory()->adminAssociazione()->create(['associazione_id' => $altra->id, 'nome' => 'Altro', 'cognome' => 'Admin']);
        $adminAltra->assignRole(Utente::RUOLO_ADMIN_ASSOCIAZIONE);
        $this->actingAs($adminAltra)->withSession(['associazione_id' => $altra->id])
            ->get(route('soci.index', ['tessera_stato' => 'all']))->assertOk()
            ->assertDontSee('Bianchi')->assertDontSee('Marchetti');
        $this->get(route('soci.show', $elena))->assertNotFound();
        // e non può aprire le richieste di iscrizione della piattaforma
        $this->get(route('richieste-registrazione.index'))->assertForbidden();
    }

    public function test_iscrizione_automatica_attiva_subito_l_associazione(): void
    {
        config(['piattaforma.registrazione_associazioni' => 'automatica']);

        $this->get('/registrati')->assertOk()->assertSee('Crea l&#039;associazione', false);
        $this->post('/registrati', $this->moduloRegistrazione())->assertRedirect(route('registrazione.inviata'));
        $this->assertSame(0, Associazione::query()->count(), 'Anche in automatico serve prima la conferma dell\'email');

        $this->confermaEmail('marta.ferraresi@aurora.test')
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'Polisportiva Aurora è attiva'));

        $associazione = Associazione::query()->sole();
        $richiesta = RichiestaRegistrazione::query()->sole();
        $this->assertSame(RichiestaRegistrazione::STATO_APPROVATA, $richiesta->stato);
        $this->assertNull($richiesta->gestita_da, 'Approvata dal sistema, non da una persona');
        $this->assertSame($associazione->id, $richiesta->associazione_id);
        Mail::assertNotSent(NuovaRichiestaRegistrazioneMail::class);
        Mail::assertSent(EsitoRegistrazioneMail::class);

        $this->accedi('marta.ferraresi@aurora.test', self::PASSWORD_ADMIN);
        $this->get(route('dashboard'))->assertOk()->assertSee('Polisportiva Aurora');
    }

    public function test_rifiuto_avvisa_e_non_crea_nulla(): void
    {
        config(['piattaforma.registrazione_associazioni' => 'revisione']);
        $this->post('/registrati', $this->moduloRegistrazione());
        $this->confermaEmail('marta.ferraresi@aurora.test')->assertOk();
        $richiesta = RichiestaRegistrazione::query()->sole();

        $this->actingAs($this->superAdmin)
            ->post(route('richieste-registrazione.rifiuta', $richiesta), ['motivo_rifiuto' => 'Servizio riservato agli enti della provincia di Modena.'])
            ->assertRedirect(route('richieste-registrazione.index'));

        $richiesta->refresh();
        $this->assertSame(RichiestaRegistrazione::STATO_RIFIUTATA, $richiesta->stato);
        $this->assertSame('-', $richiesta->admin_password_hash, 'Dopo il rifiuto l\'hash della password non si conserva');
        $this->assertSame(0, Associazione::query()->count());
        $this->assertFalse(Utente::query()->withoutGlobalScopes()->where('email', 'marta.ferraresi@aurora.test')->exists());
        Mail::assertSent(EsitoRegistrazioneMail::class, fn ($m) => $m->hasTo('marta.ferraresi@aurora.test')
            && str_contains($m->render(), 'provincia di Modena'));

        // Dopo il rifiuto la stessa associazione può ripresentare la richiesta
        $this->esci();
        $this->post('/registrati', $this->moduloRegistrazione())->assertSessionHasNoErrors();
        $this->assertSame(1, RichiestaRegistrazione::query()->inAttesa()->count());
    }

    public function test_senza_conferma_dell_email_non_succede_nulla(): void
    {
        config(['piattaforma.registrazione_associazioni' => 'automatica']);

        // Qualcuno usa l'email di un'altra associazione: senza la casella non può confermare
        $this->post('/registrati', $this->moduloRegistrazione(['admin_email' => 'impostore@altrove.test']))->assertSessionHasNoErrors();
        $this->assertSame(0, Associazione::query()->count());
        Mail::assertNotSent(NuovaRichiestaRegistrazioneMail::class);

        // ... e la richiesta non confermata non blocca la vera associazione
        $this->post('/registrati', $this->moduloRegistrazione())->assertSessionHasNoErrors();
        $vera = RichiestaRegistrazione::query()->where('admin_email', 'marta.ferraresi@aurora.test')->sole();
        $impostore = RichiestaRegistrazione::query()->where('admin_email', 'impostore@altrove.test')->sole();

        // Link manomesso o scaduto: rifiutato
        $this->get(route('registrazione.verifica', ['richiesta' => $impostore->id]))->assertForbidden();
        $scaduto = URL::temporarySignedRoute('registrazione.verifica', now()->subMinute(), ['richiesta' => $impostore->id]);
        $this->get($scaduto)->assertForbidden();
        $this->assertNull($impostore->refresh()->email_verificata_at);

        // La vera associazione conferma e viene attivata
        $this->confermaEmail('marta.ferraresi@aurora.test')->assertRedirect(route('login'));
        $this->assertSame($vera->refresh()->associazione_id, Associazione::query()->sole()->id);

        // Un link firmato valido dell'impostore, arrivato dopo, non crea un doppione:
        // la richiesta passa al super admin, che non può approvarla
        $this->confermaEmail('impostore@altrove.test')->assertOk();
        $this->assertSame(1, Associazione::query()->count());
        $this->actingAs($this->superAdmin)->post(route('richieste-registrazione.approva', $impostore))
            ->assertSessionHas('warning', fn ($m) => str_contains($m, 'Esiste già'));
        $this->assertSame(1, Associazione::query()->count());
    }

    public function test_il_modulo_non_serve_a_inondare_una_casella_email(): void
    {
        config(['piattaforma.registrazione_associazioni' => 'revisione']);

        for ($i = 1; $i <= 3; $i++) {
            $this->post('/registrati', $this->moduloRegistrazione([
                'nome' => '<b>Vinci un premio</b> http://truffa.test '.$i,
                'email' => "spam{$i}@truffa.test",
                'admin_email' => 'vittima@casella.test',
            ]))->assertSessionHasNoErrors();
        }
        // Al quarto tentativo verso lo stesso indirizzo il modulo si ferma
        $this->post('/registrati', $this->moduloRegistrazione(['email' => 'spam4@truffa.test', 'admin_email' => 'vittima@casella.test']))
            ->assertSessionHasErrors('admin_email');

        Mail::assertSentCount(3);
        // L'oggetto è fisso: il testo scritto nel modulo non finisce nell'oggetto
        Mail::assertSent(VerificaEmailRegistrazioneMail::class, fn ($m) => ! str_contains($m->envelope()->subject, 'premio')
            && str_contains($m->render(), '&lt;b&gt;Vinci un premio&lt;/b&gt;'));

        // Le richieste mai confermate vengono cancellate dopo 7 giorni
        RichiestaRegistrazione::query()->update(['created_at' => now()->subDays(8)]);
        $this->artisan('model:prune', ['--model' => [RichiestaRegistrazione::class]])->assertSuccessful();
        $this->assertSame(0, RichiestaRegistrazione::query()->count());
    }

    public function test_con_iscrizione_disattivata_le_crea_solo_il_super_admin(): void
    {
        config(['piattaforma.registrazione_associazioni' => 'disattivata']);

        $this->get('/registrati')->assertNotFound();
        $this->post('/registrati', $this->moduloRegistrazione())->assertNotFound();
        $this->get('/login')->assertOk()->assertDontSee('/registrati');

        // Il super admin crea associazione e amministratore dalla gestione associazioni
        $this->actingAs($this->superAdmin)->post(route('associazioni.store'), [
            'nome' => 'Circolo Tennis Riviera',
            'email' => 'info@tennisriviera.test',
            'attiva' => '1',
            'create_sede' => '1',
            'create_admin' => '1',
            'admin_nome' => 'Piero',
            'admin_cognome' => 'Sala',
            'admin_email' => 'piero.sala@tennisriviera.test',
            'admin_password' => self::PASSWORD_ADMIN,
        ])->assertRedirect(route('associazioni.index'))->assertSessionHasNoErrors();

        $associazione = Associazione::query()->where('nome', 'Circolo Tennis Riviera')->sole();
        $this->accedi('piero.sala@tennisriviera.test', self::PASSWORD_ADMIN);
        $this->get(route('dashboard'))->assertOk()->assertSee('Circolo Tennis Riviera');
        $this->assertSame($associazione->id, Auth::user()?->associazione_id);
    }

    public function test_validazione_del_modulo_pubblico(): void
    {
        config(['piattaforma.registrazione_associazioni' => 'revisione']);
        Associazione::factory()->create(['email' => 'gia@iscritta.test']);
        Utente::factory()->create(['email' => 'esiste@utente.test']);

        $this->post('/registrati', $this->moduloRegistrazione([
            'email' => 'gia@iscritta.test',
            'admin_email' => 'esiste@utente.test',
            'admin_password' => 'password',
            'codice_fiscale' => '123',
            'privacy' => null,
        ]))->assertSessionHasErrors(['email', 'admin_email', 'admin_password', 'codice_fiscale', 'privacy']);

        $this->assertSame(0, RichiestaRegistrazione::query()->count());
    }

    public function test_solo_il_super_admin_vede_e_gestisce_le_richieste(): void
    {
        config(['piattaforma.registrazione_associazioni' => 'revisione']);
        $this->post('/registrati', $this->moduloRegistrazione());
        $this->confermaEmail('marta.ferraresi@aurora.test');
        $richiesta = RichiestaRegistrazione::query()->sole();

        $associazione = Associazione::factory()->create();
        $admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $associazione->id]);
        $admin->assignRole(Utente::RUOLO_ADMIN_ASSOCIAZIONE);

        $this->actingAs($admin)->withSession(['associazione_id' => $associazione->id]);
        $this->get(route('richieste-registrazione.index'))->assertForbidden();
        $this->post(route('richieste-registrazione.approva', $richiesta))->assertForbidden();
        $this->post(route('richieste-registrazione.rifiuta', $richiesta))->assertForbidden();
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Polisportiva Aurora');

        $this->assertTrue($richiesta->refresh()->isInAttesa());
    }
}
