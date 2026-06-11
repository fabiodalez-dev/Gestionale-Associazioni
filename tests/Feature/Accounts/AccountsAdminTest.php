<?php

namespace Tests\Feature\Accounts;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Prenotazione;
use App\Models\Socio;
use App\Models\Utente;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gestione admin degli account del frontend pubblico
 * (docs/PIANO-PRENOTAZIONI.md, sezione Admin: "accounts").
 */
class AccountsAdminTest extends TestCase
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

    private function creaAccount(array $attributes = []): Account
    {
        return Account::factory()->create(array_merge(
            ['associazione_id' => $this->associazione->id],
            $attributes
        ));
    }

    // ------------------------------------------------------------------
    // Lista
    // ------------------------------------------------------------------

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get('/accounts')->assertRedirect('/login');
    }

    public function test_operatore_senza_permessi_struttura_riceve_403(): void
    {
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($operatore)->get('/accounts')->assertForbidden();
    }

    public function test_index_mostra_solo_gli_account_del_tenant(): void
    {
        $mio = $this->creaAccount(['cognome' => 'Verdolini']);
        Account::factory()->create(['cognome' => 'Estraneo']); // altro tenant

        $response = $this->actingAs($this->admin)->get('/accounts');

        $response->assertOk();
        $response->assertViewIs('accounts.index');
        $response->assertSee('Verdolini');
        $response->assertDontSee('Estraneo');
        $this->assertTrue($response->viewData('accounts')->contains('id', $mio->id));
    }

    public function test_index_ricerca_per_email(): void
    {
        $this->creaAccount(['email' => 'mario.rossi@example.org', 'cognome' => 'Rossi']);
        $this->creaAccount(['email' => 'luigi.bianchi@example.org', 'cognome' => 'Bianchi']);

        $response = $this->actingAs($this->admin)->get('/accounts?search=mario.rossi');

        $response->assertOk();
        $this->assertCount(1, $response->viewData('accounts'));
        $response->assertSee('Rossi');
    }

    public function test_index_conta_le_prenotazioni_e_le_richieste_socio_pendenti(): void
    {
        $account = $this->creaAccount(['richiesta_socio_at' => now()]);
        $evento = Evento::factory()->create(['associazione_id' => $this->associazione->id]);
        Prenotazione::factory()->count(2)->confermata()->create([
            'evento_id' => $evento->id,
            'account_id' => $account->id,
        ]);

        $response = $this->actingAs($this->admin)->get('/accounts');

        $response->assertOk();
        $this->assertSame(2, $response->viewData('accounts')->firstWhere('id', $account->id)->prenotazioni_count);
        $this->assertSame(1, $response->viewData('richiestePendenti'));
        $response->assertSee('Richiesta socio');
    }

    public function test_index_filtra_le_richieste_socio_pendenti(): void
    {
        $pendente = $this->creaAccount(['richiesta_socio_at' => now()]);
        $this->creaAccount(); // senza richiesta

        $response = $this->actingAs($this->admin)->get('/accounts?richieste=1');

        $response->assertOk();
        $this->assertCount(1, $response->viewData('accounts'));
        $this->assertTrue($response->viewData('accounts')->contains('id', $pendente->id));
    }

    // ------------------------------------------------------------------
    // Dettaglio
    // ------------------------------------------------------------------

    public function test_show_mostra_dettaglio_e_prenotazioni(): void
    {
        $account = $this->creaAccount();
        $evento = Evento::factory()->create(['associazione_id' => $this->associazione->id]);
        $prenotazione = Prenotazione::factory()->confermata()->create([
            'evento_id' => $evento->id,
            'account_id' => $account->id,
        ]);

        $response = $this->actingAs($this->admin)->get("/accounts/{$account->id}");

        $response->assertOk();
        $response->assertViewIs('accounts.show');
        $response->assertSee($account->email);
        $response->assertSee($prenotazione->codice);
    }

    public function test_show_404_su_account_di_altro_tenant(): void
    {
        $estraneo = Account::factory()->create(); // altro tenant

        $this->actingAs($this->admin)->get("/accounts/{$estraneo->id}")->assertNotFound();
    }

    public function test_show_suggerisce_il_socio_con_la_stessa_email(): void
    {
        $account = $this->creaAccount(['email' => 'match@example.org']);
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'MATCH@example.org', // case-insensitive
        ]);
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'altro@example.org',
        ]);

        $response = $this->actingAs($this->admin)->get("/accounts/{$account->id}");

        $response->assertOk();
        $this->assertSame($socio->id, $response->viewData('socioSuggerito')?->id);
    }

    // ------------------------------------------------------------------
    // Collega socio (azione esplicita, vincoli del piano)
    // ------------------------------------------------------------------

    public function test_collega_socio_del_tenant(): void
    {
        $account = $this->creaAccount();
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $response = $this->actingAs($this->admin)
            ->post("/accounts/{$account->id}/collega-socio", ['socio_id' => $socio->id]);

        $response->assertRedirect(route('accounts.show', $account));
        $response->assertSessionHas('success');
        $this->assertSame($socio->id, $account->fresh()->socio_id);
    }

    public function test_collega_rifiuta_socio_di_altro_tenant(): void
    {
        $account = $this->creaAccount();
        $socioAltrui = Socio::factory()->create(); // altro tenant

        $response = $this->actingAs($this->admin)
            ->post("/accounts/{$account->id}/collega-socio", ['socio_id' => $socioAltrui->id]);

        $response->assertSessionHasErrors(['socio_id']);
        $this->assertNull($account->fresh()->socio_id);
    }

    public function test_collega_rifiuta_socio_gia_collegato_ad_altro_account(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        $this->creaAccount(['socio_id' => $socio->id]);

        $account = $this->creaAccount();

        $response = $this->actingAs($this->admin)
            ->post("/accounts/{$account->id}/collega-socio", ['socio_id' => $socio->id]);

        $response->assertSessionHasErrors(['socio_id']);
        $this->assertNull($account->fresh()->socio_id);
    }

    public function test_collega_rifiuta_account_gia_collegato(): void
    {
        $socioCollegato = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        $account = $this->creaAccount(['socio_id' => $socioCollegato->id]);
        $altroSocio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $response = $this->actingAs($this->admin)
            ->post("/accounts/{$account->id}/collega-socio", ['socio_id' => $altroSocio->id]);

        $response->assertSessionHasErrors(['socio_id']);
        $this->assertSame($socioCollegato->id, $account->fresh()->socio_id);
    }

    public function test_operatore_non_puo_collegare(): void
    {
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $account = $this->creaAccount();
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($operatore)
            ->post("/accounts/{$account->id}/collega-socio", ['socio_id' => $socio->id])
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Promuovi a socio
    // ------------------------------------------------------------------

    public function test_promuovi_crea_il_socio_dall_anagrafica_e_lo_collega(): void
    {
        $account = $this->creaAccount([
            'nome' => 'Anna',
            'cognome' => 'Magnani',
            'email' => 'anna.magnani@example.org',
            'telefono' => '3331112223',
            'richiesta_socio_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->post("/accounts/{$account->id}/promuovi-socio");

        $response->assertRedirect(route('accounts.show', $account));
        $response->assertSessionHas('success');

        $account->refresh();
        $this->assertNotNull($account->socio_id);

        $socio = Socio::query()->findOrFail($account->socio_id);
        $this->assertSame('Anna', $socio->nome);
        $this->assertSame('Magnani', $socio->cognome);
        $this->assertSame('anna.magnani@example.org', $socio->email);
        $this->assertSame(Socio::STATO_ATTIVO, $socio->stato);
        // Numero socio progressivo dal service esistente (primo socio → 001)
        $this->assertSame('001', $socio->numero_socio);
        $this->assertSame($this->associazione->id, $socio->associazione_id);
    }

    public function test_promuovi_usa_il_progressivo_successivo(): void
    {
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'numero_socio' => '007',
        ]);
        $account = $this->creaAccount();

        $this->actingAs($this->admin)->post("/accounts/{$account->id}/promuovi-socio");

        $socio = Socio::query()->findOrFail($account->fresh()->socio_id);
        $this->assertSame('008', $socio->numero_socio);
    }

    public function test_promuovi_rifiutato_se_gia_collegato(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        $account = $this->creaAccount(['socio_id' => $socio->id]);

        $response = $this->actingAs($this->admin)
            ->post("/accounts/{$account->id}/promuovi-socio");

        $response->assertSessionHas('error');
        $this->assertSame($socio->id, $account->fresh()->socio_id);
        $this->assertSame(1, Socio::query()->count());
    }

    // ------------------------------------------------------------------
    // Disattiva / riattiva
    // ------------------------------------------------------------------

    public function test_disattiva_e_riattiva_account(): void
    {
        $account = $this->creaAccount();

        $this->actingAs($this->admin)
            ->patch("/accounts/{$account->id}/disattiva")
            ->assertSessionHas('success');
        $this->assertFalse($account->fresh()->attivo);

        $this->actingAs($this->admin)
            ->patch("/accounts/{$account->id}/riattiva")
            ->assertSessionHas('success');
        $this->assertTrue($account->fresh()->attivo);
    }

    public function test_disattiva_404_su_account_di_altro_tenant(): void
    {
        $estraneo = Account::factory()->create();

        $this->actingAs($this->admin)
            ->patch("/accounts/{$estraneo->id}/disattiva")
            ->assertNotFound();
        $this->assertTrue($estraneo->fresh()->attivo);
    }

    // ------------------------------------------------------------------
    // Sidebar
    // ------------------------------------------------------------------

    public function test_sidebar_mostra_voce_account_frontend_con_badge_richieste(): void
    {
        // La voce di sidebar è dietro il permesso spatie struttura.view
        $this->seed(RolesSeeder::class);
        $this->admin->assignRole('admin_associazione');

        $this->creaAccount(['richiesta_socio_at' => now()]);

        $response = $this->actingAs($this->admin)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Account frontend');
        $response->assertSee(route('accounts.index'), false);
    }
}
