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

    /** Replica inviaInvito(): nonce nel link, hash sull'utente. */
    private function linkInvito(Utente $utente, string $nonce = 'nonce-di-prova-1234567890'): string
    {
        $utente->forceFill([
            'invito_token' => hash('sha256', $nonce),
            'invito_scade_at' => now()->addDays(7),
        ])->save();

        return URL::temporarySignedRoute('invito.show', now()->addDays(7), ['utente' => $utente->id, 'nonce' => $nonce]);
    }

    public function test_accettazione_invito_imposta_la_password(): void
    {
        $utente = Utente::factory()->adminAssociazione()->create();

        $url = $this->linkInvito($utente);

        $this->get($url)->assertOk()->assertSee($utente->email);

        $this->post($url, [
            'password' => 'nuova-password-123',
            'password_confirmation' => 'nuova-password-123',
        ])->assertRedirect(route('login'));

        $this->assertTrue(
            auth()->guard('web')->attempt(['email' => $utente->email, 'password' => 'nuova-password-123'])
        );
        $this->assertNull($utente->fresh()->invito_token);
    }

    public function test_invito_e_monouso_e_non_riattiva_un_utente_disattivato(): void
    {
        $utente = Utente::factory()->adminAssociazione()->create();
        $url = $this->linkInvito($utente);

        $this->post($url, ['password' => 'prima-password-123', 'password_confirmation' => 'prima-password-123'])
            ->assertRedirect(route('login'));

        // Stesso link, seconda volta: consumato
        $this->get($url)->assertForbidden();
        $this->post($url, ['password' => 'seconda-password-123', 'password_confirmation' => 'seconda-password-123'])
            ->assertForbidden();
        $this->assertFalse(
            auth()->guard('web')->attempt(['email' => $utente->email, 'password' => 'seconda-password-123'])
        );

        // Nuovo invito, ma il super admin disattiva l'utente: il link non riapre l'account
        $url = $this->linkInvito($utente, 'secondo-nonce-0987654321');
        $utente->forceFill(['attivo' => false])->save();

        $this->post($url, ['password' => 'terza-password-123', 'password_confirmation' => 'terza-password-123'])
            ->assertForbidden();
        $this->assertFalse($utente->fresh()->attivo);
    }

    public function test_link_firmato_senza_nonce_o_con_nonce_vecchio_e_rifiutato(): void
    {
        $utente = Utente::factory()->adminAssociazione()->create();

        // Firma valida ma nessun nonce (vecchio formato del link) su un utente
        // che ha già un invito registrato: rifiutato
        $this->linkInvito($utente, 'nonce-registrato-xxxxxxxxxxxx');
        $senzaNonce = URL::temporarySignedRoute('invito.show', now()->addDays(7), ['utente' => $utente->id]);
        $this->get($senzaNonce)->assertForbidden();

        // Un reinvio sostituisce il nonce: il link precedente non vale più
        $primo = $this->linkInvito($utente, 'primo-nonce-aaaaaaaaaaaaaaaa');
        $this->linkInvito($utente, 'secondo-nonce-bbbbbbbbbbbbbbb');
        $this->get($primo)->assertForbidden();
    }

    public function test_link_senza_nonce_inviato_prima_dell_aggiornamento_vale_finche_l_utente_non_accede(): void
    {
        // Compatibilità: un invito spedito dalla versione precedente (nessun
        // invito registrato, utente mai entrato) deve poter essere accettato
        $utente = Utente::factory()->adminAssociazione()->create(['last_login' => null]);
        $vecchioLink = URL::temporarySignedRoute('invito.show', now()->addDays(7), ['utente' => $utente->id]);

        $this->get($vecchioLink)->assertOk();
        $this->post($vecchioLink, ['password' => 'Password-Di-Transizione-1', 'password_confirmation' => 'Password-Di-Transizione-1'])
            ->assertRedirect(route('login'));
        $this->assertTrue(auth()->guard('web')->attempt(['email' => $utente->email, 'password' => 'Password-Di-Transizione-1']));

        // Dopo il primo accesso la via di transizione si chiude
        $utente->forceFill(['last_login' => now()])->save();
        $this->get($vecchioLink)->assertForbidden();

        // E non riattiva mai un utente disattivato
        $altro = Utente::factory()->adminAssociazione()->create(['last_login' => null, 'attivo' => false]);
        $this->get(URL::temporarySignedRoute('invito.show', now()->addDays(7), ['utente' => $altro->id]))->assertForbidden();
    }

    public function test_invito_dal_pannello_salva_il_nonce_e_lo_mette_nel_link(): void
    {
        Mail::fake();
        $superAdmin = Utente::factory()->superAdmin()->create();
        $associazione = Associazione::factory()->create();

        $this->actingAs($superAdmin)->post('/amministratori', [
            'nome' => 'Invitato', 'cognome' => 'Nonce', 'email' => 'nonce@example.com',
            'ruolo' => Utente::RUOLO_ADMIN_ASSOCIAZIONE, 'associazione_id' => $associazione->id, 'invia_invito' => '1',
        ])->assertRedirect(route('amministratori.index'));

        $utente = Utente::query()->where('email', 'nonce@example.com')->firstOrFail();
        $this->assertNotNull($utente->invito_token);
        $this->assertTrue($utente->invito_scade_at->isFuture());

        Mail::assertQueued(InvitoAmministratoreMail::class, function (InvitoAmministratoreMail $mail) use ($utente) {
            parse_str((string) parse_url($mail->urlInvito, PHP_URL_QUERY), $q);

            return isset($q['nonce']) && hash_equals($utente->invito_token, hash('sha256', $q['nonce']));
        });
    }

    public function test_disattivazione_chiude_la_sessione_aperta_e_ruota_il_remember_token(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();
        $associazione = Associazione::factory()->create();
        $utente = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $associazione->id,
            'remember_token' => 'token-ricordami-originale',
        ]);

        $this->actingAs($superAdmin)->put('/amministratori/'.$utente->id, [
            'nome' => $utente->nome, 'cognome' => $utente->cognome, 'email' => $utente->email,
            'ruolo' => Utente::RUOLO_ADMIN_ASSOCIAZIONE, 'associazione_id' => $associazione->id,
            'attivo' => '0', 'password' => '',
        ])->assertRedirect(route('amministratori.index'));

        $utente->refresh();
        $this->assertFalse($utente->attivo);
        $this->assertNotSame('token-ricordami-originale', $utente->remember_token);

        // Sessione già aperta dell'utente disattivato: logout forzato alla prima richiesta
        auth()->guard('web')->logout();
        $this->actingAs($utente)->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_password_imposta_dal_super_admin_ruota_il_remember_token(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();
        $associazione = Associazione::factory()->create();
        $utente = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $associazione->id,
            'remember_token' => 'token-ricordami-originale',
            'invito_token' => hash('sha256', 'nonce-pendente'),
            'invito_scade_at' => now()->addDays(7),
        ]);

        $this->actingAs($superAdmin)->put('/amministratori/'.$utente->id, [
            'nome' => $utente->nome, 'cognome' => $utente->cognome, 'email' => $utente->email,
            'ruolo' => Utente::RUOLO_ADMIN_ASSOCIAZIONE, 'associazione_id' => $associazione->id,
            'password' => 'imposta-dal-super-123',
        ])->assertRedirect(route('amministratori.index'));

        $utente->refresh();
        $this->assertNotSame('token-ricordami-originale', $utente->remember_token);
        $this->assertNull($utente->invito_token, 'un invito pendente non deve poter sovrascrivere la password imposta');
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
