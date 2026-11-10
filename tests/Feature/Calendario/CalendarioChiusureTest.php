<?php

namespace Tests\Feature\Calendario;

use App\Models\Associazione;
use App\Models\Chiusura;
use App\Models\Sede;
use App\Models\Utente;
use App\Services\Calendario\CalendarioAperture;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Calendario chiusure (#34): festività nazionali calcolate da regole e
 * chiusure dell'associazione, per tutte le sedi o per una.
 */
class CalendarioChiusureTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    protected function setUp(): void
    {
        parent::setUp();
        $this->associazione = Associazione::factory()->create();
    }

    private function giorno(string $data): CarbonImmutable
    {
        return CarbonImmutable::parse($data);
    }

    public function test_pasqua_calcolata_correttamente(): void
    {
        $attese = [
            1900 => '1900-04-15', 1961 => '1961-04-02', 2000 => '2000-04-23', 2008 => '2008-03-23',
            2019 => '2019-04-21', 2024 => '2024-03-31', 2025 => '2025-04-20', 2026 => '2026-04-05',
            2038 => '2038-04-25', 2100 => '2100-03-28',
        ];
        foreach ($attese as $anno => $data) {
            $this->assertSame($data, CalendarioAperture::pasqua($anno)->toDateString(), "Pasqua {$anno}");
        }
    }

    public function test_festivita_nazionali_sempre_chiuse(): void
    {
        $calendario = app(CalendarioAperture::class);

        $this->assertSame('Ferragosto', $calendario->motivoChiusura($this->giorno('2027-08-15'), $this->associazione->id));
        $this->assertSame('Lunedì dell\'Angelo', $calendario->motivoChiusura($this->giorno('2026-04-06'), $this->associazione->id));
        $this->assertTrue($calendario->aperto($this->giorno('2026-04-07'), $this->associazione->id));
        $this->assertSame('2026-12-27', $calendario->primoGiornoAperto($this->giorno('2026-12-25'), $this->associazione->id)->toDateString());
    }

    public function test_chiusure_una_volta_ogni_anno_e_a_cavallo_di_capodanno(): void
    {
        Chiusura::query()->create(['associazione_id' => $this->associazione->id, 'titolo' => 'Trasloco', 'data_inizio' => '2026-03-02', 'data_fine' => '2026-03-04']);
        Chiusura::query()->create(['associazione_id' => $this->associazione->id, 'titolo' => 'Ferie invernali', 'data_inizio' => '2025-12-28', 'data_fine' => '2026-01-03', 'ogni_anno' => true]);
        $calendario = app(CalendarioAperture::class);

        $this->assertSame('Trasloco', $calendario->motivoChiusura($this->giorno('2026-03-03'), $this->associazione->id));
        $this->assertTrue($calendario->aperto($this->giorno('2027-03-03'), $this->associazione->id), 'Una tantum: l\'anno dopo è aperto');
        $this->assertSame('Ferie invernali', $calendario->motivoChiusura($this->giorno('2030-12-30'), $this->associazione->id));
        $this->assertSame('Ferie invernali', $calendario->motivoChiusura($this->giorno('2031-01-02'), $this->associazione->id));
        $this->assertSame('2031-01-04', $calendario->primoGiornoAperto($this->giorno('2031-01-02'), $this->associazione->id)->toDateString());
    }

    public function test_chiusura_di_una_sede_e_chiusure_sospese(): void
    {
        $padova = Sede::create(['associazione_id' => $this->associazione->id, 'nome' => 'Padova']);
        $vicenza = Sede::create(['associazione_id' => $this->associazione->id, 'nome' => 'Vicenza']);
        Chiusura::query()->create(['associazione_id' => $this->associazione->id, 'sede_id' => $padova->id, 'titolo' => 'Patrono', 'data_inizio' => '2026-06-13', 'data_fine' => '2026-06-13', 'ogni_anno' => true]);
        Chiusura::query()->create(['associazione_id' => $this->associazione->id, 'titolo' => 'Sospesa', 'data_inizio' => '2026-06-20', 'data_fine' => '2026-06-20', 'attiva' => false]);
        $calendario = app(CalendarioAperture::class);

        $this->assertFalse($calendario->aperto($this->giorno('2026-06-13'), $this->associazione->id, $padova->id));
        $this->assertTrue($calendario->aperto($this->giorno('2026-06-13'), $this->associazione->id, $vicenza->id));
        $this->assertTrue($calendario->aperto($this->giorno('2026-06-20'), $this->associazione->id));
    }

    public function test_chiusure_di_un_altra_associazione_non_contano(): void
    {
        Chiusura::query()->create(['associazione_id' => Associazione::factory()->create()->id, 'titolo' => 'Altrui', 'data_inizio' => '2026-05-11', 'data_fine' => '2026-05-11']);

        $this->assertTrue(app(CalendarioAperture::class)->aperto($this->giorno('2026-05-11'), $this->associazione->id));
    }

    public function test_gestione_dalla_pagina_configurazioni(): void
    {
        $admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->associazione->id]);
        $sessione = ['associazione_id' => $this->associazione->id];

        $this->actingAs($admin)->withSession($sessione)->get(route('configurazioni.index', ['section' => 'chiusure']))
            ->assertOk()->assertSee('Calendario chiusure')->assertSee('Prossime festività nazionali');

        $this->actingAs($admin)->withSession($sessione)->post(route('chiusure.store'), [
            'titolo' => 'Ferie estive', 'data_inizio' => '2026-08-03', 'data_fine' => '2026-08-23', 'ogni_anno' => '1',
        ])->assertRedirect(route('configurazioni.index', ['section' => 'chiusure']));

        $chiusura = Chiusura::query()->withoutGlobalScopes()->sole();
        $this->assertSame($this->associazione->id, $chiusura->associazione_id);
        $this->assertTrue($chiusura->ogni_anno);

        $this->actingAs($admin)->withSession($sessione)->post(route('chiusure.toggle', $chiusura->id));
        $this->assertFalse($chiusura->fresh()->attiva);

        $this->actingAs($admin)->withSession($sessione)->delete(route('chiusure.destroy', $chiusura->id));
        $this->assertSame(0, Chiusura::query()->withoutGlobalScopes()->count());
    }

    public function test_validazioni_e_permessi(): void
    {
        $admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->associazione->id]);
        $sessione = ['associazione_id' => $this->associazione->id];

        $this->actingAs($admin)->withSession($sessione)->post(route('chiusure.store'), ['titolo' => 'X', 'data_inizio' => '2026-08-10', 'data_fine' => '2026-08-01'])
            ->assertSessionHasErrors('data_fine');
        $this->actingAs($admin)->withSession($sessione)->post(route('chiusure.store'), ['titolo' => 'X', 'data_inizio' => '2026-01-01', 'data_fine' => '2027-01-01', 'ogni_anno' => '1'])
            ->assertSessionHasErrors('data_fine');

        $altra = Chiusura::query()->create(['associazione_id' => Associazione::factory()->create()->id, 'titolo' => 'Altrui', 'data_inizio' => '2026-05-11', 'data_fine' => '2026-05-11']);
        $this->actingAs($admin)->withSession($sessione)->delete(route('chiusure.destroy', $altra->id))->assertNotFound();

        $operatore = Utente::factory()->operatore()->create(['associazione_id' => $this->associazione->id]);
        $this->actingAs($operatore)->withSession($sessione)->post(route('chiusure.store'), ['titolo' => 'X', 'data_inizio' => '2026-08-10'])->assertForbidden();
    }
}
