<?php

namespace Tests\Feature\Operativita;

use App\Models\Associazione;
use App\Models\Documento;
use App\Models\Evento;
use App\Models\Prenotazione;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * f.1 — Ricerca globale (command palette ⌘K): endpoint JSON throttled e
 * tenant-scoped con risultati raggruppati (soci via Scout, LIKE per il
 * resto, catalogo pagine admin).
 */
class RicercaGlobaleTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create();
        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
    }

    /**
     * @return list<string>
     */
    private function nomiGruppi(array $json): array
    {
        return array_column($json['gruppi'], 'gruppo');
    }

    public function test_richiede_autenticazione(): void
    {
        $this->get('/ricerca-globale?q=rossi')->assertRedirect('/login');
    }

    public function test_query_troppo_corta_restituisce_nessun_gruppo(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/ricerca-globale?q=a');

        $response->assertOk()->assertExactJson(['query' => 'a', 'gruppi' => []]);
    }

    public function test_trova_i_soci_del_tenant_per_cognome(): void
    {
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Mario',
            'cognome' => 'Rossini',
        ]);

        $response = $this->actingAs($this->admin)->getJson('/ricerca-globale?q=Rossini');

        $response->assertOk();
        $gruppi = collect($response->json('gruppi'));
        $soci = $gruppi->firstWhere('gruppo', 'Soci');

        $this->assertNotNull($soci);
        $this->assertSame('Rossini Mario', $soci['risultati'][0]['titolo']);
        $this->assertStringContainsString("/soci/{$socio->id}", $soci['risultati'][0]['url']);
    }

    /** @return list<string> titoli del gruppo Soci */
    private function sociTrovati(string $q): array
    {
        $gruppo = collect($this->actingAs($this->admin)->getJson('/ricerca-globale?q='.urlencode($q))->assertOk()->json('gruppi'))
            ->firstWhere('gruppo', 'Soci');

        return array_column($gruppo['risultati'] ?? [], 'titolo');
    }

    public function test_trova_il_socio_dal_telefono_comunque_scritto(): void
    {
        Socio::factory()->create(['associazione_id' => $this->associazione->id, 'nome' => 'Lia', 'cognome' => 'Neri', 'telefono' => '+39 333 123 4567']);
        Socio::factory()->create(['associazione_id' => $this->associazione->id, 'nome' => 'Ugo', 'cognome' => 'Bassi', 'telefono' => '055 765432']);

        foreach (['333 12 34 567', '+393331234567', '3331234567', '333-123-4567'] as $q) {
            $this->assertSame(['Neri Lia'], $this->sociTrovati($q), "Telefono scritto come: {$q}");
        }
        $this->assertSame(['Bassi Ugo'], $this->sociTrovati('055/765432'));
    }

    public function test_trova_il_socio_da_numero_tessera_qr_codice_fiscale_ed_email(): void
    {
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id, 'nome' => 'Ada', 'cognome' => 'Bianchi',
            'codice_fiscale' => 'BNCDAA80A41H501X', 'email' => 'ada.bianchi@example.test',
        ]);
        $tessera = Tessera::factory()->create([
            'associazione_id' => $this->associazione->id, 'socio_id' => $socio->id, 'numero_tessera' => 'T-2026-0042',
        ]);

        $this->assertSame(['Bianchi Ada'], $this->sociTrovati('T-2026-0042'));
        $this->assertSame(['Bianchi Ada'], $this->sociTrovati('https://esempio.test/verifica-tessera/'.$tessera->id));
        $this->assertSame(['Bianchi Ada'], $this->sociTrovati('bncdaa80a41h501x'));
        $this->assertSame(['Bianchi Ada'], $this->sociTrovati('Ada.Bianchi@Example.test'));
    }

    public function test_le_corrispondenze_esatte_vengono_prima(): void
    {
        // "Rossi Zeno" ha il telefono cercato, gli altri lo contengono solo nel nome
        Socio::factory()->create(['associazione_id' => $this->associazione->id, 'nome' => 'Zeno', 'cognome' => 'Rossi', 'telefono' => '3471112223']);
        Socio::factory()->create(['associazione_id' => $this->associazione->id, 'nome' => 'Anna', 'cognome' => 'Alberti', 'telefono' => null]);

        $this->assertSame('Rossi Zeno', $this->sociTrovati('347 111 2223')[0]);
    }

    public function test_telefono_di_un_altro_tenant_non_trovato(): void
    {
        $altra = Associazione::factory()->create();
        Socio::factory()->create(['associazione_id' => $altra->id, 'telefono' => '3339998887']);

        $this->assertSame([], $this->sociTrovati('3339998887'));
    }

    public function test_non_trova_i_soci_di_un_altro_tenant(): void
    {
        $altra = Associazione::factory()->create();
        Socio::factory()->create([
            'associazione_id' => $altra->id,
            'nome' => 'Estraneo',
            'cognome' => 'Verdolini',
        ]);

        $response = $this->actingAs($this->admin)->getJson('/ricerca-globale?q=Verdolini');

        $response->assertOk();
        $this->assertNotContains('Soci', $this->nomiGruppi($response->json()));
    }

    public function test_trova_eventi_prenotazioni_e_documenti(): void
    {
        $evento = Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Cena sociale di primavera',
        ]);
        $prenotazione = Prenotazione::factory()->confermata()->create([
            'evento_id' => $evento->id,
            'codice' => 'PRN-ZZZ999',
        ]);
        Documento::create([
            'associazione_id' => $this->associazione->id,
            'nome_file' => 'statuto-aggiornato.pdf',
            'percorso_file' => 'documenti/statuto-aggiornato.pdf',
        ]);

        $eventi = collect($this->actingAs($this->admin)->getJson('/ricerca-globale?q=primavera')->json('gruppi'))
            ->firstWhere('gruppo', 'Eventi');
        $this->assertNotNull($eventi);
        $this->assertSame('Cena sociale di primavera', $eventi['risultati'][0]['titolo']);

        $prenotazioni = collect($this->actingAs($this->admin)->getJson('/ricerca-globale?q=ZZZ999')->json('gruppi'))
            ->firstWhere('gruppo', 'Prenotazioni');
        $this->assertNotNull($prenotazioni);
        $this->assertSame('PRN-ZZZ999', $prenotazioni['risultati'][0]['titolo']);
        $this->assertStringContainsString("/eventi/{$evento->id}/prenotazioni", $prenotazioni['risultati'][0]['url']);

        $documenti = collect($this->actingAs($this->admin)->getJson('/ricerca-globale?q=statuto')->json('gruppi'))
            ->firstWhere('gruppo', 'Documenti');
        $this->assertNotNull($documenti);
        $this->assertSame('statuto-aggiornato.pdf', $documenti['risultati'][0]['titolo']);
    }

    public function test_trova_le_pagine_admin_per_titolo_e_keyword(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/ricerca-globale?q=tessere');

        $pagine = collect($response->json('gruppi'))->firstWhere('gruppo', 'Pagine');

        $this->assertNotNull($pagine);
        $this->assertContains('Tessere', array_column($pagine['risultati'], 'titolo'));
    }

    public function test_endpoint_throttled(): void
    {
        $route = app('router')->getRoutes()->getByName('ricerca-globale');

        $this->assertNotNull($route);
        $this->assertContains('throttle:30,1', $route->middleware());
    }

    public function test_prenotazione_di_altro_tenant_non_visibile(): void
    {
        $altra = Associazione::factory()->create();
        $eventoAltrui = Evento::factory()->create(['associazione_id' => $altra->id]);
        Prenotazione::factory()->confermata()->create([
            'evento_id' => $eventoAltrui->id,
            'codice' => 'PRN-ALTRUI1',
        ]);

        $response = $this->actingAs($this->admin)->getJson('/ricerca-globale?q=ALTRUI1');

        $this->assertNotContains('Prenotazioni', $this->nomiGruppi($response->json()));
    }
}
