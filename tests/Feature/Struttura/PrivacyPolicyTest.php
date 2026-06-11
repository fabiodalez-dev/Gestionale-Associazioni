<?php

namespace Tests\Feature\Struttura;

use App\Models\Associazione;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivacyPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagina_pubblica_mostra_la_policy(): void
    {
        $associazione = Associazione::factory()->create([
            'privacy_policy_html' => '<h2>Informativa GDPR</h2><p>Trattiamo i tuoi dati con cura.</p>',
        ]);

        $this->get('/privacy-policy/'.$associazione->id)
            ->assertOk()
            ->assertSee($associazione->nome)
            ->assertSee('Informativa GDPR')
            ->assertSee('Trattiamo i tuoi dati con cura.');
    }

    public function test_policy_vuota_mostra_avviso(): void
    {
        $associazione = Associazione::factory()->create(['privacy_policy_html' => null]);

        $this->get('/privacy-policy/'.$associazione->id)
            ->assertOk()
            ->assertSee('Privacy policy non ancora disponibile');
    }

    public function test_validazione_accesso(): void
    {
        $this->get('/privacy-policy/uuid-non-valido')->assertStatus(400);

        $this->get('/privacy-policy/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee')->assertNotFound();
    }
}
