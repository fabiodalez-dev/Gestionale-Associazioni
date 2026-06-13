<?php

namespace Tests\Feature\Struttura;

use App\Models\Adempimento;
use App\Models\AdempimentoAnno;
use App\Models\Associazione;
use App\Models\PromemoriaInviato;
use App\Models\Utente;
use App\Notifications\Struttura\PromemoriaAdempimentoNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Adempimenti ricorrenti (f.20): CRUD + seed suggerito, checklist per anno
 * (adempimenti_anni) dentro lo Scadenzario, comando adempimenti:promemoria.
 * Permessi (anagrafica struttura.*, checklist scadenze.update) e tenant.
 */
class AdempimentiTest extends TestCase
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

    private function actAsAdmin(): self
    {
        $this->actingAs($this->admin)->withSession(['associazione_id' => $this->associazione->id]);

        return $this;
    }

    public function test_admin_crea_adempimento(): void
    {
        $this->actAsAdmin()->post(route('adempimenti.store'), [
            'titolo' => 'Rinnovo assicurazione',
            'mese_scadenza' => 1,
            'descrizione' => 'Polizza volontari',
            'attivo' => '1',
        ])->assertRedirect(route('adempimenti.index'));

        $adempimento = Adempimento::query()->first();
        $this->assertNotNull($adempimento);
        $this->assertSame('Rinnovo assicurazione', $adempimento->titolo);
        $this->assertSame(1, $adempimento->mese_scadenza);
        $this->assertTrue($adempimento->attivo);
        $this->assertSame($this->associazione->id, $adempimento->associazione_id);
    }

    public function test_validazione_mese_fuori_range(): void
    {
        $this->actAsAdmin()
            ->from(route('adempimenti.index'))
            ->post(route('adempimenti.store'), ['titolo' => 'X', 'mese_scadenza' => 13])
            ->assertSessionHasErrors('mese_scadenza');

        $this->assertSame(0, Adempimento::query()->count());
    }

    public function test_operatore_non_gestisce_anagrafica_ma_vede_checklist(): void
    {
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        // Anagrafica: struttura.* → operatore può consultare (scadenze.view) ma non creare
        $this->actingAs($operatore)
            ->withSession(['associazione_id' => $this->associazione->id])
            ->post(route('adempimenti.store'), ['titolo' => 'Y', 'mese_scadenza' => 2])
            ->assertForbidden();
    }

    public function test_seed_crea_adempimenti_suggeriti_idempotente(): void
    {
        $this->actAsAdmin()->post(route('adempimenti.seed'))->assertRedirect(route('adempimenti.index'));

        $this->assertSame(count(Adempimento::SUGGERITI), Adempimento::query()->count());

        // Seconda esecuzione: nessun duplicato
        $this->actAsAdmin()->post(route('adempimenti.seed'));
        $this->assertSame(count(Adempimento::SUGGERITI), Adempimento::query()->count());
    }

    public function test_toggle_checklist_annuale(): void
    {
        $adempimento = Adempimento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'mese_scadenza' => 4,
        ]);
        $anno = (int) now()->format('Y');

        // Prima spunta: crea la riga completata
        $this->actAsAdmin()
            ->from(route('scadenzario.index'))
            ->post(route('adempimenti.toggle-anno', $adempimento), ['anno' => $anno])
            ->assertRedirect(route('scadenzario.index'));

        $riga = AdempimentoAnno::query()->where('adempimento_id', $adempimento->id)->where('anno', $anno)->first();
        $this->assertNotNull($riga);
        $this->assertNotNull($riga->completato_at);
        $this->assertSame($this->admin->id, $riga->completato_da);

        // Secondo toggle: riapre (completato_at null)
        $this->actAsAdmin()
            ->from(route('scadenzario.index'))
            ->post(route('adempimenti.toggle-anno', $adempimento), ['anno' => $anno]);

        $this->assertNull($riga->refresh()->completato_at);
    }

    public function test_operatore_puo_spuntare_checklist(): void
    {
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $adempimento = Adempimento::factory()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $anno = (int) now()->format('Y');

        $this->actingAs($operatore)
            ->withSession(['associazione_id' => $this->associazione->id])
            ->from(route('scadenzario.index'))
            ->post(route('adempimenti.toggle-anno', $adempimento), ['anno' => $anno])
            ->assertRedirect(route('scadenzario.index'));

        $this->assertSame(1, AdempimentoAnno::query()->count());
    }

    public function test_checklist_appare_nello_scadenzario(): void
    {
        Adempimento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Bilancio annuale',
            'mese_scadenza' => 4,
            'attivo' => true,
        ]);

        $this->actAsAdmin()
            ->get(route('scadenzario.index'))
            ->assertOk()
            ->assertSee('Adempimenti ricorrenti')
            ->assertSee('Bilancio annuale');
    }

    public function test_isolamento_tenant_su_toggle(): void
    {
        $altra = Associazione::factory()->create();
        $adempimentoAltrui = Adempimento::factory()->create(['associazione_id' => $altra->id]);

        // Il global scope nasconde l'adempimento altrui → 404 sul route model binding
        $this->actAsAdmin()
            ->post(route('adempimenti.toggle-anno', $adempimentoAltrui), ['anno' => (int) now()->format('Y')])
            ->assertNotFound();
    }

    public function test_comando_promemoria_invia_solo_per_adempimenti_non_completati_del_mese(): void
    {
        Notification::fake();

        $mese = (int) now()->format('n');
        $anno = (int) now()->format('Y');

        // In scadenza questo mese, non completato → notifica
        $daFare = Adempimento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Da fare',
            'mese_scadenza' => $mese,
            'attivo' => true,
        ]);

        // In scadenza questo mese ma già completato → nessuna notifica
        $fatto = Adempimento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'mese_scadenza' => $mese,
            'attivo' => true,
        ]);
        AdempimentoAnno::factory()->completato()->create([
            'associazione_id' => $this->associazione->id,
            'adempimento_id' => $fatto->id,
            'anno' => $anno,
        ]);

        // Mese diverso → nessuna notifica
        Adempimento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'mese_scadenza' => ($mese % 12) + 1,
            'attivo' => true,
        ]);

        // Disattivato nel mese → nessuna notifica
        Adempimento::factory()->nonAttivo()->create([
            'associazione_id' => $this->associazione->id,
            'mese_scadenza' => $mese,
        ]);

        $this->artisan('adempimenti:promemoria')->assertSuccessful();

        Notification::assertSentTo($this->admin, PromemoriaAdempimentoNotification::class, function ($notification) use ($daFare) {
            return $notification->adempimento->id === $daFare->id;
        });
        Notification::assertSentTimes(PromemoriaAdempimentoNotification::class, 1);
    }

    public function test_comando_promemoria_idempotente_su_re_run(): void
    {
        Notification::fake();

        $mese = (int) now()->format('n');

        $adempimento = Adempimento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Rinnovo polizza',
            'mese_scadenza' => $mese,
            'attivo' => true,
        ]);

        // Primo run: invia e traccia in promemoria_inviati
        $this->artisan('adempimenti:promemoria')->assertSuccessful();

        $this->assertDatabaseHas('promemoria_inviati', [
            'riferimento_id' => $adempimento->id,
            'tipo' => 'adempimento',
            'socio_id' => null,
        ]);
        $this->assertSame(1, PromemoriaInviato::where('riferimento_id', $adempimento->id)
            ->where('tipo', 'adempimento')->count());

        // Re-run nello stesso mese: nessun nuovo invio (idempotenza #14)
        $this->artisan('adempimenti:promemoria')->assertSuccessful();

        $this->assertSame(1, PromemoriaInviato::where('riferimento_id', $adempimento->id)
            ->where('tipo', 'adempimento')->count());
        Notification::assertSentToTimes($this->admin, PromemoriaAdempimentoNotification::class, 1);
    }

    public function test_comando_promemoria_rifiuta_mese_invalido(): void
    {
        $this->artisan('adempimenti:promemoria --mese=13')->assertFailed();
    }
}
