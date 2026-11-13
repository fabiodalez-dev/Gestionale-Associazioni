<?php

namespace Tests\Feature\Flussi;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\CampoPersonalizzato;
use App\Models\Consenso;
use App\Models\DocumentoSocio;
use App\Models\NotaSocio;
use App\Models\Quota;
use App\Models\RichiestaIscrizione;
use App\Models\RichiestaModificaDati;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\TipoSocio;
use App\Models\Utente;
use App\Notifications\Accounts\VerificaEmailAccount;
use App\Notifications\Iscrizioni\IscrizioneRifiutataNotification;
use App\Services\Iscrizioni\FormIscrizioneService;
use App\Services\Privacy\DatiPersonali;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use ZipArchive;

/**
 * Flussi reali — ADESIONE E ACCOUNT (batch 01).
 *
 * Sito pubblico /a/{slug} → richiesta di iscrizione → pannello admin →
 * socio, con campi personalizzati, consensi, tutore dei minorenni,
 * account e verifica email, richieste di modifica dati, export e
 * cancellazione GDPR. Ogni test esegue più richieste HTTP di attori
 * diversi (ospite, account, admin, operatore, socio) e verifica cosa
 * finisce davvero nel database, campo per campo.
 *
 * Complementare a tests/Feature/Percorsi/01_AdesioneAccountTest.php: qui
 * si cercano i casi limite (duplicati, cestino, email non più uniche,
 * permessi, isolamento tra associazioni, lunghezze di colonna).
 */
class Flussi01_AdesioneAccountFlussiTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Lampione-Viola-Treno-42';

    private const CF_VALIDO = 'RSSMRA85T10A562S';

    private const CF_ALTRO = 'VRDGLI85T50F205P';

    private Associazione $associazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-04 10:00:00'));

        $this->seed(RolesSeeder::class);

        Notification::fake();
        Mail::fake();

        // Persone vere, non bot: honeypot spento; tutti gli attori del test
        // condividono lo stesso IP, il rate limit per IP qui sarebbe rumore
        config(['honeypot.enabled' => false]);
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->associazione = Associazione::factory()->create([
            'nome' => 'Circolo Culturale Il Faro',
            'frontend_pubblico_attivo' => true,
            'costo_tessera' => 25.00,
        ]);

        $this->admin = $this->creaAdmin($this->associazione, 'Anna', 'Presidente');
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('tenant.pubblico');
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function creaAdmin(Associazione $associazione, string $nome = 'Admin', string $cognome = 'Associazione'): Utente
    {
        $admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $associazione->id,
            'nome' => $nome,
            'cognome' => $cognome,
        ]);
        $admin->assignRole(Utente::RUOLO_ADMIN_ASSOCIAZIONE);

        return $admin;
    }

    private function url(string $path = '', ?Associazione $associazione = null): string
    {
        $associazione ??= $this->associazione;

        return '/a/'.$associazione->slug.($path !== '' ? '/'.ltrim($path, '/') : '');
    }

    /** Nessuno è più autenticato: la prossima richiesta parte da un ospite. */
    private function comeOspite(): void
    {
        $this->app['auth']->forgetGuards();
    }

    private function accountVerificato(array $attributi = []): Account
    {
        return Account::factory()->create(array_merge([
            'associazione_id' => $this->associazione->id,
            'password' => self::PASSWORD,
            'email_verified_at' => now(),
        ], $attributi));
    }

    /**
     * L'admin salva il modulo di iscrizione dalla pagina di configurazione.
     *
     * @param  array<string, array{0: bool, 1: bool}>  $campi  campo => [visibile, obbligatorio]
     * @param  list<string>  $campiPersonalizzati
     */
    private function configuraModulo(array $campi, array $campiPersonalizzati = [], string $modalita = Associazione::MODALITA_PREISCRIZIONE): void
    {
        $standard = [];
        foreach (FormIscrizioneService::CAMPI_STANDARD as $i => $campo) {
            [$visibile, $obbligatorio] = $campi[$campo] ?? [false, false];
            $standard[$campo] = array_filter([
                'ordine' => $i + 1,
                'visibile' => $visibile ? '1' : null,
                'obbligatorio' => $obbligatorio ? '1' : null,
            ], fn ($v) => $v !== null);
        }

        $this->actingAs($this->admin)
            ->post(route('configurazioni.modulo-iscrizione.salva'), [
                'iscrizioni_socio_aperte' => '1',
                'modalita_iscrizione' => $modalita,
                'intro_html' => '',
                'campi_standard' => $standard,
                'campi_personalizzati' => $campiPersonalizzati,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('configurazioni.modulo-iscrizione'));

        $this->associazione->refresh();
        $this->comeOspite();
    }

    /** Tutti i 7 campi standard visibili (telefono e data di nascita obbligatori). */
    private function tuttiICampiVisibili(): array
    {
        return [
            'telefono' => [true, true],
            'codice_fiscale' => [true, false],
            'data_nascita' => [true, true],
            'indirizzo' => [true, false],
            'citta' => [true, false],
            'provincia' => [true, false],
            'cap' => [true, false],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function datiOspite(array $override = []): array
    {
        return array_merge([
            'nome' => 'Giulia',
            'cognome' => 'Verdi',
            'email' => 'giulia.verdi@example.com',
            'privacy' => '1',
            'telefono' => '3331234567',
            'data_nascita' => '1985-12-10',
            'citta' => 'Milano',
        ], $override);
    }

    /** Dati per il modulo di default (telefono + data nascita obbligatori, città). */
    private function datiAccount(array $override = []): array
    {
        return array_merge([
            'telefono' => '3331234567',
            'data_nascita' => '1985-12-10',
            'citta' => 'Milano',
        ], $override);
    }

    private function inviaIscrizioneOspite(array $override = []): TestResponse
    {
        $this->comeOspite();

        return $this->post($this->url('iscriviti'), $this->datiOspite($override));
    }

    private function richiestaPerEmail(string $email): RichiestaIscrizione
    {
        $socio = Socio::withoutGlobalScopes()->where('email', $email)->firstOrFail();

        return RichiestaIscrizione::withoutGlobalScopes()->where('socio_id', $socio->id)->latest()->firstOrFail();
    }

    /**
     * Payload del form di modifica scheda socio (PUT /soci/{id}) costruito
     * dai dati attuali, come lo invierebbe il browser.
     *
     * @return array<string, mixed>
     */
    private function payloadSocio(Socio $socio, array $override = []): array
    {
        return array_merge([
            'numero_socio' => $socio->numero_socio,
            'nome' => $socio->nome,
            'cognome' => $socio->cognome,
            'email' => $socio->email,
            'telefono' => $socio->telefono,
            'codice_fiscale' => $socio->codice_fiscale,
            'data_nascita' => $socio->data_nascita?->toDateString(),
            'data_iscrizione' => $socio->data_iscrizione?->toDateString(),
            'stato' => $socio->stato,
            'indirizzo' => $socio->indirizzo,
            'citta' => $socio->citta,
            'provincia' => $socio->provincia,
            'cap' => $socio->cap,
            'privacy_consenso' => $socio->privacy_consenso ? '1' : '0',
            'tipo_socio_id' => $socio->tipo_socio_id,
        ], $override);
    }

    private function lunghezzaColonnaSchemaInstaller(string $tabella, string $colonna): ?int
    {
        $sql = (string) file_get_contents(base_path('database/installer/schema.sql'));

        if (! preg_match('/CREATE TABLE `'.preg_quote($tabella, '/').'` \((.*?)\n\) ENGINE/s', $sql, $m)) {
            return null;
        }

        if (! preg_match('/`'.preg_quote($colonna, '/').'` varchar\((\d+)\)/', $m[1], $c)) {
            return null;
        }

        return (int) $c[1];
    }

    /**
     * @param  array<mixed>  $dati
     * @return list<string>
     */
    private function chiaviRicorsive(array $dati): array
    {
        $chiavi = [];
        foreach ($dati as $k => $v) {
            $chiavi[] = (string) $k;
            if (is_array($v)) {
                $chiavi = array_merge($chiavi, $this->chiaviRicorsive($v));
            }
        }

        return $chiavi;
    }

    // ------------------------------------------------------------------
    // 1. Ospite: tutti i campi standard e personalizzati salvati
    // ------------------------------------------------------------------

    public function test_ospite_senza_account_invia_iscrizione_con_tutti_i_campi_standard_e_personalizzati_e_tutto_viene_salvato(): void
    {
        $professione = CampoPersonalizzato::create(['associazione_id' => $this->associazione->id, 'nome_campo' => 'Professione', 'tipo_campo' => 'text', 'ordine' => 1]);
        $strumento = CampoPersonalizzato::create(['associazione_id' => $this->associazione->id, 'nome_campo' => 'Strumento', 'tipo_campo' => 'select', 'opzioni' => "Chitarra\nBasso\nBatteria", 'ordine' => 2]);
        $diploma = CampoPersonalizzato::create(['associazione_id' => $this->associazione->id, 'nome_campo' => 'Data diploma', 'tipo_campo' => 'date', 'ordine' => 3]);

        // Un socio "ambasciatore" che ha condiviso il suo link di invito
        $ambasciatore = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'numero_socio' => '001',
        ]);
        $this->assertNotNull($ambasciatore->codice_referral);

        $this->configuraModulo($this->tuttiICampiVisibili(), [$professione->id, $strumento->id, $diploma->id]);

        // Il form pubblico mostra tutti i campi configurati
        $this->get($this->url('iscriviti'))
            ->assertOk()
            ->assertSee('name="indirizzo"', false)
            ->assertSee('name="cap"', false)
            ->assertSee('Professione')
            ->assertSee('Batteria');

        $inviati = $this->datiOspite([
            'codice_fiscale' => strtolower(self::CF_VALIDO),
            'indirizzo' => 'Via Roma 12',
            'provincia' => 'mi',
            'cap' => '20100',
            'custom_field' => [
                $professione->id => 'Insegnante',
                $strumento->id => 'Basso',
                $diploma->id => '2004-07-01',
            ],
        ]);

        $this->post($this->url('iscriviti'), $inviati + ['referral_codice' => $ambasciatore->codice_referral])
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->url())
            ->assertSessionHas('success');

        /** @var Socio $socio */
        $socio = Socio::withoutGlobalScopes()->where('email', 'giulia.verdi@example.com')->sole();

        $this->assertSame($this->associazione->id, $socio->associazione_id);
        $this->assertSame(Socio::STATO_IN_ATTESA, $socio->stato);
        $this->assertSame('Giulia', $socio->nome);
        $this->assertSame('Verdi', $socio->cognome);
        $this->assertSame('3331234567', $socio->telefono);
        $this->assertSame(self::CF_VALIDO, $socio->codice_fiscale, 'Il CF va salvato in maiuscolo.');
        $this->assertSame('1985-12-10', $socio->data_nascita->toDateString());
        $this->assertSame('Via Roma 12', $socio->indirizzo);
        $this->assertSame('Milano', $socio->citta);
        $this->assertSame('MI', $socio->provincia, 'La provincia va salvata in maiuscolo.');
        $this->assertSame('20100', $socio->cap);
        $this->assertSame('2026-11-04', $socio->data_iscrizione->toDateString());
        $this->assertSame('002', $socio->numero_socio, 'Numero socio progressivo dopo 001.');
        $this->assertTrue($socio->privacy_consenso);

        // I tre valori personalizzati
        $valori = DB::table('valori_campi_personalizzati')->where('socio_id', $socio->id)->pluck('valore', 'campo_id')->all();
        $this->assertSame([
            $professione->id => 'Insegnante',
            $strumento->id => 'Basso',
            $diploma->id => '2004-07-01',
        ], array_intersect_key($valori, array_flip([$professione->id, $strumento->id, $diploma->id])));
        $this->assertCount(3, $valori);

        // Richiesta da ospite: nessun account, snapshot integrale del form
        $richiesta = RichiestaIscrizione::withoutGlobalScopes()->where('socio_id', $socio->id)->sole();
        $this->assertSame(RichiestaIscrizione::STATO_IN_ATTESA, $richiesta->stato);
        $this->assertNull($richiesta->account_id);
        foreach ($inviati as $campo => $valore) {
            $this->assertArrayHasKey($campo, $richiesta->dati_form, "dati_form deve contenere {$campo}");
            $this->assertEquals($valore, $richiesta->dati_form[$campo], "dati_form.{$campo} diverso dal valore inviato");
        }

        // Il referral dell'ambasciatore è agganciato alla richiesta
        $this->assertSame($ambasciatore->codice_referral, $richiesta->referral_codice);

        $this->assertDatabaseHas('storico_attivita_socio', [
            'socio_id' => $socio->id,
            'tipo_attivita' => 'Iscrizione',
        ]);
    }

    // ------------------------------------------------------------------
    // 2. Campo nascosto: l'input viene ignorato
    // ------------------------------------------------------------------

    public function test_campo_standard_nascosto_dallo_schema_viene_ignorato_anche_se_inviato(): void
    {
        $campi = $this->tuttiICampiVisibili();
        $campi['indirizzo'] = [false, false];
        $campi['cap'] = [false, false];
        $this->configuraModulo($campi);

        $account = $this->accountVerificato(['nome' => 'Paolo', 'cognome' => 'Neri', 'email' => 'paolo.neri@example.com']);

        // Pagina form: indirizzo e cap non compaiono
        $this->actingAs($account, 'account')
            ->get($this->url('iscriviti'))
            ->assertOk()
            ->assertDontSee('name="indirizzo"', false)
            ->assertDontSee('name="cap"', false);

        // Un utente "furbo" li invia comunque
        $this->actingAs($account, 'account')
            ->post($this->url('iscriviti'), $this->datiAccount([
                'provincia' => 'BG',
                'indirizzo' => 'Via Nascosta 1',
                'cap' => '24100',
            ]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $socio = Socio::withoutGlobalScopes()->where('email', 'paolo.neri@example.com')->sole();
        $this->assertNull($socio->indirizzo);
        $this->assertNull($socio->cap);
        $this->assertSame('BG', $socio->provincia);
        $this->assertSame('Paolo', $socio->nome, 'Nome preso dall\'account, non dal form.');

        $richiesta = RichiestaIscrizione::withoutGlobalScopes()->where('account_id', $account->id)->sole();
        $this->assertArrayNotHasKey('indirizzo', $richiesta->dati_form);
        $this->assertArrayNotHasKey('cap', $richiesta->dati_form);
        $this->assertSame('BG', $richiesta->dati_form['provincia']);
        $this->assertNotNull($account->fresh()->richiesta_socio_at);
    }

    // ------------------------------------------------------------------
    // 3. Minorenne: tutore obbligatorio e salvato
    // ------------------------------------------------------------------

    public function test_minorenne_senza_tutore_viene_rifiutato_e_con_tutore_i_tre_campi_del_tutore_vengono_salvati(): void
    {
        $this->configuraModulo([
            'telefono' => [true, true],
            'data_nascita' => [true, true],
            'citta' => [true, false],
        ]);

        $account = $this->accountVerificato(['nome' => 'Luca', 'cognome' => 'Giovane', 'email' => 'luca.giovane@example.com']);
        $nascita = now()->subYears(16)->toDateString();

        $this->actingAs($account, 'account')
            ->from($this->url('iscriviti'))
            ->post($this->url('iscriviti'), $this->datiAccount(['data_nascita' => $nascita]))
            ->assertRedirect($this->url('iscriviti'))
            ->assertSessionHasErrors(['tutore_nome', 'tutore_telefono', 'tutore_email']);

        $this->assertSame(0, Socio::withoutGlobalScopes()->where('email', 'luca.giovane@example.com')->count());
        $this->assertSame(0, RichiestaIscrizione::withoutGlobalScopes()->count());

        $this->actingAs($account, 'account')
            ->post($this->url('iscriviti'), $this->datiAccount([
                'data_nascita' => $nascita,
                'tutore_nome' => 'Maria Giovane',
                'tutore_telefono' => '3471234567',
                'tutore_email' => 'maria.giovane@example.com',
            ]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $socio = Socio::withoutGlobalScopes()->where('email', 'luca.giovane@example.com')->sole();
        $this->assertSame($nascita, $socio->data_nascita->toDateString());
        $this->assertSame('Maria Giovane', $socio->tutore_nome);
        $this->assertSame('3471234567', $socio->tutore_telefono);
        $this->assertSame('maria.giovane@example.com', $socio->tutore_email);

        $richiesta = RichiestaIscrizione::withoutGlobalScopes()->where('account_id', $account->id)->sole();
        $this->assertSame(RichiestaIscrizione::STATO_IN_ATTESA, $richiesta->stato);
        $this->assertSame('Maria Giovane', $richiesta->dati_form['tutore_nome']);
    }

    // ------------------------------------------------------------------
    // 4. Consensi aggiuntivi nel registro consensi
    // ------------------------------------------------------------------

    public function test_consensi_aggiuntivi_obbligatori_e_facoltativi_finiscono_nel_registro_consensi_con_testo_ip_e_origine(): void
    {
        $this->actingAs($this->admin)
            ->post(route('configurazioni.moduli'), [
                'colore_primario' => '#0D9488',
                'mostra_logo' => '1',
                'consensi' => [
                    ['chiave' => 'regolamento', 'testo' => 'Accetto il regolamento interno del circolo', 'obbligatorio' => '1'],
                    ['chiave' => 'foto', 'testo' => 'Acconsento alla pubblicazione di foto durante gli eventi', 'obbligatorio' => '0'],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        // Senza il consenso obbligatorio: bloccato
        $this->comeOspite();
        $this->from($this->url('iscriviti'))
            ->post($this->url('iscriviti'), $this->datiOspite())
            ->assertSessionHasErrors('consensi.regolamento');
        $this->assertSame(0, Socio::withoutGlobalScopes()->count());

        // Spunta solo l'obbligatorio
        $this->inviaIscrizioneOspite(['consensi' => ['regolamento' => '1']])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $socio = Socio::withoutGlobalScopes()->where('email', 'giulia.verdi@example.com')->sole();

        $consensi = Consenso::withoutGlobalScopes()->where('socio_id', $socio->id)->get()->keyBy('chiave');
        $this->assertCount(3, $consensi, 'Privacy + 2 consensi aggiuntivi.');

        $this->assertTrue((bool) $consensi['privacy']->accettato);

        $this->assertTrue((bool) $consensi['regolamento']->accettato);
        $this->assertSame('Accetto il regolamento interno del circolo', $consensi['regolamento']->testo);

        $this->assertFalse((bool) $consensi['foto']->accettato);
        $this->assertSame('Acconsento alla pubblicazione di foto durante gli eventi', $consensi['foto']->testo);

        foreach ($consensi as $consenso) {
            $this->assertSame('iscrizione_online', $consenso->origine);
            $this->assertSame('127.0.0.1', $consenso->ip);
            $this->assertSame($this->associazione->id, $consenso->associazione_id);
        }
    }

    // ------------------------------------------------------------------
    // 5. Indirizzo lungo 300 caratteri (regola max:500 vs colonna 255)
    // ------------------------------------------------------------------

    public function test_indirizzo_lungo_300_caratteri_nel_form_pubblico_passa_la_validazione_e_viene_salvato_troncato_o_intero(): void
    {
        $this->configuraModulo($this->tuttiICampiVisibili());

        $indirizzo = 'Via '.str_repeat('Lunghissima ', 24).'n. 1';
        $indirizzo = str_pad($indirizzo, 300, 'x');
        $this->assertSame(300, mb_strlen($indirizzo));

        // Lo schema reale (installer MariaDB, uguale alla migration
        // string('indirizzo') = varchar(255)) non può contenere 300 caratteri:
        // in strict mode l'INSERT fallirebbe con "Data too long" → errore 500.
        // La regola del form pubblico deve quindi stare entro la colonna, come
        // già fanno SocioRequest e ModificaDatiService (max:255): l'indirizzo
        // troppo lungo va segnalato sul campo, senza creare il socio.
        $lunghezza = $this->lunghezzaColonnaSchemaInstaller('soci', 'indirizzo');
        $this->assertNotNull($lunghezza, 'Colonna soci.indirizzo non trovata in database/installer/schema.sql');
        $this->assertLessThan(mb_strlen($indirizzo), $lunghezza);

        $this->comeOspite();
        $risposta = $this->from($this->url('iscriviti'))
            ->post($this->url('iscriviti'), $this->datiOspite(['indirizzo' => $indirizzo]));

        $this->assertNotSame(500, $risposta->status(), 'L\'invio non deve generare un errore 500.');
        $risposta->assertSessionHasErrors('indirizzo');
        $this->assertSame(0, Socio::withoutGlobalScopes()->where('email', 'giulia.verdi@example.com')->count());

        // Un indirizzo che sta nella colonna viene salvato per intero
        $entro = mb_substr($indirizzo, 0, $lunghezza);
        $this->inviaIscrizioneOspite(['indirizzo' => $entro])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $socio = Socio::withoutGlobalScopes()->where('email', 'giulia.verdi@example.com')->sole();
        $this->assertSame($entro, $socio->indirizzo);
    }

    // ------------------------------------------------------------------
    // 6. Doppia richiesta ospite, rifiuto, nuova richiesta
    // ------------------------------------------------------------------

    public function test_doppia_richiesta_ospite_stessa_email_e_poi_rifiuto_sblocca_una_nuova_richiesta(): void
    {
        $professione = CampoPersonalizzato::create(['associazione_id' => $this->associazione->id, 'nome_campo' => 'Professione', 'tipo_campo' => 'text', 'ordine' => 1]);
        $this->configuraModulo([
            'telefono' => [true, true],
            'data_nascita' => [true, true],
            'citta' => [true, false],
        ], [$professione->id]);

        $this->inviaIscrizioneOspite(['custom_field' => [$professione->id => 'Medico']])
            ->assertSessionHas('success');

        // Ci riprova con la stessa email: errore chiaro, nessun duplicato
        $this->comeOspite();
        $this->from($this->url('iscriviti'))
            ->post($this->url('iscriviti'), $this->datiOspite(['nome' => 'Giulietta']))
            ->assertRedirect($this->url('iscriviti'))
            ->assertSessionHasErrors('iscrizione');

        $this->assertSame(1, RichiestaIscrizione::withoutGlobalScopes()->count());
        $this->assertSame(1, Socio::withoutGlobalScopes()->withTrashed()->count());

        $richiesta = $this->richiestaPerEmail('giulia.verdi@example.com');
        $socioId = $richiesta->socio_id;
        $this->assertSame(1, DB::table('valori_campi_personalizzati')->where('socio_id', $socioId)->count());
        $this->assertSame(1, DB::table('consensi')->where('socio_id', $socioId)->count());

        // L'admin rifiuta (motivo obbligatorio)
        $this->actingAs($this->admin)
            ->post(route('richieste-iscrizione.rifiuta', $richiesta), [])
            ->assertSessionHasErrors('motivo');

        $this->actingAs($this->admin)
            ->post(route('richieste-iscrizione.rifiuta', $richiesta), ['motivo' => 'Manca il certificato medico'])
            ->assertRedirect(route('richieste-iscrizione.index'))
            ->assertSessionHas('success');

        $richiesta->refresh();
        $this->assertSame(RichiestaIscrizione::STATO_RIFIUTATA, $richiesta->stato);
        $this->assertSame('Manca il certificato medico', $richiesta->motivo_rifiuto);
        $this->assertNull($richiesta->socio_id);
        $this->assertSame($this->admin->id, $richiesta->gestita_da);

        // Il preiscritto è eliminato davvero (niente cestino) con valori e consensi
        $this->assertSame(0, Socio::withoutGlobalScopes()->withTrashed()->where('email', 'giulia.verdi@example.com')->count());
        $this->assertSame(0, DB::table('valori_campi_personalizzati')->where('socio_id', $socioId)->count());
        $this->assertSame(0, DB::table('consensi')->where('socio_id', $socioId)->count());

        // Iscritta da ospite (senza account): l'esito arriva all'email del modulo
        Notification::assertSentOnDemand(
            IscrizioneRifiutataNotification::class,
            fn ($notifica, array $canali, $destinatario) => $destinatario->routes['mail'] === 'giulia.verdi@example.com'
        );

        // Ora può ripresentare la domanda
        $this->inviaIscrizioneOspite(['custom_field' => [$professione->id => 'Medico']])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $nuovo = Socio::withoutGlobalScopes()->where('email', 'giulia.verdi@example.com')->sole();
        $this->assertNotSame($socioId, $nuovo->id);
        $this->assertSame(Socio::STATO_IN_ATTESA, $nuovo->stato);
        $this->assertSame(2, RichiestaIscrizione::withoutGlobalScopes()->count());
        $this->assertSame(RichiestaIscrizione::STATO_IN_ATTESA, $this->richiestaPerEmail('giulia.verdi@example.com')->stato);
    }

    // ------------------------------------------------------------------
    // 7. Approvazione con delibera e tessera
    // ------------------------------------------------------------------

    public function test_approvazione_con_delibera_e_tessera_crea_quota_tessera_storico_e_la_delibera_compare_nel_libro_soci_e_nelle_decisioni(): void
    {
        $this->associazione->forceFill(['costo_tessera' => 30.00])->save();

        $account = $this->accountVerificato(['nome' => 'Sara', 'cognome' => 'Bruni', 'email' => 'sara.bruni@example.com']);

        $this->actingAs($account, 'account')
            ->post($this->url('iscriviti'), $this->datiAccount())
            ->assertSessionHas('success');

        $richiesta = RichiestaIscrizione::withoutGlobalScopes()->where('account_id', $account->id)->sole();

        $this->actingAs($this->admin)
            ->post(route('richieste-iscrizione.approva', $richiesta), [
                'genera_tessera' => '1',
                'delibera_numero' => '7/2026',
                'delibera_data' => '2026-10-30',
            ])
            ->assertRedirect(route('richieste-iscrizione.index'))
            ->assertSessionHas('success');

        $socio = Socio::withoutGlobalScopes()->findOrFail($richiesta->socio_id);
        $this->assertSame(Socio::STATO_ATTIVO, $socio->stato);
        $this->assertSame($socio->id, $account->fresh()->socio_id);
        // La data di iscrizione è quella della delibera di ammissione
        $this->assertSame('2026-10-30', $socio->data_iscrizione->toDateString());

        $tessera = Tessera::withoutGlobalScopes()->where('socio_id', $socio->id)->sole();
        $this->assertSame(2026, (int) $tessera->anno_validita);
        $this->assertNotEmpty($tessera->numero_tessera);

        $quota = Quota::withoutGlobalScopes()->where('socio_id', $socio->id)->sole();
        $this->assertSame(30.0, (float) $quota->importo);
        $this->assertSame(Quota::STATO_DA_PAGARE, $quota->stato);
        $this->assertSame(2026, (int) $quota->anno);
        $this->assertNotNull($quota->payment_token, 'La quota di ammissione deve avere il link di pagamento.');

        $richiesta->refresh();
        $this->assertSame(RichiestaIscrizione::STATO_APPROVATA, $richiesta->stato);
        $this->assertSame('7/2026', $richiesta->delibera_numero);
        $this->assertSame('2026-10-30', Carbon::parse($richiesta->delibera_data)->toDateString());
        $this->assertSame($this->admin->id, $richiesta->gestita_da);
        $this->assertNotNull($richiesta->gestita_at);

        $storico = DB::table('storico_attivita_socio')
            ->where('socio_id', $socio->id)
            ->where('tipo_attivita', 'Approvazione iscrizione')
            ->sole();
        $this->assertStringContainsString('delibera di ammissione n. 7/2026 del 30/10/2026', $storico->descrizione);
        $this->assertStringContainsString('Anna Presidente', $storico->descrizione);

        // Libro soci: la riga di Sara riporta la delibera
        $this->actingAs($this->admin)->get('/libro-soci')
            ->assertOk()
            ->assertSee('Bruni')
            ->assertSee('n. 7/2026')
            ->assertSee('30/10/2026');

        // Registro decisioni: l'ammissione compare come decisione
        $this->actingAs($this->admin)->get('/decisioni')
            ->assertOk()
            ->assertSee('Ammissione socio')
            ->assertSee('Delibera n. 7/2026');
    }

    // ------------------------------------------------------------------
    // 8. Quota dal tipo socio, genera_quota=0
    // ------------------------------------------------------------------

    public function test_approvazione_con_genera_quota_zero_e_tipo_socio_con_costo_usa_il_costo_del_tipo(): void
    {
        $giovani = TipoSocio::create(['associazione_id' => $this->associazione->id, 'nome' => 'Giovani', 'costo_tessera' => 15.00]);

        $this->inviaIscrizioneOspite()->assertSessionHas('success');
        $this->inviaIscrizioneOspite(['nome' => 'Marco', 'cognome' => 'Blu', 'email' => 'marco.blu@example.com'])->assertSessionHas('success');

        $prima = $this->richiestaPerEmail('giulia.verdi@example.com');
        $seconda = $this->richiestaPerEmail('marco.blu@example.com');

        // L'admin assegna il tipo socio "Giovani" al preiscritto dalla scheda
        $preiscritto = Socio::withoutGlobalScopes()->findOrFail($prima->socio_id);
        $this->actingAs($this->admin)
            ->put(route('soci.update', $preiscritto), $this->payloadSocio($preiscritto, ['tipo_socio_id' => $giovani->id]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('soci.show', $preiscritto));

        $this->assertSame($giovani->id, $preiscritto->fresh()->tipo_socio_id);
        $this->assertSame(Socio::STATO_IN_ATTESA, $preiscritto->fresh()->stato);

        $this->actingAs($this->admin)
            ->post(route('richieste-iscrizione.approva', $prima), ['genera_quota' => '1'])
            ->assertSessionHas('success');

        $quote = Quota::withoutGlobalScopes()->where('socio_id', $preiscritto->id)->get();
        $this->assertCount(1, $quote);
        $this->assertSame('15.00', number_format((float) $quote->first()->importo, 2, '.', ''));

        // Seconda richiesta: l'admin non vuole la quota ora
        $this->actingAs($this->admin)
            ->post(route('richieste-iscrizione.approva', $seconda), ['genera_quota' => '0'])
            ->assertSessionHas('success');

        $this->assertSame(0, Quota::withoutGlobalScopes()->where('socio_id', $seconda->socio_id)->count());

        $this->assertSame(RichiestaIscrizione::STATO_APPROVATA, $prima->fresh()->stato);
        $this->assertSame(RichiestaIscrizione::STATO_APPROVATA, $seconda->fresh()->stato);
        $this->assertSame(Socio::STATO_ATTIVO, Socio::withoutGlobalScopes()->find($seconda->socio_id)->stato);

        // Nessuna quota duplicata per lo stesso anno
        $this->assertSame(1, Quota::withoutGlobalScopes()->where('anno', 2026)->count());
    }

    // ------------------------------------------------------------------
    // 9. Due admin approvano la stessa richiesta
    // ------------------------------------------------------------------

    public function test_due_admin_approvano_la_stessa_richiesta_il_secondo_riceve_errore_e_nulla_viene_duplicato(): void
    {
        $adminB = $this->creaAdmin($this->associazione, 'Bruno', 'Vice');

        $this->inviaIscrizioneOspite()->assertSessionHas('success');
        $richiesta = $this->richiestaPerEmail('giulia.verdi@example.com');

        $this->actingAs($this->admin)
            ->post(route('richieste-iscrizione.approva', $richiesta), ['genera_tessera' => '1', 'genera_quota' => '1'])
            ->assertSessionHas('success');

        // Bruno aveva la pagina aperta da prima e clicca "Approva"
        $this->actingAs($adminB)
            ->post(route('richieste-iscrizione.approva', $richiesta), ['genera_tessera' => '1', 'genera_quota' => '1'])
            ->assertRedirect(route('richieste-iscrizione.index'))
            ->assertSessionHas('error', fn (string $msg) => str_contains($msg, 'già stata gestita'));

        // … e anche "Rifiuta": niente da fare
        $this->actingAs($adminB)
            ->post(route('richieste-iscrizione.rifiuta', $richiesta), ['motivo' => 'Doppione'])
            ->assertSessionHas('error');

        $socioId = $richiesta->fresh()->socio_id;
        $this->assertNotNull($socioId);
        $this->assertSame(1, Tessera::withoutGlobalScopes()->where('socio_id', $socioId)->count());
        $this->assertSame(1, Quota::withoutGlobalScopes()->where('socio_id', $socioId)->count());
        $this->assertSame(1, DB::table('storico_attivita_socio')->where('socio_id', $socioId)->where('tipo_attivita', 'Approvazione iscrizione')->count());
        $this->assertSame($this->admin->id, $richiesta->fresh()->gestita_da);
        $this->assertSame(RichiestaIscrizione::STATO_APPROVATA, $richiesta->fresh()->stato);
    }

    // ------------------------------------------------------------------
    // 10. Isolamento tra associazioni
    // ------------------------------------------------------------------

    public function test_admin_di_un_altra_associazione_non_vede_ne_gestisce_la_richiesta(): void
    {
        $altra = Associazione::factory()->create(['nome' => 'Polisportiva Altrove', 'frontend_pubblico_attivo' => true]);
        $adminAltra = $this->creaAdmin($altra, 'Carlo', 'Altrove');

        $this->inviaIscrizioneOspite(['cognome' => 'Riservatissima'])->assertSessionHas('success');
        $richiesta = $this->richiestaPerEmail('giulia.verdi@example.com');

        $this->actingAs($adminAltra)->get('/richieste-iscrizione')
            ->assertOk()
            ->assertDontSee('Riservatissima')
            ->assertDontSee('giulia.verdi@example.com');

        $this->actingAs($adminAltra)
            ->post(route('richieste-iscrizione.approva', $richiesta), ['genera_tessera' => '1'])
            ->assertNotFound();

        $this->actingAs($adminAltra)
            ->post(route('richieste-iscrizione.rifiuta', $richiesta), ['motivo' => 'Non ti conosco'])
            ->assertNotFound();

        $richiesta->refresh();
        $this->assertSame(RichiestaIscrizione::STATO_IN_ATTESA, $richiesta->stato);
        $this->assertNull($richiesta->gestita_da);
        $this->assertSame(Socio::STATO_IN_ATTESA, Socio::withoutGlobalScopes()->find($richiesta->socio_id)->stato);
        $this->assertSame(0, Tessera::withoutGlobalScopes()->count());

        // L'admin giusto invece la vede
        $this->actingAs($this->admin)->get('/richieste-iscrizione')
            ->assertOk()
            ->assertSee('Riservatissima');
    }

    // ------------------------------------------------------------------
    // 11. Promozione account con email già di un socio
    // ------------------------------------------------------------------

    public function test_promozione_account_a_socio_con_email_gia_di_un_socio_esistente_non_va_in_500(): void
    {
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'mario.rossi@example.it',
            'stato' => Socio::STATO_ATTIVO,
        ]);

        $account = $this->accountVerificato(['nome' => 'Mario', 'cognome' => 'Rossi', 'email' => 'mario.rossi@example.it']);
        $sociPrima = Socio::withoutGlobalScopes()->withTrashed()->count();

        $risposta = $this->actingAs($this->admin)
            ->post(route('accounts.promuovi-socio', $account));

        $this->assertNotSame(
            500,
            $risposta->status(),
            'Promuovere un account la cui email è già di un socio del tenant non deve dare 500 '
            .'(unique soci(associazione_id,email) → QueryException non gestita). Eccezione: '
            .($risposta->exception?->getMessage() ?? '-')
        );
        $risposta->assertRedirect(route('accounts.show', $account));
        $this->assertTrue(
            session()->has('error') || session('errors')?->any(),
            'L\'admin deve ricevere un messaggio di errore comprensibile.'
        );

        $this->assertSame($sociPrima, Socio::withoutGlobalScopes()->withTrashed()->count());
        $this->assertNull($account->fresh()->socio_id);
    }

    // ------------------------------------------------------------------
    // 12. Promozione account → socio, poi collegamento duplicato
    // ------------------------------------------------------------------

    public function test_promozione_account_a_socio_copia_tutti_i_dati_e_crea_numero_socio_senza_tessera(): void
    {
        Socio::factory()->create(['associazione_id' => $this->associazione->id, 'numero_socio' => '005']);

        $account = $this->accountVerificato([
            'nome' => 'Elena',
            'cognome' => 'Gialli',
            'email' => 'elena.gialli@example.com',
            'telefono' => '3337654321',
            'privacy_accettata_at' => now()->subDays(10),
        ]);

        $this->actingAs($this->admin)
            ->post(route('accounts.promuovi-socio', $account))
            ->assertRedirect(route('accounts.show', $account))
            ->assertSessionHas('success', fn (string $msg) => str_contains($msg, 'n. 006'));

        $socio = Socio::withoutGlobalScopes()->where('email', 'elena.gialli@example.com')->sole();
        $this->assertSame($this->associazione->id, $socio->associazione_id);
        $this->assertSame('Elena', $socio->nome);
        $this->assertSame('Gialli', $socio->cognome);
        $this->assertSame('3337654321', $socio->telefono);
        $this->assertSame(Socio::STATO_ATTIVO, $socio->stato);
        $this->assertTrue($socio->privacy_consenso);
        $this->assertSame('2026-11-04', $socio->data_iscrizione->toDateString());
        $this->assertSame('006', $socio->numero_socio);
        $this->assertSame(0, Tessera::withoutGlobalScopes()->where('socio_id', $socio->id)->count());
        $this->assertSame($socio->id, $account->fresh()->socio_id);

        // Ripromuovere lo stesso account: errore, nessun secondo socio
        $this->actingAs($this->admin)
            ->post(route('accounts.promuovi-socio', $account))
            ->assertSessionHas('error');
        $this->assertSame(2, Socio::withoutGlobalScopes()->count());

        // Un altro account non può essere collegato allo stesso socio
        $altro = $this->accountVerificato(['email' => 'elena.bis@example.com']);
        $this->actingAs($this->admin)
            ->from(route('accounts.show', $altro))
            ->post(route('accounts.collega-socio', $altro), ['socio_id' => $socio->id])
            ->assertSessionHasErrors(['socio_id' => 'Questo socio è già collegato a un altro account.']);

        $this->assertNull($altro->fresh()->socio_id);
    }

    // ------------------------------------------------------------------
    // 13. Collega socio: tenant e unicità
    // ------------------------------------------------------------------

    public function test_collega_socio_rifiuta_socio_di_altro_tenant_e_socio_gia_collegato(): void
    {
        $altra = Associazione::factory()->create(['frontend_pubblico_attivo' => true]);
        $socioAltroTenant = Socio::factory()->create(['associazione_id' => $altra->id, 'email' => 'luca.verdi@example.com']);

        $socioGiaCollegato = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        $this->accountVerificato(['email' => 'altro@example.com'])->forceFill(['socio_id' => $socioGiaCollegato->id])->save();

        $socioLibero = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Luca',
            'cognome' => 'Verdi',
            'email' => 'Luca.Verdi@example.com',
        ]);

        $account = $this->accountVerificato(['nome' => 'Luca', 'cognome' => 'Verdi', 'email' => 'luca.verdi@example.com']);

        // Il dettaglio suggerisce il socio con la stessa email (case-insensitive)
        $this->actingAs($this->admin)->get(route('accounts.show', $account))
            ->assertOk()
            ->assertSee('Match suggerito per email')
            ->assertSee($socioLibero->numero_socio)
            ->assertDontSee($socioAltroTenant->numero_socio.', ', false);

        // Socio di un'altra associazione
        $this->actingAs($this->admin)
            ->from(route('accounts.show', $account))
            ->post(route('accounts.collega-socio', $account), ['socio_id' => $socioAltroTenant->id])
            ->assertSessionHasErrors(['socio_id' => 'Socio non trovato in questa associazione.']);
        $this->assertNull($account->fresh()->socio_id);

        // Socio già collegato a un altro account
        $this->actingAs($this->admin)
            ->from(route('accounts.show', $account))
            ->post(route('accounts.collega-socio', $account), ['socio_id' => $socioGiaCollegato->id])
            ->assertSessionHasErrors(['socio_id' => 'Questo socio è già collegato a un altro account.']);
        $this->assertNull($account->fresh()->socio_id);

        // Socio libero del tenant: collegato
        $this->actingAs($this->admin)
            ->post(route('accounts.collega-socio', $account), ['socio_id' => $socioLibero->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('accounts.show', $account))
            ->assertSessionHas('success');
        $this->assertSame($socioLibero->id, $account->fresh()->socio_id);
        $this->assertDatabaseHas('accounts', ['id' => $account->id, 'socio_id' => $socioLibero->id]);
    }

    // ------------------------------------------------------------------
    // 14. Socio nel cestino blocca l'email
    // ------------------------------------------------------------------

    public function test_socio_nel_cestino_blocca_iscrizione_pubblica_e_creazione_admin_e_l_eliminazione_definitiva_sblocca(): void
    {
        $vecchio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'm@x.it',
            'nome' => 'Mirko',
            'cognome' => 'Cestinato',
        ]);

        $this->actingAs($this->admin)
            ->delete(route('soci.destroy', $vecchio))
            ->assertRedirect(route('soci.index'));
        $this->assertSoftDeleted('soci', ['id' => $vecchio->id]);

        // L'ospite prova a iscriversi con la stessa email: risposta NEUTRA
        // (identica a una richiesta inviata), così il modulo pubblico non
        // rivela a un anonimo che l'email appartiene a un socio
        $this->comeOspite();
        $this->from($this->url('iscriviti'))
            ->post($this->url('iscriviti'), $this->datiOspite(['email' => 'm@x.it']))
            ->assertRedirect($this->url(''))
            ->assertSessionHas('success')
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(1, Socio::withoutGlobalScopes()->withTrashed()->count());
        $this->assertSame(0, RichiestaIscrizione::withoutGlobalScopes()->count());

        // L'admin prova a crearlo a mano
        $this->actingAs($this->admin)
            ->from(route('soci.create'))
            ->post(route('soci.store'), [
                'nome' => 'Mirko',
                'cognome' => 'Nuovo',
                'email' => 'm@x.it',
                'data_nascita' => '1980-01-01',
                'data_iscrizione' => '2026-11-04',
                'stato' => Socio::STATO_ATTIVO,
            ])
            ->assertSessionHasErrors('email');
        $this->assertSame(1, Socio::withoutGlobalScopes()->withTrashed()->count());

        // Eliminazione definitiva dal cestino
        $this->actingAs($this->admin)
            ->delete(route('soci.force-destroy', $vecchio->id))
            ->assertRedirect(route('soci.cestino'))
            ->assertSessionHas('success');
        $this->assertDatabaseMissing('soci', ['id' => $vecchio->id]);

        // Ora l'iscrizione pubblica passa
        $this->inviaIscrizioneOspite(['email' => 'm@x.it'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $nuovo = Socio::withoutGlobalScopes()->where('email', 'm@x.it')->sole();
        $this->assertSame(Socio::STATO_IN_ATTESA, $nuovo->stato);
        $this->assertSame(1, RichiestaIscrizione::withoutGlobalScopes()->where('socio_id', $nuovo->id)->count());
    }

    // ------------------------------------------------------------------
    // 15. Registrazione e verifica email firmata
    // ------------------------------------------------------------------

    public function test_registrazione_account_salva_tutti_i_campi_e_la_verifica_email_firmata_imposta_email_verified_at(): void
    {
        $this->post($this->url('registrati'), [
            'nome' => 'Giulia',
            'cognome' => 'Verdi',
            'email' => 'Giulia.Verdi@Example.com',
            'telefono' => '3331234567',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'privacy' => '1',
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->url('verifica-email'));

        /** @var Account $account */
        $account = Account::withoutGlobalScopes()->where('associazione_id', $this->associazione->id)->sole();
        $this->assertSame('Giulia', $account->nome);
        $this->assertSame('Verdi', $account->cognome);
        $this->assertSame('Giulia.Verdi@Example.com', $account->email);
        $this->assertSame('3331234567', $account->telefono);
        $this->assertTrue($account->attivo);
        $this->assertNotNull($account->privacy_accettata_at);
        $this->assertNull($account->email_verified_at);
        $this->assertNull($account->socio_id);
        $this->assertNotSame(self::PASSWORD, $account->password, 'La password deve essere salvata hashata.');

        Notification::assertSentToTimes($account, VerificaEmailAccount::class, 1);

        $link = null;
        Notification::assertSentTo($account, VerificaEmailAccount::class, function (VerificaEmailAccount $n, array $c, Account $d) use (&$link) {
            $link = $n->toMail($d)->actionUrl;

            return true;
        });
        $this->assertIsString($link);

        // Chiude il browser; apre un link con la firma manomessa
        $this->post($this->url('logout'));
        $this->comeOspite();

        $manomesso = preg_replace('/signature=([0-9a-f]+)/', 'signature=deadbeef', $link);
        $this->assertNotSame($link, $manomesso);
        $this->get($manomesso)->assertForbidden();
        $this->assertNull($account->fresh()->email_verified_at);

        // Link con l'id di un altro account ma la firma originale: rifiutato
        $this->get(str_replace($account->id, (string) Str::uuid(), $link))->assertForbidden();
        $this->assertNull($account->fresh()->email_verified_at);

        // Link valido: verificata
        $this->get($link)
            ->assertRedirect($this->url('login'))
            ->assertSessionHas('success');
        $this->assertNotNull($account->fresh()->email_verified_at);

        // Il link di verifica è stato inviato una sola volta
        Notification::assertSentToTimes($account, VerificaEmailAccount::class, 1);

        // E ora può accedere con l'email come l'ha scritta
        $this->post($this->url('login'), ['email' => 'Giulia.Verdi@Example.com', 'password' => self::PASSWORD])
            ->assertRedirect($this->url());
        $this->assertAuthenticatedAs($account->fresh(), 'account');
    }

    // ------------------------------------------------------------------
    // 16. Cambio email account
    // ------------------------------------------------------------------

    public function test_cambio_email_account_azzera_la_verifica_reinvia_il_link_e_rifiuta_email_di_altro_account_dello_stesso_tenant(): void
    {
        $account = $this->accountVerificato(['email' => 'prima@example.com']);
        $this->accountVerificato(['email' => 'occupata@example.com']);

        // La stessa email in un'altra associazione non conta
        $altra = Associazione::factory()->create(['frontend_pubblico_attivo' => true]);
        Account::factory()->create(['associazione_id' => $altra->id, 'email' => 'libera-altrove@example.com']);

        $this->actingAs($account, 'account')
            ->from($this->url('account/profilo'))
            ->patch($this->url('account/profilo/email'), ['email' => 'occupata@example.com'])
            ->assertRedirect($this->url('account/profilo'))
            ->assertSessionHasErrors(['email' => 'Esiste già un account registrato con questo indirizzo email.']);

        $account->refresh();
        $this->assertSame('prima@example.com', $account->email);
        $this->assertNotNull($account->email_verified_at);
        Notification::assertNotSentTo($account, VerificaEmailAccount::class);

        $this->actingAs($account, 'account')
            ->patch($this->url('account/profilo/email'), ['email' => 'libera-altrove@example.com'])
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->url('verifica-email'))
            ->assertSessionHas('success');

        $account->refresh();
        $this->assertSame('libera-altrove@example.com', $account->email);
        $this->assertNull($account->email_verified_at);
        $this->assertDatabaseHas('accounts', ['id' => $account->id, 'email' => 'libera-altrove@example.com', 'email_verified_at' => null]);
        Notification::assertSentToTimes($account, VerificaEmailAccount::class, 1);

        // Finché non riconferma, le aree riservate rimandano all'avviso di verifica
        $this->actingAs($account, 'account')
            ->get($this->url('account/prenotazioni'))
            ->assertRedirect($this->url('verifica-email'));

        $this->actingAs($account, 'account')
            ->get($this->url('account/profilo'))
            ->assertRedirect($this->url('verifica-email'));
    }

    // ------------------------------------------------------------------
    // 17. Profilo e preferenze: merge del json
    // ------------------------------------------------------------------

    public function test_aggiornamento_profilo_e_preferenze_account_fa_merge_senza_perdere_chiavi_esistenti(): void
    {
        $account = $this->accountVerificato([
            'nome' => 'Ugo',
            'cognome' => 'Vecchio',
            'telefono' => '3330000000',
            'preferenze' => ['motivazione' => 'x', 'newsletter' => true],
        ]);

        $this->actingAs($account, 'account')
            ->patch($this->url('account/profilo'), [
                'nome' => 'Ugo Maria',
                'cognome' => 'Nuovo',
                'telefono' => '3471112233',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->url('account/profilo'));

        $this->assertDatabaseHas('accounts', [
            'id' => $account->id,
            'nome' => 'Ugo Maria',
            'cognome' => 'Nuovo',
            'telefono' => '3471112233',
        ]);

        // Spunta solo "marketing": le altre checkbox non spuntate non arrivano
        $this->actingAs($account->fresh(), 'account')
            ->patch($this->url('account/preferenze'), ['marketing' => '1'])
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->url('account/preferenze'));

        $preferenze = $account->fresh()->preferenze;
        $this->assertSame('x', $preferenze['motivazione'] ?? null, 'La motivazione salvata non deve sparire.');
        $this->assertFalse($preferenze['newsletter']);
        $this->assertFalse($preferenze['promemoria_eventi']);
        $this->assertTrue($preferenze['marketing']);

        // La pagina preferenze riflette quanto salvato
        $this->actingAs($account->fresh(), 'account')
            ->get($this->url('account/preferenze'))
            ->assertOk();
    }

    // ------------------------------------------------------------------
    // 18. Modifica dati da account: solo differenze, approvazione
    // ------------------------------------------------------------------

    public function test_richiesta_modifica_dati_dall_account_con_tutti_i_campi_salva_solo_le_differenze_e_l_approvazione_le_applica(): void
    {
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Franco',
            'cognome' => 'Bassi',
            'email' => 'franco.bassi@example.com',
            'telefono' => '3331112222',
            'codice_fiscale' => self::CF_VALIDO,
            'data_nascita' => '1985-12-10',
            'indirizzo' => 'Via Vecchia 1',
            'citta' => 'Bergamo',
            'provincia' => 'BG',
            'cap' => '24100',
        ]);
        $account = $this->accountVerificato(['email' => 'franco.bassi@example.com']);
        $account->forceFill(['socio_id' => $socio->id])->save();

        $this->actingAs($account->fresh(), 'account')
            ->get($this->url('account/modifica-dati'))
            ->assertOk()
            ->assertSee('Via Vecchia 1');

        $this->actingAs($account->fresh(), 'account')
            ->post($this->url('account/modifica-dati'), [
                // 4 invariati
                'nome' => 'Franco',
                'cognome' => 'Bassi',
                'email' => 'franco.bassi@example.com',
                'telefono' => '3331112222',
                // 6 cambiati (CF e provincia in minuscolo)
                'codice_fiscale' => strtolower(self::CF_ALTRO),
                'data_nascita' => '1986-01-15',
                'indirizzo' => 'Piazza Nuova 7',
                'citta' => 'Brescia',
                'provincia' => 'bs',
                'cap' => '25121',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->url('account/modifica-dati'))
            ->assertSessionHas('success');

        $richiesta = RichiestaModificaDati::withoutGlobalScopes()->where('socio_id', $socio->id)->sole();
        $this->assertEquals([
            'codice_fiscale' => self::CF_ALTRO,
            'data_nascita' => '1986-01-15',
            'indirizzo' => 'Piazza Nuova 7',
            'citta' => 'Brescia',
            'provincia' => 'BS',
            'cap' => '25121',
        ], $richiesta->proposte);
        $this->assertSame((new Account)->getMorphClass(), $richiesta->origine_type);
        $this->assertSame($account->id, $richiesta->origine_id);
        $this->assertSame($this->associazione->id, $richiesta->associazione_id);

        // Scheda invariata finché l'admin non approva
        $this->assertSame('Bergamo', $socio->fresh()->citta);

        $this->actingAs($this->admin)
            ->post(route('richieste-modifica-dati.approva', $richiesta))
            ->assertRedirect(route('richieste-modifica-dati.index'))
            ->assertSessionHas('success');

        $socio->refresh();
        $this->assertSame('Franco', $socio->nome);
        $this->assertSame('franco.bassi@example.com', $socio->email);
        $this->assertSame('3331112222', $socio->telefono);
        $this->assertSame(self::CF_ALTRO, $socio->codice_fiscale);
        $this->assertSame('1986-01-15', $socio->data_nascita->toDateString());
        $this->assertSame('Piazza Nuova 7', $socio->indirizzo);
        $this->assertSame('Brescia', $socio->citta);
        $this->assertSame('BS', $socio->provincia);
        $this->assertSame('25121', $socio->cap);

        $storico = DB::table('storico_attivita_socio')->where('socio_id', $socio->id)->where('tipo_attivita', 'Modifica Dati')->sole();
        $this->assertSame($this->admin->id, $storico->utente_id);
        $this->assertStringContainsString('Città: "Bergamo" → "Brescia"', $storico->descrizione);
        $this->assertEquals($richiesta->proposte, json_decode($storico->dettagli_json, true));

        $richiesta->refresh();
        $this->assertSame(RichiestaModificaDati::STATO_APPROVATA, $richiesta->stato);
        $this->assertSame($this->admin->id, $richiesta->gestita_da);
        $this->assertNotNull($richiesta->gestita_at);
    }

    // ------------------------------------------------------------------
    // 19. Approvazione di una email diventata non unica
    // ------------------------------------------------------------------

    public function test_approvazione_di_una_modifica_email_diventata_non_unica_non_va_in_500_e_lascia_la_scheda_intatta(): void
    {
        $socioA = Socio::factory()->conAccessoAreaSoci(self::PASSWORD)->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'socio.a@example.com',
        ]);
        $socioB = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'socio.b@example.com',
        ]);

        // A propone una nuova email, libera in quel momento
        $this->actingAs($socioA, 'socio')
            ->post(route('area-soci.modifica-dati.store'), ['email' => 'nuova@example.com'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $richiesta = RichiestaModificaDati::withoutGlobalScopes()->where('socio_id', $socioA->id)->sole();

        // Nel frattempo la segreteria assegna proprio quell'email a B
        $this->actingAs($this->admin)
            ->put(route('soci.update', $socioB), $this->payloadSocio($socioB, ['email' => 'nuova@example.com']))
            ->assertSessionHasNoErrors();
        $this->assertSame('nuova@example.com', $socioB->fresh()->email);

        // L'admin approva la richiesta di A
        $risposta = $this->actingAs($this->admin)
            ->post(route('richieste-modifica-dati.approva', $richiesta));

        $this->assertNotSame(
            500,
            $risposta->status(),
            'Approvare una modifica email che nel frattempo è diventata di un altro socio non deve dare 500 '
            .'(ModificaDatiService::approva non rivalida l\'unicità → QueryException). Eccezione: '
            .($risposta->exception?->getMessage() ?? '-')
        );
        $risposta->assertRedirect(route('richieste-modifica-dati.index'))
            ->assertSessionHas('error');

        $this->assertSame('socio.a@example.com', $socioA->fresh()->email);
        $this->assertSame('nuova@example.com', $socioB->fresh()->email);
        $this->assertNotSame(RichiestaModificaDati::STATO_APPROVATA, $richiesta->fresh()->stato);
        $this->assertSame(0, DB::table('storico_attivita_socio')->where('socio_id', $socioA->id)->where('tipo_attivita', 'Modifica Dati')->count());
    }

    // ------------------------------------------------------------------
    // 20. Seconda richiesta pendente, rifiuto con motivo
    // ------------------------------------------------------------------

    public function test_seconda_richiesta_modifica_mentre_una_e_pendente_viene_rifiutata_e_il_rifiuto_salva_il_motivo_nelle_proposte(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci(self::PASSWORD)->create([
            'associazione_id' => $this->associazione->id,
            'citta' => 'Bergamo',
            'telefono' => '3331112222',
        ]);

        $this->actingAs($socio, 'socio')
            ->post(route('area-soci.modifica-dati.store'), ['citta' => 'Lecco'])
            ->assertSessionHas('success');

        $this->actingAs($socio, 'socio')
            ->post(route('area-soci.modifica-dati.store'), ['telefono' => '3339998888'])
            ->assertRedirect(route('area-soci.modifica-dati'))
            ->assertSessionHas('error');

        $this->assertSame(1, RichiestaModificaDati::withoutGlobalScopes()->where('socio_id', $socio->id)->count());
        $richiesta = RichiestaModificaDati::withoutGlobalScopes()->where('socio_id', $socio->id)->sole();
        $this->assertEquals(['citta' => 'Lecco'], $richiesta->proposte);
        $this->assertSame((new Socio)->getMorphClass(), $richiesta->origine_type);

        // Rifiuto senza motivo: non passa
        $this->actingAs($this->admin)
            ->post(route('richieste-modifica-dati.rifiuta', $richiesta), [])
            ->assertSessionHasErrors('motivo');
        $this->assertSame(RichiestaModificaDati::STATO_IN_ATTESA, $richiesta->fresh()->stato);

        $this->actingAs($this->admin)
            ->post(route('richieste-modifica-dati.rifiuta', $richiesta), ['motivo' => 'Serve un documento di residenza'])
            ->assertRedirect(route('richieste-modifica-dati.index'))
            ->assertSessionHas('success');

        $richiesta->refresh();
        $this->assertSame(RichiestaModificaDati::STATO_RIFIUTATA, $richiesta->stato);
        $this->assertSame('Serve un documento di residenza', $richiesta->proposte['_motivo_rifiuto'] ?? null);
        $this->assertSame('Lecco', $richiesta->proposte['citta']);
        $this->assertSame($this->admin->id, $richiesta->gestita_da);
        $this->assertSame('Bergamo', $socio->fresh()->citta);

        $this->assertDatabaseHas('storico_attivita_socio', [
            'socio_id' => $socio->id,
            'tipo_attivita' => 'Modifica Dati',
            'descrizione' => 'Richiesta di modifica dati rifiutata: Serve un documento di residenza',
        ]);

        // Il socio legge il motivo e può proporre di nuovo
        $this->actingAs($socio, 'socio')
            ->get(route('area-soci.modifica-dati'))
            ->assertOk()
            ->assertSee('Serve un documento di residenza');

        $this->actingAs($socio, 'socio')
            ->post(route('area-soci.modifica-dati.store'), ['telefono' => '3339998888'])
            ->assertSessionHas('success');
        $this->assertSame(2, RichiestaModificaDati::withoutGlobalScopes()->where('socio_id', $socio->id)->count());
    }

    // ------------------------------------------------------------------
    // 21. Permessi dell'operatore
    // ------------------------------------------------------------------

    public function test_operatore_senza_permesso_soci_update_non_puo_approvare_richieste_di_iscrizione_ne_modifica_dati(): void
    {
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Olga',
            'cognome' => 'Operatrice',
        ]);
        $operatore->assignRole(Utente::RUOLO_OPERATORE);

        $this->inviaIscrizioneOspite()->assertSessionHas('success');
        $iscrizione = $this->richiestaPerEmail('giulia.verdi@example.com');

        $socio = Socio::factory()->conAccessoAreaSoci()->create(['associazione_id' => $this->associazione->id, 'citta' => 'Bergamo']);
        $this->actingAs($socio, 'socio')
            ->post(route('area-soci.modifica-dati.store'), ['citta' => 'Lecco'])
            ->assertSessionHas('success');
        $modifica = RichiestaModificaDati::withoutGlobalScopes()->where('socio_id', $socio->id)->sole();

        // L'admin rende Olga "tesoriera": soci e iscrizioni in sola lettura
        $this->actingAs($this->admin)
            ->put(route('ruoli.update', $operatore), ['ruoli' => ['tesoriere']])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('ruoli.index'));

        $operatore->refresh();
        $this->assertFalse($operatore->hasPermissionTo('soci.update'));
        $this->assertFalse($operatore->hasPermissionTo('iscrizioni.update'));

        $this->actingAs($operatore)->get('/richieste-iscrizione')->assertOk();

        $this->actingAs($operatore)
            ->post(route('richieste-iscrizione.approva', $iscrizione), ['genera_tessera' => '1'])
            ->assertForbidden();
        $this->actingAs($operatore)
            ->post(route('richieste-modifica-dati.approva', $modifica))
            ->assertForbidden();

        $this->assertSame(RichiestaIscrizione::STATO_IN_ATTESA, $iscrizione->fresh()->stato);
        $this->assertSame(Socio::STATO_IN_ATTESA, Socio::withoutGlobalScopes()->find($iscrizione->socio_id)->stato);
        $this->assertSame(RichiestaModificaDati::STATO_IN_ATTESA, $modifica->fresh()->stato);
        $this->assertSame('Bergamo', $socio->fresh()->citta);

        // Torna operatrice: ora può
        $this->actingAs($this->admin)
            ->put(route('ruoli.update', $operatore), ['ruoli' => ['operatore']])
            ->assertSessionHasNoErrors();

        $operatore->refresh();
        $this->actingAs($operatore)
            ->post(route('richieste-iscrizione.approva', $iscrizione), ['genera_tessera' => '1'])
            ->assertSessionHas('success');
        $this->actingAs($operatore)
            ->post(route('richieste-modifica-dati.approva', $modifica))
            ->assertSessionHas('success');

        $this->assertSame(RichiestaIscrizione::STATO_APPROVATA, $iscrizione->fresh()->stato);
        $this->assertSame($operatore->id, $iscrizione->fresh()->gestita_da);
        $this->assertSame('Lecco', $socio->fresh()->citta);
        $this->assertSame($operatore->id, $modifica->fresh()->gestita_da);
    }

    // ------------------------------------------------------------------
    // 22. Export GDPR dalla segreteria
    // ------------------------------------------------------------------

    public function test_export_gdpr_del_socio_dalla_segreteria_contiene_consensi_quote_tessere_e_note_e_viene_tracciato(): void
    {
        Storage::fake('local');
        Storage::fake('personal-data-exports');

        $socio = Socio::factory()->conAccessoAreaSoci()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Irene',
            'cognome' => 'Privata',
            'note' => 'Nota in anagrafica',
        ]);
        Quota::factory()->create(['associazione_id' => $this->associazione->id, 'socio_id' => $socio->id, 'anno' => 2026]);
        Tessera::factory()->create(['socio_id' => $socio->id]);
        NotaSocio::create(['associazione_id' => $this->associazione->id, 'socio_id' => $socio->id, 'autore_id' => $this->admin->id, 'testo' => 'Preferisce essere contattata la sera']);
        Consenso::query()->withoutGlobalScopes()->create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $socio->id,
            'chiave' => 'privacy',
            'testo' => 'Ho letto l\'informativa',
            'accettato' => true,
            'origine' => 'iscrizione_online',
            'ip' => '10.0.0.1',
        ]);
        Storage::disk('local')->put('documenti_soci/ci.pdf', 'documento');
        DocumentoSocio::create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $socio->id,
            'nome_file' => 'carta identità.pdf',
            'percorso_file' => 'documenti_soci/ci.pdf',
            'tipo_documento' => 'identita',
        ]);

        $risposta = $this->actingAs($this->admin)->get(route('soci.dati-personali', $socio));
        $risposta->assertOk()
            ->assertHeader('content-type', 'application/zip')
            ->assertDownload('dati-personali-privata-irene.zip');
        $this->assertStringStartsWith('PK', $risposta->streamedContent(), 'Il file scaricato deve essere uno ZIP.');

        // Il dati.json dentro lo ZIP scaricato contiene davvero le sezioni
        $zipLocale = tempnam(sys_get_temp_dir(), 'gdpr');
        file_put_contents($zipLocale, $risposta->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipLocale));
        $datiZip = json_decode((string) $zip->getFromName('dati.json'), true, 512, JSON_THROW_ON_ERROR);
        $documentoNelloZip = collect(range(0, $zip->numFiles - 1))
            ->contains(fn (int $i) => str_starts_with((string) $zip->getNameIndex($i), 'documenti/'));
        $zip->close();
        unlink($zipLocale);

        $this->assertSame('iscrizione_online', $datiZip['consensi'][0]['origine']);
        $this->assertCount(1, $datiZip['consensi']);
        $this->assertCount(1, $datiZip['quote']);
        $this->assertCount(1, $datiZip['tessere']);
        $this->assertCount(1, $datiZip['documenti']);
        $this->assertSame(
            ['Nota in anagrafica', 'Preferisce essere contattata la sera'],
            array_column($datiZip['note_interne'], 'testo')
        );
        $this->assertTrue($documentoNelloZip, 'Il file del documento deve essere nello ZIP.');

        $this->assertDatabaseHas('activity_log', [
            'subject_id' => $socio->id,
            'causer_id' => $this->admin->id,
            'event' => 'dati_personali_esportati',
        ]);

        $dati = app(DatiPersonali::class)->socio($socio->fresh());
        $this->assertCount(1, $dati['consensi']);
        $this->assertSame('iscrizione_online', $dati['consensi'][0]['origine']);
        $this->assertCount(1, $dati['quote']);
        $this->assertCount(1, $dati['tessere']);
        $this->assertCount(1, $dati['documenti']);
        $this->assertCount(2, $dati['note_interne']);
        $this->assertSame('Nota in anagrafica', $dati['note_interne'][0]['testo']);
        $this->assertSame('Preferisce essere contattata la sera', $dati['note_interne'][1]['testo']);
        $this->assertSame('Anna Presidente', $dati['note_interne'][1]['autore']);

        foreach ($this->chiaviRicorsive($dati) as $chiave) {
            $this->assertStringNotContainsString('token', $chiave, "Chiave segreta esportata: {$chiave}");
            $this->assertStringNotContainsString('password', $chiave, "Chiave segreta esportata: {$chiave}");
        }

        // Un admin di un'altra associazione non può esportarlo
        $adminAltra = $this->creaAdmin(Associazione::factory()->create());
        $this->actingAs($adminAltra)->get(route('soci.dati-personali', $socio))->assertNotFound();
    }

    // ------------------------------------------------------------------
    // 23. Cancellazione account con richiesta pendente
    // ------------------------------------------------------------------

    public function test_cancellazione_account_con_richiesta_in_attesa_anonimizza_e_chiude_la_richiesta_eliminando_il_preiscritto(): void
    {
        $account = $this->accountVerificato(['nome' => 'Nadia', 'cognome' => 'Ritirata', 'email' => 'nadia@example.com']);

        $this->actingAs($account, 'account')
            ->post($this->url('iscriviti'), $this->datiAccount())
            ->assertSessionHas('success');

        $richiesta = RichiestaIscrizione::withoutGlobalScopes()->where('account_id', $account->id)->sole();
        $socioId = $richiesta->socio_id;
        $this->assertNotNull($socioId);

        // Password sbagliata: nulla cambia
        $this->actingAs($account, 'account')
            ->from($this->url('account/privacy'))
            ->post($this->url('account/privacy/cancella'), ['password' => 'sbagliata-davvero'])
            ->assertRedirect($this->url('account/privacy'))
            ->assertSessionHasErrors('password');

        $this->assertFalse($account->fresh()->trashed());
        $this->assertSame('Nadia', $account->fresh()->nome);
        $this->assertSame(RichiestaIscrizione::STATO_IN_ATTESA, $richiesta->fresh()->stato);
        $this->assertNotNull(Socio::withoutGlobalScopes()->find($socioId));

        // Password corretta
        $this->actingAs($account, 'account')
            ->post($this->url('account/privacy/cancella'), ['password' => self::PASSWORD])
            ->assertRedirect($this->url())
            ->assertSessionHas('success');

        $anonimo = Account::withoutGlobalScopes()->withTrashed()->findOrFail($account->id);
        $this->assertSame('Account', $anonimo->nome);
        $this->assertSame('Anonimizzato', $anonimo->cognome);
        $this->assertSame('anonimizzato-'.$account->id.'@anonimo.invalid', $anonimo->email);
        $this->assertFalse($anonimo->attivo);
        $this->assertNull($anonimo->telefono);
        $this->assertNull($anonimo->preferenze);
        $this->assertNotNull($anonimo->deleted_at);

        $richiesta->refresh();
        $this->assertSame(RichiestaIscrizione::STATO_RIFIUTATA, $richiesta->stato);
        $this->assertSame('Account cancellato dall\'interessato', $richiesta->motivo_rifiuto);
        $this->assertSame([], $richiesta->dati_form);
        $this->assertNull($richiesta->socio_id);

        $this->assertNull(Socio::withoutGlobalScopes()->withTrashed()->find($socioId), 'Il preiscritto va eliminato definitivamente.');
        $this->assertSame(0, DB::table('consensi')->where('socio_id', $socioId)->count());

        // Le vecchie credenziali non funzionano più
        $this->comeOspite();
        $this->from($this->url('login'))
            ->post($this->url('login'), ['email' => 'nadia@example.com', 'password' => self::PASSWORD])
            ->assertSessionHasErrors('email');
        $this->assertGuest('account');

        // E l'email è libera per una nuova iscrizione da ospite
        $this->inviaIscrizioneOspite(['email' => 'nadia@example.com'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');
    }

    // ------------------------------------------------------------------
    // 24. Cancellazione account di un socio attivo
    // ------------------------------------------------------------------

    public function test_cancellazione_account_gia_socio_attivo_non_tocca_il_socio_ma_scollega_l_account(): void
    {
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Teresa',
            'cognome' => 'Fedele',
            'email' => 'teresa@example.com',
            'stato' => Socio::STATO_ATTIVO,
        ]);
        $tessera = Tessera::factory()->create(['socio_id' => $socio->id]);

        $account = $this->accountVerificato(['nome' => 'Teresa', 'cognome' => 'Fedele', 'email' => 'teresa@example.com']);
        $account->forceFill(['socio_id' => $socio->id])->save();

        $approvata = RichiestaIscrizione::factory()->approvata($this->admin)->create([
            'associazione_id' => $this->associazione->id,
            'account_id' => $account->id,
            'socio_id' => $socio->id,
            'delibera_numero' => '3/2026',
            'dati_form' => ['telefono' => '3331234567'],
        ]);

        $this->actingAs($account->fresh(), 'account')
            ->post($this->url('account/privacy/cancella'), ['password' => self::PASSWORD])
            ->assertRedirect($this->url())
            ->assertSessionHas('success');

        $socio->refresh();
        $this->assertFalse($socio->trashed());
        $this->assertSame(Socio::STATO_ATTIVO, $socio->stato);
        $this->assertSame('Teresa', $socio->nome);
        $this->assertSame('teresa@example.com', $socio->email);
        $this->assertNotNull(Tessera::withoutGlobalScopes()->find($tessera->id));

        $anonimo = Account::withoutGlobalScopes()->withTrashed()->findOrFail($account->id);
        $this->assertNull($anonimo->socio_id);
        $this->assertTrue($anonimo->trashed());

        $approvata->refresh();
        $this->assertSame(RichiestaIscrizione::STATO_APPROVATA, $approvata->stato);
        $this->assertSame($socio->id, $approvata->socio_id);
        $this->assertSame('3/2026', $approvata->delibera_numero);
        $this->assertSame(['telefono' => '3331234567'], $approvata->dati_form);
    }

    // ------------------------------------------------------------------
    // 25. Modalità diretta da ospite
    // ------------------------------------------------------------------

    public function test_modalita_diretta_da_ospite_crea_socio_attivo_quota_con_token_e_consensi_e_non_lascia_richieste_pendenti(): void
    {
        $this->associazione->forceFill(['costo_tessera' => 20.00])->save();

        $this->configuraModulo([
            'telefono' => [true, true],
            'data_nascita' => [true, true],
            'citta' => [true, false],
        ], [], Associazione::MODALITA_DIRETTA);

        // L'impostazione è coerente tra tabella settings e colonna legacy
        $this->assertSame(Associazione::MODALITA_DIRETTA, DB::table('associazioni')->where('id', $this->associazione->id)->value('modalita_iscrizione'));
        $payload = DB::table('settings')
            ->where('associazione_id', $this->associazione->id)
            ->where('group', 'iscrizioni')
            ->where('name', 'modalita_iscrizione')
            ->value('payload');
        $this->assertSame(Associazione::MODALITA_DIRETTA, json_decode((string) $payload, true));
        $this->assertTrue($this->associazione->fresh()->isIscrizioneDiretta());

        $this->inviaIscrizioneOspite()
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->url())
            ->assertSessionHas('success', fn (string $msg) => str_contains($msg, 'Benvenuto/a'));

        $socio = Socio::withoutGlobalScopes()->where('email', 'giulia.verdi@example.com')->sole();
        $this->assertSame(Socio::STATO_ATTIVO, $socio->stato);

        $richiesta = RichiestaIscrizione::withoutGlobalScopes()->where('socio_id', $socio->id)->sole();
        $this->assertSame(RichiestaIscrizione::STATO_APPROVATA, $richiesta->stato);
        $this->assertNotNull($richiesta->gestita_at);
        $this->assertNull($richiesta->gestita_da);
        $this->assertNull($richiesta->account_id);

        $quota = Quota::withoutGlobalScopes()->where('socio_id', $socio->id)->sole();
        $this->assertSame('20.00', number_format((float) $quota->importo, 2, '.', ''));
        $this->assertSame(Quota::STATO_DA_PAGARE, $quota->stato);
        $this->assertNotNull($quota->payment_token);
        $this->assertTrue($quota->payment_token_expires->isFuture());

        $this->assertDatabaseHas('consensi', ['socio_id' => $socio->id, 'chiave' => 'privacy', 'accettato' => true, 'origine' => 'iscrizione_online']);

        // L'admin non ha nulla da approvare
        $this->assertSame(0, RichiestaIscrizione::withoutGlobalScopes()->where('stato', RichiestaIscrizione::STATO_IN_ATTESA)->count());
        $this->actingAs($this->admin)->get('/richieste-iscrizione')
            ->assertOk()
            ->assertDontSee(route('richieste-iscrizione.approva', $richiesta));

        $this->actingAs($this->admin)->get('/quote')
            ->assertOk()
            ->assertSee('Verdi');

        // Il link di pagamento pubblico funziona
        $this->comeOspite();
        $this->get('/pagamento/'.$quota->payment_token)
            ->assertOk()
            ->assertSee('20,00');
    }
}
