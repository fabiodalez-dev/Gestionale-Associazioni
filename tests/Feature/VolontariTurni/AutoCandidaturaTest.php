<?php

namespace Tests\Feature\VolontariTurni;

use App\Models\Associazione;
use App\Models\Socio;
use App\Models\Turno;
use App\Models\TurnoAssegnazione;
use App\Models\Utente;
use App\Notifications\VolontariTurni\CandidaturaTurnoNotification;
use App\Notifications\VolontariTurni\RinunciaTurnoNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * f.4 — Auto-candidatura del socio: candidatura proposta o auto-confermata,
 * rinuncia con riapertura slot, notifiche admin, isolamento e conflitti.
 */
class AutoCandidaturaTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Socio $socio;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->associazione = Associazione::factory()->create();
        // Un admin del tenant che riceverà gli avvisi.
        Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->associazione->id]);

        $this->socio = Socio::factory()->conAccessoAreaSoci()->create([
            'associazione_id' => $this->associazione->id,
            'volontario_attivo' => true,
        ]);
    }

    public function test_pagina_volontariato_elenca_i_turni_aperti(): void
    {
        Turno::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Turno aperto futuro',
            'data' => now()->addDays(3)->toDateString(),
        ]);

        $this->actingAs($this->socio, 'socio')
            ->get(route('area-soci.volontariato'))
            ->assertOk()
            ->assertSee('Turno aperto futuro');
    }

    public function test_candidatura_proposta_avvisa_admin(): void
    {
        $turno = Turno::factory()->create([
            'associazione_id' => $this->associazione->id, 'posti' => 2,
            'data' => now()->addDays(2)->toDateString(),
        ]);

        $this->actingAs($this->socio, 'socio')
            ->post(route('area-soci.volontariato.candidati', $turno))
            ->assertRedirect(route('area-soci.volontariato'));

        $this->assertDatabaseHas('turni_assegnazioni', [
            'turno_id' => $turno->id,
            'socio_id' => $this->socio->id,
            'stato' => TurnoAssegnazione::STATO_PROPOSTO,
        ]);

        Notification::assertSentTo(
            Utente::where('associazione_id', $this->associazione->id)->get(),
            CandidaturaTurnoNotification::class
        );
    }

    public function test_candidatura_auto_confermata_se_turno_lo_prevede(): void
    {
        $turno = Turno::factory()->autoConferma()->create([
            'associazione_id' => $this->associazione->id, 'posti' => 2,
            'data' => now()->addDays(2)->toDateString(),
        ]);

        $this->actingAs($this->socio, 'socio')
            ->post(route('area-soci.volontariato.candidati', $turno))
            ->assertRedirect();

        $this->assertDatabaseHas('turni_assegnazioni', [
            'turno_id' => $turno->id,
            'socio_id' => $this->socio->id,
            'stato' => TurnoAssegnazione::STATO_CONFERMATO,
        ]);

        Notification::assertNothingSentTo(
            Utente::where('associazione_id', $this->associazione->id)->get()
        );
    }

    public function test_candidatura_bloccata_se_posti_esauriti(): void
    {
        $turno = Turno::factory()->create([
            'associazione_id' => $this->associazione->id, 'posti' => 1,
            'data' => now()->addDays(2)->toDateString(),
        ]);
        TurnoAssegnazione::factory()->confermata()->create([
            'associazione_id' => $this->associazione->id, 'turno_id' => $turno->id,
        ]);

        $this->actingAs($this->socio, 'socio')
            ->post(route('area-soci.volontariato.candidati', $turno))
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('turni_assegnazioni', [
            'turno_id' => $turno->id, 'socio_id' => $this->socio->id,
        ]);
    }

    public function test_candidatura_su_turno_chiuso_rifiutata(): void
    {
        $turno = Turno::factory()->chiuso()->create([
            'associazione_id' => $this->associazione->id,
            'data' => now()->addDays(2)->toDateString(),
        ]);

        $this->actingAs($this->socio, 'socio')
            ->post(route('area-soci.volontariato.candidati', $turno))
            ->assertSessionHas('error');
    }

    public function test_rinuncia_riapre_slot_e_avvisa_admin(): void
    {
        $turno = Turno::factory()->create([
            'associazione_id' => $this->associazione->id, 'posti' => 1,
            'data' => now()->addDays(2)->toDateString(),
        ]);
        $assegnazione = TurnoAssegnazione::factory()->confermata()->create([
            'associazione_id' => $this->associazione->id,
            'turno_id' => $turno->id,
            'socio_id' => $this->socio->id,
        ]);

        $this->actingAs($this->socio, 'socio')
            ->post(route('area-soci.volontariato.rinuncia', $assegnazione), [
                'motivo' => 'Imprevisto familiare',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('turni_assegnazioni', [
            'id' => $assegnazione->id,
            'stato' => TurnoAssegnazione::STATO_RINUNCIATO,
            'motivo_rinuncia' => 'Imprevisto familiare',
        ]);
        $this->assertSame(1, $turno->fresh()->postiDisponibili());

        Notification::assertSentTo(
            Utente::where('associazione_id', $this->associazione->id)->get(),
            RinunciaTurnoNotification::class
        );
    }

    public function test_socio_non_puo_rinunciare_per_un_altro(): void
    {
        $turno = Turno::factory()->create(['associazione_id' => $this->associazione->id]);
        $altroSocio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        $assegnazione = TurnoAssegnazione::factory()->confermata()->create([
            'associazione_id' => $this->associazione->id,
            'turno_id' => $turno->id,
            'socio_id' => $altroSocio->id,
        ]);

        $this->actingAs($this->socio, 'socio')
            ->post(route('area-soci.volontariato.rinuncia', $assegnazione))
            ->assertForbidden();
    }

    public function test_candidatura_richiede_autenticazione_socio(): void
    {
        $turno = Turno::factory()->create([
            'associazione_id' => $this->associazione->id,
            'data' => now()->addDays(2)->toDateString(),
        ]);

        $this->post(route('area-soci.volontariato.candidati', $turno))->assertRedirect();
    }
}
