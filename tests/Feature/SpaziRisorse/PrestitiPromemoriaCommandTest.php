<?php

namespace Tests\Feature\SpaziRisorse;

use App\Models\Associazione;
use App\Models\Attrezzatura;
use App\Models\PrestitoAttrezzatura;
use App\Models\Socio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrestitiPromemoriaCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_il_comando_conta_solo_i_prestiti_in_ritardo(): void
    {
        $associazione = Associazione::factory()->create();
        $attrezzatura = Attrezzatura::factory()->create(['associazione_id' => $associazione->id, 'quantita' => 10]);
        $socio = Socio::factory()->create(['associazione_id' => $associazione->id]);

        // In ritardo (restituzione prevista passata, non restituito)
        PrestitoAttrezzatura::factory()->inRitardo()->create([
            'associazione_id' => $associazione->id,
            'attrezzatura_id' => $attrezzatura->id,
            'socio_id' => $socio->id,
        ]);
        // In corso ma non scaduto → escluso
        PrestitoAttrezzatura::factory()->create([
            'associazione_id' => $associazione->id,
            'attrezzatura_id' => $attrezzatura->id,
            'socio_id' => $socio->id,
        ]);
        // Restituito → escluso anche se la data era passata
        PrestitoAttrezzatura::factory()->inRitardo()->restituito()->create([
            'associazione_id' => $associazione->id,
            'attrezzatura_id' => $attrezzatura->id,
            'socio_id' => $socio->id,
        ]);

        $this->artisan('prestiti:promemoria')
            ->expectsOutputToContain('Prestiti in ritardo: 1')
            ->assertSuccessful();
    }

    public function test_il_comando_attraversa_tutti_i_tenant(): void
    {
        foreach (range(1, 2) as $i) {
            $associazione = Associazione::factory()->create();
            $attrezzatura = Attrezzatura::factory()->create(['associazione_id' => $associazione->id, 'quantita' => 5]);
            $socio = Socio::factory()->create(['associazione_id' => $associazione->id]);
            PrestitoAttrezzatura::factory()->inRitardo()->create([
                'associazione_id' => $associazione->id,
                'attrezzatura_id' => $attrezzatura->id,
                'socio_id' => $socio->id,
            ]);
        }

        $this->artisan('prestiti:promemoria')
            ->expectsOutputToContain('Prestiti in ritardo: 2')
            ->assertSuccessful();
    }

    public function test_nessun_prestito_in_ritardo(): void
    {
        $this->artisan('prestiti:promemoria')
            ->expectsOutputToContain('Prestiti in ritardo: 0')
            ->assertSuccessful();
    }
}
