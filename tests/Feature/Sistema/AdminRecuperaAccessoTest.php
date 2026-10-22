<?php

namespace Tests\Feature\Sistema;

use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AdminRecuperaAccessoTest extends TestCase
{
    use RefreshDatabase;

    public function test_elenca_gli_amministratori(): void
    {
        Utente::factory()->create(['ruolo' => Utente::RUOLO_SUPER_ADMIN, 'associazione_id' => null, 'email' => 'super@test.it']);
        Utente::factory()->operatore()->create(['email' => 'operatore@test.it']);

        $this->artisan('admin:recupera-accesso')
            ->expectsOutputToContain('super@test.it')
            ->doesntExpectOutputToContain('operatore@test.it')
            ->assertSuccessful();
    }

    public function test_genera_un_link_funzionante(): void
    {
        $utente = Utente::factory()->create(['ruolo' => Utente::RUOLO_SUPER_ADMIN, 'associazione_id' => null, 'email' => 'super@test.it']);

        Artisan::call('admin:recupera-accesso', ['email' => 'SUPER@test.it']);
        $output = Artisan::output();
        $this->assertMatchesRegularExpression('#/reset-password/([A-Za-z0-9]+)\?email=super%40test.it#', $output);
        preg_match('#/reset-password/([A-Za-z0-9]+)#', $output, $m);

        // Il link permette davvero di impostare una nuova password
        $this->post('/reset-password', [
            'token' => $m[1],
            'email' => 'super@test.it',
            'password' => 'Lanterna-Velluto-Fiume-42',
            'password_confirmation' => 'Lanterna-Velluto-Fiume-42',
        ])->assertSessionHasNoErrors();
        $this->post('/login', ['email' => 'super@test.it', 'password' => 'Lanterna-Velluto-Fiume-42']);
        $this->assertAuthenticatedAs($utente);
    }

    public function test_email_sconosciuta(): void
    {
        $this->artisan('admin:recupera-accesso', ['email' => 'nessuno@test.it'])->assertFailed();
    }
}
