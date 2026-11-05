<?php

namespace Tests\Feature\Tessere;

use App\Models\Associazione;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\TesseraCounter;
use App\Services\Soci\TesseraAutomaticaService;
use App\Services\Tessere\TesseraGiaPresenteException;
use App\Services\Tessere\TesseraIssuer;
use App\Services\Tessere\TesseraQrService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;

/**
 * Issue #21: una sola tessera non annullata per socio e anno (vincolo
 * unique), emissione massiva con una transazione per socio e violazione del
 * vincolo trattata come "già presente" in tutti i percorsi di emissione.
 */
class UnicitaTesseraAnnoTest extends TessereTestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_11_05_120100_add_unique_socio_anno_to_tessere_table.php';

    private function issuer(): TesseraIssuer
    {
        return app(TesseraIssuer::class);
    }

    /**
     * Riga tessera inserita "da un altro processo": query diretta, senza
     * passare da TesseraIssuer né dagli eventi del modello.
     */
    private function inserisciTesseraGrezza(Socio $socio, int $anno, string $numero, string $stato = Tessera::STATO_ATTIVA): void
    {
        // Prima della migration la colonna del vincolo non esiste
        $vincolo = Schema::hasColumn('tessere', 'anno_non_annullata')
            ? ['anno_non_annullata' => $stato === Tessera::STATO_ANNULLATA ? null : $anno]
            : [];

        DB::table('tessere')->insert($vincolo + [
            'id' => (string) Str::uuid(),
            'socio_id' => $socio->id,
            'associazione_id' => $socio->associazione_id,
            'numero_tessera' => $numero,
            'anno_validita' => $anno,
            'data_emissione' => now()->toDateString(),
            'data_scadenza' => $anno.'-12-31',
            'tipo_scadenza' => Tessera::TIPO_SCADENZA_SOLARE,
            'stato' => $stato,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function migration(): object
    {
        return require database_path('migrations/'.self::MIGRATION);
    }

    // ------------------------------------------------------------------
    // Vincolo e migration
    // ------------------------------------------------------------------

    public function test_il_database_rifiuta_una_seconda_tessera_non_annullata_dello_stesso_socio_e_anno(): void
    {
        $socio = Socio::factory()->create();
        $base = ['socio_id' => $socio->id, 'anno_validita' => 2030];

        // Tessere annullate (perse) e il loro duplicato convivono
        $persa = Tessera::factory()->create($base + ['numero_tessera' => 'A-1', 'stato' => Tessera::STATO_ANNULLATA]);
        Tessera::factory()->create($base + ['numero_tessera' => 'A-2', 'stato' => Tessera::STATO_ANNULLATA]);
        $valida = Tessera::factory()->create($base + ['numero_tessera' => 'A-3', 'stato' => Tessera::STATO_SOSPESA]);
        $this->assertNull($persa->fresh()->anno_non_annullata);
        $this->assertSame(2030, $valida->fresh()->anno_non_annullata);
        $this->assertArrayNotHasKey('anno_non_annullata', $valida->fresh()->toArray());

        // Annullata e poi "riattivata" a mano: il vincolo la ritrova
        try {
            $persa->update(['stato' => Tessera::STATO_ATTIVA]);
            $this->fail('Attesa violazione del vincolo unique');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(Tessera::STATO_ANNULLATA, $persa->fresh()->stato);
        }

        $this->expectException(UniqueConstraintViolationException::class);

        Tessera::factory()->create($base + ['numero_tessera' => 'A-4', 'stato' => Tessera::STATO_ATTIVA]);
    }

    public function test_migration_con_doppioni_non_aggiunge_il_vincolo_e_non_tocca_i_dati_poi_lo_aggiunge_quando_sono_sistemati(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->assertFalse(Schema::hasIndex('tessere', 'uq_tessere_socio_anno'));
        $this->assertFalse(Schema::hasColumn('tessere', 'anno_non_annullata'));

        $socio = Socio::factory()->create();
        $this->inserisciTesseraGrezza($socio, 2030, 'A-1');
        $this->inserisciTesseraGrezza($socio, 2030, 'A-2');
        // Annullata + duplicato dello stesso anno: non è un doppione per il vincolo
        $this->inserisciTesseraGrezza($socio, 2031, 'A-3', Tessera::STATO_ANNULLATA);
        $this->inserisciTesseraGrezza($socio, 2031, 'A-4');

        // Doppioni presenti: nessun vincolo e nessuna tessera cancellata o cambiata
        $migration->up();
        $this->assertFalse(Schema::hasIndex('tessere', 'uq_tessere_socio_anno'));
        $righe = DB::table('tessere')->where('socio_id', $socio->id)->orderBy('numero_tessera')->get();
        $this->assertSame(['A-1', 'A-2', 'A-3', 'A-4'], $righe->pluck('numero_tessera')->all());
        $this->assertSame([2030, 2030, 2031, 2031], $righe->pluck('anno_validita')->map(fn ($a) => (int) $a)->all());
        $this->assertSame([Tessera::STATO_ATTIVA, Tessera::STATO_ATTIVA, Tessera::STATO_ANNULLATA, Tessera::STATO_ATTIVA], $righe->pluck('stato')->all());
        // La colonna del vincolo è comunque valorizzata
        $this->assertSame([2030, 2030, null, 2031], $righe->pluck('anno_non_annullata')->map(fn ($a) => $a === null ? null : (int) $a)->all());

        // Doppione sistemato dal backoffice (tessera in più annullata):
        // rieseguita, la migration aggiunge il vincolo
        Tessera::allTenants()->where('numero_tessera', 'A-2')->firstOrFail()->update(['stato' => Tessera::STATO_ANNULLATA]);
        $migration->up();
        $this->assertTrue(Schema::hasIndex('tessere', 'uq_tessere_socio_anno', 'unique'));

        // down() toglie vincolo e colonna e non fallisce se sono già assenti
        $migration->down();
        $this->assertFalse(Schema::hasIndex('tessere', 'uq_tessere_socio_anno'));
        $this->assertFalse(Schema::hasColumn('tessere', 'anno_non_annullata'));
        $migration->down();
        $migration->up();
        $this->assertTrue(Schema::hasIndex('tessere', 'uq_tessere_socio_anno', 'unique'));
        $this->assertSame(4, DB::table('tessere')->where('socio_id', $socio->id)->count());
    }

    // ------------------------------------------------------------------
    // Emissione singola e flussi automatici
    // ------------------------------------------------------------------

    public function test_emetti_rifiuta_la_seconda_tessera_dell_anno_e_i_flussi_automatici_la_trattano_come_gia_presente(): void
    {
        $socio = Socio::factory()->create();
        $prima = $this->issuer()->emetti($socio, ['anno_validita' => (int) now()->year]);

        try {
            $this->issuer()->emetti($socio, ['anno_validita' => (int) now()->year]);
            $this->fail('Attesa TesseraGiaPresenteException');
        } catch (TesseraGiaPresenteException $e) {
            $this->assertStringContainsString((string) now()->year, $e->getMessage());
        }

        $this->assertNull($this->issuer()->emettiSeNonPresente($socio, ['anno_validita' => (int) now()->year]));
        $this->assertNull(app(TesseraAutomaticaService::class)->creaPerAnnoCorrente($socio));
        $this->assertSame($prima->id, $this->issuer()->emettiSeAssente($socio)->id);
        $this->assertSame(1, Tessera::allTenants()->where('socio_id', $socio->id)->count());
    }

    public function test_violazione_del_vincolo_da_un_processo_concorrente_vale_come_gia_presente_senza_buchi_nella_numerazione(): void
    {
        $socio = Socio::factory()->create();
        $anno = 2030;

        // Tessera di un processo concorrente che il ricontrollo non vede
        // (su MariaDB: riga non ancora committata, o un flusso che non passa
        // dal lock del socio): il primo controllo di esistenza risponde
        // "assente", l'insert urta il vincolo unique
        $this->inserisciTesseraGrezza($socio, $anno, 'CONCORRENTE');
        $issuer = Mockery::mock(TesseraIssuer::class, [app(TesseraQrService::class)])->makePartial();
        $issuer->shouldReceive('tesseraDellAnno')->once()->andReturnNull();
        $issuer->shouldReceive('tesseraDellAnno')->passthru();

        $tessera = $issuer->emettiSeAssente($socio, $anno);

        $this->assertSame('CONCORRENTE', $tessera->numero_tessera);
        $this->assertSame(1, Tessera::allTenants()->where('socio_id', $socio->id)->where('anno_validita', $anno)->count());

        // Il savepoint ha annullato anche l'avanzamento del contatore
        $this->assertSame(0, (int) TesseraCounter::query()->withoutGlobalScope('associazione')
            ->where('associazione_id', $socio->associazione_id)->where('anno', $anno)->value('ultimo_numero'));
        $altro = Socio::factory()->create(['associazione_id' => $socio->associazione_id]);
        $this->assertSame($anno.'0001', $this->issuer()->emetti($altro, ['anno_validita' => $anno])->numero_tessera);
    }

    public function test_violazione_di_un_altro_vincolo_non_viene_nascosta(): void
    {
        $socio = Socio::factory()->create();
        $altro = Socio::factory()->create(['associazione_id' => $socio->associazione_id]);
        $this->inserisciTesseraGrezza($altro, 2030, 'MANUALE-1');

        // Numero tessera manuale già usato da un altro socio: non è "già presente"
        $this->expectException(UniqueConstraintViolationException::class);

        $this->issuer()->emetti($socio, ['anno_validita' => 2030, 'numero_tessera' => 'MANUALE-1']);
    }

    // ------------------------------------------------------------------
    // Generazione massiva
    // ------------------------------------------------------------------

    public function test_generazione_massiva_salta_il_socio_tesserato_nel_frattempo_da_un_altro_flusso(): void
    {
        $assoc = Associazione::factory()->create();
        $soci = Socio::factory()->count(3)->create(['associazione_id' => $assoc->id])->sortBy('id')->values();
        $anno = 2030;

        // Mentre il lotto lavora sul primo socio, un pagamento tessera
        // l'ultimo: il lotto lo aveva già selezionato come "senza tessera"
        $ultimo = $soci->last();
        $fatto = false;
        Tessera::created(function (Tessera $t) use ($soci, $ultimo, $anno, &$fatto): void {
            if (! $fatto && $t->socio_id === $soci->first()->id) {
                $fatto = true;
                $this->inserisciTesseraGrezza($ultimo, $anno, 'PAGAMENTO');
            }
        });

        $generate = $this->issuer()->generaMassivo($assoc, $anno);

        $this->assertSame(2, $generate);
        foreach ($soci as $socio) {
            $this->assertSame(1, Tessera::allTenants()->where('socio_id', $socio->id)->where('anno_validita', $anno)->count());
        }
        $this->assertSame('PAGAMENTO', Tessera::allTenants()->where('socio_id', $ultimo->id)->value('numero_tessera'));
    }

    public function test_generazione_massiva_ricontrolla_lo_stato_del_socio_sotto_lock(): void
    {
        $assoc = Associazione::factory()->create();
        $soci = Socio::factory()->count(2)->create(['associazione_id' => $assoc->id])->sortBy('id')->values();
        $anno = 2030;

        // Il secondo socio viene sospeso mentre il lotto è in corso
        $fatto = false;
        Tessera::created(function (Tessera $t) use ($soci, &$fatto): void {
            if (! $fatto && $t->socio_id === $soci->first()->id) {
                $fatto = true;
                DB::table('soci')->where('id', $soci->last()->id)->update(['stato' => Socio::STATO_SOSPESO]);
            }
        });

        $this->assertSame(1, $this->issuer()->generaMassivo($assoc, $anno));
        $this->assertSame(0, Tessera::allTenants()->where('socio_id', $soci->last()->id)->count());
    }

    public function test_generazione_massiva_con_errore_a_meta_conserva_le_tessere_gia_emesse(): void
    {
        $assoc = Associazione::factory()->create();
        $soci = Socio::factory()->count(3)->create(['associazione_id' => $assoc->id])->sortBy('id')->values();
        $anno = 2030;

        Tessera::creating(function (Tessera $t) use ($soci): void {
            if ($t->socio_id === $soci[1]->id) {
                throw new RuntimeException('Errore simulato');
            }
        });

        try {
            $this->issuer()->generaMassivo($assoc, $anno);
            $this->fail('Attesa eccezione');
        } catch (RuntimeException $e) {
            $this->assertSame('Errore simulato', $e->getMessage());
        }

        // Una transazione per socio: la prima tessera resta, la seconda no
        $this->assertSame(1, Tessera::allTenants()->where('socio_id', $soci[0]->id)->count());
        $this->assertSame(0, Tessera::allTenants()->where('socio_id', $soci[1]->id)->count());
    }

    // ------------------------------------------------------------------
    // Backoffice: creazione e modifica
    // ------------------------------------------------------------------

    public function test_creazione_manuale_di_una_seconda_tessera_dell_anno_e_un_errore_di_validazione(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);
        $socio = Socio::factory()->create(['associazione_id' => $assoc->id]);
        Tessera::factory()->create(['socio_id' => $socio->id, 'anno_validita' => 2026, 'numero_tessera' => '20260001']);

        $this->actingAs($admin)->from('/tessere')->post('/tessere', [
            'socio_id' => $socio->id,
            'numero_tessera' => '20260042',
            'anno_validita' => 2026,
            'data_emissione' => '2026-06-11',
            'stato' => 'Attiva',
        ])->assertRedirect('/tessere')->assertSessionHasErrors(['anno_validita' => 'Il socio ha già una tessera non annullata per questo anno.']);

        // Anno diverso: ammessa
        $this->actingAs($admin)->post('/tessere', [
            'socio_id' => $socio->id,
            'numero_tessera' => '20270042',
            'anno_validita' => 2027,
            'data_emissione' => '2027-01-11',
            'stato' => 'Attiva',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, Tessera::allTenants()->where('socio_id', $socio->id)->count());
    }

    public function test_creazione_manuale_in_gara_con_un_altro_flusso_e_un_errore_di_validazione_non_un_500(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);
        $socio = Socio::factory()->create(['associazione_id' => $assoc->id]);

        // La tessera dell'anno compare dopo la validazione (pagamento online)
        $this->mock(TesseraIssuer::class)
            ->shouldReceive('emetti')->once()->andThrow(new TesseraGiaPresenteException(2026));

        $this->actingAs($admin)->from('/tessere')->post('/tessere', [
            'socio_id' => $socio->id,
            'numero_tessera' => '20260042',
            'anno_validita' => 2026,
            'data_emissione' => '2026-06-11',
            'stato' => 'Attiva',
        ])->assertRedirect('/tessere')->assertSessionHasErrors(['anno_validita' => "Il socio ha già una tessera non annullata per l'anno 2026."]);
    }

    public function test_modifica_che_sposta_la_tessera_su_un_anno_gia_occupato_e_un_errore_di_validazione(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);
        $socio = Socio::factory()->create(['associazione_id' => $assoc->id]);
        Tessera::factory()->create(['socio_id' => $socio->id, 'anno_validita' => 2026, 'numero_tessera' => '20260001']);
        $vecchia = Tessera::factory()->create(['socio_id' => $socio->id, 'anno_validita' => 2025, 'numero_tessera' => '20250001']);

        $dati = [
            'socio_id' => $socio->id,
            'numero_tessera' => '20250001',
            'data_emissione' => '2025-01-10',
            'stato' => 'Attiva',
        ];

        $this->actingAs($admin)->from('/tessere')->put('/tessere/'.$vecchia->id, $dati + ['anno_validita' => 2026])
            ->assertRedirect('/tessere')
            ->assertSessionHasErrors(['anno_validita' => 'Il socio ha già una tessera non annullata per questo anno.']);
        $this->assertSame(2025, $vecchia->fresh()->anno_validita);

        // Spostata sul 2026 ma annullata: fuori dal vincolo, ammessa
        $this->actingAs($admin)->put('/tessere/'.$vecchia->id, ['stato' => Tessera::STATO_ANNULLATA, 'anno_validita' => 2026] + $dati)
            ->assertSessionHasNoErrors();
        $this->assertSame(2026, $vecchia->fresh()->anno_validita);

        // Salvare la tessera sul proprio anno resta possibile
        $this->actingAs($admin)->put('/tessere/'.$vecchia->id, $dati + ['anno_validita' => 2025])
            ->assertSessionHasNoErrors();
    }

    public function test_tessera_persa_annullata_e_duplicato_dello_stesso_anno(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);
        $socio = Socio::factory()->create(['associazione_id' => $assoc->id]);
        $persa = $this->issuer()->emetti($socio, ['anno_validita' => 2026]);

        $persa->update(['stato' => Tessera::STATO_ANNULLATA]);

        // I flussi automatici continuano a considerare presente la tessera dell'anno
        $this->assertNull($this->issuer()->emettiSeNonPresente($socio, ['anno_validita' => 2026]));
        $this->assertSame($persa->id, $this->issuer()->emettiSeAssente($socio, 2026)->id);

        // La segreteria registra il duplicato a mano
        $this->actingAs($admin)->post('/tessere', [
            'socio_id' => $socio->id,
            'numero_tessera' => '20260099',
            'anno_validita' => 2026,
            'data_emissione' => '2026-06-05',
            'stato' => 'Attiva',
        ])->assertSessionHasNoErrors();

        // ...ma non un secondo duplicato valido
        $this->actingAs($admin)->post('/tessere', [
            'socio_id' => $socio->id,
            'numero_tessera' => '20260100',
            'anno_validita' => 2026,
            'data_emissione' => '2026-06-06',
            'stato' => 'Attiva',
        ])->assertSessionHasErrors('anno_validita');

        // Il servizio applica la stessa regola anche fuori dal form
        $this->expectException(TesseraGiaPresenteException::class);
        $this->issuer()->emetti($socio, ['anno_validita' => 2026, 'stato' => Tessera::STATO_SOSPESA]);
    }
}
