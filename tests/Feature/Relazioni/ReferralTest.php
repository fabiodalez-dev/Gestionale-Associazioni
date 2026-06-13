<?php

namespace Tests\Feature\Relazioni;

use App\Models\Associazione;
use App\Models\RichiestaIscrizione;
use App\Models\Socio;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * f.23 — Porta un amico (referral): codice univoco generato in automatico sul
 * socio, salvataggio del referral sulla richiesta d'iscrizione (senza rompere
 * il form), classifica ambasciatori admin e contatore nella scheda socio.
 */
class ReferralTest extends TestCase
{
    use RefreshDatabase;

    private const CF_VALIDO = 'RSSMRA85T10A562S';

    private Associazione $associazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['honeypot.enabled' => false]);

        $this->associazione = Associazione::factory()->create([
            'frontend_pubblico_attivo' => true,
            'iscrizioni_socio_aperte' => true,
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

    private function urlTenant(string $path = ''): string
    {
        return '/a/'.$this->associazione->slug.($path !== '' ? '/'.ltrim($path, '/') : '');
    }

    public function test_il_socio_riceve_un_codice_referral_automatico_e_univoco(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->assertNotEmpty($socio->codice_referral);

        $altro = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        $this->assertNotSame($socio->codice_referral, $altro->codice_referral);
    }

    public function test_il_form_iscrizione_con_ref_valido_espone_il_codice_e_lo_salva(): void
    {
        $ambasciatore = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        // GET con ?ref= → il form deve contenere il codice nel hidden field
        $this->get($this->urlTenant('iscriviti').'?ref='.$ambasciatore->codice_referral)
            ->assertOk()
            ->assertSee('name="referral_codice"', false)
            ->assertSee($ambasciatore->codice_referral, false);

        // POST come ospite con il referral nel campo nascosto
        $this->post($this->urlTenant('iscriviti'), [
            'nome' => 'Mario',
            'cognome' => 'Verdi',
            'email' => 'mario.verdi@example.com',
            'privacy' => '1',
            'telefono' => '3331234567',
            'codice_fiscale' => self::CF_VALIDO,
            'data_nascita' => '1990-05-10',
            'citta' => 'Milano',
            'referral_codice' => $ambasciatore->codice_referral,
        ])->assertRedirect();

        $richiesta = RichiestaIscrizione::query()->latest()->first();
        $this->assertNotNull($richiesta);
        $this->assertSame($ambasciatore->codice_referral, $richiesta->referral_codice);
    }

    public function test_un_ref_inesistente_non_viene_salvato(): void
    {
        $this->post($this->urlTenant('iscriviti'), [
            'nome' => 'Anna',
            'cognome' => 'Bianchi',
            'email' => 'anna.bianchi@example.com',
            'privacy' => '1',
            'telefono' => '3331234567',
            'codice_fiscale' => self::CF_VALIDO,
            'data_nascita' => '1991-03-12',
            'citta' => 'Roma',
            'referral_codice' => 'codiceinesistente',
        ])->assertRedirect();

        $richiesta = RichiestaIscrizione::query()->latest()->first();
        $this->assertNotNull($richiesta);
        $this->assertNull($richiesta->referral_codice);
    }

    public function test_iscrizione_senza_ref_funziona_normalmente(): void
    {
        // Garanzia anti-regressione: il referral non deve mai rompere il form
        $this->post($this->urlTenant('iscriviti'), [
            'nome' => 'Luca',
            'cognome' => 'Neri',
            'email' => 'luca.neri@example.com',
            'privacy' => '1',
            'telefono' => '3331234567',
            'codice_fiscale' => self::CF_VALIDO,
            'data_nascita' => '1985-01-01',
            'citta' => 'Torino',
        ])->assertRedirect();

        $this->assertDatabaseHas('soci', ['email' => 'luca.neri@example.com']);
        $richiesta = RichiestaIscrizione::query()->latest()->first();
        $this->assertNull($richiesta->referral_codice);
    }

    public function test_la_classifica_ambasciatori_ordina_per_iscrizioni_approvate(): void
    {
        $top = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Giulia', 'cognome' => 'Top',
        ]);
        $secondo = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Paolo', 'cognome' => 'Secondo',
        ]);

        // 2 approvate per top, 1 approvata + 1 in attesa per secondo
        RichiestaIscrizione::factory()->count(2)->create([
            'associazione_id' => $this->associazione->id,
            'referral_codice' => $top->codice_referral,
            'stato' => RichiestaIscrizione::STATO_APPROVATA,
        ]);
        RichiestaIscrizione::factory()->create([
            'associazione_id' => $this->associazione->id,
            'referral_codice' => $secondo->codice_referral,
            'stato' => RichiestaIscrizione::STATO_APPROVATA,
        ]);
        RichiestaIscrizione::factory()->create([
            'associazione_id' => $this->associazione->id,
            'referral_codice' => $secondo->codice_referral,
            'stato' => RichiestaIscrizione::STATO_IN_ATTESA,
        ]);

        $response = $this->actingAs($this->admin)->get('/ambasciatori');

        $response->assertOk();
        $response->assertViewIs('relazioni.ambasciatori.index');

        $ambasciatori = $response->viewData('ambasciatori');
        $this->assertSame($top->id, $ambasciatori->first()->id);
        $this->assertSame(2, $ambasciatori->first()->iscrizioni_approvate);
        // Totale approvate: top(2) + secondo(1) = 3
        $this->assertSame(3, $response->viewData('totaleApprovate'));
    }

    public function test_la_classifica_isola_i_tenant(): void
    {
        $mio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        RichiestaIscrizione::factory()->create([
            'associazione_id' => $this->associazione->id,
            'referral_codice' => $mio->codice_referral,
            'stato' => RichiestaIscrizione::STATO_APPROVATA,
        ]);

        $altra = Associazione::factory()->create();
        $altroSocio = Socio::factory()->create(['associazione_id' => $altra->id]);
        RichiestaIscrizione::factory()->create([
            'associazione_id' => $altra->id,
            'referral_codice' => $altroSocio->codice_referral,
            'stato' => RichiestaIscrizione::STATO_APPROVATA,
        ]);

        $response = $this->actingAs($this->admin)->get('/ambasciatori');

        $ids = $response->viewData('ambasciatori')->pluck('id')->all();
        $this->assertContains($mio->id, $ids);
        $this->assertNotContains($altroSocio->id, $ids);
    }

    public function test_contatore_referral_nella_scheda_socio(): void
    {
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Sara', 'cognome' => 'Ambasciatrice',
        ]);
        RichiestaIscrizione::factory()->count(3)->create([
            'associazione_id' => $this->associazione->id,
            'referral_codice' => $socio->codice_referral,
            'stato' => RichiestaIscrizione::STATO_APPROVATA,
        ]);

        $this->actingAs($this->admin)
            ->get('/soci/'.$socio->id)
            ->assertOk()
            ->assertSee('3 amici portati');
    }

    public function test_area_soci_mostra_il_link_di_invito_personale(): void
    {
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'password_hash' => 'secret-password',
        ]);

        $response = $this->actingAs($socio, 'socio')->get('/area-soci');

        $response->assertOk();
        $response->assertSee('Porta un amico');
        $response->assertSee($socio->codice_referral, false);
    }
}
