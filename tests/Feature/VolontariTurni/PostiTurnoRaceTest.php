<?php

namespace Tests\Feature\VolontariTurni;

use App\Models\Associazione;
use App\Models\Socio;
use App\Models\Turno;
use App\Models\TurnoAssegnazione;
use App\Models\Utente;
use App\Services\VolontariTurni\CandidaturaService;
use App\Services\VolontariTurni\PostiTurnoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Regressione findings #1 e #7 (CODE-REVIEW-ROUND3): race condition
 * check-then-act sui posti del turno. La logica posti è ora centralizzata
 * in PostiTurnoService (DB::transaction + lockForUpdate sulla riga turni,
 * con ricontrollo di postiDisponibili DENTRO il lock) e condivisa tra
 * auto-candidatura (CandidaturaService) e percorsi admin (TurnoController).
 *
 * Il driver di test (SQLite) serializza le transazioni e non esegue thread
 * realmente paralleli: questi test verificano l'INVARIANTE che il fix
 * garantisce — gli occupati non superano mai `posti` — esercitando il punto
 * di occupazione dell'ultimo slot da più soci distinti e provando che il
 * secondo viene respinto senza creare l'assegnazione di troppo.
 */
class PostiTurnoRaceTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->associazione = Associazione::factory()->create();
        Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->associazione->id]);
    }

    private function attivi(Turno $turno): int
    {
        return TurnoAssegnazione::query()
            ->where('turno_id', $turno->id)
            ->whereIn('stato', [
                TurnoAssegnazione::STATO_PROPOSTO,
                TurnoAssegnazione::STATO_CONFERMATO,
            ])
            ->count();
    }

    public function test_due_soci_distinti_sullultimo_posto_non_sovra_riempiono_il_turno(): void
    {
        $service = app(PostiTurnoService::class);

        $turno = Turno::factory()->create([
            'associazione_id' => $this->associazione->id,
            'posti' => 1,
        ]);

        $socioA = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        $socioB = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        // Primo socio: occupa l'unico posto.
        $service->occupa($turno, $socioA->id, TurnoAssegnazione::STATO_CONFERMATO, richiediApertura: true);

        // Secondo socio sullo stesso (ultimo) posto: respinto.
        $this->expectException(ValidationException::class);

        try {
            $service->occupa($turno, $socioB->id, TurnoAssegnazione::STATO_CONFERMATO, richiediApertura: true);
        } finally {
            // Invariante: mai più assegnazioni attive dei posti disponibili.
            $this->assertSame(1, $this->attivi($turno));
            $this->assertDatabaseMissing('turni_assegnazioni', [
                'turno_id' => $turno->id,
                'socio_id' => $socioB->id,
            ]);
        }
    }

    public function test_invariante_posti_su_molte_candidature_concorrenti_simulate(): void
    {
        $service = app(PostiTurnoService::class);

        $turno = Turno::factory()->create([
            'associazione_id' => $this->associazione->id,
            'posti' => 3,
        ]);

        // 10 soci distinti tentano l'auto-candidatura sullo stesso turno.
        $soci = Socio::factory()->count(10)->create(['associazione_id' => $this->associazione->id]);

        $accettati = 0;

        foreach ($soci as $socio) {
            try {
                $service->occupa($turno, $socio->id, TurnoAssegnazione::STATO_CONFERMATO, richiediApertura: true);
                $accettati++;
            } catch (ValidationException) {
                // posti esauriti: atteso oltre il terzo
            }

            // L'invariante regge a OGNI passo: gli occupati non superano i posti.
            $this->assertLessThanOrEqual(3, $this->attivi($turno));
        }

        $this->assertSame(3, $accettati);
        $this->assertSame(3, $this->attivi($turno));
    }

    public function test_endpoint_assegna_admin_rispetta_il_lock_sui_posti(): void
    {
        $admin = Utente::query()->where('associazione_id', $this->associazione->id)->first();

        $turno = Turno::factory()->create([
            'associazione_id' => $this->associazione->id,
            'posti' => 1,
        ]);
        TurnoAssegnazione::factory()->confermata()->create([
            'associazione_id' => $this->associazione->id,
            'turno_id' => $turno->id,
        ]);

        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($admin)
            ->post("/turni/{$turno->id}/assegna", ['socio_id' => $socio->id])
            ->assertSessionHas('error');

        $this->assertSame(1, $this->attivi($turno));
        $this->assertDatabaseMissing('turni_assegnazioni', [
            'turno_id' => $turno->id,
            'socio_id' => $socio->id,
        ]);
    }

    public function test_conferma_admin_serializzata_non_supera_i_posti(): void
    {
        $admin = Utente::query()->where('associazione_id', $this->associazione->id)->first();

        // posti=1 con una proposta esistente: la conferma di quella proposta
        // non crea posti aggiuntivi, ma resta serializzata sul mutex di turno.
        $turno = Turno::factory()->create([
            'associazione_id' => $this->associazione->id,
            'posti' => 2,
        ]);
        $proposta = TurnoAssegnazione::factory()->create([
            'associazione_id' => $this->associazione->id,
            'turno_id' => $turno->id,
            'stato' => TurnoAssegnazione::STATO_PROPOSTO,
        ]);

        $this->actingAs($admin)
            ->post("/turni/{$turno->id}/assegnazioni/{$proposta->id}/conferma")
            ->assertRedirect();

        $this->assertDatabaseHas('turni_assegnazioni', [
            'id' => $proposta->id,
            'stato' => TurnoAssegnazione::STATO_CONFERMATO,
        ]);
        $this->assertSame(1, $this->attivi($turno));
    }

    public function test_occupa_riusa_una_assegnazione_rinunciata_senza_consumare_posti_extra(): void
    {
        $service = app(PostiTurnoService::class);

        $turno = Turno::factory()->create([
            'associazione_id' => $this->associazione->id,
            'posti' => 1,
        ]);
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $rinunciata = TurnoAssegnazione::factory()->rinunciata()->create([
            'associazione_id' => $this->associazione->id,
            'turno_id' => $turno->id,
            'socio_id' => $socio->id,
        ]);

        $riattivata = $service->occupa($turno, $socio->id, TurnoAssegnazione::STATO_PROPOSTO, richiediApertura: true);

        $this->assertSame($rinunciata->id, $riattivata->id);
        $this->assertSame(TurnoAssegnazione::STATO_PROPOSTO, $riattivata->stato);
        $this->assertSame(1, $this->attivi($turno));
    }

    public function test_la_candidatura_e_lassegna_condividono_lo_stesso_lock_di_turno(): void
    {
        // Self-service occupa l'unico posto; l'admin che prova ad assegnare un
        // altro socio sullo stesso turno viene respinto: i due percorsi si
        // serializzano sullo stesso mutex (PostiTurnoService).
        $candidatura = app(CandidaturaService::class);

        $turno = Turno::factory()->autoConferma()->create([
            'associazione_id' => $this->associazione->id,
            'posti' => 1,
        ]);
        $socioSelf = Socio::factory()->conAccessoAreaSoci()->create([
            'associazione_id' => $this->associazione->id,
            'volontario_attivo' => true,
        ]);

        $candidatura->candidati($turno, $socioSelf);
        $this->assertSame(1, $this->attivi($turno));

        $admin = Utente::query()->where('associazione_id', $this->associazione->id)->first();
        $altro = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($admin)
            ->post("/turni/{$turno->id}/assegna", ['socio_id' => $altro->id])
            ->assertSessionHas('error');

        $this->assertSame(1, $this->attivi($turno));
    }

    public function test_occupa_e_atomica_il_rifiuto_non_lascia_transazioni_pendenti(): void
    {
        // occupa() racchiude check posti + create/update nella propria
        // DB::transaction: sia il successo sia il rifiuto (ValidationException)
        // devono chiudere pulito la transazione, senza livelli pendenti e
        // senza scritture parziali.
        $service = app(PostiTurnoService::class);

        $turno = Turno::factory()->create([
            'associazione_id' => $this->associazione->id,
            'posti' => 1,
        ]);
        $socioA = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        $socioB = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $livelloBase = DB::transactionLevel();

        // Successo: transazione aperta e chiusa, livello ripristinato.
        $service->occupa($turno, $socioA->id, TurnoAssegnazione::STATO_CONFERMATO, richiediApertura: true);
        $this->assertSame($livelloBase, DB::transactionLevel());

        // Rifiuto: la transazione interna fa rollback e non resta pendente.
        try {
            $service->occupa($turno, $socioB->id, TurnoAssegnazione::STATO_CONFERMATO, richiediApertura: true);
            $this->fail('Atteso ValidationException sui posti esauriti.');
        } catch (ValidationException) {
            // atteso
        }

        $this->assertSame($livelloBase, DB::transactionLevel());
        $this->assertSame(1, $this->attivi($turno));
    }
}
