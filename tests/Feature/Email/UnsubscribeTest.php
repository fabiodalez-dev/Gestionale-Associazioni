<?php

namespace Tests\Feature\Email;

use App\Models\Socio;

class UnsubscribeTest extends EmailTestCase
{
    private function socioConToken(): Socio
    {
        return Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'stato' => 'Attivo',
            'email' => 'socio@example.com',
            'email_opt_out' => false,
            'email_opt_out_token' => str_repeat('a', 64),
        ]);
    }

    public function test_link_senza_match_restituisce_404(): void
    {
        $socio = $this->socioConToken();

        $this->get(route('email.unsubscribe.show', ['socio' => $socio->id, 'token' => str_repeat('b', 64)]))
            ->assertNotFound()
            ->assertSee('Link non valido');
    }

    public function test_get_mostra_la_pagina_di_conferma_senza_disiscrivere(): void
    {
        $socio = $this->socioConToken();

        $this->get(route('email.unsubscribe.show', ['socio' => $socio->id, 'token' => $socio->email_opt_out_token]))
            ->assertOk()
            ->assertSee('Conferma disiscrizione')
            ->assertSee('socio@example.com');

        // Double opt-out: il GET (prefetch dei link) non deve disiscrivere
        $this->assertFalse($socio->fresh()->email_opt_out);
    }

    public function test_post_con_conferma_disiscrive_il_socio(): void
    {
        $socio = $this->socioConToken();

        $this->post(route('email.unsubscribe.confirm', ['socio' => $socio->id, 'token' => $socio->email_opt_out_token]), [
            'confirm_unsubscribe' => 1,
        ])
            ->assertOk()
            ->assertSee('Disiscrizione avvenuta');

        $this->assertTrue($socio->fresh()->email_opt_out);
    }

    public function test_post_senza_conferma_non_disiscrive(): void
    {
        $socio = $this->socioConToken();

        $this->post(route('email.unsubscribe.confirm', ['socio' => $socio->id, 'token' => $socio->email_opt_out_token]))
            ->assertOk()
            ->assertSee('Conferma disiscrizione');

        $this->assertFalse($socio->fresh()->email_opt_out);
    }

    public function test_disiscrizione_idempotente(): void
    {
        $socio = $this->socioConToken();
        $socio->forceFill(['email_opt_out' => true])->save();

        $this->get(route('email.unsubscribe.show', ['socio' => $socio->id, 'token' => $socio->email_opt_out_token]))
            ->assertOk()
            ->assertSee('Già disiscritto');
    }
}
