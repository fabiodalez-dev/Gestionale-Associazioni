<?php

namespace Tests\Feature\Tessere;

use App\Models\Associazione;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\Utente;
use App\Services\Tessere\VincoloTessereAnnoService;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Installazione aggiornata con tessere doppie già presenti: la migration
 * del vincolo socio/anno (issue #21) l'ha rinviato. Senza terminale, le
 * pagine Tessere e Aggiornamenti elencano i doppioni e il vincolo si attiva
 * da solo appena sono sistemati dal backoffice (o di notte, dal comando).
 */
class VincoloTessereAnnoTest extends TessereTestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Socio $socio;

    private Tessera $prima;

    private Tessera $doppione;

    protected function setUp(): void
    {
        parent::setUp();

        // Come dopo la migration con doppioni: colonna presente, indice no
        Schema::table('tessere', fn (Blueprint $table) => $table->dropUnique(VincoloTessereAnnoService::INDICE));
        app()->forgetInstance(VincoloTessereAnnoService::class);

        $this->associazione = Associazione::factory()->create();
        $this->socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'cognome' => 'Bianchi',
            'nome' => 'Anna',
        ]);
        $base = ['associazione_id' => $this->associazione->id, 'socio_id' => $this->socio->id, 'anno_validita' => 2026, 'stato' => Tessera::STATO_ATTIVA];
        $this->prima = Tessera::factory()->create($base + ['numero_tessera' => '20260001']);
        $this->doppione = Tessera::factory()->create($base + ['numero_tessera' => '20260001-B']);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance(VincoloTessereAnnoService::class);

        parent::tearDown();
    }

    private function vincolo(): VincoloTessereAnnoService
    {
        return app(VincoloTessereAnnoService::class);
    }

    private function indicePresente(): bool
    {
        return Schema::hasIndex('tessere', VincoloTessereAnnoService::INDICE, 'unique');
    }

    public function test_con_doppioni_il_vincolo_resta_spento_e_i_doppioni_sono_elencati(): void
    {
        $this->assertFalse($this->vincolo()->applicaSePossibile());
        $this->assertFalse($this->indicePresente());

        $doppioni = $this->vincolo()->doppioni();
        $this->assertCount(1, $doppioni);
        $this->assertSame('Bianchi Anna', $doppioni[0]->nominativo);
        $this->assertSame(2026, $doppioni[0]->anno);
        $this->assertSame(2, $doppioni[0]->totale);
        $this->assertStringContainsString('20260001', $doppioni[0]->numeri);
        $this->assertStringContainsString('20260001-B', $doppioni[0]->numeri);

        // Nessuna tessera toccata
        $this->assertSame(Tessera::STATO_ATTIVA, $this->doppione->fresh()->stato);
    }

    public function test_annullare_il_doppione_dal_backoffice_attiva_subito_il_vincolo(): void
    {
        $this->doppione->update(['stato' => Tessera::STATO_ANNULLATA]);

        $this->assertTrue($this->indicePresente());
        $this->assertSame([], $this->vincolo()->doppioni()->all());
    }

    public function test_eliminare_il_doppione_attiva_subito_il_vincolo(): void
    {
        $this->doppione->delete();

        $this->assertTrue($this->indicePresente());
    }

    public function test_un_salvataggio_che_non_tocca_socio_anno_o_stato_non_tenta_il_vincolo(): void
    {
        // Il secondo doppione ha ancora la colonna del vincolo valorizzata:
        // un salvataggio qualsiasi (es. il QR alla stampa) non deve fallire
        $this->doppione->update(['qr_code_url' => 'https://example.test/qr']);

        $this->assertFalse($this->indicePresente());
    }

    public function test_la_pagina_tessere_elenca_i_doppioni_e_dopo_la_correzione_il_vincolo_e_attivo(): void
    {
        $admin = $this->creaAdmin($this->associazione);

        $this->actingAs($admin)
            ->withSession(['associazione_id' => $this->associazione->id])
            ->get(route('tessere.index'))
            ->assertOk()
            ->assertSee('Tessere doppie da sistemare')
            ->assertSee('Bianchi Anna')
            ->assertSee('20260001-B');

        // Correzione fuori dal modello (es. riga cancellata da un'altra
        // installazione o da SQL): la pagina non elenca più il doppione ma
        // non applica il vincolo (hardening audit run-1: niente DDL né update
        // cross-tenant a ogni richiesta dello staff); lo fa il comando notturno
        Tessera::allTenants()->whereKey($this->doppione->id)->toBase()->delete();

        $this->actingAs($admin)
            ->withSession(['associazione_id' => $this->associazione->id])
            ->get(route('tessere.index'))
            ->assertOk()
            ->assertDontSee('Tessere doppie da sistemare');
        $this->assertFalse($this->indicePresente());

        $this->artisan('tessere:applica-vincolo-anno')->assertSuccessful();
        $this->assertTrue($this->indicePresente());
    }

    /**
     * Hardening (audit run-1): la pagina Tessere legge soltanto. Prima a ogni
     * apertura eseguiva sistema(): ALTER TABLE e UPDATE su tutta la tabella,
     * cross-tenant, da parte di un admin di associazione.
     */
    public function test_la_pagina_tessere_non_esegue_ddl_ne_update_sulle_tessere(): void
    {
        $admin = $this->creaAdmin($this->associazione);
        // Doppione tolto da SQL: con il vecchio comportamento la pagina
        // applicherebbe subito il vincolo
        $this->eliminaDoppioneIniziale();
        DB::table('tessere')->where('id', $this->prima->id)->update(['anno_non_annullata' => null]);

        $scritture = [];
        DB::listen(function ($query) use (&$scritture): void {
            $sql = self::sqlNormalizzato($query->sql);
            if (str_contains($sql, 'tessere') && ! str_starts_with($sql, 'select') && ! str_starts_with($sql, 'pragma')) {
                $scritture[] = $sql;
            }
        });

        $this->actingAs($admin)
            ->withSession(['associazione_id' => $this->associazione->id])
            ->get(route('tessere.index'))
            ->assertOk()
            ->assertDontSee('Tessere doppie da sistemare');

        $this->assertSame([], $scritture);
        $this->assertFalse($this->indicePresente());
        $this->assertNull(Tessera::allTenants()->find($this->prima->id)->getAttribute('anno_non_annullata'));
    }

    public function test_la_pagina_tessere_non_mostra_i_doppioni_dei_soci_di_altre_associazioni(): void
    {
        // Hardening (audit run-1): l'elenco è limitato ai soci dell'associazione
        // corrente; prima la pagina contava anche i casi degli altri tenant
        // ("Altri N casi riguardano soci che non vedi da qui")
        $altra = Associazione::factory()->create();
        $admin = $this->creaAdmin($altra);

        $this->actingAs($admin)
            ->withSession(['associazione_id' => $altra->id])
            ->get(route('tessere.index'))
            ->assertOk()
            ->assertDontSee('Tessere doppie da sistemare')
            ->assertDontSee('Bianchi Anna');
    }

    public function test_la_pagina_aggiornamenti_avvisa_il_super_admin(): void
    {
        $this->seed(RolesSeeder::class);
        $super = Utente::factory()->superAdmin()->create();
        $super->assignRole('super_admin');

        $this->actingAs($super)
            ->get(route('aggiornamenti.index'))
            ->assertOk()
            ->assertSee('1 socio ha più tessere valide nello stesso anno', false);
    }

    public function test_il_comando_notturno_elenca_i_doppioni_poi_applica_il_vincolo(): void
    {
        $this->artisan('tessere:applica-vincolo-anno')
            ->expectsOutputToContain('fuori dal vincolo')
            ->expectsOutputToContain('Bianchi Anna (2026)')
            ->assertSuccessful();
        $this->assertFalse($this->indicePresente());

        Tessera::allTenants()->whereKey($this->doppione->id)->toBase()->update(['stato' => Tessera::STATO_ANNULLATA]);

        $this->artisan('tessere:applica-vincolo-anno')
            ->expectsOutputToContain('Vincolo attivo: nessuna tessera doppia')
            ->assertSuccessful();
        $this->assertTrue($this->indicePresente());

        // La colonna del vincolo è riallineata anche per l'update fuori dal modello
        $this->assertNull(Tessera::allTenants()->find($this->doppione->id)->getAttribute('anno_non_annullata'));
    }

    public function test_doppione_nato_durante_l_alter_table_non_mette_in_pausa_il_vincolo(): void
    {
        Cache::forget('tessere:vincolo-socio-anno:pausa');
        $this->eliminaDoppioneIniziale();

        // Un doppione nasce fra il controllo e l'ALTER TABLE: l'indice fallisce
        $inserito = false;
        DB::listen(function ($query) use (&$inserito): void {
            if (! $inserito && str_starts_with(self::sqlNormalizzato($query->sql), 'update tessere set anno_non_annullata = anno_validita')) {
                $inserito = true;
                $this->inserisciTesseraGrezza('20260001-C');
            }
        });

        $this->assertFalse($this->vincolo()->applicaSePossibile());
        $this->assertTrue($inserito);
        $this->assertFalse(Cache::has('tessere:vincolo-socio-anno:pausa'));

        // Doppione sistemato: il vincolo si applica subito, senza pausa
        DB::table('tessere')->where('numero_tessera', '20260001-C')->delete();
        $this->assertTrue($this->vincolo()->applicaSePossibile());
        $this->assertTrue($this->indicePresente());
    }

    public function test_dopo_un_altro_errore_del_ddl_si_riprova_solo_dopo_una_pausa(): void
    {
        Cache::forget('tessere:vincolo-socio-anno:pausa');
        $this->eliminaDoppioneIniziale();

        // Lock sulla tabella o permessi mancanti: errore del DDL che non
        // dipende dai doppioni
        $ddl = 0;
        $fallisci = true;
        DB::listen(function ($query) use (&$ddl): void {
            if (self::eCreazioneIndice($query->sql)) {
                $ddl++;
            }
        });
        DB::connection()->beforeExecuting(function (string $sql) use (&$fallisci): void {
            if ($fallisci && self::eCreazioneIndice($sql)) {
                throw new QueryException('sqlite', $sql, [], new \RuntimeException('database table is locked'));
            }
        });

        $this->assertFalse($this->vincolo()->applicaSePossibile());
        $this->assertTrue(Cache::has('tessere:vincolo-socio-anno:pausa'));

        // Durante la pausa nessun nuovo ALTER TABLE, anche se ora riuscirebbe
        $fallisci = false;
        $this->assertFalse($this->vincolo()->applicaSePossibile());
        $this->assertSame(0, $ddl);

        // Finita la pausa si applica
        Cache::forget('tessere:vincolo-socio-anno:pausa');
        $this->assertTrue($this->vincolo()->applicaSePossibile());
        $this->assertSame(1, $ddl);
        $this->assertTrue($this->indicePresente());
    }

    /** SQL in minuscolo senza virgolette né apici inversi: uguale su SQLite e MariaDB. */
    private static function sqlNormalizzato(string $sql): string
    {
        return strtolower(str_replace(['"', '`'], '', $sql));
    }

    /** Creazione dell'indice unique: CREATE UNIQUE INDEX (SQLite) o ALTER TABLE ... ADD UNIQUE (MariaDB). */
    private static function eCreazioneIndice(string $sql): bool
    {
        $sql = self::sqlNormalizzato($sql);

        return str_starts_with($sql, 'create unique index')
            || (str_starts_with($sql, 'alter table tessere') && str_contains($sql, 'add unique'));
    }

    /** Toglie il doppione del setUp senza passare dal modello (niente vincolo automatico). */
    private function eliminaDoppioneIniziale(): void
    {
        DB::table('tessere')->where('id', $this->doppione->id)->delete();
        $this->assertFalse($this->indicePresente());
    }

    private function inserisciTesseraGrezza(string $numero, ?int $annoVincolo = 2026): void
    {
        DB::table('tessere')->insert([
            'id' => (string) Str::uuid(),
            'associazione_id' => $this->associazione->id,
            'socio_id' => $this->socio->id,
            'numero_tessera' => $numero,
            'anno_validita' => 2026,
            'anno_non_annullata' => $annoVincolo,
            'data_emissione' => '2026-01-10',
            'data_scadenza' => '2026-12-31',
            'tipo_scadenza' => Tessera::TIPO_SCADENZA_SOLARE,
            'stato' => Tessera::STATO_ATTIVA,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_i_doppioni_dei_soci_nel_cestino_sono_indicati_a_parte(): void
    {
        $admin = $this->creaAdmin($this->associazione);
        $this->socio->delete();

        $this->actingAs($admin)
            ->withSession(['associazione_id' => $this->associazione->id])
            ->get(route('tessere.index'))
            ->assertOk()
            ->assertSee('Tessere doppie da sistemare')
            ->assertSee('1 caso riguarda un socio nel cestino', false)
            ->assertDontSee('che non vedi da qui');
        $this->assertFalse($this->indicePresente());
    }

    public function test_doppioni_importati_con_il_vincolo_gia_attivo_sono_elencati_e_poi_rientrano_nel_vincolo(): void
    {
        // Installazione nuova: indice presente. L'import legacy lascia i
        // doppioni fuori dal vincolo, con la colonna vuota
        DB::table('tessere')->where('id', $this->doppione->id)->delete();
        $this->assertTrue($this->vincolo()->applicaSePossibile());
        DB::table('tessere')->where('id', $this->prima->id)->update(['anno_non_annullata' => null]);
        $this->inserisciTesseraGrezza('20260001-L', annoVincolo: null);

        $admin = $this->creaAdmin($this->associazione);
        $this->actingAs($admin)
            ->withSession(['associazione_id' => $this->associazione->id])
            ->get(route('tessere.index'))
            ->assertOk()
            ->assertSee('Tessere doppie da sistemare')
            ->assertSee('20260001-L');

        // La segreteria annulla la copia in più: la tessera rimasta rientra
        Tessera::allTenants()->where('numero_tessera', '20260001-L')->firstOrFail()
            ->update(['stato' => Tessera::STATO_ANNULLATA]);

        $this->assertSame(2026, Tessera::allTenants()->find($this->prima->id)->getAttribute('anno_non_annullata'));
        $this->actingAs($admin)
            ->withSession(['associazione_id' => $this->associazione->id])
            ->get(route('tessere.index'))
            ->assertOk()
            ->assertDontSee('Tessere doppie da sistemare');
    }

    public function test_un_doppione_con_associazione_incoerente_conta_come_per_l_indice(): void
    {
        // Riga legacy con associazione diversa da quella del socio: l'indice
        // (socio, anno) la considera doppia, quindi anche l'elenco
        $altra = Associazione::factory()->create();
        DB::table('tessere')->where('id', $this->doppione->id)->update(['associazione_id' => $altra->id]);

        $this->assertCount(1, $this->vincolo()->doppioni());
        $this->assertFalse($this->vincolo()->applicaSePossibile());
        $this->assertFalse($this->indicePresente());

        // Il filtro per associazione segue il socio (chiave dell'indice), non
        // la riga incoerente: lo vede chi gestisce Bianchi, non l'altra
        $this->assertCount(1, $this->vincolo()->doppioni($this->associazione->id));
        $this->assertCount(0, $this->vincolo()->doppioni($altra->id));
    }

    public function test_la_pagina_non_mostra_i_numeri_delle_tessere_di_un_altra_associazione(): void
    {
        // Riga legacy incoerente: tessera del socio di A registrata sotto B
        $altra = Associazione::factory()->create();
        DB::table('tessere')->where('id', $this->doppione->id)->update([
            'associazione_id' => $altra->id,
            'numero_tessera' => 'B-SEGRETA',
        ]);
        $admin = $this->creaAdmin($this->associazione);

        $this->actingAs($admin)
            ->withSession(['associazione_id' => $this->associazione->id])
            ->get(route('tessere.index'))
            ->assertOk()
            ->assertSee('Bianchi Anna')
            ->assertSee('20260001')
            ->assertDontSee('B-SEGRETA')
            ->assertSee("più 1 registrata sotto un'altra associazione", false);
    }

    public function test_dopo_la_modifica_di_una_tessera_il_riallineamento_riguarda_solo_il_suo_socio(): void
    {
        DB::table('tessere')->where('id', $this->doppione->id)->delete();
        $this->assertTrue($this->vincolo()->applicaSePossibile());

        // Tessera di un altro socio rimasta fuori dal vincolo: non è affare
        // della modifica di una tessera di Bianchi
        $altro = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        $fuori = Tessera::factory()->create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $altro->id,
            'anno_validita' => 2026,
            'stato' => Tessera::STATO_ATTIVA,
        ]);
        DB::table('tessere')->where('id', $fuori->id)->update(['anno_non_annullata' => null]);
        DB::table('tessere')->where('id', $this->prima->id)->update(['anno_non_annullata' => null]);

        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = self::sqlNormalizzato($query->sql);
        });
        $this->prima->update(['stato' => Tessera::STATO_SOSPESA]);

        // La tessera modificata rientra (calcolo del modello), l'altra no: la
        // ricerca delle tessere fuori vincolo è filtrata sul socio
        $this->assertSame(2026, Tessera::allTenants()->find($this->prima->id)->getAttribute('anno_non_annullata'));
        $this->assertNull(Tessera::allTenants()->find($fuori->id)->getAttribute('anno_non_annullata'));
        $ricerche = array_filter($sql, fn (string $q): bool => str_starts_with($q, 'select') && str_contains($q, 'anno_non_annullata is null'));
        $this->assertNotEmpty($ricerche);
        foreach ($ricerche as $ricerca) {
            $this->assertStringContainsString('socio_id in', $ricerca);
        }

        // Il comando notturno (scansione completa) riporta dentro anche l'altra
        $this->vincolo()->sistema();
        $this->assertSame(2026, Tessera::allTenants()->find($fuori->id)->getAttribute('anno_non_annullata'));
    }
}
