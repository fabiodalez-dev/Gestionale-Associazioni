<?php

namespace Tests\Feature\Percorsi;

use App\Http\Controllers\Soci\CampoPersonalizzatoController;
use App\Models\Associazione;
use App\Models\CampoPersonalizzato;
use App\Models\RichiestaIscrizione;
use App\Models\Socio;
use App\Models\Utente;
use App\Models\ValoreCampoPersonalizzato;
use App\Services\Iscrizioni\FormIscrizioneService;
use App\Services\Struttura\CloneAssociazioneConfigService;
use App\Settings\ModuliSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use ZipArchive;

/**
 * Percorsi reali — CAMPI PERSONALIZZATI E MODULI.
 *
 * Un'associazione decide quali dati raccogliere sui propri soci oltre
 * all'anagrafica standard: crea i campi con il tipo giusto, li rende
 * obbligatori dove serve, li mette nel modulo di iscrizione pubblico e poi,
 * inevitabilmente, li cambia quando ci sono già dati dentro: rinomina,
 * cambia tipo, aggiunge o toglie opzioni, elimina. Ogni test ripercorre
 * uno di questi scenari con più richieste HTTP in sequenza (admin,
 * operatore, visitatore del sito) e controlla database e pagine.
 */
class Percorso06_CampiPersonalizzatiModuliTest extends TestCase
{
    use RefreshDatabase;

    private const URL_CAMPI = '/config-campi';

