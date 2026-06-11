<?php

namespace Tests\Feature\Iscrizioni;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\CampoPersonalizzato;
use App\Models\RichiestaIscrizione;
use App\Models\Socio;
use App\Models\ValoreCampoPersonalizzato;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pagina pubblica di iscrizione socio /a/{slug}/iscriviti
 * (PIANO-ISCRIZIONI §Rotte e UI, frontend pubblico):
 *
 * GET: guard account+verificato, pagina "iscrizioni chiuse", redirect
 * tessera se già socio, pagina stato se richiesta in_attesa, form
 * renderizzato dallo schema configurato (campi standard + personalizzati).
 * POST: rules() dinamiche, flusso felice preiscrizione/diretta, doppia
 * richiesta bloccata. Più: redirect della vecchia "diventa socio" e
 * stato richiesta sulla dashboard account.
 */
class IscrizionePubblicaHttpTest extends TestCase
{
    use RefreshDatabase;

    private const CF_VALIDO = 'RSSMRA85T10A562S';

    private Associazione $associazione;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        config(['honeypot.enabled' => false]);

        $this->associazione = Associazione::factory()->create([
            'frontend_pubblico_attivo' => true,
        ]);

        $this->account = Account::factory()->verificato()->create([
            'associazione_id' => $this->associazione->id,
        ]);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('tenant.pubblico');

