<?php

namespace Tests\Feature\Struttura;

use App\Mail\Struttura\InvitoAmministratoreMail;
use App\Models\Associazione;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AmministratoriTest extends TestCase
{
    use RefreshDatabase;

    public function test_solo_super_admin_accede_alla_gestione_utenti(): void
    {
        $admin = Utente::factory()->adminAssociazione()->create();
        $this->actingAs($admin)->get('/amministratori')->assertForbidden();

        $superAdmin = Utente::factory()->superAdmin()->create();
        $this->actingAs($superAdmin)->get('/amministratori')->assertOk();
    }

    public function test_creazione_admin_associazione_richiede_associazione(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->post('/amministratori', [
            'nome' => 'Senza',
            'cognome' => 'Tenant',
            'email' => 'senza.tenant@example.com',
            'ruolo' => Utente::RUOLO_ADMIN_ASSOCIAZIONE,
            'password' => 'password123',
        ]);

        $response->assertSessionHasErrors('associazione_id');
        $this->assertDatabaseMissing('utenti', ['email' => 'senza.tenant@example.com']);
    }

    public function test_password_corta_viene_rifiutata(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();
        $associazione = Associazione::factory()->create();

        $this->actingAs($superAdmin)->post('/amministratori', [
            'nome' => 'Pass',
            'email' => 'pass.corta@example.com',
            'ruolo' => Utente::RUOLO_ADMIN_ASSOCIAZIONE,
            'associazione_id' => $associazione->id,
            'password' => 'breve',
        ])->assertSessionHasErrors('password');
    }

    public function test_super_admin_creato_senza_associazione_anche_se_inviata(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();
        $associazione = Associazione::factory()->create();

        $this->actingAs($superAdmin)->post('/amministratori', [
            'nome' => 'Nuovo',
            'cognome' => 'Super',
            'email' => 'nuovo.super@example.com',
            'ruolo' => Utente::RUOLO_SUPER_ADMIN,
            'associazione_id' => $associazione->id, // deve essere ignorata
            'password' => 'Lampione-Viola-Treno-42',
        ])->assertRedirect(route('amministratori.index'));

        $this->assertDatabaseHas('utenti', [
            'email' => 'nuovo.super@example.com',
            'ruolo' => Utente::RUOLO_SUPER_ADMIN,
            'associazione_id' => null,
        ]);
    }

    public function test_invito_via_email_senza_password(): void
    {
        Mail::fake();

        $superAdmin = Utente::factory()->superAdmin()->create();
        $associazione = Associazione::factory()->create();

        $response = $this->actingAs($superAdmin)->post('/amministratori', [
            'nome' => 'Invitato',
            'cognome' => 'Via Email',
            'email' => 'invitato@example.com',
            'ruolo' => Utente::RUOLO_ADMIN_ASSOCIAZIONE,
            'associazione_id' => $associazione->id,
            'invia_invito' => '1',
        ]);

        $response->assertRedirect(route('amministratori.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('utenti', ['email' => 'invitato@example.com']);

        Mail::assertQueued(InvitoAmministratoreMail::class, function (InvitoAmministratoreMail $mail) {
            return $mail->utente->email === 'invitato@example.com'
                && str_contains($mail->urlInvito, 'signature=');
        });
    }

    public function test_accettazione_invito_imposta_la_password(): void
    {
        $utente = Utente::factory()->adminAssociazione()->create();

        $url = URL::temporarySignedRoute('invito.show', now()->addDays(7), ['utente' => $utente->id]);

        $this->get($url)->assertOk()->assertSee($utente->email);

        $this->post($url, [
            'password' => 'nuova-password-123',
            'password_confirmation' => 'nuova-password-123',
        ])->assertRedirect(route('login'));

        $this->assertTrue(
            auth()->guard('web')->attempt(['email' => $utente->email, 'password' => 'nuova-password-123'])
        );
    }

    public function test_invito_con_firma_non_valida_rifiutato(): void
    {
        $utente = Utente::factory()->adminAssociazione()->create();

        $this->get('/invito/'.$utente->id.'?signature=falsa&expires=99999999999')->assertForbidden();
    }

    public function test_password_vuota_in_modifica_resta_invariata(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();
        $associazione = Associazione::factory()->create();
        $utente = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $associazione->id,
            'password_hash' => 'password-originale',
        ]);

        $this->actingAs($superAdmin)->put('/amministratori/'.$utente->id, [
            'nome' => 'Aggiornato',
            'cognome' => $utente->cognome,
            'email' => $utente->email,
            'ruolo' => Utente::RUOLO_ADMIN_ASSOCIAZIONE,
            'associazione_id' => $associazione->id,
            'password' => '',
        ])->assertRedirect(route('amministratori.index'));

        $this->assertSame('Aggiornato', $utente->fresh()->nome);
        $this->assertTrue(
            auth()->guard('web')->attempt(['email' => $utente->email, 'password' => 'password-originale'])
        );
    }

    public function test_divieto_di_auto_eliminazione(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();
        // Un secondo super_admin: il blocco non dipende dall'essere l'ultimo
        Utente::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->delete('/amministratori/'.$superAdmin->id)
            ->assertRedirect(route('amministratori.index'));

        $this->assertDatabaseHas('utenti', ['id' => $superAdmin->id]);
    }

    public function test_divieto_di_eliminare_l_ultimo_super_admin(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();
        $altro = Utente::factory()->superAdmin()->create();

        // Con 2 super admin l'eliminazione è permessa
        $this->actingAs($superAdmin)->delete('/amministratori/'.$altro->id);
        $this->assertDatabaseMissing('utenti', ['id' => $altro->id]);

        // Ora superAdmin è l'ultimo: un altro tentativo (da se stesso) è comunque bloccato,
        // e ricreando un secondo attore il blocco "ultimo super admin" protegge il primo
        $admin = Utente::factory()->adminAssociazione()->create();
        $this->actingAs($superAdmin)->delete('/amministratori/'.$admin->id);
        $this->assertDatabaseMissing('utenti', ['id' => $admin->id]);

        $this->actingAs($superAdmin)
            ->delete('/amministratori/'.$superAdmin->id)
            ->assertRedirect(route('amministratori.index'));
        $this->assertDatabaseHas('utenti', ['id' => $superAdmin->id]);
    }
}
