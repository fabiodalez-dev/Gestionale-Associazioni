<?php

namespace Tests\Feature\AreaSoci;

use App\Models\Quota;
use App\Models\Socio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuoteSocioTest extends TestCase
{
    use RefreshDatabase;

    public function test_elenco_quote_con_stati_calcolati_a_runtime(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create();

        // Pagata (la colonna stato del DB dice "Da Pagare": deve vincere il calcolo runtime)
        Quota::factory()->create([
            'socio_id' => $socio->id,
            'tipo' => 'Quota Ordinaria Pagata',
            'importo' => 25.50,
            'stato' => Quota::STATO_DA_PAGARE,
            'data_pagamento' => now()->subMonth()->toDateString(),
            'data_scadenza' => now()->subMonths(2)->toDateString(),
        ]);

        // Scaduta
        Quota::factory()->create([
            'socio_id' => $socio->id,
            'tipo' => 'Quota Ordinaria Scaduta',
            'data_pagamento' => null,
            'data_scadenza' => now()->subDays(5)->toDateString(),
        ]);

        // In scadenza (entro 30 giorni)
        Quota::factory()->create([
            'socio_id' => $socio->id,
            'tipo' => 'Quota In Scadenza Presto',
            'data_pagamento' => null,
            'data_scadenza' => now()->addDays(10)->toDateString(),
        ]);

        // Da pagare (oltre la finestra)
        Quota::factory()->create([
            'socio_id' => $socio->id,
            'tipo' => 'Quota Futura Lontana',
            'data_pagamento' => null,
            'data_scadenza' => now()->addDays(90)->toDateString(),
        ]);

        $response = $this->actingAs($socio, 'socio')->get(route('area-soci.quote'));

        $response->assertOk();
        $response->assertSee('Quota Ordinaria Pagata');
        $response->assertSee('25,50');
        $response->assertSeeInOrder(['Quota Ordinaria Pagata', 'Pagata'], false);
        $response->assertSee('Scaduta');
        $response->assertSee('In Scadenza');
        $response->assertSee('Da Pagare');
    }

    public function test_quota_con_payment_token_valido_mostra_link_pagamento(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create();
        $token = bin2hex(random_bytes(32));

        Quota::factory()->create([
            'socio_id' => $socio->id,
            'data_pagamento' => null,
            'data_scadenza' => now()->addDays(15)->toDateString(),
            'payment_token' => $token,
            'payment_token_expires' => now()->addDays(7),
        ]);

        $response = $this->actingAs($socio, 'socio')->get(route('area-soci.quote'));

        $response->assertOk();
        $response->assertSee('Paga ora');
        $response->assertSee($token);
    }

    public function test_quota_senza_payment_token_non_mostra_link_pagamento(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create();

        Quota::factory()->create([
            'socio_id' => $socio->id,
            'data_pagamento' => null,
            'payment_token' => null,
        ]);

        $response = $this->actingAs($socio, 'socio')->get(route('area-soci.quote'));

        $response->assertOk();
        // Nessun link di pagamento renderizzato (la frase informativa "Paga ora"
        // nella card placeholder è sempre presente: si verifica l'assenza dell'URL)
        $response->assertDontSee('pagamento?token=');
    }

    public function test_isolamento_il_socio_non_vede_le_quote_di_altri_soci(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create();

        // Altro socio della STESSA associazione
        $collega = Socio::factory()->create(['associazione_id' => $socio->associazione_id]);
        Quota::factory()->create([
            'socio_id' => $collega->id,
            'tipo' => 'Quota Del Collega Riservata',
        ]);

        // Socio di un ALTRO tenant
        $estraneo = Socio::factory()->create();
        Quota::factory()->create([
            'socio_id' => $estraneo->id,
            'tipo' => 'Quota Altro Tenant Segreta',
        ]);

        $response = $this->actingAs($socio, 'socio')->get(route('area-soci.quote'));

        $response->assertOk();
        $response->assertDontSee('Quota Del Collega Riservata');
        $response->assertDontSee('Quota Altro Tenant Segreta');
    }

    public function test_ospite_non_accede_alle_quote(): void
    {
        $this->get(route('area-soci.quote'))
            ->assertRedirect(route('area-soci.login'));
    }
}
