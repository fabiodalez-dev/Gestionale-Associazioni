<?php

namespace Tests\Feature\Comunicazione;

use App\Models\Associazione;
use App\Models\PromemoriaInviato;
use App\Models\Socio;
use App\Notifications\Comunicazione\AuguriCompleannoNotification;
use App\Services\Comunicazione\CompleanniService;
use Illuminate\Support\Facades\Notification;

/**
 * f.9 — Compleanni: widget "Compleanni del mese" in dashboard, toggle
 * auguri_automatici nelle configurazioni, comando compleanni:auguri con
 * tracking anti-duplicazione in promemoria_inviati (tipo 'compleanno').
 */
class CompleanniTest extends ComunicazioneTestCase
{
    // ── Widget dashboard ─────────────────────────────────────────────

    public function test_widget_dashboard_mostra_i_compleanni_del_mese(): void
    {
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Festeggiato',
            'cognome' => 'DelMese',
            'data_nascita' => now()->subYears(30)->toDateString(),
        ]);
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Altro',
            'cognome' => 'MeseDiverso',
            'data_nascita' => now()->subYears(25)->addMonths(2)->toDateString(),
        ]);
        Socio::factory()->sospeso()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Sospeso',
            'cognome' => 'Escluso',
            'data_nascita' => now()->subYears(40)->toDateString(),
        ]);

        $altra = Associazione::factory()->create();
        Socio::factory()->create([
            'associazione_id' => $altra->id,
            'nome' => 'Estraneo',
            'cognome' => 'AltroTenant',
            'data_nascita' => now()->subYears(35)->toDateString(),
        ]);

        $html = $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Compleanni di')
            ->assertDontSee('AltroTenant')
            ->getContent();

        // Solo il widget: la dashboard elenca altrove anche i soci appena creati
        $widget = \Illuminate\Support\Str::between((string) $html, '<!-- widget:compleanni -->', '<!-- /widget:compleanni -->');
        $this->assertStringContainsString('DelMese', $widget);
        $this->assertStringNotContainsString('MeseDiverso', $widget);
        $this->assertStringNotContainsString('Escluso', $widget);
    }

    // ── Toggle configurazioni ────────────────────────────────────────

    public function test_admin_attiva_gli_auguri_automatici_dalle_configurazioni(): void
    {
        $this->assertFalse($this->associazione->refresh()->auguri_automatici);

        $this->actingAs($this->admin)
            ->post(route('configurazioni.comunicazione'), ['auguri_automatici' => '1'])
            ->assertRedirect(route('configurazioni.index', ['section' => 'comunicazione']));

        $this->assertTrue($this->associazione->refresh()->auguri_automatici);

        // Sezione visibile nelle configurazioni con lo stato corrente
        $this->actingAs($this->admin)
            ->get(route('configurazioni.index', ['section' => 'comunicazione']))
            ->assertOk()
            ->assertSee('Auguri di compleanno automatici');
    }

    public function test_operatore_non_modifica_le_configurazioni_di_comunicazione(): void
    {
        $operatore = $this->creaOperatore();

        $this->actingAs($operatore)
            ->post(route('configurazioni.comunicazione'), ['auguri_automatici' => '1'])
            ->assertForbidden();

        $this->assertFalse($this->associazione->refresh()->auguri_automatici);
    }

    // ── Comando compleanni:auguri ────────────────────────────────────

    public function test_comando_invia_auguri_ai_soci_attivi_nel_giorno_del_compleanno(): void
    {
        Notification::fake();

        $this->associazione->forceFill(['auguri_automatici' => true])->save();

        $festeggiato = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'data_nascita' => now()->subYears(30)->toDateString(),
        ]);
        // Compleanno in un altro giorno
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'data_nascita' => now()->subYears(30)->subDays(10)->toDateString(),
        ]);
        // Opt-out: il comando non invia (in coerenza con le altre email massive)
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'data_nascita' => now()->subYears(30)->toDateString(),
            'email_opt_out' => true,
        ]);
        // Non attivo
        Socio::factory()->sospeso()->create([
            'associazione_id' => $this->associazione->id,
            'data_nascita' => now()->subYears(30)->toDateString(),
        ]);
        // Tenant con auguri DISATTIVATI (default): nessun invio
        $altra = Associazione::factory()->create();
        $estraneo = Socio::factory()->create([
            'associazione_id' => $altra->id,
            'data_nascita' => now()->subYears(30)->toDateString(),
        ]);

        $this->artisan('compleanni:auguri')->assertExitCode(0);

        Notification::assertSentTo($festeggiato, AuguriCompleannoNotification::class);
        Notification::assertNotSentTo($estraneo, AuguriCompleannoNotification::class);
        Notification::assertCount(1);

        $riga = PromemoriaInviato::allTenants()->firstOrFail();
        $this->assertSame($festeggiato->id, $riga->socio_id);
        $this->assertSame(CompleanniService::TIPO_COMPLEANNO, $riga->tipo);
        $this->assertSame((int) now()->format('Y'), $riga->step);
        $this->assertNull($riga->tessera_id);
    }

    public function test_comando_rilanciato_non_duplica_gli_auguri(): void
    {
        Notification::fake();

        $this->associazione->forceFill(['auguri_automatici' => true])->save();

        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'data_nascita' => now()->subYears(50)->toDateString(),
        ]);

        $this->artisan('compleanni:auguri')->assertExitCode(0);
        $this->artisan('compleanni:auguri')->assertExitCode(0);

        Notification::assertCount(1);
        $this->assertSame(1, PromemoriaInviato::allTenants()->count());
    }
}
