<?php

namespace Tests\Feature\Prenotazioni;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Prenotazione;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rotte pubbliche di prenotazione:
 * POST /a/{slug}/eventi/{evento}/prenota (guard account + verificato,
 * throttle 10/m) e pagina di esito GET /a/{slug}/prenotazioni/{p}/esito.
 */
class PrenotaEventoHttpTest extends TestCase
{
    use RefreshDatabase;

    protected Associazione $associazione;

    protected Evento $evento;

    protected Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create(['frontend_pubblico_attivo' => true]);
        $this->evento = Evento::factory()->pubblicato()->conCapienza(10)->create([
            'associazione_id' => $this->associazione->id,
            'data_evento' => now()->addWeek(),
            'max_posti_per_prenotazione' => 4,
        ]);
        $this->account = Account::factory()->verificato()->create([
            'associazione_id' => $this->associazione->id,
        ]);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('tenant.pubblico');

        parent::tearDown();
    }

    protected function urlPrenota(?Evento $evento = null, ?Associazione $associazione = null): string
    {
        $associazione ??= $this->associazione;
        $evento ??= $this->evento;

        return "/a/{$associazione->slug}/eventi/{$evento->id}/prenota";
    }

    public function test_account_verificato_prenota_e_viene_rediretto_all_esito(): void
    {
        $response = $this->actingAs($this->account, 'account')
            ->post($this->urlPrenota(), ['posti' => 2]);

        $prenotazione = Prenotazione::allTenants()
            ->where('account_id', $this->account->id)
            ->where('evento_id', $this->evento->id)
            ->first();

        $this->assertNotNull($prenotazione);
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $prenotazione->stato);
        $this->assertSame(2, $prenotazione->posti);

        $response->assertRedirect(
            "/a/{$this->associazione->slug}/prenotazioni/{$prenotazione->id}/esito"
        );
    }

    public function test_pagina_esito_mostra_codice_della_confermata(): void
    {
        $this->actingAs($this->account, 'account')->post($this->urlPrenota(), ['posti' => 1]);

        $prenotazione = Prenotazione::allTenants()->where('account_id', $this->account->id)->firstOrFail();

        $this->actingAs($this->account, 'account')
            ->get("/a/{$this->associazione->slug}/prenotazioni/{$prenotazione->id}/esito")
            ->assertOk()
            ->assertSee($prenotazione->codice)
            ->assertSee('Prenotazione confermata')
            ->assertSee($this->evento->titolo);
    }

    public function test_pagina_esito_lista_attesa_mostra_posizione_in_coda(): void
    {
        // Riempie l'evento e accoda due account
        $this->evento->forceFill(['capienza' => 1])->save();
        $pieno = Account::factory()->verificato()->create(['associazione_id' => $this->associazione->id]);
        $this->actingAs($pieno, 'account')->post($this->urlPrenota(), ['posti' => 1]);

        $primoInCoda = Account::factory()->verificato()->create(['associazione_id' => $this->associazione->id]);
        $this->actingAs($primoInCoda, 'account')->post($this->urlPrenota(), ['posti' => 1]);

        $this->travel(1)->seconds(); // created_at distinti per l'ordinamento FIFO
        $this->actingAs($this->account, 'account')->post($this->urlPrenota(), ['posti' => 1]);

        $inCoda = Prenotazione::allTenants()->where('account_id', $this->account->id)->firstOrFail();
        $this->assertSame(Prenotazione::STATO_LISTA_ATTESA, $inCoda->stato);

        $this->actingAs($this->account, 'account')
            ->get("/a/{$this->associazione->slug}/prenotazioni/{$inCoda->id}/esito")
            ->assertOk()
            ->assertSee('lista d\'attesa', false)
            ->assertSee('<strong>2</strong>', false);
    }

    public function test_pagina_esito_in_attesa_pagamento_mostra_la_scadenza(): void
    {
        $this->evento->forceFill(['prezzo' => 10])->save();

        $this->actingAs($this->account, 'account')->post($this->urlPrenota(), ['posti' => 1]);

        $prenotazione = Prenotazione::allTenants()->where('account_id', $this->account->id)->firstOrFail();
        $this->assertSame(Prenotazione::STATO_IN_ATTESA_PAGAMENTO, $prenotazione->stato);

        $this->actingAs($this->account, 'account')
            ->get("/a/{$this->associazione->slug}/prenotazioni/{$prenotazione->id}/esito")
            ->assertOk()
            ->assertSee('completa il pagamento')
            ->assertSee($prenotazione->scadenza_pagamento_at->format('H:i'));
    }

    public function test_ospite_non_autenticato_non_prenota(): void
    {
        $response = $this->post($this->urlPrenota(), ['posti' => 1]);

        // Redirect alla login (302) o accesso negato: mai una prenotazione
        $this->assertContains($response->status(), [302, 401, 403]);
        $this->assertSame(0, Prenotazione::allTenants()->count());
    }

    public function test_account_non_verificato_non_prenota(): void
    {
        $nonVerificato = Account::factory()->nonVerificato()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $response = $this->actingAs($nonVerificato, 'account')
            ->post($this->urlPrenota(), ['posti' => 1]);

        // Redirect all'avviso di verifica email (302) o 403 se la rotta non esiste
        $this->assertContains($response->status(), [302, 403]);
        $this->assertSame(0, Prenotazione::allTenants()->count());
    }

    public function test_posti_non_validi_falliscono_la_validazione(): void
    {
        $this->actingAs($this->account, 'account')
            ->post($this->urlPrenota(), ['posti' => 0])
            ->assertSessionHasErrors('posti');

        $this->assertSame(0, Prenotazione::allTenants()->count());
    }

    public function test_posti_oltre_il_massimo_evento_tornano_indietro_con_errore(): void
    {
        $this->actingAs($this->account, 'account')
            ->post($this->urlPrenota(), ['posti' => 5]) // max_posti_per_prenotazione = 4
            ->assertSessionHasErrors('posti');

        $this->assertSame(0, Prenotazione::allTenants()->count());
    }

    public function test_doppia_prenotazione_torna_indietro_con_errore(): void
    {
        $this->actingAs($this->account, 'account')->post($this->urlPrenota(), ['posti' => 1]);

        $this->actingAs($this->account, 'account')
            ->post($this->urlPrenota(), ['posti' => 1])
            ->assertSessionHasErrors('posti');

        $this->assertSame(1, Prenotazione::allTenants()->count());
    }

    public function test_evento_in_bozza_da_404(): void
    {
        $bozza = Evento::factory()->conCapienza(10)->create([
            'associazione_id' => $this->associazione->id,
            'data_evento' => now()->addWeek(),
        ]);

        $this->actingAs($this->account, 'account')
            ->post($this->urlPrenota($bozza), ['posti' => 1])
            ->assertNotFound();
    }

    public function test_evento_di_altro_tenant_da_404_uniforme(): void
    {
        $altraAssociazione = Associazione::factory()->create(['frontend_pubblico_attivo' => true]);
        $eventoAltrui = Evento::factory()->pubblicato()->conCapienza(10)->create([
            'associazione_id' => $altraAssociazione->id,
            'data_evento' => now()->addWeek(),
        ]);

        // Evento del tenant B prenotato attraverso lo slug del tenant A
        $this->actingAs($this->account, 'account')
            ->post($this->urlPrenota($eventoAltrui), ['posti' => 1])
            ->assertNotFound();
    }

    public function test_esito_di_un_altro_account_negato(): void
    {
        $this->actingAs($this->account, 'account')->post($this->urlPrenota(), ['posti' => 1]);
        $prenotazione = Prenotazione::allTenants()->where('account_id', $this->account->id)->firstOrFail();

        $estraneo = Account::factory()->verificato()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($estraneo, 'account')
            ->get("/a/{$this->associazione->slug}/prenotazioni/{$prenotazione->id}/esito")
            ->assertForbidden();
    }

    public function test_esito_inesistente_da_404(): void
    {
        $this->actingAs($this->account, 'account')
            ->get("/a/{$this->associazione->slug}/prenotazioni/00000000-0000-0000-0000-000000000000/esito")
            ->assertNotFound();
    }

    public function test_throttle_10_richieste_al_minuto(): void
    {
        // 10 tentativi (falliscono la validazione: nessuna prenotazione creata)...
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($this->account, 'account')
                ->post($this->urlPrenota(), ['posti' => 0])
                ->assertStatus(302);
        }

        // ...l'11° viene rate-limitato
        $this->actingAs($this->account, 'account')
            ->post($this->urlPrenota(), ['posti' => 0])
            ->assertStatus(429);
    }
}
