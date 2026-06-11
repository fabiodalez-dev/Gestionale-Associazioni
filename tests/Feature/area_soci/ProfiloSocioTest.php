<?php

namespace Tests\Feature\AreaSoci;

use App\Models\Socio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfiloSocioTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_pagina_profilo_mostra_i_dati_del_socio(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create([
            'nome' => 'Giulia',
            'cognome' => 'Bianchi',
            'numero_socio' => '0042',
        ]);

        $response = $this->actingAs($socio, 'socio')->get(route('area-soci.profilo'));

        $response->assertOk();
        $response->assertSee('Giulia');
        $response->assertSee('Bianchi');
        $response->assertSee('0042');
        $response->assertSee($socio->email);
    }

    public function test_il_socio_aggiorna_i_propri_recapiti(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create();

        $response = $this->actingAs($socio, 'socio')->patch(route('area-soci.profilo.update'), [
            'telefono' => '3331234567',
            'indirizzo' => 'Via Roma 1',
            'citta' => 'Milano',
            'cap' => '20121',
            'email_opt_out' => '1',
        ]);

        $response->assertRedirect(route('area-soci.profilo'));
        $response->assertSessionHas('success');

        $socio->refresh();
        $this->assertSame('3331234567', $socio->telefono);
        $this->assertSame('Via Roma 1', $socio->indirizzo);
        $this->assertSame('Milano', $socio->citta);
        $this->assertSame('20121', $socio->cap);
        $this->assertTrue($socio->email_opt_out);
    }

    public function test_i_campi_non_modificabili_vengono_ignorati(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create([
            'nome' => 'Originale',
            'email' => 'originale@example.com',
            'stato' => Socio::STATO_ATTIVO,
            'numero_socio' => '0001',
        ]);

        $this->actingAs($socio, 'socio')->patch(route('area-soci.profilo.update'), [
            'telefono' => '3331234567',
            // Tentativi di mass-assignment su campi read-only
            'nome' => 'Hacker',
            'email' => 'hacker@example.com',
            'stato' => Socio::STATO_SOSPESO,
            'numero_socio' => '9999',
            'associazione_id' => 'altro-tenant',
        ])->assertRedirect(route('area-soci.profilo'));

        $socio->refresh();
        $this->assertSame('Originale', $socio->nome);
        $this->assertSame('originale@example.com', $socio->email);
        $this->assertSame(Socio::STATO_ATTIVO, $socio->stato);
        $this->assertSame('0001', $socio->numero_socio);
        $this->assertSame('3331234567', $socio->telefono);
    }

    public function test_cap_non_valido_viene_rifiutato(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create(['cap' => '20121']);

        $response = $this->actingAs($socio, 'socio')->patch(route('area-soci.profilo.update'), [
            'cap' => 'ABCDE',
        ]);

        $response->assertSessionHasErrors('cap');
        $this->assertSame('20121', $socio->fresh()->cap);
    }

    public function test_telefono_troppo_lungo_viene_rifiutato(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create();

        $response = $this->actingAs($socio, 'socio')->patch(route('area-soci.profilo.update'), [
            'telefono' => str_repeat('3', 25),
        ]);

        $response->assertSessionHasErrors('telefono');
    }

    public function test_ospite_non_accede_al_profilo(): void
    {
        $this->get(route('area-soci.profilo'))
            ->assertRedirect(route('area-soci.login'));
    }
}
