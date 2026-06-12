<?php

namespace Tests\Feature\FrontendPubblico;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\RichiestaIscrizione;
use App\Models\Socio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Honeypot\ProtectAgainstSpam;
use Tests\TestCase;

/**
 * Iscrizione all'associazione da OSPITE (senza account): il form
 * /a/{slug}/iscriviti è pubblico, chiede anagrafica + privacy e crea
 * la richiesta senza account collegato.
 */
class IscrizioneOspiteTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create([
            'slug' => 'ospiti',
            'frontend_pubblico_attivo' => true,
            'iscrizioni_socio_aperte' => true,
            'modalita_iscrizione' => 'preiscrizione',
        ]);

        $this->withoutMiddleware(ProtectAgainstSpam::class);
    }

    /** @return array<string, mixed> */
    private function payloadOspite(array $extra = []): array
    {
        return array_merge([
            'nome' => 'Giulia',
            'cognome' => 'Neri',
            'email' => 'giulia.neri@example.com',
            'telefono' => '+39 333 0000001',
            'data_nascita' => '1992-03-15',
            'privacy' => '1',
        ], $extra);
    }

    public function test_il_form_e_raggiungibile_anche_da_ospite(): void
    {
        $this->get('/a/ospiti/iscriviti')
            ->assertOk()
            ->assertSee('Iscriviti a')
            ->assertSee('name="nome"', false)
            ->assertSee('name="cognome"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="privacy"', false);
    }

    public function test_ospite_invia_la_richiesta_e_nasce_il_preiscritto_senza_account(): void
    {
        $this->post('/a/ospiti/iscriviti', $this->payloadOspite())
            ->assertRedirect(route('pubblico.home.show', 'ospiti'))
            ->assertSessionHas('success');

        $richiesta = RichiestaIscrizione::withoutGlobalScopes()->latest()->firstOrFail();
        $this->assertNull($richiesta->account_id);
        $this->assertSame(RichiestaIscrizione::STATO_IN_ATTESA, $richiesta->stato);
        $this->assertSame('giulia.neri@example.com', $richiesta->dati_form['email']);

        $socio = Socio::withoutGlobalScopes()->where('email', 'giulia.neri@example.com')->firstOrFail();
        $this->assertSame(Socio::STATO_IN_ATTESA, $socio->stato);
        $this->assertSame('Giulia', $socio->nome);
    }

    public function test_ospite_senza_privacy_viene_bloccato(): void
    {
        $this->post('/a/ospiti/iscriviti', $this->payloadOspite(['privacy' => null]))
            ->assertSessionHasErrors('privacy');

        $this->assertDatabaseCount('richieste_iscrizione', 0);
    }

    public function test_ospite_senza_anagrafica_viene_bloccato(): void
    {
        $this->post('/a/ospiti/iscriviti', $this->payloadOspite(['nome' => '', 'email' => 'non-valida']))
            ->assertSessionHasErrors(['nome', 'email']);
    }

    public function test_email_gia_di_un_socio_viene_rifiutata_con_messaggio_amichevole(): void
    {
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'giulia.neri@example.com',
        ]);

        $this->post('/a/ospiti/iscriviti', $this->payloadOspite())
            ->assertSessionHasErrors('iscrizione');
    }

    public function test_doppia_richiesta_ospite_con_stessa_email_viene_bloccata(): void
    {
        $this->post('/a/ospiti/iscriviti', $this->payloadOspite())->assertSessionHas('success');
        $this->post('/a/ospiti/iscriviti', $this->payloadOspite())->assertSessionHasErrors('iscrizione');

        $this->assertDatabaseCount('richieste_iscrizione', 1);
    }

    public function test_modalita_diretta_da_ospite_crea_subito_il_socio_attivo(): void
    {
        $this->associazione->update(['modalita_iscrizione' => 'diretta']);

        $this->post('/a/ospiti/iscriviti', $this->payloadOspite())
            ->assertRedirect(route('pubblico.home.show', 'ospiti'));

        $socio = Socio::withoutGlobalScopes()->where('email', 'giulia.neri@example.com')->firstOrFail();
        $this->assertSame(Socio::STATO_ATTIVO, $socio->stato);
    }

    public function test_account_loggato_continua_a_usare_il_flusso_collegato(): void
    {
        $account = Account::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($account, 'account')
            ->post('/a/ospiti/iscriviti', ['telefono' => '+39 333 0000002', 'data_nascita' => '1990-01-01'])
            ->assertSessionHas('success');

        $richiesta = RichiestaIscrizione::withoutGlobalScopes()->latest()->firstOrFail();
        $this->assertSame($account->id, $richiesta->account_id);
    }
}
