<?php

namespace Tests\Feature\AreaSoci;

use App\Models\Evento;
use App\Models\EventoPartecipante;
use App\Models\Quota;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardSocioTest extends TestCase
{
    use RefreshDatabase;

    public function test_ospite_viene_rediretto_alla_login_del_portale(): void
    {
        $this->get(route('area-soci.dashboard'))
            ->assertRedirect(route('area-soci.login'));
    }

    public function test_dashboard_mostra_prossima_quota_e_tessera_con_qr(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create(['nome' => 'Mario']);

        Quota::factory()->create([
            'socio_id' => $socio->id,
            'importo' => 50,
            'data_scadenza' => now()->addDays(20)->toDateString(),
            'data_pagamento' => null,
        ]);

        Tessera::factory()->create([
            'socio_id' => $socio->id,
            'numero_tessera' => 'TES-0042',
            'stato' => Tessera::STATO_ATTIVA,
            'data_scadenza' => now()->addMonths(6)->toDateString(),
        ]);

        $response = $this->actingAs($socio, 'socio')->get(route('area-soci.dashboard'));

        $response->assertOk();
        $response->assertSee('Ciao, Mario!');
        $response->assertSee('50,00');
        $response->assertSee(now()->addDays(20)->format('d/m/Y'));
        $response->assertSee('TES-0042');
        // QR come data URI SVG
        $response->assertSee('data:image/svg+xml');
    }

    public function test_dashboard_senza_quote_in_scadenza_mostra_messaggio_in_regola(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create();

        // Come il legacy: una quota GIA' scaduta e non pagata NON appare come "prossima quota"
        Quota::factory()->create([
            'socio_id' => $socio->id,
            'data_scadenza' => now()->subDays(10)->toDateString(),
            'data_pagamento' => null,
        ]);

        $response = $this->actingAs($socio, 'socio')->get(route('area-soci.dashboard'));

        $response->assertOk();
        $response->assertSee('Sei in regola con i pagamenti!');
        $response->assertSee('Nessuna tessera attiva');
    }

    public function test_dashboard_mostra_i_prossimi_eventi_del_socio(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create();

        $evento = Evento::factory()->create([
            'associazione_id' => $socio->associazione_id,
            'titolo' => 'Assemblea Annuale Dei Soci',
            'data_evento' => now()->addDays(7),
        ]);

        EventoPartecipante::create([
            'associazione_id' => $socio->associazione_id,
            'evento_id' => $evento->id,
            'socio_id' => $socio->id,
            'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO,
        ]);

        $response = $this->actingAs($socio, 'socio')->get(route('area-soci.dashboard'));

        $response->assertOk();
        $response->assertSee('Assemblea Annuale Dei Soci');
    }

    public function test_socio_cancellato_subisce_logout_forzato(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create();
        $socio->delete(); // soft delete

        $response = $this->actingAs($socio, 'socio')->get(route('area-soci.dashboard'));

        $response->assertRedirect(route('area-soci.login'));
        $this->assertGuest('socio');
    }

    public function test_admin_autenticato_sulla_guard_web_non_accede_al_portale(): void
    {
        $utente = Utente::factory()->create();

        $this->actingAs($utente, 'web')
            ->get(route('area-soci.dashboard'))
            ->assertRedirect(route('area-soci.login'));
    }
}
