<?php

namespace Tests\Feature\Operativita;

use App\Models\Associazione;
use App\Models\AttivitaAdmin;
use App\Models\Socio;
use App\Models\Utente;
use App\Notifications\Operativita\PromemoriaAttivitaNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * f.4 — Attività/promemoria admin: pagina elenco con filtri, CRUD,
 * completamento, widget dashboard "Le mie attività" e comando
 * attivita:promemoria (email all'assegnatario il giorno della scadenza,
 * idempotente).
 */
class AttivitaAdminTest extends TestCase
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

    public function test_pagina_elenca_le_attivita_aperte_del_tenant(): void
    {
        $aperta = AttivitaAdmin::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Preparare assemblea',
        ]);
        AttivitaAdmin::factory()->completata()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Attività già completata',
        ]);

        $altra = Associazione::factory()->create();
        AttivitaAdmin::factory()->create([
            'associazione_id' => $altra->id,
            'titolo' => 'Attività di altro tenant',
        ]);

        $response = $this->actingAs($this->admin)->get('/attivita-admin');

        $response->assertOk();
        $response->assertViewIs('operativita.attivita.index');
        $response->assertSee('Preparare assemblea');
        $response->assertDontSee('Attività già completata');
        $response->assertDontSee('Attività di altro tenant');

        $this->assertSame([$aperta->id], $response->viewData('attivita')->pluck('id')->all());
    }

    public function test_crea_un_attivita_con_assegnatario_e_collegamento_socio(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)
            ->post('/attivita-admin', [
                'titolo' => 'Richiamare il socio',
                'descrizione' => 'Per il rinnovo tessera',
                'scadenza' => now()->addDays(3)->toDateString(),
                'assegnatario_id' => $this->admin->id,
                'collegamento' => 'socio',
                'socio_id' => $socio->id,
            ])
            ->assertRedirect('/attivita-admin')
            ->assertSessionHas('success', 'Attività creata.');

        $this->assertDatabaseHas('attivita_admin', [
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Richiamare il socio',
            'assegnatario_id' => $this->admin->id,
            'notabile_type' => Socio::class,
            'notabile_id' => $socio->id,
            'stato' => AttivitaAdmin::STATO_APERTA,
        ]);
    }

    public function test_socio_collegato_di_altro_tenant_viene_rifiutato(): void
    {
        $altra = Associazione::factory()->create();
        $estraneo = Socio::factory()->create(['associazione_id' => $altra->id]);

        $this->actingAs($this->admin)
            ->from('/attivita-admin')
            ->post('/attivita-admin', [
                'titolo' => 'Tentativo',
                'collegamento' => 'socio',
                'socio_id' => $estraneo->id,
            ])
            ->assertSessionHasErrors('socio_id');
    }

    public function test_assegnatario_di_altro_tenant_viene_rifiutato(): void
    {
        $altra = Associazione::factory()->create();
        $estraneo = Utente::factory()->adminAssociazione()->create(['associazione_id' => $altra->id]);

        $this->actingAs($this->admin)
            ->from('/attivita-admin')
            ->post('/attivita-admin', [
                'titolo' => 'Tentativo',
                'assegnatario_id' => $estraneo->id,
            ])
            ->assertSessionHasErrors('assegnatario_id');
    }

    public function test_toggle_completa_e_riapre(): void
    {
        $attivita = AttivitaAdmin::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)
            ->from('/attivita-admin')
            ->post("/attivita-admin/{$attivita->id}/toggle")
            ->assertSessionHas('success', 'Attività completata.');

        $fresh = $attivita->fresh();
        $this->assertTrue($fresh->isCompletata());
        $this->assertNotNull($fresh->completata_at);

        $this->actingAs($this->admin)
            ->from('/attivita-admin')
            ->post("/attivita-admin/{$attivita->id}/toggle")
            ->assertSessionHas('success', 'Attività riaperta.');

        $this->assertFalse($attivita->fresh()->isCompletata());
        $this->assertNull($attivita->fresh()->completata_at);
    }

    public function test_aggiorna_ed_elimina(): void
    {
        $attivita = AttivitaAdmin::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)
            ->put("/attivita-admin/{$attivita->id}", [
                'titolo' => 'Titolo aggiornato',
                'collegamento' => 'nessuno',
            ])
            ->assertRedirect('/attivita-admin');

        $this->assertSame('Titolo aggiornato', $attivita->fresh()->titolo);

        $this->actingAs($this->admin)
            ->delete("/attivita-admin/{$attivita->id}")
            ->assertSessionHas('success', 'Attività eliminata.');

        $this->assertDatabaseMissing('attivita_admin', ['id' => $attivita->id]);
    }

    public function test_attivita_di_altro_tenant_non_raggiungibile(): void
    {
        $altra = Associazione::factory()->create();
        $estranea = AttivitaAdmin::factory()->create(['associazione_id' => $altra->id]);

        $this->actingAs($this->admin)
            ->post("/attivita-admin/{$estranea->id}/toggle")
            ->assertNotFound();
    }

    public function test_widget_dashboard_mostra_le_mie_attivita(): void
    {
        AttivitaAdmin::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Attività assegnata a me',
            'assegnatario_id' => $this->admin->id,
        ]);
        AttivitaAdmin::factory()->completata()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Completata non visibile nel widget',
            'assegnatario_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Le mie attività');
        $response->assertSee('Attività assegnata a me');
        $response->assertDontSee('Completata non visibile nel widget');
    }

    public function test_comando_promemoria_invia_all_assegnatario_solo_il_giorno_della_scadenza(): void
    {
        Notification::fake();

        $oggi = AttivitaAdmin::factory()->create([
            'associazione_id' => $this->associazione->id,
            'scadenza' => today()->toDateString(),
            'assegnatario_id' => $this->admin->id,
        ]);
        // Non in scadenza oggi / senza assegnatario / già completata: ignorate
        AttivitaAdmin::factory()->create([
            'associazione_id' => $this->associazione->id,
            'scadenza' => today()->addDay()->toDateString(),
            'assegnatario_id' => $this->admin->id,
        ]);
        AttivitaAdmin::factory()->create([
            'associazione_id' => $this->associazione->id,
            'scadenza' => today()->toDateString(),
            'assegnatario_id' => null,
        ]);
        AttivitaAdmin::factory()->completata()->create([
            'associazione_id' => $this->associazione->id,
            'scadenza' => today()->toDateString(),
            'assegnatario_id' => $this->admin->id,
        ]);

        $this->artisan('attivita:promemoria')
            ->expectsOutputToContain('Promemoria attività inviati: 1')
            ->assertSuccessful();

        Notification::assertSentTo(
            $this->admin,
            PromemoriaAttivitaNotification::class,
            fn (PromemoriaAttivitaNotification $n) => $n->attivita->is($oggi)
        );
        Notification::assertCount(1);
    }

    public function test_comando_promemoria_e_idempotente_nello_stesso_giorno(): void
    {
        Notification::fake();

        AttivitaAdmin::factory()->create([
            'associazione_id' => $this->associazione->id,
            'scadenza' => today()->toDateString(),
            'assegnatario_id' => $this->admin->id,
        ]);

        $this->artisan('attivita:promemoria')->assertSuccessful();
        $this->artisan('attivita:promemoria')
            ->expectsOutputToContain('Promemoria attività inviati: 0')
            ->assertSuccessful();

        Notification::assertCount(1);
    }

    public function test_richiede_autenticazione(): void
    {
        $this->get('/attivita-admin')->assertRedirect('/login');
    }
}