        parent::tearDown();
    }

    private function urlTenant(string $path = ''): string
    {
        return '/a/'.$this->associazione->slug.($path !== '' ? '/'.ltrim($path, '/') : '');
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

    private function campoPersonalizzato(array $attrs = []): CampoPersonalizzato
    {
        return CampoPersonalizzato::create(array_merge([
            'associazione_id' => $this->associazione->id,
            'nome_campo' => 'Campo '.Str::random(8),
            'tipo_campo' => 'text',
            'ordine' => 0,
        ], $attrs));
    }

    // ── GET: guardie e stati ─────────────────────────────────────────

    public function test_il_form_e_pubblico_anche_per_gli_ospiti(): void
    {
        // Il form di iscrizione è aperto a tutti: gli ospiti vedono
        // anche i campi anagrafici e il consenso privacy
        $this->get($this->urlTenant('iscriviti'))
            ->assertOk()
            ->assertSee('name="nome"', false)
            ->assertSee('name="privacy"', false);
    }

    public function test_account_non_verificato_accede_comunque_al_form(): void
    {
        // Coerenza col form pubblico: un account non verificato non è
        // meno affidabile di un ospite anonimo
        $nonVerificato = Account::factory()->nonVerificato()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($nonVerificato, 'account')
            ->get($this->urlTenant('iscriviti'))
            ->assertOk();
    }

    public function test_iscrizioni_chiuse_mostra_la_pagina_dedicata(): void
    {
        $this->associazione->update(['iscrizioni_socio_aperte' => false]);

        $this->actingAs($this->account, 'account')
            ->get($this->urlTenant('iscriviti'))
            ->assertOk()
            ->assertSee('Le iscrizioni sono chiuse');
    }

    public function test_account_gia_socio_viene_reindirizzato_alla_tessera(): void
    {
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $this->account->forceFill(['socio_id' => $socio->id])->save();

        $this->actingAs($this->account, 'account')
            ->get($this->urlTenant('iscriviti'))
            ->assertRedirect($this->urlTenant('account/tessera'));
    }

    public function test_richiesta_in_attesa_mostra_la_pagina_di_stato(): void
    {
        RichiestaIscrizione::factory()->inAttesa()->create([
            'associazione_id' => $this->associazione->id,
            'account_id' => $this->account->id,
        ]);

        $this->actingAs($this->account, 'account')
            ->get($this->urlTenant('iscriviti'))
            ->assertOk()
            ->assertSee('Richiesta in attesa di approvazione');
    }

    // ── GET: render del form dallo schema ────────────────────────────

    public function test_il_form_di_default_mostra_i_campi_standard_visibili(): void
    {
        $this->actingAs($this->account, 'account')
            ->get($this->urlTenant('iscriviti'))
            ->assertOk()
            ->assertSee('Telefono')
            ->assertSee('Data di nascita')
            ->assertSee('Città')
            // Default: indirizzo, provincia e cap NON visibili
            ->assertDontSee('name="indirizzo"', false)
            ->assertDontSee('name="provincia"', false)
            ->assertDontSee('name="cap"', false);
    }

    public function test_il_form_mostra_i_campi_configurati_e_l_intro(): void
    {
        $select = $this->campoPersonalizzato([
            'nome_campo' => 'Taglia maglietta',
            'tipo_campo' => 'select',
            'opzioni' => "S\nM\nL",
        ]);

        $this->associazione->update(['iscrizione_form_config' => [
            'intro_html' => '<p>Benvenuto nel nostro modulo</p>',
            'campi_standard' => [
                ['campo' => 'cap', 'visibile' => true, 'obbligatorio' => true],
                ['campo' => 'telefono', 'visibile' => true, 'obbligatorio' => false],
                ['campo' => 'codice_fiscale', 'visibile' => false, 'obbligatorio' => false],
            ],
            'campi_personalizzati' => [$select->id],
        ]]);

        $this->actingAs($this->account, 'account')
            ->get($this->urlTenant('iscriviti'))
            ->assertOk()
            ->assertSee('Benvenuto nel nostro modulo')
            ->assertSee('CAP')
            ->assertSee('Telefono')
            ->assertSee('Taglia maglietta')
            ->assertSee('<option value="M"', false)
            // Campo standard non visibile → non renderizzato
            ->assertDontSee('name="codice_fiscale"', false);
    }

    public function test_il_form_dopo_un_rifiuto_mostra_il_motivo(): void
    {
        RichiestaIscrizione::factory()->rifiutata('Documenti mancanti')->create([
            'associazione_id' => $this->associazione->id,
            'account_id' => $this->account->id,
        ]);

        $this->actingAs($this->account, 'account')
            ->get($this->urlTenant('iscriviti'))
            ->assertOk()
            ->assertSee('rifiutata')
            ->assertSee('Documenti mancanti');
    }

    // ── POST: validazione ─────────────────────────────────────────────

    public function test_campo_obbligatorio_mancante_da_errore(): void
    {
        // Default: telefono e data_nascita obbligatori
        $this->actingAs($this->account, 'account')
            ->from($this->urlTenant('iscriviti'))
            ->post($this->urlTenant('iscriviti'), [
                'citta' => 'Milano',
            ])
            ->assertRedirect($this->urlTenant('iscriviti'))
            ->assertSessionHasErrors(['telefono', 'data_nascita']);

        $this->assertSame(0, RichiestaIscrizione::allTenants()->count());
        $this->assertSame(0, Socio::allTenants()->count());
    }

    public function test_campo_personalizzato_obbligatorio_mancante_da_errore(): void
    {
        $campo = $this->campoPersonalizzato(['obbligatorio' => true]);

        $this->associazione->update(['iscrizione_form_config' => [
            'campi_standard' => [['campo' => 'telefono', 'visibile' => true, 'obbligatorio' => true]],
            'campi_personalizzati' => [$campo->id],
        ]]);

        $this->actingAs($this->account, 'account')
            ->from($this->urlTenant('iscriviti'))
            ->post($this->urlTenant('iscriviti'), ['telefono' => '3331234567'])
            ->assertRedirect($this->urlTenant('iscriviti'))
            ->assertSessionHasErrors(['custom_field.'.$campo->id]);
    }

    // ── POST: flusso felice preiscrizione ────────────────────────────

    public function test_flusso_felice_preiscrizione(): void
    {
        $campo = $this->campoPersonalizzato(['nome_campo' => 'Professione']);

        $this->associazione->update([
            'modalita_iscrizione' => Associazione::MODALITA_PREISCRIZIONE,
            'iscrizione_form_config' => [
                'campi_standard' => [
                    ['campo' => 'telefono', 'visibile' => true, 'obbligatorio' => true],
                    ['campo' => 'data_nascita', 'visibile' => true, 'obbligatorio' => true],
                ],
                'campi_personalizzati' => [$campo->id],
            ],
        ]);

        $this->actingAs($this->account, 'account')
            ->post($this->urlTenant('iscriviti'), [
                'telefono' => '3331234567',
                'data_nascita' => '1990-05-10',
                'custom_field' => [$campo->id => 'Falegname'],
            ])
            ->assertRedirect($this->urlTenant('iscriviti'))
            ->assertSessionHas('success');

        $richiesta = RichiestaIscrizione::allTenants()
            ->where('account_id', $this->account->id)
            ->sole();

        $this->assertSame(RichiestaIscrizione::STATO_IN_ATTESA, $richiesta->stato);

        $socio = Socio::allTenants()->findOrFail($richiesta->socio_id);
        $this->assertSame(Socio::STATO_IN_ATTESA, $socio->stato);
        $this->assertSame($this->account->nome, $socio->nome);
        $this->assertSame('3331234567', $socio->telefono);

        $this->assertSame(
            'Falegname',
            ValoreCampoPersonalizzato::query()
                ->where('socio_id', $socio->id)
                ->where('campo_id', $campo->id)
                ->sole()
                ->valore,
        );

        // L'account NON è ancora collegato (lo farà l'approvazione admin)
        $this->assertNull($this->account->fresh()->socio_id);

        // Il follow-up sul form mostra la pagina di stato
        $this->actingAs($this->account, 'account')
            ->get($this->urlTenant('iscriviti'))
            ->assertOk()
            ->assertSee('Richiesta in attesa di approvazione');
    }

    // ── POST: flusso felice modalità diretta ─────────────────────────

    public function test_flusso_felice_modalita_diretta(): void
    {
        $this->associazione->update([
            'modalita_iscrizione' => Associazione::MODALITA_DIRETTA,
        ]);

        $this->actingAs($this->account, 'account')
            ->post($this->urlTenant('iscriviti'), $this->datiValidi())
            ->assertRedirect($this->urlTenant('account/tessera'))
            ->assertSessionHas('success');

        $account = $this->account->fresh();
        $this->assertNotNull($account->socio_id);

        $socio = Socio::allTenants()->findOrFail($account->socio_id);
        $this->assertSame(Socio::STATO_ATTIVO, $socio->stato);

        $richiesta = RichiestaIscrizione::allTenants()
            ->where('account_id', $account->id)
            ->sole();
        $this->assertSame(RichiestaIscrizione::STATO_APPROVATA, $richiesta->stato);
    }

    // ── POST: guardie di dominio ─────────────────────────────────────

    public function test_doppia_richiesta_in_attesa_bloccata(): void
    {
        RichiestaIscrizione::factory()->inAttesa()->create([
            'associazione_id' => $this->associazione->id,
            'account_id' => $this->account->id,
        ]);

        $this->actingAs($this->account, 'account')
            ->post($this->urlTenant('iscriviti'), $this->datiValidi())
            ->assertRedirect($this->urlTenant('iscriviti'))
            ->assertSessionHasErrors('iscrizione');

        $this->assertSame(
            1,
            RichiestaIscrizione::allTenants()->where('account_id', $this->account->id)->count(),
        );
        $this->assertSame(0, Socio::allTenants()->count());
    }

    public function test_post_con_iscrizioni_chiuse_da_errore(): void
    {
        $this->associazione->update(['iscrizioni_socio_aperte' => false]);

        $this->actingAs($this->account, 'account')
            ->post($this->urlTenant('iscriviti'), $this->datiValidi())
            ->assertRedirect($this->urlTenant('iscriviti'))
            ->assertSessionHasErrors('iscrizione');

        $this->assertSame(0, RichiestaIscrizione::allTenants()->count());
        $this->assertSame(0, Socio::allTenants()->count());
    }

    // ── Diventa-socio: redirect al nuovo form e stato richiesta ──────

    public function test_diventa_socio_reindirizza_al_nuovo_form(): void
    {
        $this->actingAs($this->account, 'account')
            ->get($this->urlTenant('account/diventa-socio'))
            ->assertRedirect($this->urlTenant('iscriviti'));
    }

    public function test_diventa_socio_mostra_lo_stato_in_attesa(): void
    {
        RichiestaIscrizione::factory()->inAttesa()->create([
            'associazione_id' => $this->associazione->id,
            'account_id' => $this->account->id,
        ]);

        $this->actingAs($this->account, 'account')
            ->get($this->urlTenant('account/diventa-socio'))
            ->assertOk()
            ->assertSee('Richiesta inviata il');
    }

    public function test_diventa_socio_mostra_il_rifiuto_con_motivo(): void
    {
        RichiestaIscrizione::factory()->rifiutata('Quota associativa non versata')->create([
            'associazione_id' => $this->associazione->id,
            'account_id' => $this->account->id,
        ]);

        $this->actingAs($this->account, 'account')
            ->get($this->urlTenant('account/diventa-socio'))
            ->assertOk()
            ->assertSee('Richiesta rifiutata')
            ->assertSee('Quota associativa non versata');
    }

    // ── Dashboard account: stato richiesta ───────────────────────────

    public function test_dashboard_mostra_la_richiesta_in_attesa(): void
    {
        RichiestaIscrizione::factory()->inAttesa()->create([
            'associazione_id' => $this->associazione->id,
            'account_id' => $this->account->id,
        ]);

        $this->actingAs($this->account, 'account')
            ->get($this->urlTenant('account'))
            ->assertOk()
            ->assertSee('Richiesta in attesa');
    }

    public function test_dashboard_mostra_il_rifiuto_con_motivo_e_link_per_riprovare(): void
    {
        RichiestaIscrizione::factory()->rifiutata('Dati incompleti')->create([
            'associazione_id' => $this->associazione->id,
            'account_id' => $this->account->id,
        ]);

        $this->actingAs($this->account, 'account')
            ->get($this->urlTenant('account'))
            ->assertOk()
            ->assertSee('Richiesta rifiutata')
            ->assertSee('Dati incompleti')
            ->assertSee($this->urlTenant('iscriviti'));
    }
}
