<?php

namespace Tests\Feature\Settings;

use App\Models\Associazione;
use App\Settings\IscrizioniSettings;
use App\Settings\Tenant\ContestoImpostazioni;
use App\Settings\TesseramentoSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Spatie\LaravelSettings\SettingsMapper;
use Tests\TestCase;

class ImpostazioniAssociazioneTest extends TestCase
{
    use RefreshDatabase;

    public function test_senza_righe_salvate_legge_le_colonne_dell_associazione(): void
    {
        $associazione = Associazione::factory()->create([
            'tipo_scadenza_default' => 'annuale',
            'giorni_notifica_scadenza' => 45,
            'modalita_iscrizione' => Associazione::MODALITA_DIRETTA,
            'iscrizioni_socio_aperte' => false,
        ]);

        $tesseramento = TesseramentoSettings::per($associazione);
        $iscrizioni = IscrizioniSettings::per($associazione);

        $this->assertTrue($tesseramento->scadenzaAnnuale());
        $this->assertSame(45, $tesseramento->giorni_notifica_scadenza);
        $this->assertSame([30, 15, 3], $tesseramento->promemoriaRinnovoSteps());
        $this->assertTrue($iscrizioni->diretta());
        $this->assertFalse($iscrizioni->iscrizioni_socio_aperte);
        $this->assertDatabaseCount('settings', 0);
    }

    public function test_il_salvataggio_scrive_le_righe_dell_associazione_e_le_colonne(): void
    {
        $associazione = Associazione::factory()->create(['giorni_notifica_scadenza' => 30]);
        $altra = Associazione::factory()->create(['giorni_notifica_scadenza' => 30]);

        $impostazioni = TesseramentoSettings::per($associazione);
        $impostazioni->giorni_notifica_scadenza = 60;
        $impostazioni->promemoria_rinnovo_config = [7, 21];
        $impostazioni->save();

        $this->assertDatabaseHas('settings', [
            'associazione_id' => $associazione->id,
            'group' => 'tesseramento',
            'name' => 'giorni_notifica_scadenza',
            'payload' => '60',
        ]);
        $this->assertSame(4, DB::table('settings')->where('associazione_id', $associazione->id)->count());
        $this->assertSame(0, DB::table('settings')->where('associazione_id', $altra->id)->count());

        // Doppia scrittura sulle colonne finché esistono
        $this->assertSame(60, $associazione->fresh()->giorni_notifica_scadenza);
        $this->assertSame([7, 21], $associazione->fresh()->promemoria_rinnovo_config);

        // Il modello vede i valori appena salvati, l'altra associazione no
        $this->assertSame([21, 7], $associazione->promemoriaRinnovoSteps());
        $this->assertSame(60, TesseramentoSettings::per($associazione->fresh())->giorni_notifica_scadenza);
        $this->assertSame(30, TesseramentoSettings::per($altra)->giorni_notifica_scadenza);
    }

    public function test_con_un_modello_parziale_rilegge_le_colonne_mancanti(): void
    {
        $associazione = Associazione::factory()->create(['giorni_notifica_scadenza' => 12, 'tipo_scadenza_default' => 'annuale']);
        $parziale = Associazione::query()->select(['id', 'nome'])->findOrFail($associazione->id);

        $this->assertSame(12, TesseramentoSettings::per($parziale)->giorni_notifica_scadenza);
        $this->assertTrue(TesseramentoSettings::per($parziale)->scadenzaAnnuale());
    }

    public function test_la_riga_salvata_prevale_sulla_colonna(): void
    {
        $associazione = Associazione::factory()->create();
        $iscrizioni = IscrizioniSettings::per($associazione);
        $iscrizioni->iscrizioni_socio_aperte = false;
        $iscrizioni->save();

        DB::table('associazioni')->where('id', $associazione->id)->update(['iscrizioni_socio_aperte' => true]);

        $this->assertFalse(IscrizioniSettings::per($associazione->fresh())->iscrizioni_socio_aperte);
    }

    public function test_un_secondo_salvataggio_aggiorna_le_righe_senza_duplicarle(): void
    {
        $associazione = Associazione::factory()->create();

        foreach ([Associazione::MODALITA_DIRETTA, Associazione::MODALITA_PREISCRIZIONE] as $modalita) {
            $iscrizioni = IscrizioniSettings::per($associazione->fresh());
            $iscrizioni->modalita_iscrizione = $modalita;
            $iscrizioni->save();
        }

        $this->assertSame(4, DB::table('settings')->where('associazione_id', $associazione->id)->count());
        $this->assertFalse(IscrizioniSettings::per($associazione->fresh())->diretta());
    }

    public function test_valori_non_validi_vengono_normalizzati(): void
    {
        $associazione = Associazione::factory()->create([
            'tipo_scadenza_default' => 'mensile',
            'giorni_notifica_scadenza' => 0,
            'modalita_iscrizione' => 'boh',
            'iscrizione_form_config' => [],
        ]);

        $this->assertSame('solare', TesseramentoSettings::per($associazione)->tipo_scadenza_default);
        $this->assertSame(30, TesseramentoSettings::per($associazione)->giorni_notifica_scadenza);
        $this->assertSame(Associazione::MODALITA_PREISCRIZIONE, IscrizioniSettings::per($associazione)->modalita_iscrizione);
        $this->assertNull(IscrizioniSettings::per($associazione)->iscrizione_form_config);
    }

    public function test_il_repository_rifiuta_letture_fuori_contesto(): void
    {
        $repository = app(SettingsMapper::class)->initialize(TesseramentoSettings::class)->getRepository();

        $this->expectException(LogicException::class);
        $repository->getPropertiesInGroup('tesseramento');
    }

    public function test_il_contesto_viene_ripristinato_anche_dopo_un_errore(): void
    {
        ContestoImpostazioni::con('esterna', function (): void {
            try {
                ContestoImpostazioni::con('interna', fn () => throw new \RuntimeException('x'));
            } catch (\RuntimeException) {
            }

            $this->assertSame('esterna', ContestoImpostazioni::richiesto());
        });

        $this->expectException(LogicException::class);
        ContestoImpostazioni::richiesto();
    }

    public function test_l_eliminazione_dell_associazione_rimuove_le_sue_impostazioni(): void
    {
        $associazione = Associazione::factory()->create();
        $impostazioni = TesseramentoSettings::per($associazione);
        $impostazioni->giorni_notifica_scadenza = 10;
        $impostazioni->save();

        $associazione->forceDelete();

        $this->assertDatabaseCount('settings', 0);
    }
}
