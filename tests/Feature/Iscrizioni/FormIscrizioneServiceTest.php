<?php

namespace Tests\Feature\Iscrizioni;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\CampoPersonalizzato;
use App\Models\RichiestaIscrizione;
use App\Models\Socio;
use App\Models\ValoreCampoPersonalizzato;
use App\Services\Iscrizioni\FormIscrizioneService;
use App\Services\Iscrizioni\IscrizioneException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FormIscrizioneService (PIANO-ISCRIZIONI §Form personalizzabile):
 * schema risolto (default e configurato), regole dinamiche
 * (telefono laravel-phone, codice fiscale, tipi campi personalizzati),
 * salvataggio in modalità preiscrizione e diretta, doppia richiesta bloccata.
 */
class FormIscrizioneServiceTest extends TestCase
{
    use RefreshDatabase;

    private const CF_VALIDO = 'RSSMRA85T10A562S';

    private FormIscrizioneService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(FormIscrizioneService::class);
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function account(Associazione $associazione): Account
    {
        return Account::factory()->verificato()->create([
            'associazione_id' => $associazione->id,
        ]);
    }

    /**
     * CampoPersonalizzato senza factory (pattern dei test soci esistenti).
     *
     * @param  array<string, mixed>  $attrs
     */
    private function campoPersonalizzato(Associazione $associazione, array $attrs = []): CampoPersonalizzato
    {
        return CampoPersonalizzato::create(array_merge([
            'associazione_id' => $associazione->id,
            'nome_campo' => 'Campo '.Str::random(8),
            'tipo_campo' => 'text',
            'ordine' => 0,
        ], $attrs));
    }

    /**
     * @return array<string, mixed> input valido per il form di default
     */
    private function datiValidi(): array
    {
        return [
            'telefono' => '3331234567',
            'codice_fiscale' => self::CF_VALIDO,
            'data_nascita' => '1990-05-10',
            'citta' => 'Milano',
        ];
    }

    private function valida(Associazione $associazione, array $dati): \Illuminate\Validation\Validator
    {
        return Validator::make($dati, $this->service->rules($associazione));
    }

    // ------------------------------------------------------------------
    // Schema
    // ------------------------------------------------------------------

    public function test_schema_default_senza_config(): void
    {
        $associazione = Associazione::factory()->create(['iscrizione_form_config' => null]);

        $schema = $this->service->schema($associazione);

        $this->assertNull($schema['intro_html']);
        $this->assertCount(0, $schema['campi_personalizzati']);
        $this->assertSame(
            FormIscrizioneService::CAMPI_STANDARD_DEFAULT,
            $schema['campi_standard']
        );

        // Default sensato: telefono visibile e obbligatorio, indirizzo nascosto
        $perCampo = collect($schema['campi_standard'])->keyBy('campo');
        $this->assertTrue($perCampo['telefono']['obbligatorio']);
        $this->assertFalse($perCampo['indirizzo']['visibile']);
    }

    public function test_schema_configurato_con_ordine_e_campi_personalizzati(): void
    {
        $associazione = Associazione::factory()->create();

        $campoA = $this->campoPersonalizzato($associazione, ['tipo_campo' => 'text']);
        $campoB = $this->campoPersonalizzato($associazione, ['tipo_campo' => 'number']);

        $associazione->update(['iscrizione_form_config' => [
            'intro_html' => '<p>Benvenuto</p><script>alert(1)</script>',
            'campi_standard' => [
                ['campo' => 'cap', 'visibile' => true, 'obbligatorio' => true],
                ['campo' => 'telefono', 'visibile' => true, 'obbligatorio' => false],
                ['campo' => 'campo_inventato', 'visibile' => true, 'obbligatorio' => true], // scartato
            ],
            // Ordine configurato: B prima di A
            'campi_personalizzati' => [$campoB->id, $campoA->id],
        ]]);

        $schema = $this->service->schema($associazione->fresh());

        // Intro sanitizzata con purifier: lo script sparisce
        $this->assertStringContainsString('Benvenuto', (string) $schema['intro_html']);
        $this->assertStringNotContainsString('<script>', (string) $schema['intro_html']);

        $this->assertSame(['cap', 'telefono'], array_column($schema['campi_standard'], 'campo'));
        $this->assertSame(
            [$campoB->id, $campoA->id],
            $schema['campi_personalizzati']->pluck('id')->all()
        );
    }

    public function test_schema_scarta_campi_personalizzati_di_altro_tenant(): void
    {
        $associazione = Associazione::factory()->create();
        $altra = Associazione::factory()->create();

        $campoAltrui = $this->campoPersonalizzato($altra);

        $associazione->update(['iscrizione_form_config' => [
            'campi_personalizzati' => [$campoAltrui->id],
        ]]);

        $schema = $this->service->schema($associazione->fresh());

        $this->assertCount(0, $schema['campi_personalizzati']);
    }

    // ------------------------------------------------------------------
    // Rules dinamiche
    // ------------------------------------------------------------------