    private Associazione $associazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['honeypot.enabled' => false]);
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->associazione = Associazione::factory()->create([
            'nome' => 'Banda Musicale Santa Cecilia',
            'frontend_pubblico_attivo' => true,
        ]);

        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
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

    /**
     * L'admin crea un campo dalla pagina /config-campi, come farebbe dal browser.
     *
     * @param  array<string, mixed>  $attrs
     */
    private function creaCampo(string $nome, string $tipo, array $attrs = []): CampoPersonalizzato
    {
        $this->actingAs($this->admin)
            ->post(self::URL_CAMPI, array_merge([
                'nome_campo' => $nome,
                'tipo_campo' => $tipo,
            ], $attrs))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('config-campi.index'));

        return CampoPersonalizzato::query()->where('nome_campo', $nome)->sole();
    }

    /**
     * Payload del form di modifica inline di /config-campi (gli stessi input
     * della vista: nome, tipo, descrizione, opzioni, obbligatorio).
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function payloadModificaCampo(CampoPersonalizzato $campo, array $override = []): array
    {
        return array_merge([
            'nome_campo' => $campo->nome_campo,
            'tipo_campo' => $campo->tipo_campo,
            'descrizione' => $campo->descrizione,
            'opzioni' => $campo->opzioni,
            'obbligatorio' => $campo->obbligatorio ? '1' : null,
        ], $override);
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function payloadSocio(array $override = []): array
    {
        return array_merge([
            'nome' => 'Mario',
            'cognome' => 'Rossi',
            'email' => 'mario.rossi@example.com',
            'data_nascita' => '1985-12-10',
            'data_iscrizione' => now()->toDateString(),
            'stato' => Socio::STATO_ATTIVO,
            'telefono' => '3331234567',
            'privacy_consenso' => '1',
        ], $override);
    }

    /**
     * Payload della modifica di un socio esistente (numero_socio è obbligatorio in update).
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function payloadModificaSocio(Socio $socio, array $override = []): array
    {
        return array_merge([
            'nome' => $socio->nome,
            'cognome' => $socio->cognome,
            'email' => $socio->email,
            'numero_socio' => $socio->numero_socio,
            'data_nascita' => '1985-12-10',
            'data_iscrizione' => now()->toDateString(),
            'stato' => Socio::STATO_ATTIVO,
            'telefono' => '3331234567',
            'privacy_consenso' => '1',
        ], $override);
    }

    /**
     * Payload del costruttore del modulo di iscrizione pubblico.
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function payloadModulo(array $override = []): array
    {
        $campiStandard = [];
        foreach (FormIscrizioneService::CAMPI_STANDARD as $i => $campo) {
            $campiStandard[$campo] = ['ordine' => $i + 1];
        }

        return array_merge([
            'iscrizioni_socio_aperte' => '1',
            'modalita_iscrizione' => Associazione::MODALITA_PREISCRIZIONE,
            'intro_html' => '',
            'campi_standard' => $campiStandard,
            'campi_personalizzati' => [],
        ], $override);
    }

    private function socioConValore(CampoPersonalizzato $campo, string $valore, array $attrs = []): Socio
    {
        $socio = Socio::factory()->create(array_merge(['associazione_id' => $this->associazione->id], $attrs));

        ValoreCampoPersonalizzato::create([
            'socio_id' => $socio->id,
            'campo_id' => $campo->id,
            'valore' => $valore,
        ]);

        return $socio;
    }

    private function valore(Socio $socio, CampoPersonalizzato $campo): ?string
    {
        return ValoreCampoPersonalizzato::query()
            ->where('socio_id', $socio->id)
            ->where('campo_id', $campo->id)
            ->value('valore');
    }

    private function urlTenant(string $path = ''): string
    {
        return '/a/'.$this->associazione->slug.($path !== '' ? '/'.ltrim($path, '/') : '');
    }

    private function assertHtmlMatches(string $regex, TestResponse $risposta, string $messaggio = ''): void
    {
        $this->assertMatchesRegularExpression($regex, $risposta->getContent(), $messaggio);
    }

    private function assertHtmlNotMatches(string $regex, TestResponse $risposta, string $messaggio = ''): void
    {
        $this->assertDoesNotMatchRegularExpression($regex, $risposta->getContent(), $messaggio);
    }

    // ------------------------------------------------------------------
    // Percorso 1 — un campo per ogni tipo, con il widget giusto e in ordine
    // ------------------------------------------------------------------

    public function test_percorso_1_l_admin_crea_un_campo_per_ogni_tipo_e_il_form_socio_li_mostra_col_widget_giusto_e_in_ordine(): void
    {
        // Li crea in ordine casuale: nel form devono comparire per "ordine", non per data di creazione
        $definizioni = [
            ['Biografia', 'textarea', 7],
            ['Soprannome', 'text', 1],
            ['Newsletter cartacea', 'checkbox', 9],
            ['Email di lavoro', 'email', 2],
            ['Titolo di studio', 'select', 8, "Diploma\nLaurea\nDottorato"],
            ['Cellulare secondario', 'tel', 3],
            ['Sito personale', 'url', 6],
            ['Numero di scarpe', 'number', 4],
            ['Data del battesimo', 'date', 5],
        ];

        $campi = [];
        foreach ($definizioni as [$nome, $tipo, $ordine]) {
            $campi[$tipo] = $this->creaCampo($nome, $tipo, [
                'ordine' => $ordine,
                'opzioni' => $tipo === 'select' ? "Diploma\nLaurea\nDottorato" : null,
            ]);
        }

        $this->assertCount(9, CampoPersonalizzato::TIPI_CAMPO);
        $this->assertSame(9, CampoPersonalizzato::query()->count());
        $this->assertSame($this->associazione->id, $campi['text']->associazione_id);

        // La pagina di configurazione li elenca tutti
        $pagina = $this->actingAs($this->admin)->get(self::URL_CAMPI)->assertOk();
        foreach ($definizioni as [$nome]) {
            $pagina->assertSee($nome);
        }

        // Il form di creazione socio: ordine impostato dall'admin...
        $form = $this->actingAs($this->admin)->get(route('soci.create'))->assertOk();
        $form->assertSeeInOrder([
            'Soprannome', 'Email di lavoro', 'Cellulare secondario', 'Numero di scarpe',
            'Data del battesimo', 'Sito personale', 'Biografia', 'Titolo di studio', 'Newsletter cartacea',
        ]);

        // ...e widget coerente col tipo
        foreach (['text', 'email', 'tel', 'number', 'date', 'url'] as $tipo) {
            $this->assertHtmlMatches(
                '/<input type="'.$tipo.'"[^>]*id="campo_'.preg_quote($campi[$tipo]->id, '/').'"/',
                $form,
                "Il campo di tipo {$tipo} deve essere un <input type=\"{$tipo}\">"
            );
        }
        $this->assertHtmlMatches('/<textarea[^>]*id="campo_'.preg_quote($campi['textarea']->id, '/').'"/', $form);
        $this->assertHtmlMatches('/<input[^>]*type="checkbox"[^>]*id="campo_'.preg_quote($campi['checkbox']->id, '/').'"/', $form);
        $this->assertHtmlMatches('/<select[^>]*id="campo_'.preg_quote($campi['select']->id, '/').'"/', $form);
        $form->assertSee('<option value="Diploma"', false)
            ->assertSee('<option value="Laurea"', false)
            ->assertSee('<option value="Dottorato"', false);

        // Stesso risultato nel form di modifica di un socio esistente
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        $modifica = $this->actingAs($this->admin)->get(route('soci.edit', $socio))->assertOk();
        $modifica->assertSeeInOrder(['Soprannome', 'Email di lavoro', 'Titolo di studio', 'Newsletter cartacea']);
        $this->assertHtmlMatches('/<select[^>]*id="campo_'.preg_quote($campi['select']->id, '/').'"/', $modifica);
        $this->assertHtmlMatches('/<input[^>]*type="checkbox"[^>]*id="campo_'.preg_quote($campi['checkbox']->id, '/').'"/', $modifica);
    }

    // ------------------------------------------------------------------
    // Percorso 2 — valori validi salvati, valori sbagliati rifiutati per tipo
    // ------------------------------------------------------------------

    public function test_percorso_2_il_form_socio_salva_i_valori_validi_e_rifiuta_quelli_sbagliati_campo_per_campo(): void
    {
        $testo = $this->creaCampo('Soprannome', 'text');
        $email = $this->creaCampo('Email di lavoro', 'email');
        $tel = $this->creaCampo('Cellulare secondario', 'tel');
        $numero = $this->creaCampo('Numero di scarpe', 'number');
        $data = $this->creaCampo('Data del battesimo', 'date');
        $url = $this->creaCampo('Sito personale', 'url');
        $textarea = $this->creaCampo('Biografia', 'textarea');
        $select = $this->creaCampo('Titolo di studio', 'select', ['opzioni' => "Diploma\nLaurea\nDottorato"]);
        $check = $this->creaCampo('Newsletter cartacea', 'checkbox');

        // Compilazione corretta
        $this->actingAs($this->admin)
            ->post(route('soci.store'), $this->payloadSocio([
                'custom_field' => [
                    $testo->id => 'Gigi',
                    $email->id => 'gigi.lavoro@example.com',
                    $tel->id => '3339876543',
                    $numero->id => '42',
                    $data->id => '1990-05-04',
                    $url->id => 'https://gigi.example.it',
                    $textarea->id => "Suona il clarinetto dal 1998.\nDirige la banda giovanile.",
                    $select->id => 'Laurea',
                    $check->id => '1',
                ],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $socio = Socio::query()->where('email', 'mario.rossi@example.com')->sole();
        $attesi = [
            $testo->id => 'Gigi',
            $email->id => 'gigi.lavoro@example.com',
            $tel->id => '3339876543',
            $numero->id => '42',
            $data->id => '1990-05-04',
            $url->id => 'https://gigi.example.it',
            $textarea->id => "Suona il clarinetto dal 1998.\nDirige la banda giovanile.",
            $select->id => 'Laurea',
            $check->id => '1',
        ];
        foreach ($attesi as $campoId => $valore) {
            $this->assertDatabaseHas('valori_campi_personalizzati', [
                'socio_id' => $socio->id,
                'campo_id' => $campoId,
                'valore' => $valore,
            ]);
        }
        $this->assertSame(9, ValoreCampoPersonalizzato::query()->where('socio_id', $socio->id)->count());

        // La scheda mostra i valori, la checkbox come "Sì"
        $this->actingAs($this->admin)->get(route('soci.show', $socio))
            ->assertOk()
            ->assertSee('Gigi')
            ->assertSee('gigi.lavoro@example.com')
            ->assertSee('https://gigi.example.it')
            ->assertSee('Laurea')
            ->assertSeeInOrder(['Newsletter cartacea', 'Sì']);

        // Compilazione sbagliata: un errore per ogni campo, con il nome del campo, e nessun socio creato
        $risposta = $this->actingAs($this->admin)
            ->from(route('soci.create'))
            ->post(route('soci.store'), $this->payloadSocio([
                'email' => 'luigi.bianchi@example.com',
                'custom_field' => [
                    $email->id => 'gigi-chiocciola-example',
                    $numero->id => '12abc',
                    $data->id => '2024-02-30',
                    $url->id => 'www.senza-schema.it',
                    $select->id => 'Dottorato in magia',
                    $testo->id => 'Va bene',
                    $check->id => '1',
                ],
            ]));

        $risposta->assertRedirect(route('soci.create'))
            ->assertSessionHasErrors([
                'custom_field.'.$email->id,
                'custom_field.'.$numero->id,
                'custom_field.'.$data->id,
                'custom_field.'.$url->id,
                'custom_field.'.$select->id,
            ])
            ->assertSessionDoesntHaveErrors(['custom_field.'.$testo->id, 'custom_field.'.$check->id]);

        $errori = session('errors');
        $this->assertStringContainsString('Email di lavoro', $errori->first('custom_field.'.$email->id));
        $this->assertStringContainsString('Numero di scarpe', $errori->first('custom_field.'.$numero->id));
        $this->assertStringContainsString('Data del battesimo', $errori->first('custom_field.'.$data->id));
        $this->assertStringContainsString('Sito personale', $errori->first('custom_field.'.$url->id));
        $this->assertStringContainsString('Titolo di studio', $errori->first('custom_field.'.$select->id));
        $this->assertStringNotContainsString('custom', $errori->first('custom_field.'.$email->id));

        $this->assertDatabaseMissing('soci', ['email' => 'luigi.bianchi@example.com']);
        $this->assertSame(9, ValoreCampoPersonalizzato::query()->count());

        // Il form ricaricato dopo il redirect segnala l'errore accanto al campo giusto
        $this->actingAs($this->admin)
            ->followingRedirects()
            ->from(route('soci.create'))
            ->post(route('soci.store'), $this->payloadSocio([
                'email' => 'luigi.bianchi@example.com',
                'custom_field' => [$email->id => 'gigi-chiocciola-example'],
            ]))
            ->assertOk()
            ->assertSee('Il campo Email di lavoro deve essere un indirizzo email valido.')
            ->assertSee('value="gigi-chiocciola-example"', false);
        $this->assertDatabaseMissing('soci', ['email' => 'luigi.bianchi@example.com']);
    }

    // ------------------------------------------------------------------
    // Percorso 3 — obbligatorio in segreteria vs obbligatorio in preiscrizione
    // ------------------------------------------------------------------

    public function test_percorso_3_un_campo_obbligatorio_in_segreteria_e_uno_solo_nella_preiscrizione_pubblica(): void
    {
        $strumento = $this->creaCampo('Strumento suonato', 'text', ['obbligatorio' => '1']);
        $taglia = $this->creaCampo('Taglia divisa', 'select', ['opzioni' => "S\nM\nL\nXL"]);

        // Dalla pagina Configurazioni → Campi obbligatori: "Taglia divisa" obbligatoria solo nel modulo pubblico
        $this->actingAs($this->admin)
            ->post(route('configurazioni.campi-obbligatori'), [
                'required_backend' => [],
                'required_preiscrizione' => [],
                'required_custom_backend' => [$strumento->id],
                'required_custom_preiscrizione' => [$taglia->id],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $strumento->refresh();
        $taglia->refresh();
        $this->assertTrue($strumento->obbligatorio);
        $this->assertFalse($strumento->obbligatorio_preiscrizione);
        $this->assertFalse($taglia->obbligatorio);
        $this->assertTrue($taglia->obbligatorio_preiscrizione);

        // Scheda admin: lo strumento è richiesto (asterisco e attributo required), la taglia no
        $form = $this->actingAs($this->admin)->get(route('soci.create'))->assertOk();
        $form->assertSee('Strumento suonato *');
        $this->assertHtmlMatches('/<input[^>]*id="campo_'.preg_quote($strumento->id, '/').'"[^>]*required/', $form);
        $this->assertHtmlNotMatches('/<select[^>]*id="campo_'.preg_quote($taglia->id, '/').'"[^>]*required/', $form);

        $this->actingAs($this->admin)
            ->post(route('soci.store'), $this->payloadSocio(['custom_field' => [$taglia->id => 'M']]))
            ->assertSessionHasErrors(['custom_field.'.$strumento->id])
            ->assertSessionDoesntHaveErrors(['custom_field.'.$taglia->id]);
        $this->assertDatabaseMissing('soci', ['email' => 'mario.rossi@example.com']);

        $this->actingAs($this->admin)
            ->post(route('soci.store'), $this->payloadSocio(['custom_field' => [$strumento->id => 'Tromba']]))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('soci', ['email' => 'mario.rossi@example.com']);

        // Per un socio ancora "In Attesa" l'obbligo non si applica (dati incompleti ammessi)
        $this->actingAs($this->admin)
            ->post(route('soci.store'), $this->payloadSocio([
                'email' => 'attesa@example.com',
                'stato' => Socio::STATO_IN_ATTESA,
            ]))
            ->assertSessionHasNoErrors();

        // Modulo pubblico di preiscrizione: la taglia è richiesta, lo strumento no
        $pubblico = $this->get('/preiscrizione/'.$this->associazione->id)->assertOk();
        $pubblico->assertSee('Taglia divisa')->assertSee('Strumento suonato');
        $this->assertHtmlMatches('/<select[^>]*id="custom_'.preg_quote($taglia->id, '/').'"[^>]*required/', $pubblico);
        $this->assertHtmlNotMatches('/<input[^>]*id="custom_'.preg_quote($strumento->id, '/').'"[^>]*required/', $pubblico);

        $datiPubblici = [
            'nome' => 'Giulia',
            'cognome' => 'Verdi',
            'email' => 'giulia.verdi@example.com',
            'privacy_consenso' => '1',
        ];

        $this->post('/preiscrizione/'.$this->associazione->id, $datiPubblici)
            ->assertSessionHasErrors(['custom_field.'.$taglia->id])
            ->assertSessionDoesntHaveErrors(['custom_field.'.$strumento->id]);
        $this->assertDatabaseMissing('soci', ['email' => 'giulia.verdi@example.com']);

        $this->post('/preiscrizione/'.$this->associazione->id, $datiPubblici + [
            'custom_field' => [$taglia->id => 'L'],
        ])->assertSessionHasNoErrors();

        $giulia = Socio::query()->where('email', 'giulia.verdi@example.com')->sole();
        $this->assertSame(Socio::STATO_IN_ATTESA, $giulia->stato);
        $this->assertSame('L', $this->valore($giulia, $taglia));
        $this->assertNull($this->valore($giulia, $strumento));
    }

    // ------------------------------------------------------------------
    // Percorso 4 — rinomina, descrizione, ordine e vincolo di unicità
    // ------------------------------------------------------------------

    public function test_percorso_4_modificare_un_campo_con_dati_gia_inseriti_non_perde_i_valori_e_il_nome_doppio_da_un_errore_chiaro(): void
    {
        $professione = $this->creaCampo('Professione', 'text', ['ordine' => 2]);
        $hobby = $this->creaCampo('Hobby', 'text', ['ordine' => 1]);

        // Il flag "obbligatorio in preiscrizione" viene dalla pagina Configurazioni
        $this->actingAs($this->admin)->post(route('configurazioni.campi-obbligatori'), [
            'required_custom_preiscrizione' => [$professione->id],
        ])->assertRedirect();
        $this->assertTrue($professione->fresh()->obbligatorio_preiscrizione);

        $anna = $this->socioConValore($professione, 'Insegnante', ['nome' => 'Anna', 'cognome' => 'Moretti']);
        $luca = $this->socioConValore($professione, 'Falegname', ['nome' => 'Luca', 'cognome' => 'Ferri']);

        // Rinomina + descrizione + ordine dal form inline di /config-campi
        $this->actingAs($this->admin)
            ->put(route('config-campi.update', $professione), $this->payloadModificaCampo($professione, [
                'nome_campo' => 'Mestiere',
                'descrizione' => 'Attività lavorativa principale',
                'ordine' => 0,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('config-campi.index'));

        $professione->refresh();
        $this->assertSame('Mestiere', $professione->nome_campo);
        $this->assertSame('Attività lavorativa principale', $professione->descrizione);
        $this->assertSame(0, $professione->ordine);
        $this->assertTrue($professione->obbligatorio_preiscrizione, 'Rinominare il campo non deve azzerare il flag di preiscrizione');

        // I valori restano agganciati allo stesso campo (stesso id)
        $this->assertSame(2, ValoreCampoPersonalizzato::query()->where('campo_id', $professione->id)->count());
        $this->assertSame('Insegnante', $this->valore($anna, $professione));
        $this->assertSame('Falegname', $this->valore($luca, $professione));

        // La scheda socio mostra il nuovo nome con il vecchio valore
        $this->actingAs($this->admin)->get(route('soci.show', $anna))
            ->assertOk()
            ->assertSeeInOrder(['Mestiere', 'Insegnante'])
            ->assertDontSee('Professione');

        // Nel form di modifica il campo rinominato precede "Hobby" (ordine 0 < 1) e mostra la descrizione
        $this->actingAs($this->admin)->get(route('soci.edit', $luca))
            ->assertOk()
            ->assertSeeInOrder(['Mestiere', 'Hobby'])
            ->assertSee('Attività lavorativa principale')
            ->assertSee('value="Falegname"', false);

        // Nome già usato da un altro campo: errore di validazione, non 500
        $this->actingAs($this->admin)
            ->from(route('config-campi.index'))
            ->put(route('config-campi.update', $hobby), $this->payloadModificaCampo($hobby, ['nome_campo' => 'Mestiere']))
            ->assertRedirect(route('config-campi.index'))
            ->assertSessionHasErrors(['nome_campo']);
        $this->assertSame('Hobby', $hobby->fresh()->nome_campo);

        $this->actingAs($this->admin)
            ->from(route('config-campi.index'))
            ->post(self::URL_CAMPI, ['nome_campo' => 'Mestiere', 'tipo_campo' => 'text'])
            ->assertRedirect(route('config-campi.index'))
            ->assertSessionHasErrors(['nome_campo']);
        $this->assertSame(2, CampoPersonalizzato::query()->count());

        // Rimandare lo stesso nome al campo stesso invece non è un conflitto
        $this->actingAs($this->admin)
            ->put(route('config-campi.update', $professione), $this->payloadModificaCampo($professione->fresh()))
            ->assertSessionHasNoErrors();
    }

    // ------------------------------------------------------------------
    // Percorso 5 — cambio di tipo con valori esistenti
    // ------------------------------------------------------------------

    public function test_percorso_5_cambiare_il_tipo_di_un_campo_con_valori_esistenti_non_rompe_scheda_elenco_e_form_di_modifica(): void
    {
        $professione = $this->creaCampo('Professione', 'text');
        $livello = $this->creaCampo('Livello musicale', 'select', ['opzioni' => "Base\nIntermedio\nAvanzato"]);
        $anni = $this->creaCampo('Anni di esperienza', 'number');
        $volontario = $this->creaCampo('Disponibile come volontario', 'checkbox');
        $brevetto = $this->creaCampo('Data brevetto', 'text');

        $anna = Socio::factory()->create(['associazione_id' => $this->associazione->id, 'nome' => 'Anna', 'cognome' => 'Moretti']);
        $luca = Socio::factory()->create(['associazione_id' => $this->associazione->id, 'nome' => 'Luca', 'cognome' => 'Ferri']);
        foreach ([
            [$anna, $professione, 'Insegnante'], [$luca, $professione, 'Avvocato'],
            [$anna, $livello, 'Base'], [$anna, $anni, '12'], [$anna, $volontario, '1'],
            [$anna, $brevetto, 'primavera 2019'],
        ] as [$socio, $campo, $valore]) {
            ValoreCampoPersonalizzato::create(['socio_id' => $socio->id, 'campo_id' => $campo->id, 'valore' => $valore]);
        }

        // Cambiare in select senza opzioni viene fermato dalla validazione
        $this->actingAs($this->admin)
            ->put(route('config-campi.update', $professione), $this->payloadModificaCampo($professione, ['tipo_campo' => 'select']))
            ->assertSessionHasErrors(['opzioni']);
        $this->assertSame('text', $professione->fresh()->tipo_campo);

        // text → select (le opzioni coprono Anna ma non Luca), select → text, number → text, checkbox → text, text → date
        foreach ([
            [$professione, ['tipo_campo' => 'select', 'opzioni' => "Insegnante\nOperaio"]],
            [$livello, ['tipo_campo' => 'text']],
            [$anni, ['tipo_campo' => 'text']],
            [$volontario, ['tipo_campo' => 'text']],
            [$brevetto, ['tipo_campo' => 'date']],
        ] as [$campo, $modifiche]) {
            $this->actingAs($this->admin)
                ->put(route('config-campi.update', $campo), $this->payloadModificaCampo($campo, $modifiche))
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('config-campi.index'));
        }

        // Nessun valore perso nel cambio di tipo
        $this->assertSame(6, ValoreCampoPersonalizzato::query()->count());
        $this->assertSame('Avvocato', $this->valore($luca, $professione));
        $this->assertSame('Base', $this->valore($anna, $livello));
        $this->assertSame('1', $this->valore($anna, $volontario));

        // Elenco e scheda si aprono e mostrano i valori com'erano
        $this->actingAs($this->admin)->get(route('soci.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('soci.show', $anna))
            ->assertOk()
            ->assertSeeInOrder(['Professione', 'Insegnante'])
            ->assertSeeInOrder(['Livello musicale', 'Base'])
            ->assertSeeInOrder(['Anni di esperienza', '12'])
            ->assertSeeInOrder(['Data brevetto', 'primavera 2019']);
        $this->actingAs($this->admin)->get(route('soci.show', $luca))
            ->assertOk()
            ->assertSeeInOrder(['Professione', 'Avvocato']);

        // Form di modifica di Anna: tutto coerente col nuovo tipo
        $formAnna = $this->actingAs($this->admin)->get(route('soci.edit', $anna))->assertOk();
        $this->assertHtmlMatches('/<select[^>]*id="campo_'.preg_quote($professione->id, '/').'"/', $formAnna);
        $formAnna->assertSee('<option value="Insegnante" selected', false);
        $this->assertHtmlMatches('/<input type="text"[^>]*id="campo_'.preg_quote($livello->id, '/').'"[^>]*value="Base"/', $formAnna);
        $this->assertHtmlMatches('/<input type="text"[^>]*id="campo_'.preg_quote($anni->id, '/').'"[^>]*value="12"/', $formAnna);
        $this->assertHtmlMatches('/<input type="text"[^>]*id="campo_'.preg_quote($volontario->id, '/').'"[^>]*value="1"/', $formAnna);
        $this->assertHtmlMatches('/<input type="date"[^>]*id="campo_'.preg_quote($brevetto->id, '/').'"/', $formAnna);

        // Form di modifica di Luca: "Avvocato" non è tra le opzioni ma non sparisce né blocca il salvataggio
        $formLuca = $this->actingAs($this->admin)->get(route('soci.edit', $luca))->assertOk();
        $formLuca->assertSee('<option value="Avvocato" selected', false)
            ->assertSee('non più tra le opzioni');

        $this->actingAs($this->admin)
            ->put(route('soci.update', $luca), $this->payloadModificaSocio($luca, [
                'custom_field' => [$professione->id => 'Avvocato'],
            ]))
            ->assertSessionHasNoErrors();
        $this->assertSame('Avvocato', $this->valore($luca, $professione));

        // Mentre un valore mai salvato e fuori opzioni resta vietato
        $this->actingAs($this->admin)
            ->put(route('soci.update', $anna), $this->payloadModificaSocio($anna, [
                'custom_field' => [$professione->id => 'Dirigente'],
            ]))
            ->assertSessionHasErrors(['custom_field.'.$professione->id]);
        $this->assertSame('Insegnante', $this->valore($anna, $professione));

        // Il vecchio testo nel campo ora "date": salvarlo tal quale è un errore di validazione (non 500),
        // svuotarlo è lo scarto controllato
        $this->actingAs($this->admin)
            ->put(route('soci.update', $anna), $this->payloadModificaSocio($anna, [
                'custom_field' => [$brevetto->id => 'primavera 2019'],
            ]))
            ->assertSessionHasErrors(['custom_field.'.$brevetto->id]);

        $this->actingAs($this->admin)
            ->put(route('soci.update', $anna), $this->payloadModificaSocio($anna, [
                'custom_field' => [
                    $professione->id => 'Insegnante',
                    $livello->id => 'Base',
                    $anni->id => '12',
                    $volontario->id => '1',
                    $brevetto->id => '',
                ],
            ]))
            ->assertSessionHasNoErrors();
        $this->assertNull($this->valore($anna, $brevetto));
        $this->assertSame('12', $this->valore($anna, $anni));
        $this->assertSame('1', $this->valore($anna, $volontario));

        $this->actingAs($this->admin)
            ->put(route('soci.update', $anna), $this->payloadModificaSocio($anna, [
                'custom_field' => [$brevetto->id => '2019-04-21'],
            ]))
            ->assertSessionHasNoErrors();
        $this->assertSame('2019-04-21', $this->valore($anna, $brevetto));
    }

    // ------------------------------------------------------------------
    // Percorso 6 — opzioni di una select aggiunte, rinominate, rimosse
    // ------------------------------------------------------------------

    public function test_percorso_6_aggiungere_rinominare_o_togliere_un_opzione_gia_usata_non_tocca_i_valori_salvati(): void
    {
        $titolo = $this->creaCampo('Titolo di studio', 'select', ['opzioni' => "Diploma\nLaurea"]);
        $anna = $this->socioConValore($titolo, 'Laurea', ['nome' => 'Anna', 'cognome' => 'Moretti']);
        $luca = $this->socioConValore($titolo, 'Diploma', ['nome' => 'Luca', 'cognome' => 'Ferri']);

        // 1) Aggiunta di un'opzione: tutto come prima, più "Master"
        $this->actingAs($this->admin)
            ->put(route('config-campi.update', $titolo), $this->payloadModificaCampo($titolo, ['opzioni' => "Diploma\nLaurea\nMaster"]))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->get(route('soci.edit', $anna))
            ->assertOk()
            ->assertSee('<option value="Master"', false)
            ->assertSee('<option value="Laurea" selected', false)
            ->assertDontSee('non più tra le opzioni');

        // 2) Rinomina di "Laurea" in "Laurea magistrale": il valore di Anna resta "Laurea"
        $this->actingAs($this->admin)
            ->put(route('config-campi.update', $titolo), $this->payloadModificaCampo($titolo->fresh(), ['opzioni' => "Diploma\nLaurea magistrale\nMaster"]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Laurea', $this->valore($anna, $titolo));
        $this->actingAs($this->admin)->get(route('soci.show', $anna))->assertOk()->assertSeeInOrder(['Titolo di studio', 'Laurea']);

        $form = $this->actingAs($this->admin)->get(route('soci.edit', $anna))->assertOk();
        $form->assertSee('<option value="Laurea magistrale"', false)
            ->assertSee('<option value="Laurea" selected', false)
            ->assertSee('non più tra le opzioni');

        // Salvare la scheda senza toccare il campo non perde il valore...
        $this->actingAs($this->admin)
            ->put(route('soci.update', $anna), $this->payloadModificaSocio($anna, ['custom_field' => [$titolo->id => 'Laurea']]))
            ->assertSessionHasNoErrors();
        $this->assertSame('Laurea', $this->valore($anna, $titolo));

        // ...e scegliere l'opzione nuova lo aggiorna
        $this->actingAs($this->admin)
            ->put(route('soci.update', $anna), $this->payloadModificaSocio($anna, ['custom_field' => [$titolo->id => 'Laurea magistrale']]))
            ->assertSessionHasNoErrors();
        $this->assertSame('Laurea magistrale', $this->valore($anna, $titolo));

        // 3) Rimozione di "Diploma", usata da Luca: il suo valore non viene cancellato
        $this->actingAs($this->admin)
            ->put(route('config-campi.update', $titolo), $this->payloadModificaCampo($titolo->fresh(), ['opzioni' => "Laurea magistrale\nMaster"]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Diploma', $this->valore($luca, $titolo));
        $this->actingAs($this->admin)->get(route('soci.show', $luca))->assertOk()->assertSeeInOrder(['Titolo di studio', 'Diploma']);
        $this->actingAs($this->admin)->get(route('soci.edit', $luca))
            ->assertOk()
            ->assertSee('<option value="Diploma" selected', false)
            ->assertDontSee('<option value="Diploma">', false);

        $this->actingAs($this->admin)
            ->put(route('soci.update', $luca), $this->payloadModificaSocio($luca, ['custom_field' => [$titolo->id => 'Diploma']]))
            ->assertSessionHasNoErrors();
        $this->assertSame('Diploma', $this->valore($luca, $titolo));

        // Un nuovo socio invece non può più scegliere "Diploma"
        $this->actingAs($this->admin)
            ->post(route('soci.store'), $this->payloadSocio(['custom_field' => [$titolo->id => 'Diploma']]))
            ->assertSessionHasErrors(['custom_field.'.$titolo->id]);
        $this->actingAs($this->admin)
            ->post(route('soci.store'), $this->payloadSocio(['custom_field' => [$titolo->id => 'Master']]))
            ->assertSessionHasNoErrors();

        $this->assertSame(3, ValoreCampoPersonalizzato::query()->where('campo_id', $titolo->id)->count());
    }

    // ------------------------------------------------------------------
    // Percorso 7 — eliminazione di un campo con valori
    // ------------------------------------------------------------------

    public function test_percorso_7_eliminare_un_campo_con_valori_li_cancella_in_cascata_senza_lasciare_orfani_in_schede_richieste_export_e_import(): void
    {
        $professione = $this->creaCampo('Professione', 'text');
        $hobby = $this->creaCampo('Hobby', 'text');

        $anna = $this->socioConValore($professione, 'Insegnante', ['nome' => 'Anna', 'cognome' => 'Moretti']);
        $luca = $this->socioConValore($professione, 'Falegname', ['nome' => 'Luca', 'cognome' => 'Ferri']);
        ValoreCampoPersonalizzato::create(['socio_id' => $anna->id, 'campo_id' => $hobby->id, 'valore' => 'Scacchi']);

        // Una richiesta di iscrizione pubblica arrivata col campo compilato (snapshot in dati_form)
        $giulia = Socio::factory()->inAttesa()->create(['associazione_id' => $this->associazione->id, 'nome' => 'Giulia', 'cognome' => 'Verdi']);
        ValoreCampoPersonalizzato::create(['socio_id' => $giulia->id, 'campo_id' => $professione->id, 'valore' => 'Liutaia']);
        RichiestaIscrizione::create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $giulia->id,
            'stato' => RichiestaIscrizione::STATO_IN_ATTESA,
            'dati_form' => ['telefono' => '3331234567', 'custom_field' => [$professione->id => 'Liutaia']],
        ]);

        $this->assertSame(4, ValoreCampoPersonalizzato::query()->count());

        // L'admin elimina "Professione" dalla pagina di configurazione
        $this->actingAs($this->admin)
            ->delete(route('config-campi.destroy', $professione))
            ->assertRedirect(route('config-campi.index'))
            ->assertSessionHas('success');

        // Comportamento atteso: i valori del campo spariscono in cascata, quelli degli altri campi restano
        $this->assertDatabaseMissing('campi_personalizzati', ['id' => $professione->id]);
        $this->assertDatabaseMissing('valori_campi_personalizzati', ['campo_id' => $professione->id]);
        $this->assertSame(1, ValoreCampoPersonalizzato::query()->count());
        $this->assertSame('Scacchi', $this->valore($anna, $hobby));
        $this->assertDatabaseHas('soci', ['id' => $anna->id]);
        $this->assertDatabaseHas('soci', ['id' => $luca->id]);

        // Scheda, form di modifica, form di creazione ed elenco: nessun riferimento al campo sparito
        $this->actingAs($this->admin)->get(route('soci.show', $anna))
            ->assertOk()
            ->assertDontSee('Professione')
            ->assertDontSee('Insegnante')
            ->assertSeeInOrder(['Hobby', 'Scacchi']);
        $this->actingAs($this->admin)->get(route('soci.show', $luca))->assertOk()->assertDontSee('Falegname');
        $this->actingAs($this->admin)->get(route('soci.edit', $anna))->assertOk()->assertDontSee('Professione')->assertSee('value="Scacchi"', false);
        $this->actingAs($this->admin)->get(route('soci.create'))->assertOk()->assertDontSee('Professione')->assertSee('Hobby');
        $this->actingAs($this->admin)->get(route('soci.index'))->assertOk();
        // (nella pagina di configurazione "Professione" resta solo come preset riattivabile, non come riga)
        $this->actingAs($this->admin)->get(self::URL_CAMPI)->assertOk()->assertDontSee(route('config-campi.update', $professione->id));

        // La pagina delle richieste di iscrizione mostra lo snapshot con un'etichetta generica, senza errori
        $this->actingAs($this->admin)->get('/richieste-iscrizione')
            ->assertOk()
            ->assertSee('Giulia')
            ->assertSee('Campo personalizzato')
            ->assertSee('Liutaia');

        // Export ed import dei soci non si accorgono del campo eliminato
        $export = $this->actingAs($this->admin)->get(route('soci.export', ['format' => 'csv']))->assertOk();
        $this->assertStringContainsString('.csv', (string) $export->headers->get('content-disposition'));

        $csv = implode("\n", [
            'nome,cognome,email,stato',
            'Paolo,Neri,paolo.neri@example.com,Attivo',
        ]);
        $this->actingAs($this->admin)
            ->post(route('soci.import.store'), ['file' => UploadedFile::fake()->createWithContent('soci.csv', $csv)])
            ->assertRedirect(route('soci.import'));
        $this->assertSame(1, session('import_report')['importati']);
        $this->assertSame([], session('import_report')['errori']);
        $this->assertDatabaseHas('soci', ['email' => 'paolo.neri@example.com', 'associazione_id' => $this->associazione->id]);
    }

    // ------------------------------------------------------------------
    // Percorso 8 — preset senza duplicati e idempotente
    // ------------------------------------------------------------------

    public function test_percorso_8_applicare_un_preset_crea_solo_i_campi_mancanti_e_riapplicarlo_non_cambia_nulla(): void
    {
        // "Professione" esiste già, creata a mano con una descrizione propria
        $professione = $this->creaCampo('Professione', 'text', ['descrizione' => 'Scritta dalla segreteria']);

        $pagina = $this->actingAs($this->admin)->get(self::URL_CAMPI)->assertOk();
        foreach (array_keys(CampoPersonalizzatoController::PRESET) as $nome) {
            $pagina->assertSee($nome);
        }

        $this->actingAs($this->admin)
            ->post(route('config-campi.preset'), ['preset' => ['Professione', 'Titolo di Studio', 'Sito Web', 'Non Esiste']])
            ->assertRedirect(route('config-campi.index'))
            ->assertSessionHas('success', '2 campi aggiunti.');

        $this->assertSame(3, CampoPersonalizzato::query()->count());
        $this->assertSame('Scritta dalla segreteria', $professione->fresh()->descrizione, 'Il preset non deve sovrascrivere il campo esistente');

        $titolo = CampoPersonalizzato::query()->where('nome_campo', 'Titolo di Studio')->sole();
        $this->assertSame('select', $titolo->tipo_campo);
        $this->assertStringContainsString("Diploma\nLaurea Triennale", (string) $titolo->opzioni);
        $this->assertFalse($titolo->obbligatorio);
        $this->assertSame('url', CampoPersonalizzato::query()->where('nome_campo', 'Sito Web')->sole()->tipo_campo);

        // Riapplicare lo stesso preset è idempotente
        $this->actingAs($this->admin)
            ->post(route('config-campi.preset'), ['preset' => ['Professione', 'Titolo di Studio', 'Sito Web']])
            ->assertRedirect(route('config-campi.index'))
            ->assertSessionHas('success', '0 campi aggiunti.');
        $this->assertSame(3, CampoPersonalizzato::query()->count());

        // Il resto del preset si aggiunge in un secondo momento
        $this->actingAs($this->admin)
            ->post(route('config-campi.preset'), ['preset' => array_keys(CampoPersonalizzatoController::PRESET)])
            ->assertSessionHas('success', '3 campi aggiunti.');
        $this->assertSame(6, CampoPersonalizzato::query()->count());
        $this->assertSame(
            6,
            CampoPersonalizzato::query()->whereIn('nome_campo', array_keys(CampoPersonalizzatoController::PRESET))->distinct()->count('nome_campo')
        );

        // I campi del preset si usano subito nel form socio
        $form = $this->actingAs($this->admin)->get(route('soci.create'))->assertOk();
        $form->assertSee('Titolo di Studio')->assertSee('<option value="Laurea Magistrale"', false);
        $this->assertHtmlMatches('/<input type="date"[^>]*id="campo_'.preg_quote(CampoPersonalizzato::query()->where('nome_campo', 'Data Diploma/Laurea')->sole()->id, '/').'"/', $form);
    }

    // ------------------------------------------------------------------
    // Percorso 9 — costruttore del modulo pubblico e invio dal sito
    // ------------------------------------------------------------------

    public function test_percorso_9_il_costruttore_del_modulo_pubblico_decide_campi_obblighi_testi_e_consensi_e_il_sito_li_rispetta(): void
    {
        $strumento = $this->creaCampo('Strumento musicale', 'select', ['opzioni' => "Chitarra\nPianoforte\nViolino", 'obbligatorio' => '1']);
        $motivazione = $this->creaCampo('Perché vuoi iscriverti', 'textarea');
        $fuoriModulo = $this->creaCampo('Note della segreteria', 'text');

        ModuliSettings::per($this->associazione)->aggiorna(['consensi' => [
            ['testo' => 'Accetto il regolamento della banda', 'obbligatorio' => true, 'link_url' => 'https://banda.example/regolamento'],
        ]]);

        // L'admin configura: telefono visibile e obbligatorio, data di nascita visibile,
        // codice fiscale e indirizzo nascosti, due campi personalizzati (uno obbligatorio) e il testo di benvenuto
        $payload = $this->payloadModulo([
            'intro_html' => '<p>Benvenuto nella banda! Raccontaci di te.</p><script>alert(1)</script>',
            'campi_personalizzati' => [$strumento->id, $motivazione->id],
        ]);
        $payload['campi_standard']['telefono'] += ['visibile' => '1', 'obbligatorio' => '1'];
        $payload['campi_standard']['data_nascita'] += ['visibile' => '1'];

        $this->actingAs($this->admin)
            ->post(route('configurazioni.modulo-iscrizione.salva'), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('configurazioni.modulo-iscrizione'));

        $config = $this->associazione->fresh()->iscrizione_form_config;
        $this->assertSame('<p>Benvenuto nella banda! Raccontaci di te.</p>', $config['intro_html']);
        $this->assertSame([$strumento->id, $motivazione->id], $config['campi_personalizzati']);

        // Il costruttore riaperto mostra la scelta fatta
        $this->actingAs($this->admin)->get(route('configurazioni.modulo-iscrizione'))
            ->assertOk()
            ->assertSee('Strumento musicale')
            ->assertSee('Note della segreteria')
            ->assertSee('value="'.$strumento->id.'" data-ruolo="visibile" checked', false);

        // Un campo di un altro tenant non entra nel modulo
        $estraneo = CampoPersonalizzato::create([
            'associazione_id' => Associazione::factory()->create()->id,
            'nome_campo' => 'Estraneo',
            'tipo_campo' => 'text',
        ]);
        $this->actingAs($this->admin)
            ->post(route('configurazioni.modulo-iscrizione.salva'), $this->payloadModulo(['campi_personalizzati' => [$estraneo->id]]))
            ->assertSessionHasErrors(['campi_personalizzati.0']);

        // Il visitatore apre /a/{slug}/iscriviti: vede intro, campi scelti, select con opzioni, consenso; non i campi nascosti
        $modulo = $this->get($this->urlTenant('iscriviti'))->assertOk();
        $modulo->assertSee('Benvenuto nella banda! Raccontaci di te.')
            ->assertDontSee('alert(1)', false)
            ->assertSee('name="telefono"', false)
            ->assertSee('name="data_nascita"', false)
            ->assertDontSee('name="codice_fiscale"', false)
            ->assertDontSee('name="indirizzo"', false)
            ->assertSee('Strumento musicale')
            ->assertSee('<option value="Violino"', false)
            ->assertSee('Perché vuoi iscriverti')
            ->assertDontSee('Note della segreteria')
            ->assertSee('Accetto il regolamento della banda')
            ->assertSee('https://banda.example/regolamento', false);
        $this->assertHtmlMatches('/<select[^>]*name="custom_field\['.preg_quote($strumento->id, '/').'\]"[^>]*required/', $modulo);
        $this->assertHtmlNotMatches('/<textarea[^>]*name="custom_field\['.preg_quote($motivazione->id, '/').'\]"[^>]*required/', $modulo);

        $datiBase = [
            'nome' => 'Giulia',
            'cognome' => 'Verdi',
            'email' => 'giulia.verdi@example.com',
            'privacy' => '1',
            'telefono' => '3331234567',
            'data_nascita' => '1990-05-04',
        ];

        // Invio incompleto: manca lo strumento (obbligatorio) e il consenso; opzione inventata rifiutata
        $this->from($this->urlTenant('iscriviti'))
            ->post($this->urlTenant('iscriviti'), $datiBase)
            ->assertRedirect($this->urlTenant('iscriviti'))
            ->assertSessionHasErrors(['custom_field.'.$strumento->id, 'consensi.accetto-il-regolamento-della-banda']);
        $this->assertStringContainsString('Strumento musicale', session('errors')->first('custom_field.'.$strumento->id));

        $this->post($this->urlTenant('iscriviti'), $datiBase + [
            'custom_field' => [$strumento->id => 'Tromba'],
            'consensi' => ['accetto-il-regolamento-della-banda' => '1'],
        ])->assertSessionHasErrors(['custom_field.'.$strumento->id]);
        $this->assertDatabaseMissing('soci', ['email' => 'giulia.verdi@example.com']);

        // Invio corretto (il codice fiscale nascosto, se inviato, viene ignorato; il campo fuori modulo pure)
        $this->post($this->urlTenant('iscriviti'), $datiBase + [
            'codice_fiscale' => 'VRDGLI90E44F205Z',
            'custom_field' => [
                $strumento->id => 'Violino',
                $motivazione->id => 'Per suonare in gruppo la domenica',
                $fuoriModulo->id => 'Tentativo di scrittura',
            ],
            'consensi' => ['accetto-il-regolamento-della-banda' => '1'],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $giulia = Socio::query()->where('email', 'giulia.verdi@example.com')->sole();
        $this->assertSame(Socio::STATO_IN_ATTESA, $giulia->stato);
        $this->assertSame('3331234567', $giulia->telefono);
        $this->assertNull($giulia->codice_fiscale);
        $this->assertSame('Violino', $this->valore($giulia, $strumento));
        $this->assertSame('Per suonare in gruppo la domenica', $this->valore($giulia, $motivazione));
        $this->assertNull($this->valore($giulia, $fuoriModulo));

        $richiesta = RichiestaIscrizione::query()->where('socio_id', $giulia->id)->sole();
        $this->assertSame('Violino', $richiesta->dati_form['custom_field'][$strumento->id]);
        $this->assertSame('Per suonare in gruppo la domenica', $richiesta->dati_form['custom_field'][$motivazione->id]);
        $this->assertDatabaseHas('consensi', ['socio_id' => $giulia->id, 'chiave' => 'accetto-il-regolamento-della-banda', 'accettato' => true]);

        // L'admin vede la richiesta con il nome del campo accanto al valore
        $this->actingAs($this->admin)->get('/richieste-iscrizione')
            ->assertOk()
            ->assertSee('Giulia')
            ->assertSeeInOrder(['Strumento musicale', 'Violino'])
            ->assertSeeInOrder(['Perché vuoi iscriverti', 'Per suonare in gruppo la domenica']);

        // Toglie la motivazione dal modulo: dal sito sparisce, ma il valore già raccolto resta
        $payload['campi_personalizzati'] = [$strumento->id];
        $this->actingAs($this->admin)->post(route('configurazioni.modulo-iscrizione.salva'), $payload)->assertSessionHasNoErrors();
        $this->get($this->urlTenant('iscriviti'))->assertOk()->assertSee('Strumento musicale')->assertDontSee('Perché vuoi iscriverti');
        $this->assertSame('Per suonare in gruppo la domenica', $this->valore($giulia, $motivazione));
    }

    // ------------------------------------------------------------------
    // Percorso 10 — tenant, permessi, export GDPR e clone
    // ------------------------------------------------------------------

    public function test_percorso_10_i_campi_restano_nel_proprio_tenant_l_operatore_li_usa_ma_non_li_configura_e_finiscono_in_export_gdpr_e_clone(): void
    {
        $professione = $this->creaCampo('Professione', 'text');
        $strumento = $this->creaCampo('Strumento musicale', 'select', ['opzioni' => "Chitarra\nViolino", 'obbligatorio' => '1', 'descrizione' => 'Lo strumento principale']);

        $altra = Associazione::factory()->create(['nome' => 'Coro Polifonico Aurora']);
        $adminAltra = Utente::factory()->adminAssociazione()->create(['associazione_id' => $altra->id]);
        $segreto = CampoPersonalizzato::create([
            'associazione_id' => $altra->id,
            'nome_campo' => 'Registro vocale',
            'tipo_campo' => 'select',
            'opzioni' => "Soprano\nContralto\nTenore\nBasso",
        ]);

        // Ogni associazione vede solo i propri campi, in configurazione e nel form socio
        $this->actingAs($this->admin)->get(self::URL_CAMPI)->assertOk()->assertSee('Professione')->assertDontSee('Registro vocale');
        $this->actingAs($this->admin)->get(route('soci.create'))->assertOk()->assertSee('Strumento musicale')->assertDontSee('Registro vocale');
        $this->actingAs($adminAltra)->get(self::URL_CAMPI)->assertOk()->assertSee('Registro vocale')->assertDontSee(route('config-campi.update', $professione));
        $this->actingAs($adminAltra)->get(route('soci.create'))->assertOk()->assertSee('Registro vocale')->assertDontSee('Strumento musicale');

        // Modificare o cancellare il campo di un altro tenant: non trovato, non toccato
        $this->actingAs($this->admin)
            ->put(route('config-campi.update', $segreto), ['nome_campo' => 'Rubato', 'tipo_campo' => 'text'])
            ->assertNotFound();
        $this->actingAs($this->admin)->delete(route('config-campi.destroy', $segreto))->assertNotFound();
        $this->assertSame('Registro vocale', $segreto->fresh()->nome_campo);

        // Un valore inviato per il campo dell'altro tenant viene ignorato
        $this->actingAs($this->admin)
            ->post(route('soci.store'), $this->payloadSocio([
                'custom_field' => [$strumento->id => 'Violino', $segreto->id => 'Tenore'],
            ]))
            ->assertSessionHasNoErrors();
        $mario = Socio::query()->where('email', 'mario.rossi@example.com')->sole();
        $this->assertSame('Violino', $this->valore($mario, $strumento));
        $this->assertDatabaseMissing('valori_campi_personalizzati', ['campo_id' => $segreto->id]);

        // L'operatore: 403 su tutta la configurazione dei campi...
        $operatore = Utente::factory()->operatore()->create(['associazione_id' => $this->associazione->id]);
        $this->actingAs($operatore)->get(self::URL_CAMPI)->assertForbidden();
        $this->actingAs($operatore)->post(self::URL_CAMPI, ['nome_campo' => 'Abusivo', 'tipo_campo' => 'text'])->assertForbidden();
        $this->actingAs($operatore)->post(route('config-campi.preset'), ['preset' => ['Sito Web']])->assertForbidden();
        $this->actingAs($operatore)->put(route('config-campi.update', $professione), ['nome_campo' => 'Cambiato', 'tipo_campo' => 'text'])->assertForbidden();
        $this->actingAs($operatore)->delete(route('config-campi.destroy', $professione))->assertForbidden();
        $this->assertSame(2, CampoPersonalizzato::query()->count());
        $this->assertSame('Professione', $professione->fresh()->nome_campo);

        // ...ma li compila nelle schede dei soci
        $this->actingAs($operatore)->get(route('soci.create'))->assertOk()->assertSee('Professione')->assertSee('Strumento musicale *');
        $this->actingAs($operatore)
            ->put(route('soci.update', $mario), $this->payloadModificaSocio($mario, [
                'custom_field' => [$professione->id => 'Insegnante', $strumento->id => 'Violino'],
            ]))
            ->assertSessionHasNoErrors();
        $this->assertSame('Insegnante', $this->valore($mario, $professione));

        // Export GDPR del socio dalla segreteria: i campi personalizzati sono nel dati.json dello zip
        $risposta = $this->actingAs($this->admin)->get(route('soci.dati-personali', $mario))->assertOk();
        $zipLocale = tempnam(sys_get_temp_dir(), 'gdpr');
        file_put_contents($zipLocale, $risposta->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipLocale));
        $dati = json_decode((string) $zip->getFromName('dati.json'), true, 512, JSON_THROW_ON_ERROR);
        $zip->close();
        unlink($zipLocale);

        $campiEsportati = collect($dati['campi_personalizzati'])->pluck('valore', 'campo')->sortKeys()->all();
        $this->assertSame(['Professione' => 'Insegnante', 'Strumento musicale' => 'Violino'], $campiEsportati);
        $this->assertArrayNotHasKey('Registro vocale', $campiEsportati);

        // Clone della configurazione verso una nuova associazione: definizioni copiate, valori no
        $nuova = Associazione::factory()->create(['nome' => 'Banda Giovanile']);
        app(CloneAssociazioneConfigService::class)->clone($this->associazione, $nuova, ['campi' => true]);

        $clonati = CampoPersonalizzato::allTenants()->where('associazione_id', $nuova->id)->orderBy('nome_campo')->get();
        $this->assertSame(['Professione', 'Strumento musicale'], $clonati->pluck('nome_campo')->all());
        $strumentoClonato = $clonati->firstWhere('nome_campo', 'Strumento musicale');
        $this->assertSame('select', $strumentoClonato->tipo_campo);
        $this->assertSame("Chitarra\nViolino", $strumentoClonato->opzioni);
        $this->assertTrue($strumentoClonato->obbligatorio);
        $this->assertSame('Lo strumento principale', $strumentoClonato->descrizione);
        $this->assertNotSame($strumento->id, $strumentoClonato->id);
        $this->assertSame(0, ValoreCampoPersonalizzato::query()->whereIn('campo_id', $clonati->pluck('id'))->count());
        $this->assertSame(2, CampoPersonalizzato::allTenants()->where('associazione_id', $this->associazione->id)->count());

        $adminNuova = Utente::factory()->adminAssociazione()->create(['associazione_id' => $nuova->id]);
        $this->actingAs($adminNuova)->get(route('soci.create'))->assertOk()->assertSee('Strumento musicale *')->assertSee('<option value="Violino"', false);
    }
}
