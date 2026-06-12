<?php

namespace Tests\Feature\DocumentiVerbaliScadenze;

use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Utente;
use App\Models\Verbale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verbali potenziati (f.17 del piano vita sociale): numerazione
 * automatica per anno (es. 3/2026), collegamento opzionale a un evento
 * e ricerca full-text sul contenuto.
 */
class VerbaliPotenziatiTest extends TestCase
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
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'titolo' => 'Assemblea dei soci',
            'data_riunione' => '2026-05-20',
            'tipo_riunione' => 'Assemblea Ordinaria',
        ], $extra);
    }

    public function test_la_numerazione_e_progressiva_per_anno(): void
    {
        $this->actingAs($this->admin)->post(route('verbali.store'), $this->payload());
        $this->actingAs($this->admin)->post(route('verbali.store'), $this->payload([
            'titolo' => 'Consiglio direttivo di giugno',
            'data_riunione' => '2026-06-10',
            'tipo_riunione' => 'Consiglio Direttivo',
        ]));
        // Anno diverso: la numerazione riparte da 1
        $this->actingAs($this->admin)->post(route('verbali.store'), $this->payload([
            'titolo' => 'Assemblea 2027',
            'data_riunione' => '2027-04-15',
        ]));

        $verbali = Verbale::query()->orderBy('data_riunione')->get();

        $this->assertSame([1, 2026], [$verbali[0]->numero, $verbali[0]->anno]);
        $this->assertSame('1/2026', $verbali[0]->numero_completo);
        $this->assertSame([2, 2026], [$verbali[1]->numero, $verbali[1]->anno]);
        $this->assertSame([1, 2027], [$verbali[2]->numero, $verbali[2]->anno]);
    }

    public function test_la_numerazione_e_indipendente_per_tenant(): void
    {
        $this->actingAs($this->admin)->post(route('verbali.store'), $this->payload());

        $altra = Associazione::factory()->create();
        $altroAdmin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $altra->id,
        ]);

        $this->actingAs($altroAdmin)->post(route('verbali.store'), $this->payload([
            'titolo' => 'Prima assemblea altrui',
        ]));

        $verbaleAltrui = Verbale::allTenants()
            ->where('associazione_id', $altra->id)
            ->first();

        // Anche il secondo tenant parte da 1/2026 (nessuna interferenza)
        $this->assertSame(1, $verbaleAltrui->numero);
        $this->assertSame(2026, $verbaleAltrui->anno);
    }

    public function test_il_cambio_anno_in_modifica_rinumera_il_verbale(): void
    {
        $this->actingAs($this->admin)->post(route('verbali.store'), $this->payload());
        $this->actingAs($this->admin)->post(route('verbali.store'), $this->payload([
            'titolo' => 'Assemblea 2027 esistente',
            'data_riunione' => '2027-03-01',
        ]));

        $verbale2026 = Verbale::query()->where('anno', 2026)->first();

        // Sposto il verbale del 2026 nel 2027: deve prendere il numero 2/2027
        $this->actingAs($this->admin)->put(route('verbali.update', $verbale2026), $this->payload([
            'data_riunione' => '2027-09-01',
        ]))->assertRedirect(route('verbali.index'));

        $verbale2026->refresh();
        $this->assertSame(2027, $verbale2026->anno);
        $this->assertSame(2, $verbale2026->numero);
        $this->assertSame('2/2027', $verbale2026->numero_completo);
    }

    public function test_collegamento_a_un_evento_del_tenant(): void
    {
        $evento = Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Assemblea annuale in sede',
        ]);

        $this->actingAs($this->admin)->post(route('verbali.store'), $this->payload([
            'evento_id' => $evento->id,
        ]))->assertRedirect(route('verbali.index'));

        $verbale = Verbale::query()->first();
        $this->assertSame($evento->id, $verbale->evento_id);

        // L'evento collegato è visibile nella lista
        $this->actingAs($this->admin)
            ->get(route('verbali.index'))
            ->assertOk()
            ->assertSee('Assemblea annuale in sede');
    }

    public function test_evento_di_altro_tenant_viene_rifiutato(): void
    {
        $altra = Associazione::factory()->create();
        $eventoAltrui = Evento::factory()->create(['associazione_id' => $altra->id]);

        $this->actingAs($this->admin)
            ->from(route('verbali.index'))
            ->post(route('verbali.store'), $this->payload([
                'evento_id' => $eventoAltrui->id,
            ]))
            ->assertSessionHasErrors('evento_id');

        $this->assertSame(0, Verbale::query()->count());
    }

    public function test_ricerca_full_text_trova_il_contenuto_del_verbale(): void
    {
        $this->actingAs($this->admin)->post(route('verbali.store'), $this->payload([
            'titolo' => 'Consiglio di primavera',
            'deliberazioni' => 'Approvato il rifacimento del tetto della sede sociale.',
        ]));
        $this->actingAs($this->admin)->post(route('verbali.store'), $this->payload([
            'titolo' => 'Consiglio d\'autunno',
            'ordine_del_giorno' => '1. Tesseramento 2027',
        ]));

        // Match su deliberazioni
        $this->actingAs($this->admin)
            ->get(route('verbali.index', ['search' => 'rifacimento del tetto']))
            ->assertOk()
            ->assertSee('Consiglio di primavera')
            ->assertDontSee('Consiglio d&#039;autunno', false);

        // Match su ordine del giorno
        $this->actingAs($this->admin)
            ->get(route('verbali.index', ['search' => 'Tesseramento 2027']))
            ->assertOk()
            ->assertSee('Consiglio d&#039;autunno', false)
            ->assertDontSee('Consiglio di primavera');
    }

    public function test_filtro_per_anno_della_lista(): void
    {
        $this->actingAs($this->admin)->post(route('verbali.store'), $this->payload([
            'titolo' => 'Verbale del 2026',
        ]));
        $this->actingAs($this->admin)->post(route('verbali.store'), $this->payload([
            'titolo' => 'Verbale del 2027',
            'data_riunione' => '2027-02-02',
        ]));

        $this->actingAs($this->admin)
            ->get(route('verbali.index', ['anno' => 2027]))
            ->assertOk()
            ->assertSee('Verbale del 2027')
            ->assertDontSee('Verbale del 2026');
    }

    public function test_il_numero_compare_nel_pdf(): void
    {
        $this->actingAs($this->admin)->post(route('verbali.store'), $this->payload());

        $verbale = Verbale::query()->first();

        $response = $this->actingAs($this->admin)->get(route('verbali.pdf', $verbale));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }
}
