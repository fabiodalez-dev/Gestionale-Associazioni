<?php

namespace Tests\Feature\Sistema;

use App\Models\Associazione;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EnsureUtenteAttivo: il logout forzato di un utente disattivato non lascia
 * in sessione il tenant selezionato (audit run-2).
 */
class UtenteDisattivatoSessioneTest extends TestCase
{
    use RefreshDatabase;

    public function test_il_logout_forzato_dimentica_l_associazione_in_sessione(): void
    {
        $associazione = Associazione::factory()->create();
        $utente = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $associazione->id,
            'attivo' => false,
        ]);

        $this->actingAs($utente)
            ->withSession(['associazione_id' => $associazione->id, 'associazione_nome' => $associazione->nome])
            ->get('/dashboard')
            ->assertRedirect(route('login'))
            ->assertSessionMissing('associazione_id')
            ->assertSessionMissing('associazione_nome');

        $this->assertGuest('web');
    }
}
