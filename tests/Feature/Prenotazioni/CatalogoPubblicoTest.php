<?php

namespace Tests\Feature\Prenotazioni;

use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Prenotazione;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Frontend catalogo pubblico (fase 2a): home brandizzata, lista eventi
 * (solo pubblicati, prezzo/posti/badge), dettaglio con CTA, 404 uniformi.
 */
class CatalogoPubblicoTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create([
            'nome' => 'Polisportiva Buon Vento',
            'descrizione_pubblica' => 'La polisportiva del quartiere dal 1980.',
            'frontend_pubblico_attivo' => true,
        ]);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('tenant.pubblico');

        parent::tearDown();
    }

    private function urlTenant(string $path = ''): string
    {
        return '/a/'.$this->associazione->slug.($path !== '' ? '/'.ltrim($path, '/') : '');
    }

    // ── Home ─────────────────────────────────────────────────────────

    public function test_home_brandizzata_con_prossimi_eventi_pubblicati(): void
    {
        $pubblicato = Evento::factory()->pubblicato()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Torneo di burraco',
            'data_evento' => now()->addWeek(),
        ]);

        Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Evento ancora in bozza',
            'data_evento' => now()->addWeek(),
        ]);

        Evento::factory()->pubblicato()->passato()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Evento già passato',
        ]);

        $this->get($this->urlTenant())
            ->assertOk()
            ->assertSee('Polisportiva Buon Vento')
            ->assertSee('La polisportiva del quartiere dal 1980.')
            ->assertSee('Torneo di burraco')
            ->assertDontSee('Evento ancora in bozza')
            ->assertDontSee('Evento già passato');
    }

    public function test_home_404_su_slug_inesistente_e_frontend_disattivato(): void
    {
        $this->get('/a/slug-che-non-esiste')->assertNotFound();

        $spenta = Associazione::factory()->create(['frontend_pubblico_attivo' => false]);

        $this->get('/a/'.$spenta->slug)->assertNotFound();
        $this->get('/a/'.$spenta->slug.'/eventi')->assertNotFound();
    }

    // ── Lista eventi ─────────────────────────────────────────────────

    public function test_lista_eventi_solo_pubblicati_del_tenant(): void
    {
        Evento::factory()->pubblicato()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Cena sociale',
            'data_evento' => now()->addWeek(),
        ]);

        Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Bozza segreta',
            'data_evento' => now()->addWeek(),
        ]);

        $altra = Associazione::factory()->create(['frontend_pubblico_attivo' => true]);
        Evento::factory()->pubblicato()->create([
            'associazione_id' => $altra->id,
            'titolo' => 'Evento di un altro tenant',
            'data_evento' => now()->addWeek(),
        ]);

        $this->get($this->urlTenant('eventi'))
            ->assertOk()
            ->assertSee('Cena sociale')
            ->assertDontSee('Bozza segreta')
            ->assertDontSee('Evento di un altro tenant');
    }

    public function test_card_evento_gratuito_e_a_pagamento(): void
    {
        Evento::factory()->pubblicato()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Festa gratuita',
            'prezzo' => 0,
            'data_evento' => now()->addWeek(),
        ]);

        Evento::factory()->pubblicato()->aPagamento(15.50)->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Concerto a pagamento',
            'data_evento' => now()->addWeeks(2),
        ]);

        $this->get($this->urlTenant('eventi'))
            ->assertOk()
            ->assertSee('Gratuito')
            ->assertSee('15,50');
    }

    public function test_badge_posti_rimasti_esaurito_e_lista_attesa(): void
    {
        // Capienza 10, 8 posti occupati → "2 posti rimasti"
        $conPosti = Evento::factory()->pubblicato()->conCapienza(10)->create([
            'associazione_id' => $this->associazione->id,
            'data_evento' => now()->addWeek(),
        ]);
        Prenotazione::factory()->confermata()->create([
            'associazione_id' => $this->associazione->id,
            'evento_id' => $conPosti->id,
            'posti' => 8,
        ]);

        // Pieno con lista d'attesa attiva → badge "Lista d'attesa"
        $inCoda = Evento::factory()->pubblicato()->conCapienza(2)->create([
            'associazione_id' => $this->associazione->id,
            'lista_attesa_attiva' => true,
            'data_evento' => now()->addWeek(),
        ]);
        Prenotazione::factory()->confermata()->create([
            'associazione_id' => $this->associazione->id,
            'evento_id' => $inCoda->id,
            'posti' => 2,
        ]);

        // Pieno senza lista d'attesa → badge "Esaurito"
        $esaurito = Evento::factory()->pubblicato()->conCapienza(2)->create([
            'associazione_id' => $this->associazione->id,
            'lista_attesa_attiva' => false,
            'data_evento' => now()->addWeek(),
        ]);
        Prenotazione::factory()->confermata()->create([
            'associazione_id' => $this->associazione->id,
            'evento_id' => $esaurito->id,
            'posti' => 2,
        ]);

        $this->get($this->urlTenant('eventi'))
            ->assertOk()
            ->assertSee('2 posti rimasti')
            ->assertSee("Lista d'attesa", false)
            ->assertSee('Esaurito');
    }

    // ── Dettaglio evento ─────────────────────────────────────────────

    public function test_dettaglio_evento_pubblicato_con_cta_login_per_ospiti(): void
    {
        $evento = Evento::factory()->pubblicato()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Serata jazz',
            'data_evento' => now()->addWeek(),
        ]);

        $this->get($this->urlTenant('eventi/'.$evento->id))
            ->assertOk()
            ->assertSee('Serata jazz')
            ->assertSee('Accedi per prenotare');
    }

    public function test_dettaglio_evento_bozza_404(): void
    {
        $bozza = Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'data_evento' => now()->addWeek(),
        ]);

        $this->get($this->urlTenant('eventi/'.$bozza->id))->assertNotFound();
    }

    public function test_dettaglio_evento_di_altro_tenant_404(): void
    {
        $altra = Associazione::factory()->create(['frontend_pubblico_attivo' => true]);

        $estraneo = Evento::factory()->pubblicato()->create([
            'associazione_id' => $altra->id,
            'data_evento' => now()->addWeek(),
        ]);

        $this->get($this->urlTenant('eventi/'.$estraneo->id))->assertNotFound();
    }
}
