<?php

namespace Tests\Feature\EventiVita;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Prenotazione;
use App\Models\Socio;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * f.10 — Eventi riservati ai soci: enforcement in PrenotazioneService
 * (prenotabile solo da account collegati a un socio Attivo), badge nel
 * catalogo pubblico e CTA spiegata per i non soci.
 */
class SoloSociTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Evento $evento;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create(['frontend_pubblico_attivo' => true]);
        $this->evento = Evento::factory()->pubblicato()->conCapienza(10)->create([
            'associazione_id' => $this->associazione->id,
            'data_evento' => now()->addWeek(),
            'solo_soci' => true,
        ]);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('tenant.pubblico');

        parent::tearDown();
    }

    private function urlPrenota(): string
    {
        return "/a/{$this->associazione->slug}/eventi/{$this->evento->id}/prenota";
    }

    public function test_account_collegato_a_socio_attivo_prenota(): void
    {
        $account = Account::factory()->verificato()->collegatoASocio()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($account, 'account')
            ->post($this->urlPrenota(), ['posti' => 1])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Prenotazione::allTenants()->where('account_id', $account->id)->count());
    }

    public function test_account_senza_socio_non_prenota(): void
    {
        $account = Account::factory()->verificato()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($account, 'account')
            ->post($this->urlPrenota(), ['posti' => 1])
            ->assertSessionHasErrors('posti');

        $this->assertSame(0, Prenotazione::allTenants()->count());
    }

    public function test_account_con_socio_non_attivo_non_prenota(): void
    {
        $socioSospeso = Socio::factory()->sospeso()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $account = Account::factory()->verificato()->collegatoASocio($socioSospeso)->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($account, 'account')
            ->post($this->urlPrenota(), ['posti' => 1])
            ->assertSessionHasErrors('posti');

        $this->assertSame(0, Prenotazione::allTenants()->count());
    }

    public function test_evento_senza_flag_resta_prenotabile_da_tutti(): void
    {
        $this->evento->forceFill(['solo_soci' => false])->save();

        $account = Account::factory()->verificato()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($account, 'account')
            ->post($this->urlPrenota(), ['posti' => 1])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Prenotazione::allTenants()->count());
    }

    // ------------------------------------------------------------------
    // Catalogo pubblico: badge e CTA
    // ------------------------------------------------------------------

    public function test_badge_riservato_ai_soci_nel_catalogo(): void
    {
        $this->get("/a/{$this->associazione->slug}/eventi")
            ->assertOk()
            ->assertSee('Riservato ai soci');
    }

    public function test_cta_spiegata_per_account_non_socio_nel_dettaglio(): void
    {
        $account = Account::factory()->verificato()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $response = $this->actingAs($account, 'account')
            ->get("/a/{$this->associazione->slug}/eventi/{$this->evento->id}");

        $response->assertOk();
        $response->assertSee('Evento riservato ai soci');
        $response->assertSee('socio attivo');
        // Il form di prenotazione non viene proposto
        $response->assertDontSee('Numero di posti');
    }

    public function test_account_socio_attivo_vede_il_form_di_prenotazione(): void
    {
        $account = Account::factory()->verificato()->collegatoASocio()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($account, 'account')
            ->get("/a/{$this->associazione->slug}/eventi/{$this->evento->id}")
            ->assertOk()
            ->assertSee('Numero di posti');
    }

    // ------------------------------------------------------------------
    // Admin: flag impostabile da form
    // ------------------------------------------------------------------

    public function test_admin_imposta_il_flag_solo_soci_alla_creazione(): void
    {
        $admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($admin)->post('/eventi', [
            'titolo' => 'Assemblea dei soci',
            'data_evento' => now()->addWeek()->format('Y-m-d\TH:i'),
            'solo_soci' => '1',
        ])->assertRedirect('/eventi');

        $evento = Evento::query()->where('titolo', 'Assemblea dei soci')->firstOrFail();
        $this->assertTrue($evento->solo_soci);
    }

    public function test_il_flag_non_si_azzera_se_il_form_legacy_non_lo_invia(): void
    {
        $admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        // Update senza il campo solo_soci: il valore corrente viene preservato
        $this->actingAs($admin)->put("/eventi/{$this->evento->id}", [
            'titolo' => 'Titolo aggiornato',
            'data_evento' => $this->evento->data_evento->format('Y-m-d\TH:i'),
        ])->assertRedirect('/eventi');

        $this->assertTrue($this->evento->fresh()->solo_soci);
    }
}
