<?php

namespace Tests\Feature\Quote;

use App\Models\Associazione;
use App\Models\Quota;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\Utente;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pagina "Quote e pagamenti": KPI dell'anno e export CSV filtrato.
 */
class QuoteExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_kpi_ed_export_csv_filtrato(): void
    {
        $this->seed(RolesSeeder::class);
        $associazione = Associazione::factory()->create();
        $altra = Associazione::factory()->create();
        $admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $associazione->id]);
        $admin->assignRole(Utente::RUOLO_ADMIN_ASSOCIAZIONE);
        $anno = now()->year;

        $giulia = Socio::factory()->create(['associazione_id' => $associazione->id, 'nome' => 'Giulia', 'cognome' => 'Pagantini']);
        $luca = Socio::factory()->create(['associazione_id' => $associazione->id, 'nome' => 'Luca', 'cognome' => 'Ritardini']);
        $estraneo = Socio::factory()->create(['associazione_id' => $altra->id, 'cognome' => 'Estraneo']);
        Quota::create(['associazione_id' => $associazione->id, 'socio_id' => $giulia->id, 'anno' => $anno, 'importo' => 45, 'data_scadenza' => now()->addMonths(2)->toDateString(), 'data_pagamento' => now()->toDateString(), 'stato' => 'Pagata', 'metodo_pagamento' => 'contanti', 'tipo' => 'Quota Associativa']);
        Quota::create(['associazione_id' => $associazione->id, 'socio_id' => $luca->id, 'anno' => $anno, 'importo' => 25, 'data_scadenza' => now()->subDays(10)->toDateString(), 'stato' => 'Da Pagare', 'tipo' => 'Quota Associativa']);
        Quota::create(['associazione_id' => $altra->id, 'socio_id' => $estraneo->id, 'anno' => $anno, 'importo' => 99, 'data_scadenza' => now()->addMonth()->toDateString(), 'stato' => 'Da Pagare', 'tipo' => 'Quota Associativa']);

        $this->actingAs($admin)->withSession(['associazione_id' => $associazione->id]);

        $this->get(route('quote.index'))->assertOk()
            ->assertSee('Quote e pagamenti')
            ->assertSee('Incassato '.$anno)
            ->assertSee('€ 45')            // incassato
            ->assertSee('1 da sollecitare')
            ->assertSee('Metodi di incasso')
            ->assertDontSee('Estraneo');

        $csv = $this->get(route('quote.export', ['year' => $anno, 'status' => 'Scaduta']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Ritardini;Luca', $csv);
        $this->assertStringNotContainsString('Pagantini', $csv);
        $this->assertStringNotContainsString('Estraneo', $csv);

        $tutte = $this->get(route('quote.export', ['year' => $anno]))->streamedContent();
        $this->assertStringContainsString('Pagantini;Giulia', $tutte);
        $this->assertStringContainsString('45,00', $tutte);
        $this->assertStringContainsString('contanti', $tutte);
    }

    public function test_export_csv_delle_tessere(): void
    {
        $this->seed(RolesSeeder::class);
        $associazione = Associazione::factory()->create();
        $admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $associazione->id]);
        $admin->assignRole(Utente::RUOLO_ADMIN_ASSOCIAZIONE);
        $socio = Socio::factory()->create(['associazione_id' => $associazione->id, 'cognome' => 'Tesserini', 'nome' => 'Anna']);
        Tessera::create(['associazione_id' => $associazione->id, 'socio_id' => $socio->id, 'numero_tessera' => 'T-0042', 'anno_validita' => now()->year, 'data_emissione' => now()->toDateString(), 'data_scadenza' => now()->endOfYear()->toDateString(), 'stato' => 'Attiva', 'tipo_scadenza' => 'solare']);

        $csv = $this->actingAs($admin)->withSession(['associazione_id' => $associazione->id])
            ->get(route('tessere.export', ['anno' => now()->year]))->assertOk()->streamedContent();

        $this->assertStringContainsString('T-0042', $csv);
        $this->assertStringContainsString('Tesserini;Anna', $csv);
    }
}
