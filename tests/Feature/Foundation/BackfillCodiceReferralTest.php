<?php

namespace Tests\Feature\Foundation;

use App\Models\Associazione;
use App\Models\Socio;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regressione finding #6: la migration di backfill popola codice_referral su
 * tutti i soci preesistenti (creati prima dell'introduzione del campo, quindi
 * con codice_referral = NULL), riusando la logica univoca del model.
 */
class BackfillCodiceReferralTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_06_13_110000_backfill_codice_referral_soci.php');
    }

    /**
     * Simula soci preesistenti azzerando il codice generato in automatico,
     * bypassando gli eventi del model (come dopo un upgrade in-place).
     */
    /**
     * @param  iterable<Socio>  $soci
     */
    private function svuotaCodici(iterable $soci): void
    {
        DB::table('soci')
            ->whereIn('id', collect($soci)->pluck('id')->all())
            ->update(['codice_referral' => null]);
    }

    public function test_backfill_popola_tutti_i_soci_con_codice_referral_null(): void
    {
        $assoc = Associazione::factory()->create();
        $altra = Associazione::factory()->create();

        $soci = Socio::factory()->count(5)->create(['associazione_id' => $assoc->id]);
        $sociAltroTenant = Socio::factory()->count(3)->create(['associazione_id' => $altra->id]);
        $softDeleted = Socio::factory()->create(['associazione_id' => $assoc->id]);
        $softDeleted->delete();

        $this->svuotaCodici($soci->concat($sociAltroTenant)->push($softDeleted));

        // Sanity: dopo lo svuotamento tutti hanno codice NULL.
        $this->assertSame(
            9,
            DB::table('soci')->whereNull('codice_referral')->count(),
            'precondizione: tutti i soci devono partire senza codice referral'
        );

        $this->migration()->up();

        // Nessun socio (nemmeno soft-deleted) resta senza codice referral.
        $this->assertSame(
            0,
            DB::table('soci')->whereNull('codice_referral')->count(),
            'dopo il backfill nessun socio deve avere codice_referral NULL'
        );

        // I codici generati sono univoci a livello globale.
        $codici = DB::table('soci')->pluck('codice_referral');
        $this->assertCount(
            $codici->count(),
            $codici->unique(),
            'i codici referral generati devono essere univoci'
        );
    }

    public function test_backfill_e_idempotente_e_non_sovrascrive_codici_esistenti(): void
    {
        $assoc = Associazione::factory()->create();

        // Socio con codice già valorizzato (non deve essere toccato).
        $conCodice = Socio::factory()->create(['associazione_id' => $assoc->id]);
        $codiceOriginale = $conCodice->codice_referral;
        $this->assertNotNull($codiceOriginale);

        // Socio "preesistente" senza codice.
        $senzaCodice = Socio::factory()->create(['associazione_id' => $assoc->id]);
        $this->svuotaCodici([$senzaCodice]);

        $this->migration()->up();

        // Il codice esistente non è stato modificato.
        $this->assertSame(
            $codiceOriginale,
            DB::table('soci')->where('id', $conCodice->id)->value('codice_referral')
        );

        // Quello mancante è stato popolato.
        $codiceBackfill = DB::table('soci')->where('id', $senzaCodice->id)->value('codice_referral');
        $this->assertNotNull($codiceBackfill);

        // Re-run: idempotente, nessun codice cambia, nessun NULL residuo.
        $this->migration()->up();

        $this->assertSame(
            $codiceBackfill,
            DB::table('soci')->where('id', $senzaCodice->id)->value('codice_referral')
        );
        $this->assertSame(0, DB::table('soci')->whereNull('codice_referral')->count());
    }
}
