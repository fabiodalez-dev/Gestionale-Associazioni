<?php

namespace Tests\Feature\Iscrizioni;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\RichiestaIscrizione;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\Utente;
use App\Notifications\Iscrizioni\IscrizioneApprovataNotification;
use App\Notifications\Iscrizioni\IscrizioneRifiutataNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Pagina admin "Richieste iscrizione" (PIANO-ISCRIZIONI §Admin):
 * lista pendenti + storico, approvazione (socio → Attivo, account
 * collegato, tessera opzionale), rifiuto con motivo obbligatorio
 * (socio preiscritto eliminato), isolamento tenant, notifiche.
 */
class RichiesteIscrizioneAdminTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->associazione = Associazione::factory()->create();
        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
    }

    /**
     * Richiesta in_attesa completa di account e socio preiscritto del tenant.
     */
    private function creaRichiestaPendente(array $attributes = []): RichiestaIscrizione
    {
        return RichiestaIscrizione::factory()
            ->conSocio()
            ->create(array_merge(['associazione_id' => $this->associazione->id], $attributes));
    }

    // ------------------------------------------------------------------
    // Accesso e lista
    // ------------------------------------------------------------------

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get('/richieste-iscrizione')->assertRedirect('/login');
    }

    public function test_index_mostra_le_richieste_pendenti_del_tenant(): void
    {
        $richiesta = $this->creaRichiestaPendente();
        $account = $richiesta->account;

        // Richiesta di un altro tenant: NON deve comparire
        $altraAssociazione = Associazione::factory()->create();
        $accountEstraneo = Account::factory()->create([
            'associazione_id' => $altraAssociazione->id,
            'email' => 'estraneo@example.test',
        ]);
        RichiestaIscrizione::factory()->conSocio()->create([
            'associazione_id' => $altraAssociazione->id,
            'account_id' => $accountEstraneo->id,
        ]);

        $response = $this->actingAs($this->admin)->get('/richieste-iscrizione');

        $response->assertOk();
        $response->assertSee($account->email);
        $response->assertDontSee('estraneo@example.test');
    }

    public function test_index_mostra_lo_storico_gestito(): void
    {
        $rifiutata = RichiestaIscrizione::factory()
            ->rifiutata('Dati non verificabili', $this->admin)
            ->create(['associazione_id' => $this->associazione->id]);

        $response = $this->actingAs($this->admin)->get('/richieste-iscrizione');

        $response->assertOk();
        $response->assertSee('Dati non verificabili');
        $response->assertSee($rifiutata->account->email);
    }

    // ------------------------------------------------------------------
    // Approvazione
    // ------------------------------------------------------------------

    public function test_approva_attiva_il_socio_collega_account_e_chiude_la_richiesta(): void
    {
        $richiesta = $this->creaRichiestaPendente();
        $account = $richiesta->account;
        $socio = $richiesta->socio;

        $this->assertSame(Socio::STATO_IN_ATTESA, $socio->stato);
        $this->assertNull($account->socio_id);

        $response = $this->actingAs($this->admin)
            ->post("/richieste-iscrizione/{$richiesta->id}/approva");

        $response->assertRedirect('/richieste-iscrizione');
        $response->assertSessionHas('success');

        $richiesta->refresh();
        $this->assertSame(RichiestaIscrizione::STATO_APPROVATA, $richiesta->stato);
        $this->assertSame($this->admin->id, $richiesta->gestita_da);
        $this->assertNotNull($richiesta->gestita_at);

        $this->assertSame(Socio::STATO_ATTIVO, $socio->fresh()->stato);
        $this->assertSame($socio->id, $account->fresh()->socio_id);

        Notification::assertSentTo($account, IscrizioneApprovataNotification::class);
    }

    public function test_approva_senza_checkbox_non_genera_la_tessera(): void
    {
        $richiesta = $this->creaRichiestaPendente();

        $this->actingAs($this->admin)
            ->post("/richieste-iscrizione/{$richiesta->id}/approva");

        $this->assertSame(0, Tessera::allTenants()->where('socio_id', $richiesta->socio_id)->count());
    }

    public function test_approva_con_genera_tessera_crea_la_tessera_anno_corrente(): void
    {
        $richiesta = $this->creaRichiestaPendente();
        $account = $richiesta->account;

        $this->actingAs($this->admin)
            ->post("/richieste-iscrizione/{$richiesta->id}/approva", [
                'genera_tessera' => '1',
            ])
            ->assertSessionHas('success');

        $tessera = Tessera::allTenants()
            ->where('socio_id', $richiesta->socio_id)
            ->where('anno_validita', now()->year)
            ->first();

        $this->assertNotNull($tessera);
        $this->assertSame(Tessera::STATO_ATTIVA, $tessera->stato);

        // Il numero tessera viaggia nella notifica di benvenuto
        Notification::assertSentTo(
            $account,
            IscrizioneApprovataNotification::class,
            fn (IscrizioneApprovataNotification $n) => $n->numeroTessera === $tessera->numero_tessera
        );
    }

    public function test_approva_richiesta_gia_gestita_mostra_errore(): void
    {
        $richiesta = RichiestaIscrizione::factory()
            ->approvata($this->admin)
            ->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)
            ->post("/richieste-iscrizione/{$richiesta->id}/approva")
            ->assertRedirect('/richieste-iscrizione')
            ->assertSessionHas('error');
    }

    // ------------------------------------------------------------------
    // Rifiuto
    // ------------------------------------------------------------------

    public function test_rifiuta_richiede_il_motivo(): void
    {
        $richiesta = $this->creaRichiestaPendente();

        $this->actingAs($this->admin)
            ->post("/richieste-iscrizione/{$richiesta->id}/rifiuta")
            ->assertSessionHasErrors('motivo');

        $this->assertSame(RichiestaIscrizione::STATO_IN_ATTESA, $richiesta->fresh()->stato);
        $this->assertNotNull(Socio::allTenants()->find($richiesta->socio_id));
    }

    public function test_rifiuta_elimina_il_socio_preiscritto_e_registra_il_motivo(): void
    {
        $richiesta = $this->creaRichiestaPendente();
        $account = $richiesta->account;
        $account->forceFill(['richiesta_socio_at' => now()])->save();
        $socioId = $richiesta->socio_id;

        $response = $this->actingAs($this->admin)
            ->post("/richieste-iscrizione/{$richiesta->id}/rifiuta", [
                'motivo' => 'Codice fiscale non valido',
            ]);

        $response->assertRedirect('/richieste-iscrizione');
        $response->assertSessionHas('success');

        $richiesta->refresh();
        $this->assertSame(RichiestaIscrizione::STATO_RIFIUTATA, $richiesta->stato);
        $this->assertSame('Codice fiscale non valido', $richiesta->motivo_rifiuto);
        $this->assertSame($this->admin->id, $richiesta->gestita_da);
        $this->assertNull($richiesta->socio_id);

        // Il socio preiscritto è eliminato definitivamente (niente cestino)
        $this->assertDatabaseMissing('soci', ['id' => $socioId]);

        // L'account può ripresentare la domanda
        $this->assertNull($account->fresh()->richiesta_socio_at);

        Notification::assertSentTo($account, IscrizioneRifiutataNotification::class);
    }

    public function test_rifiuta_richiesta_gia_gestita_mostra_errore(): void
    {
        $richiesta = RichiestaIscrizione::factory()
            ->rifiutata('Già rifiutata', $this->admin)
            ->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)
            ->post("/richieste-iscrizione/{$richiesta->id}/rifiuta", ['motivo' => 'Di nuovo'])
            ->assertRedirect('/richieste-iscrizione')
            ->assertSessionHas('error');

        $this->assertSame('Già rifiutata', $richiesta->fresh()->motivo_rifiuto);
    }

    // ------------------------------------------------------------------
    // Isolamento tenant
    // ------------------------------------------------------------------

    public function test_richiesta_di_altro_tenant_produce_404(): void
    {
        $estranea = RichiestaIscrizione::factory()->conSocio()->create();

        $this->actingAs($this->admin)
            ->post("/richieste-iscrizione/{$estranea->id}/approva")
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->post("/richieste-iscrizione/{$estranea->id}/rifiuta", ['motivo' => 'X'])
            ->assertNotFound();

        $this->assertSame(RichiestaIscrizione::STATO_IN_ATTESA, $estranea->fresh()->stato);
    }

    public function test_operatore_puo_gestire_le_richieste(): void
    {
        // La gestione richieste segue i permessi soci (aperta anche all'operatore)
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $richiesta = $this->creaRichiestaPendente();

        $this->actingAs($operatore)
            ->post("/richieste-iscrizione/{$richiesta->id}/approva")
            ->assertRedirect('/richieste-iscrizione')
            ->assertSessionHas('success');

        $this->assertSame(RichiestaIscrizione::STATO_APPROVATA, $richiesta->fresh()->stato);
    }
}
