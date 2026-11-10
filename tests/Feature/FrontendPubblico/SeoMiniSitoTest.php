<?php

namespace Tests\Feature\FrontendPubblico;

use App\Models\Annuncio;
use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sitemap, RSS, llms.txt, robots.txt, dati strutturati, canonical e noindex
 * del mini-sito pubblico (#39, #40, #41, #42, #44, #45).
 */
class SeoMiniSitoTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    protected function setUp(): void
    {
        parent::setUp();
        $this->associazione = Associazione::factory()->create([
            'nome' => 'APS Il Faro',
            'slug' => 'il-faro',
            'citta' => 'Bologna',
            'provincia' => 'BO',
            'email' => 'info@ilfaro.test',
            'telefono' => null,
            'frontend_pubblico_attivo' => true,
            'attiva' => true,
            'descrizione_pubblica' => '<p>Cultura e mare</p>',
        ]);
    }

    private function evento(array $attributi = []): Evento
    {
        return Evento::factory()->pubblicato()->create(['associazione_id' => $this->associazione->id] + $attributi);
    }

    public function test_robots_indica_la_sitemap_della_piattaforma(): void
    {
        $this->get('/robots.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('Sitemap: '.route('seo.sitemap'), false);
    }

    public function test_indice_delle_sitemap_solo_associazioni_attive_con_mini_sito(): void
    {
        Associazione::factory()->create(['slug' => 'spenta', 'frontend_pubblico_attivo' => false, 'attiva' => true]);
        Associazione::factory()->create(['slug' => 'sospesa', 'frontend_pubblico_attivo' => true, 'attiva' => false]);

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        $doc = simplexml_load_string((string) $xml);
        $this->assertNotFalse($doc, 'XML valido');
        $this->assertStringContainsString(route('pubblico.sitemap', 'il-faro'), (string) $xml);
        $this->assertStringNotContainsString('/a/spenta/', (string) $xml);
        $this->assertStringNotContainsString('/a/sospesa/', (string) $xml);
    }

    public function test_sitemap_del_mini_sito_con_eventi_pubblicati_e_senza_bozze(): void
    {
        $pubblicato = $this->evento(['titolo' => 'Regata']);
        $bozza = Evento::factory()->create(['associazione_id' => $this->associazione->id, 'stato_pubblicazione' => Evento::STATO_BOZZA]);
        $vecchio = $this->evento(['data_evento' => now()->subYears(2)]);
        $altrui = Evento::factory()->pubblicato()->create();

        $xml = (string) $this->get('/a/il-faro/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->getContent();

        $this->assertNotFalse(simplexml_load_string($xml));
        $this->assertStringContainsString(route('pubblico.eventi.show', ['il-faro', $pubblicato->id]), $xml);
        $this->assertStringContainsString(route('pubblico.iscriviti', 'il-faro'), $xml);
        $this->assertStringNotContainsString((string) $bozza->id, $xml);
        $this->assertStringNotContainsString((string) $vecchio->id, $xml);
        $this->assertStringNotContainsString((string) $altrui->id, $xml);
    }

    public function test_mini_sito_spento_niente_sitemap_feed_llms(): void
    {
        $this->associazione->update(['frontend_pubblico_attivo' => false]);

        $this->get('/a/il-faro/sitemap.xml')->assertNotFound();
        $this->get('/a/il-faro/feed.xml')->assertNotFound();
        $this->get('/a/il-faro/llms.txt')->assertNotFound();
    }

    public function test_feed_rss_valido_con_eventi_e_novita_pubbliche(): void
    {
        $this->evento(['titolo' => 'Cena <sociale> & musica']);
        Evento::factory()->create(['associazione_id' => $this->associazione->id, 'titolo' => 'Bozza segreta']);
        $pubblico = Annuncio::factory()->create(['associazione_id' => $this->associazione->id, 'titolo' => 'Nuova sede']);
        Annuncio::factory()->create(['associazione_id' => $this->associazione->id, 'titolo' => 'Solo per i soci', 'solo_soci' => true]);

        $xml = (string) $this->get('/a/il-faro/feed.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8')
            ->getContent();

        $rss = simplexml_load_string($xml);
        $this->assertNotFalse($rss, 'RSS valido anche con caratteri speciali nei titoli');
        $titoli = array_map('strval', $rss->xpath('//item/title'));
        $this->assertCount(2, $titoli);
        $this->assertStringContainsString('Cena <sociale> & musica', implode('|', $titoli));
        $this->assertContains('Nuova sede', $titoli);
        $this->assertStringContainsString('#annuncio-'.$pubblico->id, $xml);
        $this->assertStringNotContainsString('Bozza segreta', $xml);
        $this->assertStringNotContainsString('Solo per i soci', $xml);

        // Il mini-sito lo annuncia nell'head
        $this->get('/a/il-faro')->assertSee('application/rss+xml', false)->assertSee(route('pubblico.feed', 'il-faro'), false);
    }

    public function test_llms_txt_del_mini_sito_e_della_piattaforma(): void
    {
        $this->evento(['titolo' => 'Laboratorio di vela']);

        $this->get('/a/il-faro/llms.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')
            ->assertSee('# APS Il Faro', false)
            ->assertSee('Cultura e mare', false)
            ->assertSee('Laboratorio di vela', false)
            ->assertSee(route('pubblico.iscriviti', 'il-faro'), false);

        $this->get('/llms.txt')
            ->assertOk()
            ->assertSee('[APS Il Faro]('.route('pubblico.home.show', 'il-faro').')', false);
    }

    public function test_home_con_dati_strutturati_dell_associazione(): void
    {
        $html = (string) $this->get('/a/il-faro')->assertOk()->getContent();

        $dati = $this->jsonLd($html, 'NGO');
        $this->assertSame('APS Il Faro', $dati['name']);
        $this->assertSame('Bologna', $dati['address']['addressLocality']);
        $this->assertSame('Cultura e mare', $dati['description']);
        $this->assertArrayNotHasKey('telephone', $dati, 'Nessun campo vuoto');
    }

    public function test_pagina_evento_con_event_e_briciole_e_testo_sicuro(): void
    {
        $evento = $this->evento([
            'titolo' => 'Concerto </script><script>alert(1)</script>',
            'luogo' => 'Molo 5',
            'prezzo' => 12.5,
            'capienza' => null,
        ]);

        $html = (string) $this->get(route('pubblico.eventi.show', ['il-faro', $evento->id]))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $dati = $this->jsonLd($html, 'Event');
        $this->assertSame('Concerto </script><script>alert(1)</script>', $dati['name']);
        $this->assertSame('Molo 5', $dati['location']['name']);
        $this->assertSame('12.50', $dati['offers']['price']);
        $this->assertSame('EUR', $dati['offers']['priceCurrency']);
        $this->assertSame($evento->data_evento->toIso8601String(), $dati['startDate']);

        $briciole = $this->jsonLd($html, 'BreadcrumbList');
        $this->assertCount(3, $briciole['itemListElement']);
        $this->assertSame('Eventi', $briciole['itemListElement'][1]['name']);
    }

    public function test_canonical_senza_parametri_tranne_la_pagina(): void
    {
        $this->get('/a/il-faro/eventi?utm_source=newsletter')
            ->assertSee('<link rel="canonical" href="'.route('pubblico.eventi.index', 'il-faro').'">', false);

        $this->get('/a/il-faro/novita?page=2&utm_source=x')
            ->assertSee('<link rel="canonical" href="'.route('pubblico.novita', 'il-faro').'?page=2">', false);
    }

    public function test_noindex_fuori_dalle_pagine_pubbliche(): void
    {
        $this->get('/a/il-faro')->assertOk()->assertHeaderMissing('X-Robots-Tag');
        $this->get('/a/il-faro/eventi')->assertOk()->assertHeaderMissing('X-Robots-Tag');

        $this->get('/login')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/a/il-faro/login')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/a/il-faro/password/reset')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/verifica-tessera')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->actingAs(Utente::factory()->superAdmin()->create())
            ->get('/dashboard')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /** @return array<string, mixed> */
    private function jsonLd(string $html, string $tipo): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $blocchi);
        foreach ($blocchi[1] as $json) {
            $dati = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            if (($dati['@type'] ?? null) === $tipo) {
                return $dati;
            }
        }
        $this->fail("Nessun JSON-LD di tipo {$tipo}");
    }
}
