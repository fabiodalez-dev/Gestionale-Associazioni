<?php

namespace Tests\Feature\Percorsi;

use App\Mail\Piattaforma\EsitoRegistrazioneMail;
use App\Mail\Piattaforma\NuovaRichiestaRegistrazioneMail;
use App\Mail\Piattaforma\VerificaEmailRegistrazioneMail;
use App\Mail\Struttura\InvitoAmministratoreMail;
use App\Models\Accesso;
use App\Models\Associazione;
use App\Models\AttivitaAdmin;
use App\Models\Carica;
use App\Models\Nucleo;
use App\Models\Quota;
use App\Models\RichiestaRegistrazione;
use App\Models\Sede;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\Utente;
use App\Settings\ModuliSettings;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Percorsi reali — struttura e organizzazione dell'associazione.
 *
 * Ogni test segue passo per passo quello che succede davvero in
 * un'associazione: più richieste HTTP in sequenza, fatte da attori diversi
 * (super admin della piattaforma, amministratore dell'associazione, operatore
 * limitato a una sede, visitatore del modulo pubblico), con controlli sul
 * database, sulle email e sulle pagine.
 */
class Percorso03_StrutturaOrganizzazioneTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Lanterna-Velluto-Fiume-42';

    private Associazione $associazione;

    private Utente $superAdmin;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);
        config(['honeypot.enabled' => false]);
        Mail::fake();

        $this->superAdmin = Utente::factory()->superAdmin()->create(['email' => 'super@piattaforma.test']);
        $this->superAdmin->assignRole(Utente::RUOLO_SUPER_ADMIN);

        // Nome fisso: un nome casuale potrebbe contenere i cognomi cercati nelle pagine
        $this->associazione = Associazione::factory()->create(['nome' => 'Circolo Culturale Il Faro', 'email' => 'segreteria@ilfaro.test']);
        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Giovanna',
            'cognome' => 'Mantovani',
            'email' => 'giovanna.mantovani@ilfaro.test',
            'password_hash' => self::PASSWORD,
        ]);
        $this->admin->assignRole(Utente::RUOLO_ADMIN_ASSOCIAZIONE);
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function comeAdmin(): static
    {
        return $this->actingAs($this->admin);
    }

    private function comeSuperAdmin(?Associazione $tenant = null): static
    {
        return $this->actingAs($this->superAdmin)->withSession(['associazione_id' => $tenant?->id]);
    }

    /**
     * Operatore dello staff dell'associazione, con il ruolo spatie corrispondente.
     *
     * @param  list<string>  $ruoli
     */
    private function creaOperatore(string $nome, string $cognome, array $ruoli = ['operatore']): Utente
    {
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => $nome,
            'cognome' => $cognome,
            'email' => strtolower($nome.'.'.$cognome).'@ilfaro.test',
            'password_hash' => self::PASSWORD,
        ]);
        $operatore->syncRoles($ruoli);

        return $operatore;
    }

    /**
     * @param  array<string, mixed>  $attributi
     */
    private function creaSocio(string $nome, string $cognome, array $attributi = []): Socio
    {
        return Socio::factory()->create(array_merge([
            'associazione_id' => $this->associazione->id,
            'nome' => $nome,
            'cognome' => $cognome,
            'email' => strtolower($nome.'.'.$cognome).'@mail.test',
        ], $attributi));
    }

    /**
     * Dati del form di creazione/modifica socio, come li invia la segreteria.
     *
     * @return array<string, mixed>
     */
    private function formSocio(string $nome, string $cognome, array $override = []): array
    {
        return array_merge([
            'nome' => $nome,
            'cognome' => $cognome,
            'email' => strtolower($nome.'.'.$cognome).'@mail.test',
            'data_nascita' => '1985-04-12',
            'data_iscrizione' => now()->toDateString(),
            'stato' => Socio::STATO_ATTIVO,
        ], $override);
    }

    /**
     * Come un nuovo visitatore: nessuna sessione né utente dal passo precedente.
     */
    private function esci(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    /**
     * Login reale dal modulo di accesso (stessa strada di un utente).
     */
    private function accedi(string $email, string $password): TestResponse
    {
        $this->esci();

        return $this->post('/login', ['email' => $email, 'password' => $password]);
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
            'admin_password' => self::PASSWORD,
            'privacy' => '1',
        ], $override);
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
        $this->assertNotNull($url, "Nessuna email di conferma inviata a {$email}");

        return $this->get((string) $url);
    }

    // ------------------------------------------------------------------
    // 1. Una nuova associazione si iscrive alla piattaforma
    // ------------------------------------------------------------------

    public function test_una_nuova_associazione_si_registra_e_il_super_admin_la_approva_o_la_rifiuta(): void
    {
        config(['piattaforma.registrazione_associazioni' => 'revisione']);

        // L'associazione compila il modulo pubblico dal link sulla pagina di accesso
        $this->get('/login')->assertOk()->assertSee(route('registrazione.create'), false);
        $this->get(route('registrazione.create'))->assertOk()->assertSee('Iscrivi la tua associazione');
        $this->post(route('registrazione.store'), $this->moduloRegistrazione())->assertRedirect(route('registrazione.inviata'));

        $richiesta = RichiestaRegistrazione::query()->where('admin_email', 'marta.ferraresi@aurora.test')->sole();
        $this->assertSame(RichiestaRegistrazione::STATO_IN_ATTESA, $richiesta->stato);
        $this->assertSame(1, Associazione::query()->count(), 'Con la revisione l\'associazione non esiste ancora');

        // Prima della conferma dell'email il super admin non viene disturbato e non la vede tra quelle da esaminare
        Mail::assertNotSent(NuovaRichiestaRegistrazioneMail::class);
        $this->comeSuperAdmin()->get(route('richieste-registrazione.index'))->assertOk()->assertDontSee('Polisportiva Aurora');
        $this->post(route('richieste-registrazione.approva', $richiesta))->assertSessionHas('warning');
        $this->esci();

        // L'amministratrice clicca sul link ricevuto: la richiesta arriva al super admin
        $this->confermaEmail('marta.ferraresi@aurora.test')->assertOk()->assertSee('Email confermata');
        $this->assertNotNull($richiesta->refresh()->email_verificata_at);
        Mail::assertSent(NuovaRichiestaRegistrazioneMail::class, fn ($m) => $m->hasTo('super@piattaforma.test'));

        // Finché non è approvata non può accedere
        $this->accedi('marta.ferraresi@aurora.test', self::PASSWORD);
        $this->assertGuest();

        // Il super admin la trova nell'elenco e la approva
        $this->comeSuperAdmin()->get(route('richieste-registrazione.index'))->assertOk()
            ->assertSee('Polisportiva Aurora')
            ->assertSee('marta.ferraresi@aurora.test')
            ->assertSee('Arriviamo da fogli Excel');
        $this->post(route('richieste-registrazione.approva', $richiesta))->assertRedirect(route('richieste-registrazione.index'));

        $aurora = Associazione::query()->where('nome', 'Polisportiva Aurora')->sole();
        $this->assertTrue((bool) $aurora->attiva);
        $this->assertSame('polisportiva-aurora', $aurora->slug);
        $this->assertSame(1, Sede::query()->withoutGlobalScopes()->where('associazione_id', $aurora->id)->count(), 'Nasce con la sede principale');
        $this->assertSame(RichiestaRegistrazione::STATO_APPROVATA, $richiesta->refresh()->stato);
        $this->assertSame($this->superAdmin->id, $richiesta->gestita_da);

        $marta = Utente::query()->withoutGlobalScopes()->where('email', 'marta.ferraresi@aurora.test')->sole();
        $this->assertSame(Utente::RUOLO_ADMIN_ASSOCIAZIONE, $marta->ruolo);
        $this->assertSame($aurora->id, $marta->associazione_id);
        $this->assertTrue($marta->hasRole(Utente::RUOLO_ADMIN_ASSOCIAZIONE));
        Mail::assertSent(EsitoRegistrazioneMail::class, fn ($m) => $m->hasTo('marta.ferraresi@aurora.test'));

        // La prima amministratrice accede con la password scelta nel modulo e vede la sua associazione
        $this->accedi('marta.ferraresi@aurora.test', self::PASSWORD)->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($marta);
        $this->get(route('dashboard'))->assertOk()->assertSee('Polisportiva Aurora');
        $this->get(route('configurazioni.index'))->assertOk()->assertSee('Polisportiva Aurora');
        // ... e non vede le richieste della piattaforma
        $this->get(route('richieste-registrazione.index'))->assertForbidden();

        // Variante: una seconda associazione viene rifiutata con un motivo
        $this->esci();
        $this->post(route('registrazione.store'), $this->moduloRegistrazione([
            'nome' => 'Banda Musicale Vallata',
            'email' => 'info@bandavallata.test',
            'codice_fiscale' => '80012340371',
            'admin_nome' => 'Ruggero',
            'admin_cognome' => 'Tosi',
            'admin_email' => 'ruggero.tosi@bandavallata.test',
        ]))->assertSessionHasNoErrors();
        $this->confermaEmail('ruggero.tosi@bandavallata.test')->assertOk();
        $rifiutanda = RichiestaRegistrazione::query()->where('admin_email', 'ruggero.tosi@bandavallata.test')->sole();

        $this->comeSuperAdmin()->get(route('richieste-registrazione.index'))->assertOk()->assertSee('Banda Musicale Vallata');
        $this->post(route('richieste-registrazione.rifiuta', $rifiutanda), ['motivo_rifiuto' => 'Servizio riservato alle associazioni della provincia di Bologna.'])
            ->assertRedirect(route('richieste-registrazione.index'));

        $rifiutanda->refresh();
        $this->assertSame(RichiestaRegistrazione::STATO_RIFIUTATA, $rifiutanda->stato);
        $this->assertSame('-', $rifiutanda->admin_password_hash, 'Dopo il rifiuto la password non si conserva');
        $this->assertFalse(Associazione::query()->where('nome', 'Banda Musicale Vallata')->exists());
        $this->assertFalse(Utente::query()->withoutGlobalScopes()->where('email', 'ruggero.tosi@bandavallata.test')->exists());
        Mail::assertSent(EsitoRegistrazioneMail::class, fn ($m) => $m->hasTo('ruggero.tosi@bandavallata.test')
            && str_contains($m->render(), 'provincia di Bologna'));
        $this->get(route('richieste-registrazione.index', ['stato' => 'rifiutata']))->assertOk()->assertSee('Banda Musicale Vallata');

        // Il rifiutato non ha un account e non entra
        $this->accedi('ruggero.tosi@bandavallata.test', self::PASSWORD);
        $this->assertGuest();
    }

    // ------------------------------------------------------------------
    // 2. Nuova sede e operatore limitato alla sede
    // ------------------------------------------------------------------

    public function test_nuova_sede_e_operatore_che_vede_solo_i_soci_della_sua_sede(): void
    {
        // L'amministratrice registra la sede storica e apre una seconda sede
        $this->comeAdmin()->post(route('sedi.save'), ['nome' => 'Sede Centrale', 'citta' => 'Ferrara', 'provincia' => 'FE', 'cap' => '44121'])
            ->assertRedirect(route('configurazioni.index', ['section' => 'sedi']));
        $this->post(route('sedi.save'), ['nome' => 'Sede di Rovigo', 'citta' => 'Rovigo', 'provincia' => 'RO', 'cap' => '45100', 'responsabile' => 'Ennio Zampolletti'])
            ->assertRedirect(route('configurazioni.index', ['section' => 'sedi']))
            ->assertSessionHas('success');

        $centrale = Sede::query()->forAssociazione($this->associazione->id)->where('nome', 'Sede Centrale')->sole();
        $rovigo = Sede::query()->forAssociazione($this->associazione->id)->where('nome', 'Sede di Rovigo')->sole();
        $this->get(route('configurazioni.index', ['section' => 'sedi']))->assertOk()->assertSee('Sede Centrale')->assertSee('Sede di Rovigo');

        // Iscrive un socio per sede e uno senza sede
        $this->post(route('soci.store'), $this->formSocio('Carla', 'Ferraresini', ['sede_id' => $centrale->id]))->assertSessionHasNoErrors();
        $this->post(route('soci.store'), $this->formSocio('Dario', 'Rovigatti', ['sede_id' => $rovigo->id]))->assertSessionHasNoErrors();
        $this->post(route('soci.store'), $this->formSocio('Elsa', 'Senzasede'))->assertSessionHasNoErrors();

        $socioCentrale = Socio::query()->where('cognome', 'Ferraresini')->sole();
        $socioRovigo = Socio::query()->where('cognome', 'Rovigatti')->sole();
        $this->assertSame($rovigo->id, $socioRovigo->sede_id);

        // Il super admin crea l'utente operatore per l'associazione (gli utenti si creano solo dalla piattaforma)
        $this->comeAdmin()->post(route('amministratori.store'), [
            'nome' => 'Ennio', 'cognome' => 'Zampolletti', 'email' => 'ennio.zampolletti@ilfaro.test',
            'ruolo' => Utente::RUOLO_OPERATORE, 'associazione_id' => $this->associazione->id, 'password' => self::PASSWORD,
        ])->assertForbidden();

        $this->comeSuperAdmin()->post(route('amministratori.store'), [
            'nome' => 'Ennio', 'cognome' => 'Zampolletti', 'email' => 'ennio.zampolletti@ilfaro.test',
            'ruolo' => Utente::RUOLO_OPERATORE, 'associazione_id' => $this->associazione->id, 'password' => self::PASSWORD,
        ])->assertRedirect(route('amministratori.index'))->assertSessionHasNoErrors();

        $ennio = Utente::query()->withoutGlobalScopes()->where('email', 'ennio.zampolletti@ilfaro.test')->sole();
        $this->assertSame($this->associazione->id, $ennio->associazione_id);
        $this->assertTrue($ennio->hasRole(Utente::RUOLO_OPERATORE));

        // Appena creato, senza sedi assegnate, l'operatore vede tutti i soci
        $this->accedi('ennio.zampolletti@ilfaro.test', self::PASSWORD)->assertRedirect(route('dashboard'));
        $this->get(route('soci.index', ['tessera_stato' => 'all']))->assertOk()
            ->assertSee('Ferraresini')->assertSee('Rovigatti')->assertSee('Senzasede');

        // L'amministratrice lo limita alla sede di Rovigo dalla pagina "Ruoli e permessi"
        $this->comeAdmin()->get(route('ruoli.index'))->assertOk()->assertSee('ennio.zampolletti@ilfaro.test')->assertSee('Sede di Rovigo');
        $this->put(route('ruoli.update', $ennio), ['ruoli' => ['operatore'], 'sedi' => [$rovigo->id]])
            ->assertRedirect(route('ruoli.index'))
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'sedi Sede di Rovigo'));
        $this->assertTrue((bool) $ennio->fresh()->limitato_sedi);
        $this->assertSame([$rovigo->id], $ennio->fresh()->sediAssegnateIds());

        // Da ora l'operatore vede solo la sua sede
        $this->accedi('ennio.zampolletti@ilfaro.test', self::PASSWORD)->assertRedirect(route('dashboard'));
        $this->get(route('soci.index', ['tessera_stato' => 'all']))->assertOk()
            ->assertSee('Rovigatti')->assertDontSee('Ferraresini')->assertDontSee('Senzasede');
        $this->get(route('soci.show', $socioRovigo))->assertOk()->assertSee('Rovigatti');
        $this->get(route('soci.show', $socioCentrale))->assertNotFound();
        $this->get(route('soci.edit', $socioCentrale))->assertNotFound();
        $this->put(route('soci.update', $socioCentrale), $this->formSocio('Carla', 'Ferraresini', ['numero_socio' => $socioCentrale->numero_socio, 'sede_id' => $centrale->id]))
            ->assertNotFound();
        $this->delete(route('soci.destroy', $socioCentrale))->assertNotFound();
        $this->assertNull($socioCentrale->fresh()->deleted_at);

        // Un nuovo socio lo può iscrivere solo nella sua sede
        $this->post(route('soci.store'), $this->formSocio('Fabrizio', 'Adriatici', ['sede_id' => $centrale->id]))->assertSessionHasErrors('sede_id');
        $this->post(route('soci.store'), $this->formSocio('Fabrizio', 'Adriatici'))->assertSessionHasErrors('sede_id');
        $this->post(route('soci.store'), $this->formSocio('Fabrizio', 'Adriatici', ['sede_id' => $rovigo->id]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('soci', ['cognome' => 'Adriatici', 'sede_id' => $rovigo->id]);

        // La ricerca globale rispetta lo stesso perimetro
        $this->getJson(route('ricerca-globale', ['q' => 'Ferraresini']))->assertOk()->assertJsonPath('gruppi', []);

        // L'amministratrice continua a vedere tutto
        $this->comeAdmin()->get(route('soci.index', ['tessera_stato' => 'all']))->assertOk()
            ->assertSee('Ferraresini')->assertSee('Rovigatti')->assertSee('Senzasede')->assertSee('Adriatici');
    }

    // ------------------------------------------------------------------
    // 3. Secondo amministratore: invito, password, accesso, rimozione
    // ------------------------------------------------------------------

    public function test_invito_di_un_secondo_amministratore_fino_alla_sua_rimozione(): void
    {
        // L'amministratrice non può creare utenti da sola: gli account si creano dalla piattaforma
        $this->comeAdmin()->get(route('amministratori.index'))->assertForbidden();
        $this->post(route('amministratori.store'), [
            'nome' => 'Lorenzo', 'cognome' => 'Quarantotto', 'email' => 'lorenzo.quarantotto@ilfaro.test',
            'ruolo' => Utente::RUOLO_ADMIN_ASSOCIAZIONE, 'associazione_id' => $this->associazione->id, 'invia_invito' => '1',
        ])->assertForbidden();
        $this->assertFalse(Utente::query()->withoutGlobalScopes()->where('email', 'lorenzo.quarantotto@ilfaro.test')->exists());

        // Il super admin invita il secondo amministratore via email (senza password)
        $this->comeSuperAdmin()->get(route('amministratori.index'))->assertOk()->assertSee('giovanna.mantovani@ilfaro.test');
        $this->post(route('amministratori.store'), [
            'nome' => 'Lorenzo', 'cognome' => 'Quarantotto', 'email' => 'lorenzo.quarantotto@ilfaro.test',
            'ruolo' => Utente::RUOLO_ADMIN_ASSOCIAZIONE, 'associazione_id' => $this->associazione->id, 'invia_invito' => '1',
        ])->assertRedirect(route('amministratori.index'))->assertSessionHas('success', fn (string $m) => str_contains($m, 'Invito inviato'));

        $lorenzo = Utente::query()->withoutGlobalScopes()->where('email', 'lorenzo.quarantotto@ilfaro.test')->sole();
        $this->assertSame($this->associazione->id, $lorenzo->associazione_id);
        $this->assertTrue($lorenzo->hasRole(Utente::RUOLO_ADMIN_ASSOCIAZIONE));

        $urlInvito = null;
        Mail::assertQueued(InvitoAmministratoreMail::class, function (InvitoAmministratoreMail $m) use ($lorenzo, &$urlInvito) {
            $urlInvito = $m->urlInvito;

            return $m->utente->is($lorenzo) && $m->hasTo('lorenzo.quarantotto@ilfaro.test');
        });
        $this->assertNotNull($urlInvito);

        // Senza aver impostato la password non entra
        $this->accedi('lorenzo.quarantotto@ilfaro.test', self::PASSWORD);
        $this->assertGuest();

        // L'invito viene reinviato (il link precedente resta valido: è firmato per 7 giorni)
        $this->comeSuperAdmin()->post(route('amministratori.reinvia-invito', $lorenzo))
            ->assertRedirect(route('amministratori.index'))->assertSessionHas('success', 'Invito reinviato.');
        Mail::assertQueued(InvitoAmministratoreMail::class, 2);

        // Il link manomesso viene rifiutato, quello vero apre la pagina di scelta password
        $this->esci();
        $this->get('/invito/'.$lorenzo->id.'?signature=falsa&expires=99999999999')->assertForbidden();
        $this->get((string) $urlInvito)->assertOk()->assertSee('lorenzo.quarantotto@ilfaro.test');
        $this->post((string) $urlInvito, ['password' => 'password', 'password_confirmation' => 'password'])->assertSessionHasErrors('password');
        $this->post((string) $urlInvito, ['password' => 'Ciliegio-Nebbia-Remo-93', 'password_confirmation' => 'Ciliegio-Nebbia-Remo-93'])
            ->assertRedirect(route('login'))->assertSessionHas('status');

        // Ora accede e lavora nella stessa associazione della collega
        $this->accedi('lorenzo.quarantotto@ilfaro.test', 'Ciliegio-Nebbia-Remo-93')->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($lorenzo);
        $this->assertSame($this->associazione->id, session('associazione_id'));
        $this->get(route('dashboard'))->assertOk()->assertSee('Circolo Culturale Il Faro');
        $this->get(route('configurazioni.index'))->assertOk();
        $this->comeAdmin()->get(route('ruoli.index'))->assertOk()->assertSee('lorenzo.quarantotto@ilfaro.test');

        // Lorenzo lascia il consiglio: il super admin rimuove l'account
        $this->comeSuperAdmin()->delete(route('amministratori.destroy', $lorenzo))
            ->assertRedirect(route('amministratori.index'))->assertSessionHas('success', 'Amministratore eliminato.');
        $this->assertDatabaseMissing('utenti', ['id' => $lorenzo->id]);

        // L'ex amministratore non accede più, Giovanna sì
        $this->accedi('lorenzo.quarantotto@ilfaro.test', 'Ciliegio-Nebbia-Remo-93')->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->accedi('giovanna.mantovani@ilfaro.test', self::PASSWORD)->assertRedirect(route('dashboard'));
        $this->get(route('ruoli.index'))->assertOk()->assertDontSee('lorenzo.quarantotto@ilfaro.test');
    }

    // ------------------------------------------------------------------
    // 4. Ruoli e permessi: all'operatore viene tolta la modifica dei soci
    // ------------------------------------------------------------------

    public function test_ruoli_e_permessi_tolgono_e_restituiscono_la_modifica_dei_soci_a_un_operatore(): void
    {
        $operatore = $this->creaOperatore('Pietro', 'Segantini');
        $socio = $this->creaSocio('Anna', 'Boscolo');
        $modifica = fn (string $telefono) => $this->formSocio('Anna', 'Boscolo', ['numero_socio' => $socio->numero_socio, 'telefono' => $telefono]);

        // Come operatore modifica la scheda senza problemi
        $this->actingAs($operatore)->get(route('soci.show', $socio))->assertOk()->assertSee('Boscolo');
        $this->get(route('soci.edit', $socio))->assertOk();
        $this->put(route('soci.update', $socio), $modifica('3331112222'))->assertRedirect(route('soci.show', $socio))->assertSessionHasNoErrors();
        $this->assertSame('3331112222', $socio->fresh()->telefono);

        // L'operatore non può toccare i ruoli
        $this->get(route('ruoli.index'))->assertForbidden();
        $this->put(route('ruoli.update', $operatore), ['ruoli' => ['presidente']])->assertForbidden();

        // L'amministratrice lo passa a "consigliere": consultazione senza modifiche
        $this->comeAdmin()->get(route('ruoli.index'))->assertOk()
            ->assertSee('pietro.segantini@ilfaro.test')->assertSee('Consigliere')->assertSee('Ruoli e permessi');
        $this->put(route('ruoli.update', $operatore), ['ruoli' => ['consigliere']])
            ->assertRedirect(route('ruoli.index'))
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'Consigliere'));
        $this->assertSame(['consigliere'], $operatore->fresh()->getRoleNames()->all());

        // Vede la scheda, ma ogni modifica è vietata
        $this->actingAs($operatore->fresh())->get(route('soci.index', ['tessera_stato' => 'all']))->assertOk()->assertSee('Boscolo');
        $this->get(route('soci.show', $socio))->assertOk();
        $this->get(route('soci.edit', $socio))->assertForbidden();
        $this->put(route('soci.update', $socio), $modifica('3339998888'))->assertForbidden();
        $this->post(route('soci.store'), $this->formSocio('Nuovo', 'Intruso'))->assertForbidden();
        $this->delete(route('soci.destroy', $socio))->assertForbidden();
        $this->assertSame('3331112222', $socio->fresh()->telefono);
        $this->assertNull($socio->fresh()->deleted_at);

        // Ripristinato il ruolo di operatore, può di nuovo modificare
        $this->comeAdmin()->put(route('ruoli.update', $operatore), ['ruoli' => ['operatore']])->assertRedirect(route('ruoli.index'));
        $this->actingAs($operatore->fresh())->put(route('soci.update', $socio), $modifica('3339998888'))
            ->assertRedirect(route('soci.show', $socio))->assertSessionHasNoErrors();
        $this->assertSame('3339998888', $socio->fresh()->telefono);

        // Nominato presidente: modifica i soci ma non li elimina (la configurazione resta agli amministratori)
        $this->comeAdmin()->put(route('ruoli.update', $operatore), ['ruoli' => ['presidente']])->assertRedirect(route('ruoli.index'));
        $this->actingAs($operatore->fresh())->put(route('soci.update', $socio), $modifica('3337776666'))->assertRedirect(route('soci.show', $socio));
        $this->assertSame('3337776666', $socio->fresh()->telefono);
        $this->delete(route('soci.destroy', $socio))->assertForbidden();
        $this->get(route('configurazioni.index'))->assertForbidden();
        $this->assertNull($socio->fresh()->deleted_at);
    }

    // ------------------------------------------------------------------
    // 5. Configurazioni: dati, logo, tesseramento, campi obbligatori
    // ------------------------------------------------------------------

    public function test_configurazioni_dell_associazione_dati_logo_tesseramento_e_campi_obbligatori(): void
    {
        Storage::fake('public');
        $operatore = $this->creaOperatore('Pietro', 'Segantini');

        // L'operatore non entra nelle configurazioni
        $this->actingAs($operatore)->get(route('configurazioni.index'))->assertForbidden();
        $this->post(route('configurazioni.info'), ['nome' => 'Nome abusivo'])->assertForbidden();

        // L'amministratrice aggiorna i dati anagrafici
        $this->comeAdmin()->get(route('configurazioni.index'))->assertOk()->assertSee('Circolo Culturale Il Faro');
        $this->post(route('configurazioni.info'), [
            'nome' => 'Circolo Culturale Il Faro APS',
            'email' => 'info@ilfaro.test',
            'codice_fiscale' => '93011220383',
            'telefono' => '0532 123456',
            'indirizzo' => 'Via Mazzini 12',
            'citta' => 'Ferrara',
            'provincia' => 'FE',
            'cap' => '44121',
        ])->assertRedirect(route('configurazioni.index', ['section' => 'info']))->assertSessionHasNoErrors();

        $associazione = $this->associazione->fresh();
        $this->assertSame('Circolo Culturale Il Faro APS', $associazione->nome);
        $this->assertSame('info@ilfaro.test', $associazione->email);
        $this->assertSame('93011220383', $associazione->codice_fiscale);
        $this->assertTrue((bool) $associazione->attiva, 'Da qui non si tocca lo stato attiva');
        $this->get(route('dashboard'))->assertOk()->assertSee('Circolo Culturale Il Faro APS');
        $this->post(route('configurazioni.info'), ['nome' => '', 'provincia' => 'Ferrara'])->assertSessionHasErrors(['nome', 'provincia']);

        // Carica il logo: file sbagliato rifiutato, PNG accettato e disponibile sul disco pubblico
        $this->post(route('configurazioni.logo'), ['logo_file' => UploadedFile::fake()->create('statuto.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('logo_file');
        $this->assertNull($associazione->fresh()->logo_url);

        $this->post(route('configurazioni.logo'), ['logo_file' => UploadedFile::fake()->image('logo-faro.png', 300, 300)])
            ->assertRedirect(route('configurazioni.index', ['section' => 'logo']))->assertSessionHasNoErrors();
        $associazione = $this->associazione->fresh();
        $media = $associazione->getFirstMedia('logo');
        $this->assertNotNull($media);
        $this->assertSame($media->getPathRelativeToRoot(), $associazione->logo_url);
        Storage::disk('public')->assertExists($associazione->logo_url);
        $this->get(route('configurazioni.index', ['section' => 'logo']))->assertOk()->assertSee($media->getUrl(), false);

        // Tesseramento: scadenza annuale dalla data di emissione e avviso 60 giorni prima
        $this->post(route('configurazioni.tesseramento'), ['tipo_scadenza_default' => 'annuale', 'giorni_notifica_scadenza' => 60])
            ->assertRedirect(route('configurazioni.index', ['section' => 'tesseramento']))->assertSessionHasNoErrors();
        $this->assertSame('annuale', $this->associazione->fresh()->tipo_scadenza_default);
        $this->assertSame(60, (int) $this->associazione->fresh()->giorni_notifica_scadenza);
        $this->post(route('configurazioni.tesseramento'), ['tipo_scadenza_default' => 'lunare', 'giorni_notifica_scadenza' => 0])
            ->assertSessionHasErrors(['tipo_scadenza_default', 'giorni_notifica_scadenza']);

        // Campi obbligatori del socio: la segreteria vuole sempre telefono e codice fiscale
        $this->post(route('configurazioni.campi-obbligatori'), [
            'required_backend' => ['telefono' => '1', 'codice_fiscale' => '1'],
            'required_preiscrizione' => [],
        ])->assertRedirect(route('configurazioni.index', ['section' => 'campi-obbligatori']));
        $config = $this->associazione->fresh()->campi_obbligatori_config;
        $this->assertTrue($config['backend']['telefono']);
        $this->assertTrue($config['backend']['codice_fiscale']);
        $this->assertFalse($config['backend']['indirizzo']);
        $this->assertTrue($config['preiscrizione']['privacy_consenso'], 'Il consenso privacy in preiscrizione è sempre obbligatorio');

        // Il form soci ora richiede quei campi...
        $this->post(route('soci.store'), $this->formSocio('Ugo', 'Tramontini'))->assertSessionHasErrors(['telefono', 'codice_fiscale']);
        $this->assertDatabaseMissing('soci', ['cognome' => 'Tramontini']);
        $this->post(route('soci.store'), $this->formSocio('Ugo', 'Tramontini', ['telefono' => '3471234567', 'codice_fiscale' => 'BNCGLI88C52A944I']))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('soci', ['cognome' => 'Tramontini', 'codice_fiscale' => 'BNCGLI88C52A944I']);

        // ... ma non alle pre-iscrizioni in attesa, che si completano dopo
        $this->post(route('soci.store'), $this->formSocio('Vera', 'Pendenti', ['stato' => Socio::STATO_IN_ATTESA]))->assertSessionHasNoErrors();

        // Tolto l'obbligo, il telefono torna facoltativo
        $this->post(route('configurazioni.campi-obbligatori'), ['required_backend' => [], 'required_preiscrizione' => []])->assertRedirect();
        $this->post(route('soci.store'), $this->formSocio('Zeno', 'Liberi'))->assertSessionHasNoErrors();
    }

    // ------------------------------------------------------------------
    // 6. Moduli pubblici: campi mostrati e consensi della pre-iscrizione
    // ------------------------------------------------------------------

    public function test_moduli_pubblici_campi_nascosti_e_consenso_obbligatorio_poi_ripristino(): void
    {
        $urlPubblico = route('preiscrizione.show', ['associazione' => $this->associazione->id]);
        $datiIscritto = fn (array $extra = []) => array_merge([
            'nome' => 'Giulia', 'cognome' => 'Verdiani', 'email' => 'giulia.verdiani@mail.test', 'privacy_consenso' => '1',
        ], $extra);

        // Modulo di partenza: tutti i campi anagrafici, solo il consenso privacy
        $this->get($urlPubblico)->assertOk()->assertSee('Circolo Culturale Il Faro')
            ->assertSee('name="telefono"', false)->assertSee('name="codice_fiscale"', false);

        // L'operatore non configura i moduli
        $this->actingAs($this->creaOperatore('Pietro', 'Segantini'))
            ->post(route('configurazioni.moduli'), ['colore_primario' => '#000000'])->assertForbidden();

        // L'amministratrice snellisce il modulo e aggiunge l'accettazione del regolamento
        $this->comeAdmin()->get(route('configurazioni.index', ['section' => 'moduli']))->assertOk()->assertSee('Consensi aggiuntivi');
        $this->post(route('configurazioni.moduli'), [
            'colore_primario' => '#1a7f37',
            'mostra_logo' => '1',
            'titolo_preiscrizione' => 'Diventa socio del Faro',
            'intro_preiscrizione_html' => '<p>Compila il modulo: ti <strong>richiameremo</strong> noi.</p><script>alert(1)</script>',
            'messaggio_conferma' => 'Grazie, ci sentiamo presto.',
            'campi_visibili' => ['telefono'],
            'consensi' => [
                ['testo' => 'Accetto il regolamento interno', 'obbligatorio' => '1', 'link_url' => 'https://ilfaro.test/regolamento'],
                ['testo' => 'Voglio ricevere la newsletter', 'obbligatorio' => '0', 'link_url' => ''],
            ],
        ])->assertRedirect(route('configurazioni.index', ['section' => 'moduli']))->assertSessionHasNoErrors();

        $moduli = ModuliSettings::per($this->associazione->fresh());
        $this->assertSame('#1A7F37', $moduli->colore_primario);
        $this->assertTrue($moduli->campoVisibile('telefono'));
        $this->assertFalse($moduli->campoVisibile('codice_fiscale'));
        $this->assertCount(2, $moduli->elencoConsensi());
        $this->assertStringNotContainsString('<script', (string) $moduli->intro_preiscrizione_html);

        // Il visitatore vede il nuovo modulo
        $this->esci();
        $this->get($urlPubblico)->assertOk()
            ->assertSee('Diventa socio del Faro')
            ->assertSee('<strong>richiameremo</strong>', false)
            ->assertSee('--brand: #1A7F37', false)
            ->assertSee('name="telefono"', false)
            ->assertDontSee('name="codice_fiscale"', false)
            ->assertDontSee('name="indirizzo"', false)
            ->assertSee('Accetto il regolamento interno')
            ->assertSee('https://ilfaro.test/regolamento', false)
            ->assertSee('(facoltativo)');

        // Senza accettare il regolamento la richiesta non passa
        $this->post($urlPubblico, $datiIscritto())->assertSessionHasErrors('consensi.accetto-il-regolamento-interno');
        $this->assertDatabaseMissing('soci', ['email' => 'giulia.verdiani@mail.test']);

        // Con il regolamento accettato il socio entra in attesa; il campo nascosto non viene salvato
        $this->post($urlPubblico, $datiIscritto(['consensi' => ['accetto-il-regolamento-interno' => '1'], 'codice_fiscale' => 'VRDGLI90A41D548X', 'telefono' => '3334445566']))
            ->assertSessionHasNoErrors()->assertRedirect($urlPubblico);
        $this->get($urlPubblico)->assertSee('Grazie, ci sentiamo presto.');

        $giulia = Socio::query()->where('email', 'giulia.verdiani@mail.test')->sole();
        $this->assertSame(Socio::STATO_IN_ATTESA, $giulia->stato);
        $this->assertSame('3334445566', $giulia->telefono);
        $this->assertNull($giulia->codice_fiscale, 'Un campo nascosto non si salva anche se inviato a mano');
        $this->assertTrue($giulia->consensi()->withoutGlobalScopes()->where('chiave', 'accetto-il-regolamento-interno')->where('accettato', true)->exists());
        $this->assertTrue($giulia->consensi()->withoutGlobalScopes()->where('chiave', 'voglio-ricevere-la-newsletter')->where('accettato', false)->exists());

        // La segreteria ritrova la richiesta e i consensi nella scheda
        $this->comeAdmin()->get(route('soci.index', ['status' => 'pending', 'tessera_stato' => 'all']))->assertOk()->assertSee('Verdiani');
        $this->get(route('soci.show', $giulia))->assertOk()->assertSee('Consensi')->assertSee('Accetto il regolamento interno');

        // Ripristinato il modulo completo, i campi e il solo consenso privacy tornano
        $this->post(route('configurazioni.moduli'), [
            'colore_primario' => ModuliSettings::COLORE_DEFAULT,
            'campi_visibili' => ModuliSettings::CAMPI_NASCONDIBILI,
            'consensi' => [],
        ])->assertRedirect(route('configurazioni.index', ['section' => 'moduli']));

        $this->esci();
        $this->get($urlPubblico)->assertOk()
            ->assertSee('name="codice_fiscale"', false)->assertSee('name="indirizzo"', false)
            ->assertDontSee('Accetto il regolamento interno')->assertDontSee('Diventa socio del Faro');
        $this->post($urlPubblico, $datiIscritto(['nome' => 'Marco', 'cognome' => 'Verdiani', 'email' => 'marco.verdiani@mail.test']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('soci', ['email' => 'marco.verdiani@mail.test', 'stato' => Socio::STATO_IN_ATTESA]);
    }

    // ------------------------------------------------------------------
    // 7. Organi sociali: presidente, consiglio, mandati conclusi e in scadenza
    // ------------------------------------------------------------------

    public function test_organi_sociali_con_mandati_attivi_conclusi_e_in_scadenza(): void
    {
        $pina = $this->creaSocio('Pina', 'Presidenti');
        $carlo = $this->creaSocio('Carlo', 'Consiglieri');
        $teo = $this->creaSocio('Teo', 'Tesorieri');
        $ex = $this->creaSocio('Ettore', 'Passatini');
        $operatore = $this->creaOperatore('Pietro', 'Segantini');
        $inizioMandato = now()->subYear()->toDateString();
        $fineMandato = now()->addYears(2)->toDateString();

        // Pagina vuota all'inizio
        $this->comeAdmin()->get(route('organi-sociali.index'))->assertOk()->assertSee('Organi sociali')->assertSee('Nessuna carica attiva');

        // L'assemblea ha eletto presidente, consigliere e tesoriere con mandato triennale
        $this->post(route('organi-sociali.store'), ['socio_id' => $pina->id, 'ruolo' => 'Presidente', 'inizio' => $inizioMandato, 'fine' => $fineMandato, 'note' => 'Eletta all\'assemblea ordinaria'])
            ->assertRedirect(route('organi-sociali.index'))->assertSessionHas('success');
        $this->post(route('organi-sociali.store'), ['socio_id' => $carlo->id, 'ruolo' => 'Consigliere', 'inizio' => $inizioMandato, 'fine' => $fineMandato])->assertSessionHasNoErrors();
        $this->post(route('organi-sociali.store'), ['socio_id' => $teo->id, 'ruolo' => 'Tesoriere', 'inizio' => $inizioMandato, 'fine' => $fineMandato])->assertSessionHasNoErrors();
        // ... e viene registrato anche lo storico: il presidente uscente
        $this->post(route('organi-sociali.store'), ['socio_id' => $ex->id, 'ruolo' => 'Presidente', 'inizio' => now()->subYears(4)->toDateString(), 'fine' => now()->subYear()->subDay()->toDateString()])
            ->assertSessionHasNoErrors();

        // Errori: fine prima dell'inizio, socio di un'altra associazione
        $this->post(route('organi-sociali.store'), ['socio_id' => $pina->id, 'ruolo' => 'Probiviro', 'inizio' => '2026-01-01', 'fine' => '2025-12-31'])->assertSessionHasErrors('fine');
        $estraneo = Socio::factory()->create();
        $this->post(route('organi-sociali.store'), ['socio_id' => $estraneo->id, 'ruolo' => 'Probiviro', 'inizio' => '2026-01-01'])->assertSessionHasErrors('socio_id');
        $this->assertSame(4, Carica::query()->count());

        // La pagina separa chi è in carica da chi ha concluso il mandato
        $pagina = $this->get(route('organi-sociali.index'))->assertOk()
            ->assertSee('Presidenti')->assertSee('Consiglieri')->assertSee('Tesorieri')->assertSee('Passatini');
        $this->assertEqualsCanonicalizing(['Presidente', 'Consigliere', 'Tesoriere'], $pagina->viewData('attive')->pluck('ruolo')->all());
        $this->assertSame([$ex->id], collect($pagina->viewData('storiche')->items())->pluck('socio_id')->all());
        $this->assertCount(0, $pagina->viewData('inScadenza'));
        $this->get(route('dashboard'))->assertOk()->assertDontSee('mandato in scadenza');

        // L'operatore consulta ma non nomina
        $this->actingAs($operatore)->get(route('organi-sociali.index'))->assertOk()->assertSee('Presidenti');
        $this->post(route('organi-sociali.store'), ['socio_id' => $teo->id, 'ruolo' => 'Probiviro', 'inizio' => $inizioMandato])->assertForbidden();

        // Il tesoriere si dimette: il mandato viene chiuso a oggi e passa nello storico
        $caricaTeo = Carica::query()->where('socio_id', $teo->id)->sole();
        $this->comeAdmin()->put(route('organi-sociali.update', $caricaTeo), [
            'socio_id' => $teo->id, 'ruolo' => 'Tesoriere', 'inizio' => $inizioMandato,
            'fine' => now()->subDay()->toDateString(), 'note' => 'Dimissioni per motivi personali',
        ])->assertRedirect(route('organi-sociali.index'));
        $this->assertFalse($caricaTeo->fresh()->isAttiva());

        // Il mandato del presidente si avvicina alla scadenza: avviso nella pagina e in dashboard
        $caricaPina = Carica::query()->where('socio_id', $pina->id)->sole();
        $this->put(route('organi-sociali.update', $caricaPina), [
            'socio_id' => $pina->id, 'ruolo' => 'Presidente', 'inizio' => $inizioMandato, 'fine' => now()->addDays(40)->toDateString(),
        ])->assertRedirect(route('organi-sociali.index'));

        $pagina = $this->get(route('organi-sociali.index'))->assertOk()->assertSee('Mandati in scadenza');
        $this->assertEqualsCanonicalizing(['Presidente', 'Consigliere'], $pagina->viewData('attive')->pluck('ruolo')->all());
        $this->assertEqualsCanonicalizing([$ex->id, $teo->id], collect($pagina->viewData('storiche')->items())->pluck('socio_id')->all());
        $this->assertSame([$caricaPina->id], $pagina->viewData('inScadenza')->pluck('id')->all());
        $this->get(route('dashboard'))->assertOk()->assertSee('mandato in scadenza')->assertSee('Pina Presidenti');

        // Una carica inserita per errore si elimina; quelle altrui non si toccano
        $caricaCarlo = Carica::query()->where('socio_id', $carlo->id)->sole();
        $this->delete(route('organi-sociali.destroy', $caricaCarlo))->assertRedirect(route('organi-sociali.index'));
        $this->assertDatabaseMissing('cariche', ['id' => $caricaCarlo->id]);

        $caricaAltrui = Carica::factory()->create(['socio_id' => $estraneo->id, 'associazione_id' => $estraneo->associazione_id]);
        $this->delete(route('organi-sociali.destroy', $caricaAltrui->id))->assertNotFound();
        $this->assertDatabaseHas('cariche', ['id' => $caricaAltrui->id]);
    }

    // ------------------------------------------------------------------
    // 8. Nuclei familiari
    // ------------------------------------------------------------------

    public function test_nucleo_familiare_con_capofamiglia_aggiunta_e_rimozione_dei_membri(): void
    {
        $padre = $this->creaSocio('Roberto', 'Rossellini');
        $figlia = $this->creaSocio('Chiara', 'Rossellini');
        $vicino = $this->creaSocio('Nando', 'Vicinanza');

        // Dalla scheda del padre la segreteria crea il nucleo con lui capofamiglia
        $this->comeAdmin()->get(route('soci.show', $padre))->assertOk()->assertSee('Nucleo familiare');
        $this->from(route('soci.show', $padre))->post(route('nuclei.store'), ['nome' => 'Famiglia Rossellini', 'capofamiglia_id' => $padre->id])
            ->assertRedirect(route('soci.show', $padre))->assertSessionHas('success');

        $nucleo = Nucleo::query()->sole();
        $this->assertSame('Famiglia Rossellini', $nucleo->nome);
        $this->assertSame($padre->id, $nucleo->capofamiglia_id);
        $this->assertSame($this->associazione->id, $nucleo->associazione_id);
        $this->assertSame($nucleo->id, $padre->fresh()->nucleo_id);

        // Aggiunge la figlia; un socio di un'altra associazione non è aggiungibile
        $this->post(route('nuclei.membri.store', $nucleo), ['socio_id' => $figlia->id])->assertSessionHas('success');
        $this->assertSame($nucleo->id, $figlia->fresh()->nucleo_id);
        $estraneo = Socio::factory()->create();
        $this->from(route('nuclei.index'))->post(route('nuclei.membri.store', $nucleo), ['socio_id' => $estraneo->id])->assertSessionHasErrors('socio_id');
        $this->assertNull($estraneo->fresh()->nucleo_id);

        // Entrambe le schede e la vista per nuclei mostrano la famiglia
        $this->get(route('soci.show', $figlia))->assertOk()->assertSee('Nucleo familiare')->assertSee('Famiglia Rossellini');
        $this->get(route('nuclei.index'))->assertOk()
            ->assertSee('Famiglia Rossellini')->assertSee('Capofamiglia')->assertSee('Roberto')->assertSee('Chiara')->assertDontSee('Vicinanza');

        // Nella lista soci il filtro per nucleo isola la famiglia
        $json = $this->get(route('soci.index', ['tessera_stato' => 'all', 'ajax' => 1, 'nucleo' => $nucleo->id]))->assertOk()->json();
        $this->assertSame(2, $json['count']);
        $this->assertStringNotContainsString('Vicinanza', $json['html']);

        // La figlia va a vivere altrove: viene tolta dal nucleo, il capofamiglia resta
        $this->delete(route('nuclei.membri.destroy', [$nucleo, $figlia]))->assertSessionHas('success');
        $this->assertNull($figlia->fresh()->nucleo_id);
        $this->assertSame($padre->id, $nucleo->fresh()->capofamiglia_id);
        $this->delete(route('nuclei.membri.destroy', [$nucleo, $figlia]))->assertSessionHas('error');

        // Il nucleo viene rinominato e il vicino, erroneamente aggiunto, diventa capofamiglia per sbaglio: si corregge
        $this->put(route('nuclei.update', $nucleo), ['nome' => 'Famiglia Rossellini-Vicinanza', 'capofamiglia_id' => $vicino->id])->assertSessionHas('success');
        $this->assertSame($vicino->id, $nucleo->fresh()->capofamiglia_id);
        $this->assertSame($nucleo->id, $vicino->fresh()->nucleo_id);
        $this->delete(route('nuclei.membri.destroy', [$nucleo, $vicino]))->assertSessionHas('success');
        $this->assertNull($nucleo->fresh()->capofamiglia_id, 'Tolto il capofamiglia, il nucleo resta senza');
        $this->put(route('nuclei.update', $nucleo), ['nome' => 'Famiglia Rossellini', 'capofamiglia_id' => $padre->id])->assertSessionHas('success');

        // L'operatore (soci.update) può gestire i nuclei; un'altra associazione no
        $operatore = $this->creaOperatore('Pietro', 'Segantini');
        $this->actingAs($operatore)->post(route('nuclei.membri.store', $nucleo), ['socio_id' => $figlia->id])->assertSessionHas('success');
        $this->assertSame($nucleo->id, $figlia->fresh()->nucleo_id);

        $altra = Associazione::factory()->create();
        $adminAltra = Utente::factory()->adminAssociazione()->create(['associazione_id' => $altra->id]);
        $adminAltra->assignRole(Utente::RUOLO_ADMIN_ASSOCIAZIONE);
        $this->esci();
        $this->actingAs($adminAltra)->get(route('nuclei.index'))->assertOk()->assertDontSee('Rossellini');
        $this->delete(route('nuclei.destroy', $nucleo))->assertNotFound();

        // Infine il nucleo viene sciolto: i soci restano, senza più legame
        $this->comeAdmin()->delete(route('nuclei.destroy', $nucleo))->assertSessionHas('success');
        $this->assertDatabaseMissing('nuclei', ['id' => $nucleo->id]);
        $this->assertNull($padre->fresh()->nucleo_id);
        $this->assertNull($figlia->fresh()->nucleo_id);
        $this->assertSame(3, Socio::query()->count());
    }

    // ------------------------------------------------------------------
    // 9. Cestino: eliminazione, ripristino, eliminazione definitiva
    // ------------------------------------------------------------------

    public function test_socio_nel_cestino_ripristinato_con_tessere_e_quote_poi_eliminato_definitivamente(): void
    {
        $socio = $this->creaSocio('Mario', 'Cestinati', ['data_iscrizione' => now()->subYear()->toDateString()]);
        $tessera = Tessera::factory()->create(['socio_id' => $socio->id]);
        $quota = Quota::factory()->pagata()->create(['socio_id' => $socio->id, 'importo' => 30]);
        $this->creaSocio('Lucia', 'Restanti');

        // Prima: il socio è nel libro soci e nell'elenco, con tessera e quota
        $this->comeAdmin()->get(route('libro-soci.index'))->assertOk()->assertSee('Cestinati')->assertSee('Restanti');
        $this->get(route('soci.show', $socio))->assertOk()->assertSee($tessera->numero_tessera);

        // Eliminazione dalla scheda: finisce nel cestino, sparisce da elenco e libro soci
        $this->delete(route('soci.destroy', $socio))->assertRedirect(route('soci.index'))->assertSessionHas('success');
        $this->assertNotNull($socio->fresh()?->deleted_at ?? Socio::withTrashed()->find($socio->id)->deleted_at);
        $this->get(route('soci.index', ['tessera_stato' => 'all']))->assertOk()->assertDontSee('Cestinati')->assertSee('Restanti');
        $this->get(route('libro-soci.index'))->assertOk()->assertDontSee('Cestinati');
        $this->get(route('soci.show', $socio))->assertNotFound();
        $this->get(route('soci.cestino'))->assertOk()->assertSee('Cestino Soci')->assertSee('Cestinati');
        $this->get(route('cestino.index'))->assertOk()->assertSee('Mario Cestinati')->assertSee('1 elemento nel cestino');
        $this->get(route('cestino.index', ['tipo' => 'eventi']))->assertOk()->assertDontSee('Mario Cestinati');

        // Era un errore: ripristino dal cestino unificato, tessera e quota sono ancora sue
        $this->post(route('cestino.ripristina', ['tipo' => 'soci', 'id' => $socio->id]))
            ->assertRedirect(route('cestino.index'))->assertSessionHas('success', 'Socio ripristinato con successo.');
        $socio = $socio->fresh();
        $this->assertNull($socio->deleted_at);
        $this->assertSame([$tessera->id], $socio->tessere()->pluck('id')->all());
        $this->assertSame([$quota->id], $socio->quote()->pluck('id')->all());
        $this->assertEquals(30, $quota->fresh()->importo);
        $this->get(route('soci.show', $socio))->assertOk()->assertSee('Cestinati')->assertSee($tessera->numero_tessera);
        $this->get(route('libro-soci.index'))->assertOk()->assertSee('Cestinati');
        $this->get(route('cestino.index'))->assertOk()->assertSee('0 elementi nel cestino');

        // Un socio di un'altra associazione nel cestino non è né visibile né ripristinabile
        $altrui = Socio::factory()->create(['nome' => 'Tizio', 'cognome' => 'Altrui']);
        $altrui->delete();
        $this->get(route('cestino.index'))->assertOk()->assertDontSee('Tizio Altrui');
        $this->post(route('cestino.ripristina', ['tipo' => 'soci', 'id' => $altrui->id]))->assertNotFound();
        $this->post(route('soci.restore', $altrui->id))->assertNotFound();

        // Seconda eliminazione, poi la segreteria lo rimuove per sempre dal cestino soci
        $this->delete(route('soci.destroy', $socio))->assertRedirect(route('soci.index'));
        $this->delete(route('soci.force-destroy', $socio->id))->assertRedirect(route('soci.cestino'))->assertSessionHas('success', 'Socio eliminato definitivamente.');

        $this->assertDatabaseMissing('soci', ['id' => $socio->id]);
        $this->assertNull(Socio::withTrashed()->find($socio->id));
        $this->get(route('libro-soci.index'))->assertOk()->assertDontSee('Cestinati')->assertSee('Restanti');
        $this->get(route('soci.cestino'))->assertOk()->assertDontSee('Cestinati');
        $this->get(route('cestino.index'))->assertOk()->assertSee('0 elementi nel cestino');
        $this->delete(route('soci.force-destroy', $socio->id))->assertNotFound();

        // Un operatore senza il permesso di eliminare non svuota il cestino
        $consigliere = $this->creaOperatore('Pietro', 'Segantini', ['consigliere']);
        $lucia = Socio::query()->where('cognome', 'Restanti')->sole();
        $lucia->delete();
        $this->actingAs($consigliere)->post(route('cestino.ripristina', ['tipo' => 'soci', 'id' => $lucia->id]))->assertForbidden();
        $this->delete(route('cestino.elimina-definitivamente', ['tipo' => 'soci', 'id' => $lucia->id]))->assertForbidden();
        $this->assertNotNull(Socio::withTrashed()->find($lucia->id));
    }

    // ------------------------------------------------------------------
    // 10. Registro modifiche, audit degli accessi, attività admin
    // ------------------------------------------------------------------

    public function test_registro_modifiche_audit_accessi_e_attivita_admin(): void
    {
        $socio = $this->creaSocio('Bruno', 'Bianchi', ['telefono' => '3330001111']);
        $operatore = $this->creaOperatore('Pietro', 'Segantini');

        // La segreteria corregge il cognome e il telefono del socio dalla scheda
        $this->comeAdmin()->put(route('soci.update', $socio), $this->formSocio('Bruno', 'Bianchi', [
            'cognome' => 'Bianchini', 'email' => 'bruno.bianchi@mail.test', 'numero_socio' => $socio->numero_socio, 'telefono' => '3339990000',
        ]))->assertRedirect(route('soci.show', $socio))->assertSessionHasNoErrors();
        $this->assertSame('Bianchini', $socio->fresh()->cognome);

        // Nel registro modifiche si vede chi ha cambiato cosa: vecchio e nuovo valore, e l'autrice
        $registro = $this->get(route('registro-modifiche.index', ['modello' => 'Socio', 'azione' => 'updated']))->assertOk()
            ->assertSee('Registro modifiche')
            ->assertSee('Bianchi')->assertSee('Bianchini')
            ->assertSee('3330001111')->assertSee('3339990000')
            ->assertSee('Giovanna Mantovani');
        $voci = collect($registro->viewData('attivita')->items());
        // Questa versione di activitylog salva il diff in attribute_changes (come legge la vista)
        $cambi = fn ($a) => collect($a->attribute_changes ?? $a->properties ?? []);
        $voce = $voci->first(fn ($a) => $a->subject_id === $socio->id && ($cambi($a)['old']['cognome'] ?? null) === 'Bianchi');
        $this->assertNotNull($voce, 'La modifica del cognome deve essere nel registro');
        $this->assertSame($this->admin->id, $voce->causer_id);
        $this->assertSame('Bianchini', $cambi($voce)['attributes']['cognome']);
        $this->assertSame('3339990000', $cambi($voce)['attributes']['telefono']);
        $this->assertSame('3330001111', $cambi($voce)['old']['telefono']);

        // Anche l'operatore lavora e lascia traccia, ma non consulta il registro
        $this->actingAs($operatore)->put(route('soci.update', $socio), $this->formSocio('Bruno', 'Bianchini', [
            'email' => 'bruno.bianchi@mail.test', 'numero_socio' => $socio->numero_socio, 'telefono' => '3339990000', 'citta' => 'Ferrara',
        ]))->assertRedirect(route('soci.show', $socio));
        $this->get(route('registro-modifiche.index'))->assertForbidden();
        $this->comeAdmin()->get(route('registro-modifiche.index', ['utente_id' => $operatore->id]))->assertOk()->assertSee('Pietro Segantini')->assertSee('Ferrara');

        // Accessi: un tentativo con password sbagliata, poi quello riuscito, e un'email sconosciuta
        $this->accedi('giovanna.mantovani@ilfaro.test', 'password-sbagliata')->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->accedi('giovanna.mantovani@ilfaro.test', self::PASSWORD)->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->admin);
        $this->accedi('nessuno@sconosciuto.test', 'qualunque');
        $this->assertGuest();

        $this->assertDatabaseHas('accessi', ['email_tentata' => 'giovanna.mantovani@ilfaro.test', 'esito' => Accesso::ESITO_FALLITO, 'associazione_id' => $this->associazione->id]);
        $this->assertDatabaseHas('accessi', ['email_tentata' => 'giovanna.mantovani@ilfaro.test', 'esito' => Accesso::ESITO_LOGIN, 'autenticabile_id' => $this->admin->id]);
        $this->assertDatabaseHas('accessi', ['email_tentata' => 'nessuno@sconosciuto.test', 'esito' => Accesso::ESITO_FALLITO, 'associazione_id' => null]);

        // L'amministratrice vede nell'audit i propri accessi, non il tentativo su un'email di nessuno
        $this->comeAdmin()->get(route('audit-accessi.index'))->assertOk()
            ->assertSee('giovanna.mantovani@ilfaro.test')->assertDontSee('nessuno@sconosciuto.test');
        $this->get(route('audit-accessi.index', ['esito' => Accesso::ESITO_FALLITO]))->assertOk()->assertSee('giovanna.mantovani@ilfaro.test');
        $this->assertSame(1, Accesso::query()->where('esito', Accesso::ESITO_FALLITO)->count());
        $this->assertSame(1, Accesso::query()->where('esito', Accesso::ESITO_LOGIN)->count());
        $this->actingAs($operatore)->get(route('audit-accessi.index'))->assertForbidden();
        // Il super admin in vista globale vede anche i tentativi senza associazione
        $this->comeSuperAdmin()->get(route('audit-accessi.index'))->assertOk()->assertSee('nessuno@sconosciuto.test');

        // Attività admin: un promemoria legato al socio, creato, visibile, completato
        $this->comeAdmin()->post(route('attivita-admin.store'), [
            'titolo' => 'Richiamare Bianchini per il rinnovo',
            'descrizione' => 'Ha chiesto di essere ricontattato dopo le 18.',
            'scadenza' => now()->addDays(2)->toDateString(),
            'assegnatario_id' => $this->admin->id,
            'collegamento' => 'socio',
            'socio_id' => $socio->id,
        ])->assertRedirect(route('attivita-admin.index'))->assertSessionHas('success', 'Attività creata.');

        $attivita = AttivitaAdmin::query()->sole();
        $this->assertSame(AttivitaAdmin::STATO_APERTA, $attivita->stato);
        $this->assertSame($socio->id, $attivita->notabile_id);
        $this->get(route('attivita-admin.index'))->assertOk()->assertSee('Richiamare Bianchini per il rinnovo');
        $this->get(route('dashboard'))->assertOk()->assertSee('Richiamare Bianchini per il rinnovo');

        $this->from(route('attivita-admin.index'))->post(route('attivita-admin.toggle', $attivita))
            ->assertRedirect(route('attivita-admin.index'))->assertSessionHas('success', 'Attività completata.');
        $attivita->refresh();
        $this->assertSame(AttivitaAdmin::STATO_COMPLETATA, $attivita->stato);
        $this->assertNotNull($attivita->completata_at);
        $this->get(route('attivita-admin.index'))->assertOk()->assertDontSee('Richiamare Bianchini per il rinnovo');
        $this->get(route('attivita-admin.index', ['stato' => AttivitaAdmin::STATO_COMPLETATA]))->assertOk()->assertSee('Richiamare Bianchini per il rinnovo');

        // Anche la chiusura dell'attività resta nel registro modifiche
        $this->get(route('registro-modifiche.index', ['modello' => 'Attività admin']))->assertOk()
            ->assertSee(AttivitaAdmin::STATO_COMPLETATA);
    }
}
