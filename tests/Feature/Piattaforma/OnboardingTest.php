<?php

namespace Tests\Feature\Piattaforma;

use App\Models\Associazione;
use App\Models\Evento;
use App\Models\SmtpSetting;
use App\Models\Socio;
use App\Models\Utente;
use App\Services\Piattaforma\OnboardingChecklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * f.23 — Card onboarding admin in dashboard: checklist calcolata dallo
 * stato reale del tenant, progress, dismiss persistente, sparizione al 100%.
 */
class OnboardingTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_la_card_compare_in_dashboard_per_l_admin_con_checklist_incompleta(): void
    {
        $response = $this->actingAs($this->admin)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Per iniziare: configura');
        // Factory: slug (auto-generato) e costo tessera già a posto → 2 di 7
        $response->assertSee('2 di 7 passi completati');
        $response->assertSee('Registra il primo socio');
        $response->assertSee('Pubblica il primo evento');
    }

    public function test_la_checklist_riflette_lo_stato_reale_del_tenant(): void
    {
        Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        SmtpSetting::create([
            'associazione_id' => $this->associazione->id,
            'smtp_host' => 'smtp.example.test',
            'smtp_pass_encrypted' => 'segreta',
        ]);

        $response = $this->actingAs($this->admin)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('4 di 7 passi completati');
    }

    public function test_i_dati_di_un_altro_tenant_non_completano_i_passi(): void
    {
        $altra = Associazione::factory()->create();
        Socio::factory()->create(['associazione_id' => $altra->id]);
        Evento::factory()->pubblicato()->create(['associazione_id' => $altra->id]);

        $response = $this->actingAs($this->admin)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('2 di 7 passi completati');
    }

    public function test_la_card_sparisce_quando_la_checklist_e_al_100_per_cento(): void
    {
        $this->associazione->update([
            'logo_url' => '/storage/logo.png',
            'iscrizione_form_config' => ['campi' => ['nome', 'cognome']],
        ]);
        SmtpSetting::create([
            'associazione_id' => $this->associazione->id,
            'smtp_host' => 'smtp.example.test',
            'smtp_pass_encrypted' => 'segreta',
        ]);
        Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        Evento::factory()->pubblicato()->create(['associazione_id' => $this->associazione->id]);

        $response = $this->actingAs($this->admin)->get(route('dashboard'));

        $response->assertOk();
        $response->assertDontSee('Per iniziare: configura');
    }

    public function test_il_dismiss_nasconde_la_card_in_modo_persistente(): void
    {
        $this->actingAs($this->admin)
            ->post(route('onboarding.dismiss'))
            ->assertRedirect(route('dashboard'));

        $this->assertTrue(app(OnboardingChecklist::class)->dismessa($this->associazione));

        $response = $this->actingAs($this->admin)->get(route('dashboard'));

        $response->assertOk();
        $response->assertDontSee('Per iniziare: configura');
    }

    public function test_l_operatore_non_vede_la_card_e_non_puo_dismetterla(): void
    {
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($operatore)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Per iniziare: configura');

        $this->actingAs($operatore)
            ->post(route('onboarding.dismiss'))
            ->assertForbidden();
    }

    public function test_il_super_admin_in_vista_globale_non_vede_la_card(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->get(route('dashboard'));

        $response->assertOk();
        $response->assertDontSee('Per iniziare: configura');
    }

    public function test_il_super_admin_con_tenant_selezionato_vede_la_card(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)
            ->withSession(['associazione_id' => $this->associazione->id])
            ->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Per iniziare: configura');
    }

    public function test_il_dismiss_richiede_il_login(): void
    {
        $this->post(route('onboarding.dismiss'))->assertRedirect('/login');
    }
}
