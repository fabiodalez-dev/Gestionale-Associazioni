<?php

namespace Tests\Feature\FrontendPubblico;

use App\Models\Associazione;
use App\Models\Evento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Anteprima social degli eventi (#43): meta Open Graph e immagine generata
 * quando l'evento non ha una copertina.
 */
class ImmagineSocialEventoTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    protected function setUp(): void
    {
        parent::setUp();
        $this->associazione = Associazione::factory()->create(['slug' => 'faro', 'frontend_pubblico_attivo' => true, 'nome' => 'APS Il Faro']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/immagini-social'));
        parent::tearDown();
    }

    private function evento(array $attributi = []): Evento
    {
        return Evento::factory()->pubblicato()->create(['associazione_id' => $this->associazione->id, 'titolo' => 'Regata d\'autunno & festa sul molo con musica dal vivo fino a tarda sera', 'luogo' => 'Molo Sud'] + $attributi);
    }

    public function test_immagine_generata_1200x630_e_rigenerata_quando_l_evento_cambia(): void
    {
        $evento = $this->evento(['immagine_url' => null]);
        $url = route('pubblico.eventi.immagine-social', ['faro', $evento->id]);

        $risposta = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
        [$l, $h] = getimagesizefromstring((string) file_get_contents($risposta->getFile()->getPathname()));
        $this->assertSame([1200, 630], [$l, $h]);
        $this->assertCount(1, glob(storage_path('app/immagini-social/'.$evento->id.'-*.png')));

        $this->travel(1)->minutes();
        $evento->update(['titolo' => 'Regata rinviata']);
        $this->get($url)->assertOk();
        $this->assertCount(1, glob(storage_path('app/immagini-social/'.$evento->id.'-*.png')), 'La versione vecchia viene tolta');
    }

    public function test_meta_open_graph_con_copertina_o_immagine_generata(): void
    {
        $senza = $this->evento(['immagine_url' => null]);
        $this->get(route('pubblico.eventi.show', ['faro', $senza->id]))
            ->assertOk()
            ->assertSee('<meta property="og:image" content="'.route('pubblico.eventi.immagine-social', ['faro', $senza->id]).'">', false)
            ->assertSee('<meta name="twitter:card" content="summary_large_image">', false)
            ->assertSee('<meta property="og:title" content="Regata d&#039;autunno &amp; festa', false);

        $con = $this->evento(['immagine_url' => 'https://img.example.test/copertina.jpg?a=1&b=2']);
        $this->get(route('pubblico.eventi.show', ['faro', $con->id]))
            ->assertSee('content="https://img.example.test/copertina.jpg?a=1&amp;b=2"', false);
    }

    public function test_bozze_e_eventi_altrui_niente_immagine(): void
    {
        $bozza = Evento::factory()->create(['associazione_id' => $this->associazione->id, 'stato_pubblicazione' => Evento::STATO_BOZZA]);
        $altrui = Evento::factory()->pubblicato()->create();

        $this->get(route('pubblico.eventi.immagine-social', ['faro', $bozza->id]))->assertNotFound();
        $this->get(route('pubblico.eventi.immagine-social', ['faro', $altrui->id]))->assertNotFound();
    }
}
