<?php

namespace Tests\Feature\Rinnovi;

use App\Models\Associazione;
use App\Models\PromemoriaInviato;
use App\Models\Socio;
use App\Models\Tessera;
use App\Notifications\Rinnovi\PromemoriaRinnovoNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

/**
 * f.3 — Promemoria rinnovo multi-step (comando rinnovi:promemoria):
 * step configurabili per tenant, tracking anti-duplicazione in
 * promemoria_inviati.
 */
class PromemoriaRinnoviTest extends RinnoviTestCase
{
    use RefreshDatabase;

    private function creaTessera(Socio $socio, int $giorniAllaScadenza): Tessera
    {
        return Tessera::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'data_scadenza' => now()->addDays($giorniAllaScadenza)->toDateString(),
        ]);
    }

    public function test_invia_promemoria_per_tessera_in_finestra_e_traccia_gli_step(): void
    {
        Notification::fake();
        $this->configuraTenant();
        $socio = $this->creaSocio();
        $tessera = $this->creaTessera($socio, 10); // dentro step 30 e 15, fuori da 3

        $this->artisan('rinnovi:promemoria')->assertSuccessful();

        Notification::assertSentTo($socio, PromemoriaRinnovoNotification::class, function ($notification) use ($tessera) {
            return $notification->tessera->id === $tessera->id && $notification->step === 15;
        });
        Notification::assertCount(1);

        $steps = PromemoriaInviato::allTenants()
            ->where('socio_id', $socio->id)
            ->where('tessera_id', $tessera->id)
            ->where('tipo', PromemoriaInviato::TIPO_RINNOVO_TESSERA)
            ->pluck('step')->map(fn ($s) => (int) $s)->sort()->values()->all();

        $this->assertSame([15, 30], $steps);
    }

    public function test_seconda_esecuzione_non_duplica(): void
    {
        Notification::fake();
        $this->configuraTenant();
        $socio = $this->creaSocio();
        $this->creaTessera($socio, 10);

        $this->artisan('rinnovi:promemoria')->assertSuccessful();
        $this->artisan('rinnovi:promemoria')->assertSuccessful();

        Notification::assertCount(1);
        $this->assertSame(2, PromemoriaInviato::allTenants()->count());
    }

    public function test_nuovo_step_quando_la_scadenza_si_avvicina(): void
    {
        Notification::fake();
        $this->configuraTenant();
        $socio = $this->creaSocio();
        $tessera = $this->creaTessera($socio, 20); // step 30

        $this->artisan('rinnovi:promemoria')->assertSuccessful();
        Notification::assertCount(1);

        // La scadenza si avvicina: entra negli step 15 e 3
        $tessera->forceFill(['data_scadenza' => now()->addDays(2)->toDateString()])->save();

        $this->artisan('rinnovi:promemoria')->assertSuccessful();

        Notification::assertCount(2);
        $this->assertSame(3, PromemoriaInviato::allTenants()->count()); // step 30, 15, 3
    }

    public function test_step_personalizzati_per_tenant(): void
    {
        Notification::fake();
        $this->configuraTenant();
        $this->associazione->forceFill(['promemoria_rinnovo_config' => [60]])->save();

        $socio = $this->creaSocio();
        $this->creaTessera($socio, 50); // dentro 60, fuori dai default

        $this->artisan('rinnovi:promemoria')->assertSuccessful();

        Notification::assertSentTo($socio, PromemoriaRinnovoNotification::class, fn ($n) => $n->step === 60);
        $this->assertSame(1, PromemoriaInviato::allTenants()->where('step', 60)->count());
    }

    public function test_nessun_promemoria_fuori_finestra_o_per_socio_non_attivo(): void
    {
        Notification::fake();
        $this->configuraTenant();

        // Fuori finestra (scadenza lontana)
        $this->creaTessera($this->creaSocio(), 90);

        // Socio sospeso in finestra
        $sospeso = $this->creaSocio(['stato' => Socio::STATO_SOSPESO]);
        $this->creaTessera($sospeso, 10);

        // Tessera già scaduta
        $scaduto = $this->creaSocio();
        Tessera::factory()->create([
            'socio_id' => $scaduto->id,
            'associazione_id' => $this->associazione->id,
            'data_scadenza' => now()->subDays(5)->toDateString(),
        ]);

        $this->artisan('rinnovi:promemoria')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertSame(0, PromemoriaInviato::allTenants()->count());
    }

    public function test_nessun_promemoria_se_gia_rinnovato_con_tessera_successiva(): void
    {
        Notification::fake();
        $this->configuraTenant();
        $socio = $this->creaSocio();

        // Tessera in scadenza dell'anno scorso + tessera nuova dell'anno corrente
        Tessera::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'anno_validita' => (int) now()->year - 1,
            'data_scadenza' => now()->addDays(10)->toDateString(),
        ]);
        Tessera::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'anno_validita' => (int) now()->year,
            'data_scadenza' => now()->endOfYear()->toDateString(),
        ]);

        $this->artisan('rinnovi:promemoria')->assertSuccessful();

        // L'unica notifica possibile sarebbe per la tessera vecchia → soppressa.
        // (La tessera nuova scade a fine anno: se rientra in finestra è legittima.)
        $vecchia = PromemoriaInviato::allTenants()
            ->whereIn('tessera_id', Tessera::allTenants()->where('anno_validita', (int) now()->year - 1)->pluck('id'))
            ->count();

        $this->assertSame(0, $vecchia);
    }

    public function test_opzione_associazione_limita_il_tenant(): void
    {
        Notification::fake();
        $this->configuraTenant();
        $socioA = $this->creaSocio();
        $this->creaTessera($socioA, 10);

        $altra = Associazione::factory()->create();
        $socioB = Socio::factory()->create(['associazione_id' => $altra->id]);
        Tessera::factory()->create([
            'socio_id' => $socioB->id,
            'associazione_id' => $altra->id,
            'data_scadenza' => now()->addDays(10)->toDateString(),
        ]);

        $this->artisan('rinnovi:promemoria', ['--associazione' => $altra->id])->assertSuccessful();

        Notification::assertNotSentTo($socioA, PromemoriaRinnovoNotification::class);
        Notification::assertSentTo($socioB, PromemoriaRinnovoNotification::class);

        $this->assertSame(0, PromemoriaInviato::allTenants()->where('associazione_id', $this->associazione->id)->count());
        $this->assertGreaterThan(0, PromemoriaInviato::allTenants()->where('associazione_id', $altra->id)->count());
    }
}
