<?php

namespace Tests\Feature\Prenotazioni;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Prenotazione;
use App\Models\Utente;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Check-in dei biglietti dallo scanner admin: POST /biglietti/checkin
 * (auth + tenant, JSON). Conferma con checkin_at + checkin_utente_id,
 * doppio check-in = warning con orario, stati non validi = errore con
 * motivo, isolamento tenant.
 */
class CheckinBigliettoTest extends TestCase
{
    use RefreshDatabase;

    private function creaAdmin(Associazione $associazione): Utente
    {
        $this->seed(RolesSeeder::class);

        /** @var Utente $admin */
        $admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $associazione->id,
        ]);
        $admin->assignRole('admin_associazione');

        return $admin;
    }

    private function creaPrenotazione(Associazione $associazione, array $attributi = []): Prenotazione
    {
        $evento = Evento::factory()->create(['associazione_id' => $associazione->id]);
        $account = Account::factory()->create(['associazione_id' => $associazione->id]);

        return Prenotazione::factory()->create(array_merge([
            'evento_id' => $evento->id,
            'associazione_id' => $associazione->id,
            'account_id' => $account->id,
            'posti' => 3,
        ], $attributi));
    }

    public function test_checkin_richiede_login(): void
    {
        $this->post('/biglietti/checkin', [])->assertRedirect('/login');
    }

    public function test_checkin_felice_registra_checkin_at_e_operatore(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);
        $prenotazione = $this->creaPrenotazione($assoc);

        $response = $this->actingAs($admin)->postJson('/biglietti/checkin', [
            'qr_token' => $prenotazione->qr_token,
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'already_checked_in' => false,
            'biglietto' => [
                'codice' => $prenotazione->codice,
                'posti' => 3,
            ],
        ]);

        $prenotazione->refresh();
        $this->assertNotNull($prenotazione->checkin_at);
        $this->assertSame($admin->id, $prenotazione->checkin_utente_id);
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $prenotazione->stato);
    }

    public function test_doppio_checkin_warning_con_orario_senza_sovrascrivere(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);
        $prenotazione = $this->creaPrenotazione($assoc);

        $payload = ['qr_token' => $prenotazione->qr_token];

        $this->actingAs($admin)->postJson('/biglietti/checkin', $payload)->assertOk();
        $primoCheckin = $prenotazione->refresh()->checkin_at;

        $response = $this->actingAs($admin)->postJson('/biglietti/checkin', $payload);

        $response->assertOk();
        $response->assertJson(['success' => true, 'already_checked_in' => true]);
        $this->assertStringContainsString(
            'già utilizzato alle '.$primoCheckin->format('H:i'),
            (string) $response->json('message')
        );

        // Il primo check-in non viene sovrascritto
        $this->assertTrue($primoCheckin->equalTo($prenotazione->refresh()->checkin_at));
    }

    public function test_checkin_manuale_tramite_codice_prenotazione(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);
        $prenotazione = $this->creaPrenotazione($assoc);

        $response = $this->actingAs($admin)->postJson('/biglietti/checkin', [
            'codice' => strtolower($prenotazione->codice),
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true, 'already_checked_in' => false]);
        $this->assertNotNull($prenotazione->refresh()->checkin_at);
    }

    public function test_stati_non_validi_rifiutati_con_motivo(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);

        $statiInvalidi = [
            Prenotazione::STATO_IN_ATTESA_PAGAMENTO,
            Prenotazione::STATO_LISTA_ATTESA,
            Prenotazione::STATO_ANNULLATA,
            Prenotazione::STATO_SCADUTA,
        ];

        foreach ($statiInvalidi as $stato) {
            $prenotazione = $this->creaPrenotazione($assoc, ['stato' => $stato]);

            $response = $this->actingAs($admin)->postJson('/biglietti/checkin', [
                'qr_token' => $prenotazione->qr_token,
            ]);

            $response->assertStatus(409);
            $response->assertJson([
                'success' => false,
                'code' => 'stato_non_valido',
                'stato' => $stato,
            ]);
            $this->assertNotSame('', (string) $response->json('error'));
            $this->assertNull($prenotazione->refresh()->checkin_at);
        }
    }

    public function test_isolamento_tenant_biglietto_di_altra_associazione_non_trovato(): void
    {
        $assocA = Associazione::factory()->create();
        $assocB = Associazione::factory()->create();
        $adminA = $this->creaAdmin($assocA);

        $prenotazioneB = $this->creaPrenotazione($assocB);

        $response = $this->actingAs($adminA)->postJson('/biglietti/checkin', [
            'qr_token' => $prenotazioneB->qr_token,
        ]);

        $response->assertStatus(404);
        $response->assertJson(['success' => false, 'code' => 'biglietto_not_found']);
        $this->assertNull($prenotazioneB->refresh()->checkin_at);
    }

    public function test_biglietto_di_un_altro_evento_rifiutato_se_evento_selezionato(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);
        $prenotazione = $this->creaPrenotazione($assoc);
        $altroEvento = Evento::factory()->create(['associazione_id' => $assoc->id]);

        $response = $this->actingAs($admin)->postJson('/biglietti/checkin', [
            'qr_token' => $prenotazione->qr_token,
            'evento_id' => $altroEvento->id,
        ]);

        $response->assertStatus(409);
        $response->assertJson(['success' => false, 'code' => 'evento_mismatch']);
        $this->assertNull($prenotazione->refresh()->checkin_at);
    }

    public function test_checkin_con_evento_corrispondente_accettato(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);
        $prenotazione = $this->creaPrenotazione($assoc);

        $response = $this->actingAs($admin)->postJson('/biglietti/checkin', [
            'qr_token' => $prenotazione->qr_token,
            'evento_id' => $prenotazione->evento_id,
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true, 'already_checked_in' => false]);
    }

    public function test_token_sconosciuto_404(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);

        $this->actingAs($admin)->postJson('/biglietti/checkin', [
            'qr_token' => bin2hex(random_bytes(32)),
        ])->assertStatus(404)->assertJson(['code' => 'biglietto_not_found']);
    }

    public function test_payload_senza_identificatore_422(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);

        $this->actingAs($admin)->postJson('/biglietti/checkin', [])
            ->assertStatus(422)
            ->assertJson(['success' => false, 'code' => 'validation_error']);
    }
}
