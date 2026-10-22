<?php

namespace Tests\Feature\Moduli;

use App\Models\Associazione;
use App\Models\CampoPersonalizzato;
use App\Models\Consenso;
use App\Models\Socio;
use App\Models\Utente;
use App\Services\Iscrizioni\FormIscrizioneService;
use App\Settings\ModuliSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuliPubbliciTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    protected function setUp(): void
    {
        parent::setUp();

        config(['honeypot.enabled' => false]);
        $this->associazione = Associazione::factory()->create(['privacy_policy_html' => null]);
    }

    private function url(): string
    {
        return '/preiscrizione/'.$this->associazione->id;
    }

    /**
     * @param  array<string, mixed>  $valori
     */
    private function configura(array $valori): void
    {
        ModuliSettings::per($this->associazione)->aggiorna($valori);
        $this->associazione = $this->associazione->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function dati(array $override = []): array
    {
        return array_merge(['nome' => 'Giulia', 'cognome' => 'Verdi', 'email' => 'giulia@example.com', 'privacy_consenso' => '1'], $override);
    }

    public function test_link_alla_privacy_sempre_presente_anche_senza_testo_personalizzato(): void
    {
        $this->get($this->url())->assertOk()
            ->assertSee(route('privacy-policy.show', ['associazione' => $this->associazione->id]), false)
            ->assertSee('leggi l&#039;informativa', false);
    }

    public function test_colore_logo_e_testi_dell_associazione(): void
    {
        $this->configura([
            'colore_primario' => '#1a7f37',
            'mostra_logo' => false,
            'titolo_preiscrizione' => 'Unisciti al Circolo',
            'intro_preiscrizione_html' => '<p>Benvenuto <strong>nel circolo</strong>.</p>',
            'messaggio_conferma' => 'Grazie! Ti contatteremo presto.',
        ]);
        $this->associazione->update(['logo_url' => 'https://cdn.example.com/logo.png']);

        $this->get($this->url())->assertOk()
            ->assertSee('--brand: #1A7F37', false)
            ->assertSee('Unisciti al Circolo')
            ->assertSee('<strong>nel circolo</strong>', false)
            ->assertDontSee('cdn.example.com/logo.png');

        $this->post($this->url(), $this->dati())->assertRedirect($this->url());
        $this->get($this->url())->assertSee('Grazie! Ti contatteremo presto.');

        // Stesso colore anche sulla pagina della privacy
        $this->get('/privacy-policy/'.$this->associazione->id)->assertOk()->assertSee('--brand: #1A7F37', false);
    }

    public function test_campi_nascosti_non_mostrati_non_obbligatori_e_non_salvati(): void
    {
        $this->associazione->update(['campi_obbligatori_config' => ['backend' => [], 'preiscrizione' => ['telefono' => true]]]);
        $this->configura(['campi_nascosti_preiscrizione' => ['telefono', 'codice_fiscale']]);

        $this->get($this->url())->assertOk()->assertDontSee('name="telefono"', false)->assertSee('name="cap"', false);

        $this->post($this->url(), $this->dati(['telefono' => '3331234567']))->assertSessionHasNoErrors();
        $this->assertNull(Socio::query()->withoutGlobalScopes()->where('email', 'giulia@example.com')->value('telefono'));
    }

    public function test_campi_personalizzati_scelti(): void
    {
        $mostrato = CampoPersonalizzato::create(['associazione_id' => $this->associazione->id, 'nome_campo' => 'Taglia maglietta', 'tipo_campo' => 'text']);
        $nascosto = CampoPersonalizzato::create(['associazione_id' => $this->associazione->id, 'nome_campo' => 'Numero di gara', 'tipo_campo' => 'text', 'obbligatorio_preiscrizione' => true]);
        $this->configura(['campi_personalizzati_preiscrizione' => [$mostrato->id]]);

        $this->get($this->url())->assertOk()->assertSee('Taglia maglietta')->assertDontSee('Numero di gara');

        // Il campo nascosto non è richiesto e un valore inviato a mano viene ignorato
        $this->post($this->url(), $this->dati(['custom_field' => [$mostrato->id => 'M', $nascosto->id => '99']]))->assertSessionHasNoErrors();
        $socio = Socio::query()->withoutGlobalScopes()->where('email', 'giulia@example.com')->firstOrFail();
        $this->assertSame(['M'], $socio->valoriCampiPersonalizzati()->withoutGlobalScopes()->pluck('valore')->all());
    }

    public function test_consensi_aggiuntivi_obbligatori_e_facoltativi_registrati(): void
    {
        $this->configura(['consensi' => [
            ['testo' => 'Accetto il regolamento interno', 'obbligatorio' => true, 'link_url' => 'https://circolo.example/regolamento'],
            ['testo' => 'Acconsento a ricevere la newsletter', 'obbligatorio' => false],
        ]]);

        $this->get($this->url())->assertOk()
            ->assertSee('Accetto il regolamento interno')
            ->assertSee('https://circolo.example/regolamento', false)
            ->assertSee('(facoltativo)');

        $this->post($this->url(), $this->dati())->assertSessionHasErrors('consensi.accetto-il-regolamento-interno');

        $this->post($this->url(), $this->dati(['consensi' => ['accetto-il-regolamento-interno' => '1']]))->assertSessionHasNoErrors();

        $socio = Socio::query()->withoutGlobalScopes()->where('email', 'giulia@example.com')->firstOrFail();
        $consensi = Consenso::query()->withoutGlobalScopes()->where('socio_id', $socio->id)->get()->keyBy('chiave');
        $this->assertCount(3, $consensi);
        $this->assertTrue($consensi[Consenso::CHIAVE_PRIVACY]->accettato);
        $this->assertTrue($consensi['accetto-il-regolamento-interno']->accettato);
        $this->assertFalse($consensi['acconsento-a-ricevere-la-newsletter']->accettato);
        $this->assertSame('Acconsento a ricevere la newsletter', $consensi['acconsento-a-ricevere-la-newsletter']->testo);
        $this->assertSame('preiscrizione', $consensi[Consenso::CHIAVE_PRIVACY]->origine);
        $this->assertNotNull($consensi[Consenso::CHIAVE_PRIVACY]->ip);

        // Visibili nella scheda socio
        $admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->associazione->id]);
        $this->actingAs($admin)->withSession(['associazione_id' => $this->associazione->id])
            ->get(route('soci.show', $socio))->assertOk()->assertSee('Consensi')->assertSee('Accetto il regolamento interno');

        // Le stesse regole valgono per l'iscrizione online
        $regole = app(FormIscrizioneService::class)->rules($this->associazione->fresh(), ospite: true);
        $this->assertSame(['accepted'], $regole['consensi.accetto-il-regolamento-interno']);
    }

    public function test_configurazione_dalla_pagina_dell_associazione(): void
    {
        $admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->associazione->id]);
        $sessione = ['associazione_id' => $this->associazione->id];

        $this->actingAs($admin)->withSession($sessione)->get(route('configurazioni.index', ['section' => 'moduli']))
            ->assertOk()->assertSee('Consensi aggiuntivi')->assertSee('Colore principale');

        $this->actingAs($admin)->withSession($sessione)->post(route('configurazioni.moduli'), [
            'colore_primario' => '#0055aa',
            'mostra_logo' => '1',
            'intro_preiscrizione_html' => '<p>Ciao</p><script>alert(1)</script>',
            'campi_visibili' => ['telefono', 'citta'],
            'consensi' => [
                ['testo' => 'Uso delle foto per il sito', 'obbligatorio' => '0', 'link_url' => ''],
                ['testo' => '', 'link_url' => ''],
            ],
        ])->assertRedirect(route('configurazioni.index', ['section' => 'moduli']));

        $mod = ModuliSettings::per($this->associazione->fresh());
        $this->assertSame('#0055AA', $mod->colore_primario);
        $this->assertStringNotContainsString('script', (string) $mod->intro_preiscrizione_html);
        $this->assertTrue($mod->campoVisibile('telefono'));
        $this->assertFalse($mod->campoVisibile('cap'));
        $this->assertCount(1, $mod->elencoConsensi());

        $operatore = Utente::factory()->operatore()->create(['associazione_id' => $this->associazione->id]);
        $this->actingAs($operatore)->withSession($sessione)->post(route('configurazioni.moduli'), ['colore_primario' => '#000000'])->assertForbidden();
    }

    public function test_modello_di_informativa_inseribile_dalla_configurazione(): void
    {
        $admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($admin)->withSession(['associazione_id' => $this->associazione->id])
            ->get(route('configurazioni.index', ['section' => 'privacy']))
            ->assertOk()->assertSee('Inserisci il modello predefinito')->assertSee('id="informativaPredefinita"', false);
    }
}
