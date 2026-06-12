<?php

namespace Tests\Feature\EventiVita;

use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Utente;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * f.8 — Eventi ricorrenti: creazione della serie (settimanale/mensile,
 * fino a data, max 52 occorrenze con serie_id condiviso), gestione della
 * singola occorrenza e dell'intera serie.
 */
class EventiRicorrentiTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function payload(array $override = []): array
    {
        return array_merge([
            'titolo' => 'Corso di ballo',
            'data_evento' => now()->addDay()->setTime(21, 0)->format('Y-m-d\TH:i'),
            'data_fine' => now()->addDay()->setTime(23, 0)->format('Y-m-d\TH:i'),
            'luogo' => 'Sala grande',
            'frequenza' => 'settimanale',
            'fino_al' => now()->addDay()->addWeeks(3)->format('Y-m-d'),
            'stato_pubblicazione' => Evento::STATO_PUBBLICATO,
            'prezzo' => '0',
            'solo_soci' => '0',
        ], $override);
    }

    public function test_form_di_creazione_visibile_all_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/eventi-ricorrenti/nuovo')
            ->assertOk()
            ->assertViewIs('eventi-vita.ricorrente-create');
    }

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get('/eventi-ricorrenti/nuovo')->assertRedirect('/login');
    }

    public function test_serie_settimanale_genera_le_occorrenze_con_serie_id_condiviso(): void
    {
        $this->actingAs($this->admin)
            ->post('/eventi-ricorrenti', $this->payload())
            ->assertRedirect('/eventi')
            ->assertSessionHas('success');

        $occorrenze = Evento::query()->orderBy('data_evento')->get();

        // 4 occorrenze: giorno 0, +1, +2, +3 settimane
        $this->assertCount(4, $occorrenze);
        $this->assertCount(1, $occorrenze->pluck('serie_id')->unique());
        $this->assertNotNull($occorrenze->first()->serie_id);

        // Cadenza settimanale e durata preservata (2 ore)
        $prima = $occorrenze[0];
        $seconda = $occorrenze[1];
        $this->assertSame(7, (int) $prima->data_evento->diffInDays($seconda->data_evento));
        $this->assertSame(120, (int) $prima->data_evento->diffInMinutes($prima->data_fine));
        $this->assertSame($this->associazione->id, $prima->associazione_id);
    }

    public function test_serie_mensile_genera_le_occorrenze_mensili(): void
    {
        $this->actingAs($this->admin)->post('/eventi-ricorrenti', $this->payload([
            'frequenza' => 'mensile',
            'fino_al' => now()->addDay()->addMonths(2)->format('Y-m-d'),
        ]));

        $occorrenze = Evento::query()->orderBy('data_evento')->get();

        $this->assertCount(3, $occorrenze);
        $this->assertSame(
            $occorrenze[0]->data_evento->copy()->addMonthNoOverflow()->format('Y-m-d H:i'),
            $occorrenze[1]->data_evento->format('Y-m-d H:i')
        );
    }

    public function test_la_serie_non_supera_le_52_occorrenze(): void
    {
        $this->actingAs($this->admin)->post('/eventi-ricorrenti', $this->payload([
            'fino_al' => now()->addYears(3)->format('Y-m-d'), // ~156 settimane
        ]));

        $this->assertSame(52, Evento::query()->count());
    }

    public function test_fino_al_precedente_alla_data_evento_fallisce(): void
    {
        $this->actingAs($this->admin)
            ->post('/eventi-ricorrenti', $this->payload([
                'fino_al' => now()->subWeek()->format('Y-m-d'),
            ]))
            ->assertSessionHasErrors('fino_al');

        $this->assertSame(0, Evento::query()->count());
    }

    public function test_frequenza_non_valida_fallisce(): void
    {
        $this->actingAs($this->admin)
            ->post('/eventi-ricorrenti', $this->payload(['frequenza' => 'giornaliera']))
            ->assertSessionHasErrors('frequenza');
    }

    // ------------------------------------------------------------------
    // Gestione serie
    // ------------------------------------------------------------------

    /**
     * @return Collection<int, Evento>
     */
    private function creaSerie(int $n = 3): Collection
    {
        $this->actingAs($this->admin)->post('/eventi-ricorrenti', $this->payload([
            'fino_al' => now()->addDay()->addWeeks($n - 1)->format('Y-m-d'),
        ]));

        return Evento::query()->orderBy('data_evento')->get();
    }

    public function test_pagina_serie_elenca_le_occorrenze(): void
    {
        $occorrenze = $this->creaSerie(3);

        $response = $this->actingAs($this->admin)->get("/eventi/{$occorrenze->first()->id}/serie");

        $response->assertOk();
        $response->assertViewIs('eventi-vita.serie');
        $this->assertCount(3, $response->viewData('occorrenze'));
    }

    public function test_pagina_serie_di_un_evento_non_ricorrente_da_404(): void
    {
        $singolo = Evento::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)->get("/eventi/{$singolo->id}/serie")->assertNotFound();
    }

    public function test_update_serie_propaga_i_campi_a_tutte_le_occorrenze(): void
    {
        $occorrenze = $this->creaSerie(3);
        $dateOriginali = $occorrenze->pluck('data_evento')->map->format('Y-m-d H:i');

        $this->actingAs($this->admin)
            ->put("/eventi/{$occorrenze->first()->id}/serie", [
                'titolo' => 'Corso di ballo AVANZATO',
                'luogo' => 'Sala piccola',
                'descrizione' => null,
                'stato_pubblicazione' => Evento::STATO_PUBBLICATO,
                'capienza' => 20,
                'prezzo' => '5,50',
                'iscrizioni_aperte' => '1',
                'richiede_approvazione' => '0',
                'solo_soci' => '1',
            ])
            ->assertRedirect("/eventi/{$occorrenze->first()->id}/serie")
            ->assertSessionHas('success');

        $aggiornate = Evento::query()->orderBy('data_evento')->get();

        $this->assertCount(3, $aggiornate);
        foreach ($aggiornate as $i => $occorrenza) {
            $this->assertSame('Corso di ballo AVANZATO', $occorrenza->titolo);
            $this->assertSame('Sala piccola', $occorrenza->luogo);
            $this->assertSame(20, $occorrenza->capienza);
            $this->assertSame('5.50', (string) $occorrenza->prezzo);
            $this->assertTrue($occorrenza->solo_soci);
            // Le date NON vengono toccate
            $this->assertSame($dateOriginali[$i], $occorrenza->data_evento->format('Y-m-d H:i'));
        }
    }

    public function test_modifica_della_singola_occorrenza_non_tocca_la_serie(): void
    {
        $occorrenze = $this->creaSerie(3);
        $seconda = $occorrenze[1];

        $this->actingAs($this->admin)
            ->put("/eventi/{$seconda->id}", [
                'titolo' => 'Occorrenza speciale',
                'data_evento' => $seconda->data_evento->format('Y-m-d\TH:i'),
            ])
            ->assertRedirect('/eventi');

        $this->assertSame('Occorrenza speciale', $seconda->fresh()->titolo);
        $this->assertSame('Corso di ballo', $occorrenze[0]->fresh()->titolo);
        $this->assertSame('Corso di ballo', $occorrenze[2]->fresh()->titolo);
        // Resta nella serie
        $this->assertSame($occorrenze[0]->serie_id, $seconda->fresh()->serie_id);
    }

    public function test_destroy_serie_elimina_tutte_le_occorrenze(): void
    {
        $occorrenze = $this->creaSerie(3);

        $this->actingAs($this->admin)
            ->delete("/eventi/{$occorrenze->first()->id}/serie")
            ->assertRedirect('/eventi')
            ->assertSessionHas('success');

        $this->assertSame(0, Evento::query()->count());
        $this->assertSame(3, Evento::withTrashed()->count()); // soft delete
    }

    public function test_eliminazione_della_singola_occorrenza_lascia_le_altre(): void
    {
        $occorrenze = $this->creaSerie(3);

        $this->actingAs($this->admin)
            ->delete("/eventi/{$occorrenze[1]->id}")
            ->assertRedirect('/eventi');

        $this->assertSame(2, Evento::query()->count());
    }

    public function test_serie_di_un_altro_tenant_non_gestibile(): void
    {
        $occorrenze = $this->creaSerie(2);

        $altra = Associazione::factory()->create();
        $estraneo = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $altra->id,
        ]);

        $this->actingAs($estraneo)->get("/eventi/{$occorrenze->first()->id}/serie")->assertNotFound();
        $this->actingAs($estraneo)->delete("/eventi/{$occorrenze->first()->id}/serie")->assertNotFound();

        $this->assertSame(2, Evento::allTenants()->whereNotNull('serie_id')->count());
    }
}
