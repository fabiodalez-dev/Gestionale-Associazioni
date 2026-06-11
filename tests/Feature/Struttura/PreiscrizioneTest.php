<?php

namespace Tests\Feature\Struttura;

use App\Mail\Struttura\NuovaPreiscrizioneMail;
use App\Models\Associazione;
use App\Models\CampoPersonalizzato;
use App\Models\Socio;
use App\Models\TipoSocio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PreiscrizioneTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    protected function setUp(): void
    {
        parent::setUp();

        // Honeypot disattivato per i percorsi "umani"; riattivato nel test dedicato
        config(['honeypot.enabled' => false]);

        $this->associazione = Associazione::factory()->create();
    }

    /**
     * @return array<string, string>
     */
    private function datiValidi(array $override = []): array
    {
        return array_merge([
            'nome' => 'Giulia',
            'cognome' => 'Verdi',
            'email' => 'giulia.verdi@example.com',
            'privacy_consenso' => '1',
        ], $override);
    }

    public function test_pagina_pubblica_visibile_per_associazione_attiva(): void
    {
        $this->get('/preiscrizione/'.$this->associazione->id)
            ->assertOk()
            ->assertSee($this->associazione->nome)
            ->assertSee('pre-iscrizione');
    }

    public function test_uuid_malformato_400_e_associazione_inattiva_404(): void
    {
        $this->get('/preiscrizione/non-un-uuid')->assertStatus(400);

        $inattiva = Associazione::factory()->inattiva()->create();
        $this->get('/preiscrizione/'.$inattiva->id)->assertNotFound();
        $this->get('/privacy-policy/'.$inattiva->id)->assertNotFound();
    }

    public function test_preiscrizione_crea_socio_in_attesa_con_numero_progressivo(): void
    {
        Mail::fake();

        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'numero_socio' => '007',
        ]);

        $response = $this->post('/preiscrizione/'.$this->associazione->id, $this->datiValidi([
            'codice_fiscale' => 'rssgli80a41f205x',
        ]));

        $response->assertRedirect(route('preiscrizione.show', ['associazione' => $this->associazione->id]));
        $response->assertSessionHas('success');

        $socio = Socio::query()->forAssociazione($this->associazione->id)
            ->where('email', 'giulia.verdi@example.com')
            ->firstOrFail();

        $this->assertSame(Socio::STATO_IN_ATTESA, $socio->stato);
        $this->assertSame('008', $socio->numero_socio); // MAX+1 con padding a 3 cifre
        $this->assertSame('RSSGLI80A41F205X', $socio->codice_fiscale); // uppercase
        $this->assertTrue($socio->privacy_consenso);

        $this->assertDatabaseHas('storico_attivita_socio', [
            'socio_id' => $socio->id,
            'tipo_attivita' => 'Pre-iscrizione',
        ]);

        // Notifica best-effort all'email dell'associazione
        Mail::assertQueued(NuovaPreiscrizioneMail::class, function (NuovaPreiscrizioneMail $mail) {
            return $mail->hasTo($this->associazione->email);
        });
    }

    public function test_privacy_consenso_obbligatorio(): void
    {
        $dati = $this->datiValidi();
        unset($dati['privacy_consenso']);

        $this->post('/preiscrizione/'.$this->associazione->id, $dati)
            ->assertSessionHasErrors('privacy_consenso');

        $this->assertSame(0, Socio::query()->forAssociazione($this->associazione->id)->count());
    }

    public function test_campo_configurato_obbligatorio_viene_validato(): void
    {
        $this->associazione->update([
            'campi_obbligatori_config' => [
                'backend' => [],
                'preiscrizione' => ['telefono' => true],
            ],
        ]);

        $this->post('/preiscrizione/'.$this->associazione->id, $this->datiValidi())
            ->assertSessionHasErrors('telefono');
    }

    public function test_duplicato_per_email_bloccato(): void
    {
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'giulia.verdi@example.com',
        ]);

        $this->post('/preiscrizione/'.$this->associazione->id, $this->datiValidi())
            ->assertSessionHas('error');

        $this->assertSame(
            1,
            Socio::query()->forAssociazione($this->associazione->id)->where('email', 'giulia.verdi@example.com')->count()
        );
    }

    public function test_tipo_socio_di_altro_tenant_azzerato_silenziosamente(): void
    {
        $altraAssociazione = Associazione::factory()->create();
        $tipoAltrui = TipoSocio::create([
            'associazione_id' => $altraAssociazione->id,
            'nome' => 'Tipo Altrui',
        ]);

        $this->post('/preiscrizione/'.$this->associazione->id, $this->datiValidi([
            'tipo_socio_id' => $tipoAltrui->id,
        ]))->assertSessionHas('success');

        $socio = Socio::query()->forAssociazione($this->associazione->id)
            ->where('email', 'giulia.verdi@example.com')
            ->firstOrFail();

        $this->assertNull($socio->tipo_socio_id); // anti-tamper FK (spec §6.5)
    }

    public function test_honeypot_finto_successo_per_i_bot(): void
    {
        config([
            'honeypot.enabled' => true,
            'honeypot.randomize_name_field_name' => false,
            'honeypot.valid_from_timestamp' => false,
        ]);

        $response = $this->from('/preiscrizione/'.$this->associazione->id)
            ->post('/preiscrizione/'.$this->associazione->id, $this->datiValidi([
                'website_url' => 'http://spam.example.com', // campo esca compilato
            ]));

        $response->assertRedirect('/preiscrizione/'.$this->associazione->id);
        $response->assertSessionHas('success'); // finto successo

        // ...ma nessun socio è stato creato
        $this->assertSame(0, Socio::query()->forAssociazione($this->associazione->id)->count());
    }

    public function test_rinnovo_con_token_valido_aggiorna_e_azzera_il_token(): void
    {
        $token = str_repeat('ab', 32); // 64 hex
        $campo = CampoPersonalizzato::create([
            'associazione_id' => $this->associazione->id,
            'nome_campo' => 'Professione',
            'tipo_campo' => 'text',
        ]);

        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Vecchio',
            'cognome' => 'Nome',
            'email' => 'rinnovo@example.com',
            'stato' => Socio::STATO_ATTIVO,
            'rinnovo_token' => $token,
            'rinnovo_token_expires' => now()->addDay(),
        ]);

        // La pagina con token valido mostra il form di rinnovo precompilato
        $this->get('/preiscrizione/'.$this->associazione->id.'?token='.$token)
            ->assertOk()
            ->assertSee('Rinnovo iscrizione')
            ->assertSee('rinnovo@example.com');

        $this->post('/preiscrizione/'.$this->associazione->id, $this->datiValidi([
            'nome' => 'Nuovo',
            'cognome' => 'Nome',
            'email' => 'rinnovo@example.com',
            'renewal_token' => $token,
            'custom_field' => [$campo->id => 'Ingegnere'],
        ]))->assertSessionHas('success');

        $socio->refresh();
        $this->assertSame('Nuovo', $socio->nome);
        $this->assertSame(Socio::STATO_ATTIVO, $socio->stato); // lo stato NON cambia col rinnovo
        $this->assertNull($socio->rinnovo_token); // token one-shot
        $this->assertNull($socio->rinnovo_token_expires);

        $this->assertDatabaseHas('valori_campi_personalizzati', [
            'socio_id' => $socio->id,
            'campo_id' => $campo->id,
            'valore' => 'Ingegnere',
        ]);

        $this->assertDatabaseHas('storico_attivita_socio', [
            'socio_id' => $socio->id,
            'tipo_attivita' => 'Rinnovo dati',
        ]);
    }

    public function test_token_scaduto_mostra_solo_l_avviso(): void
    {
        $token = str_repeat('cd', 32);

        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'rinnovo_token' => $token,
            'rinnovo_token_expires' => now()->subDay(), // scaduto
        ]);

        $this->get('/preiscrizione/'.$this->associazione->id.'?token='.$token)
            ->assertOk()
            ->assertSee('scaduto')
            ->assertDontSee('id="formPreiscrizione"', false);
    }
}
