<?php

namespace Tests\Feature\Struttura;

use App\Models\Associazione;
use App\Models\CampoPersonalizzato;
use App\Models\Utente;
use App\Services\Iscrizioni\FormIscrizioneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sezione admin "Modulo di iscrizione" (PIANO-ISCRIZIONI §Rotte/Admin):
 * salvataggio dello schema iscrizione_form_config, purificazione XSS
 * dell'intro, operatore 403, isolamento tenant dei campi personalizzati.
 */
class ModuloIscrizioneTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/configurazioni/modulo-iscrizione';

    private Associazione $associazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create();
        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    /**
     * Payload valido: tutti i campi standard con ordine progressivo.
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function payload(array $override = []): array
    {
        $campiStandard = [];
        foreach (FormIscrizioneService::CAMPI_STANDARD as $i => $campo) {
            $campiStandard[$campo] = ['ordine' => $i + 1];
        }
        $campiStandard['telefono']['visibile'] = '1';
        $campiStandard['telefono']['obbligatorio'] = '1';
        $campiStandard['data_nascita']['visibile'] = '1';

        return array_merge([
            'iscrizioni_socio_aperte' => '1',
            'modalita_iscrizione' => Associazione::MODALITA_PREISCRIZIONE,
            'intro_html' => '<p>Benvenuto nel modulo di iscrizione.</p>',
            'campi_standard' => $campiStandard,
            'campi_personalizzati' => [],
        ], $override);
    }

    private function campoPersonalizzato(Associazione $associazione, array $attrs = []): CampoPersonalizzato
    {
        return CampoPersonalizzato::create(array_merge([
            'associazione_id' => $associazione->id,
            'nome_campo' => 'Campo '.Str::random(8),
            'tipo_campo' => 'text',
            'ordine' => 1,
            'obbligatorio' => false,
        ], $attrs));
    }

    // ------------------------------------------------------------------
    // Accesso
    // ------------------------------------------------------------------

    public function test_pagina_modulo_iscrizione_visibile_all_admin_del_tenant(): void
    {
        $campo = $this->campoPersonalizzato($this->associazione, ['nome_campo' => 'Taglia maglietta']);

        $this->actingAs($this->admin)
            ->get(self::URL)
            ->assertOk()
            ->assertSee('Modulo di iscrizione')
            ->assertSee('Telefono')
            ->assertSee('Codice fiscale')
            ->assertSee($campo->nome_campo)
            ->assertSee('/a/'.$this->associazione->slug.'/iscriviti');
    }

    public function test_super_admin_col_tenant_selezionato_accede_e_salva(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->withSession(['associazione_id' => $this->associazione->id])
            ->get(self::URL)
            ->assertOk();

        $this->actingAs($superAdmin)
            ->withSession(['associazione_id' => $this->associazione->id])
            ->post(self::URL, $this->payload(['modalita_iscrizione' => Associazione::MODALITA_DIRETTA]))
            ->assertRedirect(route('configurazioni.modulo-iscrizione'));

        $this->assertSame(Associazione::MODALITA_DIRETTA, $this->associazione->fresh()->modalita_iscrizione);
    }

    public function test_operatore_riceve_403_in_lettura_e_scrittura(): void
    {
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($operatore)->get(self::URL)->assertForbidden();
        $this->actingAs($operatore)->post(self::URL, $this->payload())->assertForbidden();

        // Nessuna modifica al tenant
        $this->assertNull($this->associazione->fresh()->iscrizione_form_config);
    }

    // ------------------------------------------------------------------
    // Salvataggio schema
    // ------------------------------------------------------------------

    public function test_salvataggio_schema_completo(): void
    {
        $campo = $this->campoPersonalizzato($this->associazione);

        $payload = $this->payload([
            'modalita_iscrizione' => Associazione::MODALITA_DIRETTA,
            'campi_personalizzati' => [$campo->id],
        ]);
        // CAP per primo, telefono per ultimo: l'ordine va rispettato nello schema
        $payload['campi_standard']['cap'] = ['visibile' => '1', 'ordine' => 1];
        $payload['campi_standard']['telefono']['ordine'] = 50;
        unset($payload['iscrizioni_socio_aperte']); // checkbox non spuntata

        $this->actingAs($this->admin)
            ->post(self::URL, $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('configurazioni.modulo-iscrizione'));

        $associazione = $this->associazione->fresh();
        $this->assertFalse($associazione->iscrizioni_socio_aperte);
        $this->assertSame(Associazione::MODALITA_DIRETTA, $associazione->modalita_iscrizione);

        $config = $associazione->iscrizione_form_config;
        $this->assertSame('<p>Benvenuto nel modulo di iscrizione.</p>', $config['intro_html']);
        $this->assertSame([$campo->id], $config['campi_personalizzati']);

        // Tutti e 7 i campi standard presenti, CAP primo e telefono ultimo
        $ordine = array_column($config['campi_standard'], 'campo');
        $this->assertCount(count(FormIscrizioneService::CAMPI_STANDARD), $config['campi_standard']);
        $this->assertSame('cap', $ordine[0]);
        $this->assertSame('telefono', end($ordine));

        $righe = collect($config['campi_standard'])->keyBy('campo');
        $this->assertTrue($righe['cap']['visibile']);
        $this->assertTrue($righe['telefono']['visibile']);
        $this->assertTrue($righe['telefono']['obbligatorio']);
        $this->assertFalse($righe['indirizzo']['visibile']);
        // Campo non visibile mai obbligatorio (coerenza schema)
        $this->assertFalse($righe['indirizzo']['obbligatorio']);
    }

    public function test_xss_nell_intro_viene_purificato_al_salvataggio(): void
    {
        $this->actingAs($this->admin)
            ->post(self::URL, $this->payload([
                'intro_html' => '<p>Benvenuto</p><script>alert("xss")</script><img src=x onerror="alert(1)">',
            ]))
            ->assertSessionHasNoErrors();

        $intro = (string) $this->associazione->fresh()->iscrizione_form_config['intro_html'];

        $this->assertStringContainsString('<p>Benvenuto</p>', $intro);
        $this->assertStringNotContainsString('<script', $intro);
        $this->assertStringNotContainsString('onerror', $intro);
        $this->assertStringNotContainsString('alert(', $intro);
    }

    public function test_intro_vuota_salvata_come_null(): void
    {
        $this->actingAs($this->admin)
            ->post(self::URL, $this->payload(['intro_html' => '   ']))
            ->assertSessionHasNoErrors();

        $this->assertNull($this->associazione->fresh()->iscrizione_form_config['intro_html']);
    }

    // ------------------------------------------------------------------
    // Validazione struttura
    // ------------------------------------------------------------------

    public function test_campo_standard_non_in_whitelist_viene_rifiutato(): void
    {
        $payload = $this->payload();
        $payload['campi_standard']['ruolo_admin'] = ['visibile' => '1', 'ordine' => 1];

        $this->actingAs($this->admin)
            ->post(self::URL, $payload)
            ->assertSessionHasErrors('campi_standard');

        $this->assertNull($this->associazione->fresh()->iscrizione_form_config);
    }

    public function test_modalita_non_valida_e_ordine_mancante_vengono_rifiutati(): void
    {
        $payload = $this->payload(['modalita_iscrizione' => 'istantanea']);
        unset($payload['campi_standard']['telefono']['ordine']);

        $this->actingAs($this->admin)
            ->post(self::URL, $payload)
            ->assertSessionHasErrors(['modalita_iscrizione', 'campi_standard.telefono.ordine']);
    }

    // ------------------------------------------------------------------
    // Isolamento tenant
    // ------------------------------------------------------------------

    public function test_campo_personalizzato_di_altro_tenant_viene_rifiutato(): void
    {
        $altra = Associazione::factory()->create();
        $campoAltrui = $this->campoPersonalizzato($altra);

        $this->actingAs($this->admin)
            ->post(self::URL, $this->payload([
                'campi_personalizzati' => [$campoAltrui->id],
            ]))
            ->assertSessionHasErrors('campi_personalizzati.0');

        $this->assertNull($this->associazione->fresh()->iscrizione_form_config);
    }

    public function test_admin_di_altro_tenant_non_modifica_questa_associazione(): void
    {
        $altra = Associazione::factory()->create();
        $adminAltrui = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $altra->id,
        ]);

        // L'admin dell'altro tenant salva: modifica SOLO la propria associazione
        $this->actingAs($adminAltrui)
            ->post(self::URL, $this->payload(['modalita_iscrizione' => Associazione::MODALITA_DIRETTA]))
            ->assertRedirect(route('configurazioni.modulo-iscrizione'));

        $this->assertSame(Associazione::MODALITA_PREISCRIZIONE, $this->associazione->fresh()->modalita_iscrizione);
        $this->assertSame(Associazione::MODALITA_DIRETTA, $altra->fresh()->modalita_iscrizione);
    }
}
