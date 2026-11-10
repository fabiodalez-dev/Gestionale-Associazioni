<?php

namespace Tests\Feature\FrontendPubblico;

use App\Models\Associazione;
use App\Models\Evento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ricerca e filtri del catalogo eventi pubblico (#46).
 */
class FiltriCatalogoEventiTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfWeek()->setTime(9, 0)); // lunedì
        $this->associazione = Associazione::factory()->create(['slug' => 'faro', 'frontend_pubblico_attivo' => true]);

        $this->evento('Concerto jazz al molo', now()->addDays(2), 15, null);
        $this->evento('Laboratorio di vela', now()->addDays(20), 0, null);
        $this->evento('Cena sociale', now()->addMonths(2), 0, 0);
    }

    private function evento(string $titolo, $data, float $prezzo, ?int $capienza): void
    {
        Evento::factory()->pubblicato()->create([
            'associazione_id' => $this->associazione->id, 'titolo' => $titolo, 'data_evento' => $data,
            'prezzo' => $prezzo, 'capienza' => $capienza, 'descrizione' => 'Evento '.$titolo, 'luogo' => 'Bologna',
        ]);
    }

    /** @return list<string> */
    private function titoli(string $query): array
    {
        $html = (string) $this->get('/a/faro/eventi'.$query)->assertOk()->getContent();
        preg_match_all('/(Concerto jazz al molo|Laboratorio di vela|Cena sociale)/', $html, $m);

        return array_values(array_unique($m[1]));
    }

    public function test_senza_filtri_tutti_gli_eventi(): void
    {
        $this->assertCount(3, $this->titoli(''));
    }

    public function test_ricerca_per_parole_in_qualunque_ordine(): void
    {
        $this->assertSame(['Concerto jazz al molo'], $this->titoli('?q=molo+jazz'));
    }

    public function test_periodo_settimana_e_mese(): void
    {
        $this->assertSame(['Concerto jazz al molo'], $this->titoli('?periodo=settimana'));
        $this->assertNotContains('Cena sociale', $this->titoli('?periodo=mese'));
    }

    public function test_gratuiti_a_pagamento_e_posti_liberi(): void
    {
        $this->assertSame(['Concerto jazz al molo'], $this->titoli('?prezzo=a_pagamento'));
        $this->assertSame(['Laboratorio di vela', 'Cena sociale'], $this->titoli('?prezzo=gratuiti'));
        // Capienza 0: nessun posto libero
        $this->assertNotContains('Cena sociale', $this->titoli('?posti=1'));
    }

    public function test_valori_non_validi_ignorati(): void
    {
        $this->assertCount(3, $this->titoli('?periodo=anno&prezzo=tutto'));
    }

    public function test_richiesta_dell_elenco_senza_layout_e_messaggio_se_vuoto(): void
    {
        $html = (string) $this->withHeaders(['X-Elenco-Eventi' => '1'])->get('/a/faro/eventi?q=inesistente')->assertOk()->getContent();

        $this->assertStringNotContainsString('<html', $html);
        $this->assertStringContainsString('Nessun evento corrisponde ai filtri scelti.', $html);
    }

    public function test_filtri_restano_nel_form(): void
    {
        $this->get('/a/faro/eventi?q=vela&prezzo=gratuiti&posti=1')
            ->assertSee('value="vela"', false)
            ->assertSee('<option value="gratuiti" selected>', false);
    }
}
