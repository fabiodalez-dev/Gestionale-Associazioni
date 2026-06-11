<?php

namespace Tests\Feature\AreaSoci;

use App\Models\Socio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailOptOutTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagina_di_conferma_opt_out_con_token_valido(): void
    {
        $token = bin2hex(random_bytes(32));
        $socio = Socio::factory()->create([
            'email_opt_out' => false,
            'email_opt_out_token' => $token,
        ]);

        $response = $this->get(route('area-soci.optout', ['token' => $token]));

        $response->assertOk();
        $response->assertSee('Annulla iscrizione');
        $response->assertSee($socio->email);
        // Il GET non cambia lo stato (niente side-effect su richieste GET)
        $this->assertFalse($socio->fresh()->email_opt_out);
    }

    public function test_il_post_applica_l_opt_out(): void
    {
        $token = bin2hex(random_bytes(32));
        $socio = Socio::factory()->create([
            'email_opt_out' => false,
            'email_opt_out_token' => $token,
        ]);

        $response = $this->post(route('area-soci.optout.store', ['token' => $token]));

        $response->assertOk();
        $response->assertSee('Disiscrizione confermata');
        $this->assertTrue($socio->fresh()->email_opt_out);
    }

    public function test_token_inesistente_restituisce_404(): void
    {
        $this->get(route('area-soci.optout', ['token' => str_repeat('0', 64)]))
            ->assertNotFound();

        $this->post(route('area-soci.optout.store', ['token' => str_repeat('0', 64)]))
            ->assertNotFound();
    }

    public function test_token_malformato_restituisce_404(): void
    {
        // Vincolo di rotta: solo 64 hex
        $this->get('/area-soci/opt-out/abc')->assertNotFound();
    }

    public function test_il_socio_puo_riattivare_le_email_dal_profilo(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create(['email_opt_out' => true]);

        $this->actingAs($socio, 'socio')->patch(route('area-soci.profilo.update'), [
            'email_opt_out' => '0',
        ])->assertRedirect(route('area-soci.profilo'));

        $this->assertFalse($socio->fresh()->email_opt_out);
    }
}