    public function test_rules_input_valido_passa(): void
    {
        $associazione = Associazione::factory()->create();

        $this->assertTrue($this->valida($associazione, $this->datiValidi())->passes());
    }

    public function test_rules_telefono_invalido_fallisce(): void
    {
        $associazione = Associazione::factory()->create();

        $validator = $this->valida($associazione, array_merge($this->datiValidi(), [
            'telefono' => 'non-un-telefono',
        ]));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('telefono', $validator->errors()->messages());
    }

    public function test_rules_codice_fiscale_invalido_fallisce(): void
    {
        $associazione = Associazione::factory()->create();

        $validator = $this->valida($associazione, array_merge($this->datiValidi(), [
            'codice_fiscale' => 'NONVALIDO1234567',
        ]));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('codice_fiscale', $validator->errors()->messages());
    }

    public function test_rules_data_nascita_futura_fallisce(): void
    {
        $associazione = Associazione::factory()->create();

        $validator = $this->valida($associazione, array_merge($this->datiValidi(), [
            'data_nascita' => now()->addYear()->toDateString(),
        ]));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('data_nascita', $validator->errors()->messages());
    }

    public function test_rules_campo_obbligatorio_mancante_fallisce(): void
    {
        $associazione = Associazione::factory()->create();

        // Default: telefono required
        $validator = $this->valida($associazione, [
            'data_nascita' => '1990-05-10',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('telefono', $validator->errors()->messages());
    }

    public function test_rules_campi_personalizzati_per_tipo(): void
    {
        $associazione = Associazione::factory()->create();

        $numero = $this->campoPersonalizzato($associazione, [
            'tipo_campo' => 'number',
            'obbligatorio' => true,
        ]);
        $select = $this->campoPersonalizzato($associazione, [
            'tipo_campo' => 'select',
            'opzioni' => "Rosso\nVerde\nBlu",
            'obbligatorio' => false,
        ]);

        $associazione->update(['iscrizione_form_config' => [
            'campi_standard' => [['campo' => 'citta', 'visibile' => true, 'obbligatorio' => false]],
            'campi_personalizzati' => [$numero->id, $select->id],
        ]]);
        $associazione = $associazione->fresh();

        // number non numerico + select fuori opzioni → errori
        $validator = $this->valida($associazione, ['custom_field' => [
            $numero->id => 'abc',
            $select->id => 'Giallo',
        ]]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('custom_field.'.$numero->id, $validator->errors()->messages());
        $this->assertArrayHasKey('custom_field.'.$select->id, $validator->errors()->messages());

        // obbligatorio mancante → errore
        $validator = $this->valida($associazione, ['custom_field' => []]);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('custom_field.'.$numero->id, $validator->errors()->messages());

        // valori coerenti → passa
        $this->assertTrue($this->valida($associazione, ['custom_field' => [
            $numero->id => '42',
            $select->id => 'Verde',
        ]])->passes());
    }

    // ------------------------------------------------------------------
    // salva(): modalità preiscrizione
    // ------------------------------------------------------------------

    public function test_salva_in_modalita_preiscrizione_crea_socio_in_attesa(): void
    {
        $associazione = Associazione::factory()->create([
            'modalita_iscrizione' => Associazione::MODALITA_PREISCRIZIONE,
        ]);
        $account = $this->account($associazione);

        $richiesta = $this->service->salva($account, $this->datiValidi());

        $this->assertSame(RichiestaIscrizione::STATO_IN_ATTESA, $richiesta->stato);
        $this->assertSame($associazione->id, $richiesta->associazione_id);
        $this->assertSame($account->id, $richiesta->account_id);
        $this->assertNull($richiesta->gestita_at);

        // Snapshot integrale dei dati inviati
        $this->assertSame($this->datiValidi(), $richiesta->dati_form);

        $socio = Socio::allTenants()->findOrFail($richiesta->socio_id);
        $this->assertSame(Socio::STATO_IN_ATTESA, $socio->stato);
        $this->assertSame('001', $socio->numero_socio);
        $this->assertSame($account->nome, $socio->nome);
        $this->assertSame($account->cognome, $socio->cognome);
        $this->assertSame($account->email, $socio->email);
        $this->assertSame('3331234567', $socio->telefono);
        $this->assertSame(self::CF_VALIDO, $socio->codice_fiscale);
        $this->assertTrue($socio->privacy_consenso);

        // L'account NON è ancora collegato (lo farà l'approvazione admin)
        $account->refresh();
        $this->assertNull($account->socio_id);
        $this->assertNotNull($account->richiesta_socio_at);
    }

    public function test_salva_usa_il_numero_socio_progressivo(): void
    {
        $associazione = Associazione::factory()->create();
        Socio::factory()->create([
            'associazione_id' => $associazione->id,
            'numero_socio' => '007',
        ]);

        $richiesta = $this->service->salva($this->account($associazione), $this->datiValidi());

        $socio = Socio::allTenants()->findOrFail($richiesta->socio_id);
        $this->assertSame('008', $socio->numero_socio);
    }

    public function test_salva_persiste_i_valori_dei_campi_personalizzati_configurati(): void
    {
        $associazione = Associazione::factory()->create();
        $campo = $this->campoPersonalizzato($associazione);
        $campoNonConfigurato = $this->campoPersonalizzato($associazione);

        $associazione->update(['iscrizione_form_config' => [
            'campi_standard' => [['campo' => 'telefono', 'visibile' => true, 'obbligatorio' => true]],
            'campi_personalizzati' => [$campo->id],
        ]]);

        $richiesta = $this->service->salva($this->account($associazione->fresh()), [
            'telefono' => '3331234567',
            'custom_field' => [
                $campo->id => 'Valore A',
                // Anti-tamper: campo non incluso nello schema → ignorato
                $campoNonConfigurato->id => 'Valore B',
            ],
        ]);

        $valori = ValoreCampoPersonalizzato::query()
            ->where('socio_id', $richiesta->socio_id)
            ->pluck('valore', 'campo_id');

        $this->assertSame(['Valore A'], $valori->values()->all());
        $this->assertTrue($valori->has($campo->id));
        $this->assertFalse($valori->has($campoNonConfigurato->id));
    }

    public function test_salva_ignora_i_campi_standard_non_visibili(): void
    {
        // Default: indirizzo NON visibile → input ignorato sull'anagrafica
        $associazione = Associazione::factory()->create();

        $dati = array_merge($this->datiValidi(), ['indirizzo' => 'Via Roma 1']);
        $richiesta = $this->service->salva($this->account($associazione), $dati);

        $socio = Socio::allTenants()->findOrFail($richiesta->socio_id);
        $this->assertNull($socio->indirizzo);

        // ...ma lo snapshot dati_form resta integrale
        $this->assertSame('Via Roma 1', $richiesta->dati_form['indirizzo']);
    }

    // ------------------------------------------------------------------
    // salva(): modalità diretta
    // ------------------------------------------------------------------

    public function test_salva_in_modalita_diretta_crea_socio_attivo_e_collega_account(): void
    {
        $associazione = Associazione::factory()->create([
            'modalita_iscrizione' => Associazione::MODALITA_DIRETTA,
        ]);
        $account = $this->account($associazione);

        $richiesta = $this->service->salva($account, $this->datiValidi());

        $this->assertSame(RichiestaIscrizione::STATO_APPROVATA, $richiesta->stato);
        $this->assertNotNull($richiesta->gestita_at);
        $this->assertNull($richiesta->gestita_da); // nessun admin coinvolto

        $socio = Socio::allTenants()->findOrFail($richiesta->socio_id);
        $this->assertSame(Socio::STATO_ATTIVO, $socio->stato);

        $account->refresh();
        $this->assertSame($socio->id, $account->socio_id);
        $this->assertNotNull($account->richiesta_socio_at);
    }

    // ------------------------------------------------------------------
    // salva(): guardie
    // ------------------------------------------------------------------

    public function test_salva_blocca_la_doppia_richiesta_in_attesa(): void
    {
        $associazione = Associazione::factory()->create();
        $account = $this->account($associazione);

        $this->service->salva($account, $this->datiValidi());

        $this->expectException(IscrizioneException::class);
        $this->expectExceptionMessage('già una richiesta');

        $this->service->salva($account->refresh(), $this->datiValidi());
    }

    public function test_salva_consente_una_nuova_richiesta_dopo_un_rifiuto(): void
    {
        $associazione = Associazione::factory()->create();
        $account = $this->account($associazione);

        RichiestaIscrizione::factory()->rifiutata('Dati incompleti')->create([
            'associazione_id' => $associazione->id,
            'account_id' => $account->id,
        ]);

        $richiesta = $this->service->salva($account, $this->datiValidi());

        $this->assertSame(RichiestaIscrizione::STATO_IN_ATTESA, $richiesta->stato);
        $this->assertSame(
            2,
            RichiestaIscrizione::allTenants()->where('account_id', $account->id)->count()
        );
    }

    public function test_salva_blocca_quando_le_iscrizioni_sono_chiuse(): void
    {
        $associazione = Associazione::factory()->create([
            'iscrizioni_socio_aperte' => false,
        ]);

        $this->expectException(IscrizioneException::class);
        $this->expectExceptionMessage('non sono aperte');

        $this->service->salva($this->account($associazione), $this->datiValidi());
    }

    public function test_salva_blocca_un_account_gia_collegato_a_un_socio(): void
    {
        $associazione = Associazione::factory()->create();
        $account = Account::factory()->verificato()->collegatoASocio()->create([
            'associazione_id' => $associazione->id,
        ]);

        $this->expectException(IscrizioneException::class);
        $this->expectExceptionMessage('già collegato');

        $this->service->salva($account->refresh(), $this->datiValidi());
    }
}
