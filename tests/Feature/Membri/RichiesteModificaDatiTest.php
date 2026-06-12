<?php

namespace Tests\Feature\Membri;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\RichiestaModificaDati;
use App\Models\Socio;
use App\Models\Utente;
use App\Services\Membri\ModificaDatiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * f.19 (piano "Gestione 2") — Richieste di modifica dati anagrafici:
 * proposta dall'area soci e dall'area account (solo se collegato a un
 * socio), gestione admin con diff, approva (applica + storico), rifiuta
 * con motivo, isolamento tenant.
 */
class RichiesteModificaDatiTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create([
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

    private function creaSocio(array $attributes = []): Socio
    {
        return Socio::factory()->conAccessoAreaSoci()->create(array_merge(
            ['associazione_id' => $this->associazione->id],
            $attributes
        ));
    }

    // ------------------------------------------------------------------
    // Area soci: proposta
    // ------------------------------------------------------------------

    public function test_la_pagina_modifica_dati_richiede_il_login_socio(): void
    {
        $this->get(route('area-soci.modifica-dati'))
            ->assertRedirect(route('area-soci.login'));
    }

    public function test_il_socio_vede_il_form_precompilato(): void
    {
        $socio = $this->creaSocio(['telefono' => '3331234567', 'citta' => 'Milano']);

        $this->actingAs($socio, 'socio')
            ->get(route('area-soci.modifica-dati'))
            ->assertOk()
            ->assertSee('Richiedi modifica dati')
            ->assertSee('3331234567')
            ->assertSee('Milano');
    }

    public function test_il_socio_propone_modifiche_e_viene_salvato_solo_il_diff(): void
    {
        $socio = $this->creaSocio([
            'nome' => 'Giulia',
            'telefono' => '3331234567',
            'citta' => 'Milano',
        ]);

        $this->actingAs($socio, 'socio')
            ->post(route('area-soci.modifica-dati.store'), [
                'nome' => 'Giulia',          // invariato → escluso dal diff
                'telefono' => '3399876543',  // cambiato
                'citta' => 'Torino',         // cambiato
            ])
            ->assertRedirect(route('area-soci.modifica-dati'))
            ->assertSessionHas('success');

        $richiesta = RichiestaModificaDati::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($socio->id, $richiesta->socio_id);
        $this->assertSame($this->associazione->id, $richiesta->associazione_id);
        $this->assertSame(RichiestaModificaDati::STATO_IN_ATTESA, $richiesta->stato);
        $this->assertSame(['telefono' => '3399876543', 'citta' => 'Torino'], $richiesta->proposte);
        $this->assertSame(Socio::class, $richiesta->origine_type);
        $this->assertSame($socio->id, $richiesta->origine_id);

        // Il socio NON viene modificato finché l'admin non approva
        $socio->refresh();
        $this->assertSame('3331234567', $socio->telefono);
        $this->assertSame('Milano', $socio->citta);
    }

    public function test_proposta_senza_modifiche_reali_viene_rifiutata(): void
    {
        $socio = $this->creaSocio(['telefono' => '3331234567']);

        $this->actingAs($socio, 'socio')
            ->post(route('area-soci.modifica-dati.store'), ['telefono' => '3331234567'])
            ->assertRedirect(route('area-soci.modifica-dati'))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('richieste_modifica_dati', 0);
    }

    public function test_una_sola_richiesta_pendente_per_socio(): void
    {
        $socio = $this->creaSocio();
        RichiestaModificaDati::factory()->create([
            'socio_id' => $socio->id,
            'origine_type' => Socio::class,
            'origine_id' => $socio->id,
        ]);

        $this->actingAs($socio, 'socio')
            ->post(route('area-soci.modifica-dati.store'), ['citta' => 'NuovaCittà'])
            ->assertSessionHas('error');

        $this->assertSame(1, RichiestaModificaDati::withoutGlobalScopes()->count());
    }

    public function test_email_di_un_altro_socio_del_tenant_viene_bloccata(): void
    {
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'occupata@example.com',
        ]);
        $socio = $this->creaSocio();

        $this->actingAs($socio, 'socio')
            ->from(route('area-soci.modifica-dati'))
            ->post(route('area-soci.modifica-dati.store'), ['email' => 'occupata@example.com'])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('richieste_modifica_dati', 0);
    }

    // ------------------------------------------------------------------
    // Area account (frontend pubblico)
    // ------------------------------------------------------------------

    public function test_account_collegato_propone_modifiche_con_origine_account(): void
    {
        $socio = $this->creaSocio(['telefono' => '3331234567']);
        $account = Account::factory()->collegatoASocio($socio)->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $url = '/a/'.$this->associazione->slug.'/account/modifica-dati';

        $this->actingAs($account, 'account')->get($url)
            ->assertOk()
            ->assertSee('Richiedi modifica dati');

        $this->actingAs($account, 'account')
            ->post($url, ['telefono' => '3398887777'])
            ->assertSessionHas('success');

        $richiesta = RichiestaModificaDati::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($socio->id, $richiesta->socio_id);
        $this->assertSame(Account::class, $richiesta->origine_type);
        $this->assertSame($account->id, $richiesta->origine_id);
        $this->assertSame(['telefono' => '3398887777'], $richiesta->proposte);
    }

    public function test_account_non_collegato_a_un_socio_riceve_404(): void
    {
        $account = Account::factory()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($account, 'account')
            ->get('/a/'.$this->associazione->slug.'/account/modifica-dati')
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Admin: lista, approvazione, rifiuto
    // ------------------------------------------------------------------

    public function test_la_pagina_admin_richiede_il_login(): void
    {
        $this->get('/richieste-modifica-dati')->assertRedirect('/login');
    }

    public function test_index_mostra_le_pendenti_del_tenant_con_il_diff(): void
    {
        $socio = $this->creaSocio(['telefono' => '3331234567']);
        RichiestaModificaDati::factory()->create([
            'socio_id' => $socio->id,
            'proposte' => ['telefono' => '3390000000'],
        ]);

        // Richiesta di un altro tenant: non deve comparire
        $altroSocio = Socio::factory()->create(['nome' => 'Estraneo', 'cognome' => 'AltroTenant']);
        RichiestaModificaDati::factory()->create(['socio_id' => $altroSocio->id]);

        $this->actingAs($this->admin)
            ->get('/richieste-modifica-dati')
            ->assertOk()
            ->assertSee($socio->nome_completo)
            ->assertSee('3331234567')   // valore attuale
            ->assertSee('3390000000')   // valore proposto
            ->assertDontSee('AltroTenant');
    }

    public function test_approva_applica_le_modifiche_e_scrive_lo_storico(): void
    {
        $socio = $this->creaSocio(['telefono' => '3331234567', 'citta' => 'Milano']);
        $richiesta = RichiestaModificaDati::factory()->create([
            'socio_id' => $socio->id,
            'proposte' => ['telefono' => '3390000000', 'citta' => 'Torino'],
        ]);

        $this->actingAs($this->admin)
            ->post("/richieste-modifica-dati/{$richiesta->id}/approva")
            ->assertRedirect('/richieste-modifica-dati')
            ->assertSessionHas('success');

        $socio->refresh();
        $this->assertSame('3390000000', $socio->telefono);
        $this->assertSame('Torino', $socio->citta);

        $richiesta->refresh();
        $this->assertSame(RichiestaModificaDati::STATO_APPROVATA, $richiesta->stato);
        $this->assertSame($this->admin->id, $richiesta->gestita_da);
        $this->assertNotNull($richiesta->gestita_at);

        $this->assertDatabaseHas('storico_attivita_socio', [
            'socio_id' => $socio->id,
            'tipo_attivita' => 'Modifica Dati',
            'utente_id' => $this->admin->id,
        ]);
    }

    public function test_il_rifiuto_richiede_il_motivo_e_lo_storicizza(): void
    {
        $socio = $this->creaSocio();
        $richiesta = RichiestaModificaDati::factory()->create([
            'socio_id' => $socio->id,
            'proposte' => ['citta' => 'Torino'],
        ]);

        // Motivo mancante → errore di validazione
        $this->actingAs($this->admin)
            ->from('/richieste-modifica-dati')
            ->post("/richieste-modifica-dati/{$richiesta->id}/rifiuta")
            ->assertSessionHasErrors('motivo');

        $this->actingAs($this->admin)
            ->post("/richieste-modifica-dati/{$richiesta->id}/rifiuta", ['motivo' => 'Dati non verificabili'])
            ->assertRedirect('/richieste-modifica-dati')
            ->assertSessionHas('success');

        $richiesta->refresh();
        $this->assertSame(RichiestaModificaDati::STATO_RIFIUTATA, $richiesta->stato);
        $this->assertSame('Dati non verificabili', app(ModificaDatiService::class)->motivoRifiuto($richiesta));

        // La modifica NON è stata applicata
        $this->assertNotSame('Torino', $socio->fresh()->citta);

        // Il motivo è visibile al socio nella sua area riservata
        $this->actingAs($socio, 'socio')
            ->get(route('area-soci.modifica-dati'))
            ->assertOk()
            ->assertSee('Dati non verificabili');
    }

    public function test_una_richiesta_gia_gestita_non_e_riapprovabile(): void
    {
        $socio = $this->creaSocio();
        $richiesta = RichiestaModificaDati::factory()->approvata()->create([
            'socio_id' => $socio->id,
        ]);

        $this->actingAs($this->admin)
            ->post("/richieste-modifica-dati/{$richiesta->id}/approva")
            ->assertRedirect('/richieste-modifica-dati')
            ->assertSessionHas('error');
    }

    public function test_richiesta_di_un_altro_tenant_da_404(): void
    {
        $altroSocio = Socio::factory()->create();
        $richiesta = RichiestaModificaDati::factory()->create(['socio_id' => $altroSocio->id]);

        $this->actingAs($this->admin)
            ->post("/richieste-modifica-dati/{$richiesta->id}/approva")
            ->assertNotFound();

        $this->assertSame(
            RichiestaModificaDati::STATO_IN_ATTESA,
            $richiesta->fresh()->stato
        );
    }
}
