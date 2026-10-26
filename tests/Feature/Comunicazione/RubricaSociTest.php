<?php

namespace Tests\Feature\Comunicazione;

use App\Models\Associazione;
use App\Models\Consenso;
use App\Models\RubricaContatto;
use App\Models\RubricaScheda;
use App\Models\Socio;
use App\Models\Utente;
use App\Notifications\Comunicazione\ContattoRubricaNotification;
use App\Services\Comunicazione\RubricaService;
use App\Services\Privacy\DatiPersonali;
use App\Settings\PartecipazioneSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Rubrica interna dei soci con consenso (issue #14): ingresso con consenso,
 * campi scelti, reciprocità, esclusione di minorenni e non attivi, filtri
 * solo sui campi visibili, contatto mediato con limiti, revoca ed export.
 */
class RubricaSociTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private RubricaService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->associazione = Associazione::factory()->create();
        $this->associazione->impostazioni(PartecipazioneSettings::class)->aggiorna(['rubrica_attiva' => true]);
        $this->associazione->refresh();
        $this->service = app(RubricaService::class);
    }

    private function socio(array $attributi = []): Socio
    {
        return Socio::factory()->conAccessoAreaSoci()->create(array_merge(['associazione_id' => $this->associazione->id], $attributi));
    }

    private function inRubrica(array $attributi = [], array $campi = ['citta'], array $interessi = []): Socio
    {
        $socio = $this->socio($attributi);
        $this->service->salva($socio, $campi, null, $interessi);

        return $socio;
    }

    public function test_si_entra_in_rubrica_solo_con_il_consenso(): void
    {
        $socio = $this->socio();

        $this->actingAs($socio, 'socio')->put(route('area-soci.rubrica.salva'), ['campi' => ['email']])->assertSessionHasErrors('consenso');
        $this->assertFalse(RubricaScheda::query()->exists());

        $this->actingAs($socio, 'socio')->put(route('area-soci.rubrica.salva'), [
            'consenso' => '1',
            'campi' => ['email', 'citta', 'campo_inventato'],
            'presentazione' => 'Fotografo per passione.',
            'interessi' => 'fotografia, grafica , ',
        ])->assertSessionHasErrors('campi.2');

        $this->actingAs($socio, 'socio')->put(route('area-soci.rubrica.salva'), [
            'consenso' => '1',
            'campi' => ['email', 'citta'],
            'presentazione' => 'Fotografo per passione.',
            'interessi' => 'fotografia, grafica , ',
        ])->assertSessionHas('success');

        $scheda = RubricaScheda::query()->firstOrFail();
        $this->assertTrue($scheda->attiva);
        $this->assertSame(['email', 'citta'], $scheda->campi_visibili);
        $this->assertSame(['fotografia', 'grafica'], $scheda->interessi);
        $this->assertTrue(Consenso::query()->withoutGlobalScopes()->where('socio_id', $socio->id)->where('chiave', 'rubrica_interna')->where('accettato', true)->exists());
    }

    public function test_si_vedono_solo_i_campi_scelti(): void
    {
        $visibile = $this->inRubrica(['citta' => 'Bologna', 'telefono' => '3331112222', 'email' => 'nascosta@example.test'], ['citta']);
        $lettore = $this->inRubrica();

        $this->actingAs($lettore, 'socio')->get(route('area-soci.rubrica'))
            ->assertOk()
            ->assertSee($visibile->nome_completo)
            ->assertSee('Bologna')
            ->assertDontSee('3331112222')
            ->assertDontSee('nascosta@example.test');
    }

    public function test_reciprocita_minorenni_e_soci_non_attivi(): void
    {
        $fuori = $this->socio();
        $this->actingAs($fuori, 'socio')->get(route('area-soci.rubrica'))->assertOk()->assertSee('devi comparirci anche tu');

        $minorenne = $this->socio(['data_nascita' => now()->subYears(15)->toDateString()]);
        $this->actingAs($minorenne, 'socio')->get(route('area-soci.rubrica'))->assertSee('maggiorenni');
        $this->actingAs($minorenne, 'socio')->put(route('area-soci.rubrica.salva'), ['consenso' => '1'])->assertSessionHas('error');

        $sospeso = $this->inRubrica();
        $sospeso->update(['stato' => Socio::STATO_SOSPESO]);
        $lettore = $this->inRubrica();

        $this->actingAs($lettore, 'socio')->get(route('area-soci.rubrica'))->assertDontSee($sospeso->nome_completo);

        // Senza reciprocità basta essere socio attivo maggiorenne
        $this->associazione->impostazioni(PartecipazioneSettings::class)->aggiorna(['rubrica_reciprocita' => false]);
        // Istanza fresca: quella usata prima tiene in cache le impostazioni lette
        $this->actingAs($fuori->fresh(), 'socio')->get(route('area-soci.rubrica'))->assertSee($lettore->nome_completo);
    }

    public function test_filtri_solo_sui_campi_visibili(): void
    {
        // Città non mostrata: il filtro per città non deve rivelarla
        $nascosto = $this->inRubrica(['citta' => 'Trento', 'cognome' => 'Nascostoqx'], []);
        $mostrato = $this->inRubrica(['citta' => 'Trento', 'cognome' => 'Mostratoqx'], ['citta']);
        $lettore = $this->inRubrica(['citta' => 'Roma']);

        $this->actingAs($lettore, 'socio')->get(route('area-soci.rubrica', ['citta' => 'Trento']))
            ->assertSee('Mostratoqx')
            ->assertDontSee('Nascostoqx');

        $this->inRubrica(['cognome' => 'Fotografoqx'], ['interessi'], ['fotografia']);
        $this->inRubrica(['cognome' => 'Riservatoqx'], [], ['fotografia']);

        $this->actingAs($lettore, 'socio')->get(route('area-soci.rubrica', ['interesse' => 'foto']))
            ->assertSee('Fotografoqx')
            ->assertDontSee('Riservatoqx');
    }

    public function test_contatto_mediato_con_rispondi_a_e_limiti(): void
    {
        $destinatario = $this->inRubrica();
        $mittente = $this->inRubrica(['email' => 'mittente@example.test']);
        $scheda = RubricaScheda::query()->where('socio_id', $destinatario->id)->firstOrFail();

        $this->actingAs($mittente, 'socio')->post(route('area-soci.rubrica.contatta', $scheda), [
            'oggetto' => 'Passaggio in auto',
            'testo' => 'Ciao, andiamo insieme all\'assemblea?',
        ])->assertSessionHas('success');

        Notification::assertSentTo($destinatario, ContattoRubricaNotification::class, function ($notifica) use ($destinatario) {
            $mail = $notifica->toMail($destinatario);

            return $mail->replyTo[0][0] === 'mittente@example.test'
                && in_array('Ciao, andiamo insieme all\'assemblea?', $mail->introLines, true);
        });

        // Stesso destinatario entro 24 ore: rifiutato
        $this->actingAs($mittente, 'socio')->post(route('area-soci.rubrica.contatta', $scheda), ['oggetto' => 'Di nuovo', 'testo' => '…'])->assertSessionHas('error');

        // Massimo 5 al giorno
        foreach (range(1, 4) as $i) {
            $altra = RubricaScheda::query()->where('socio_id', $this->inRubrica()->id)->firstOrFail();
            $this->actingAs($mittente, 'socio')->post(route('area-soci.rubrica.contatta', $altra), ['oggetto' => "Ciao {$i}", 'testo' => '…'])->assertSessionHas('success');
        }
        $sesta = RubricaScheda::query()->where('socio_id', $this->inRubrica()->id)->firstOrFail();
        $this->actingAs($mittente, 'socio')->post(route('area-soci.rubrica.contatta', $sesta), ['oggetto' => 'Troppi', 'testo' => '…'])->assertSessionHas('error');

        $this->assertSame(5, RubricaContatto::query()->count());
    }

    public function test_segnalazione_del_destinatario(): void
    {
        $destinatario = $this->inRubrica();
        $mittente = $this->inRubrica();
        $this->service->contatta($mittente, RubricaScheda::query()->where('socio_id', $destinatario->id)->firstOrFail(), 'Offerta', 'Compra!');
        $contatto = RubricaContatto::query()->firstOrFail();

        $this->actingAs($mittente, 'socio')->get(route('area-soci.rubrica.segnala', $contatto))->assertNotFound();
        $this->actingAs($destinatario, 'socio')->get(route('area-soci.rubrica.segnala', $contatto))->assertOk()->assertSee('Segnala alla segreteria');
        $this->actingAs($destinatario, 'socio')->post(route('area-soci.rubrica.segnala.store', $contatto))->assertSessionHas('success');

        $this->assertNotNull($contatto->fresh()->segnalato_at);

        $admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->associazione->id]);
        $this->actingAs($admin)->withSession(['associazione_id' => $this->associazione->id])
            ->get(route('rubrica-soci.index'))->assertOk()->assertSee($mittente->nome_completo);
    }

    public function test_revoca_toglie_subito_la_scheda_e_registra_il_consenso_negato(): void
    {
        $socio = $this->inRubrica();
        $lettore = $this->inRubrica();

        $this->actingAs($socio, 'socio')->delete(route('area-soci.rubrica.revoca'))->assertSessionHas('success');

        $this->assertFalse(RubricaScheda::query()->where('socio_id', $socio->id)->value('attiva'));
        $this->assertTrue(Consenso::query()->withoutGlobalScopes()->where('socio_id', $socio->id)->where('chiave', 'rubrica_interna')->where('accettato', false)->exists());
        $this->actingAs($lettore, 'socio')->get(route('area-soci.rubrica'))->assertDontSee($socio->nome_completo);
    }

    public function test_rubrica_spenta_e_impostazioni_solo_admin(): void
    {
        $this->associazione->impostazioni(PartecipazioneSettings::class)->aggiorna(['rubrica_attiva' => false]);
        $socio = $this->socio();
        $this->actingAs($socio, 'socio')->get(route('area-soci.rubrica'))->assertSee('non è attiva');

        $admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->associazione->id]);
        $this->actingAs($admin)->withSession(['associazione_id' => $this->associazione->id])
            ->put(route('rubrica-soci.update'), ['rubrica_attiva' => '1', 'rubrica_reciprocita' => '0', 'rubrica_campi' => ['email']])
            ->assertSessionHas('success');

        $impostazioni = $this->associazione->refresh()->impostazioni(PartecipazioneSettings::class);
        $this->assertTrue($impostazioni->rubrica_attiva);
        $this->assertFalse($impostazioni->rubrica_reciprocita);
        $this->assertSame(['email'], $impostazioni->rubrica_campi);

        $operatore = Utente::factory()->operatore()->create(['associazione_id' => $this->associazione->id]);
        $this->actingAs($operatore)->withSession(['associazione_id' => $this->associazione->id])
            ->get(route('rubrica-soci.index'))->assertForbidden();
    }

    public function test_export_dei_dati_personali_include_la_rubrica(): void
    {
        $socio = $this->inRubrica();

        $dati = app(DatiPersonali::class)->socio($socio);

        $this->assertCount(1, $dati['rubrica_interna']);
        $this->assertArrayHasKey('proposte', $dati);
        $this->assertArrayHasKey('messaggi_discussioni', $dati);
    }
}
