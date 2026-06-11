<?php

namespace Tests\Feature\Prenotazioni;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Prenotazione;
use App\Models\Socio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Fondamenta del sistema prenotazioni (fase 1):
 * migrations, slug, guard account, tenant pubblico, rotta home placeholder.
 */
class FondamentaPrenotazioniTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // Evita che il binding del tenant pubblico sporchi gli altri test
        app()->forgetInstance('tenant.pubblico');

        parent::tearDown();
    }

    public function test_slug_generato_dal_nome_con_suffisso_su_collisione(): void
    {
        $a = Associazione::factory()->create(['nome' => 'Circolo Arci Prova']);
        $b = Associazione::factory()->create(['nome' => 'Circolo Arci Prova']);
        $c = Associazione::factory()->create(['nome' => 'Circolo Arci Prova']);

        $this->assertSame('circolo-arci-prova', $a->slug);
        $this->assertSame('circolo-arci-prova-2', $b->slug);
        $this->assertSame('circolo-arci-prova-3', $c->slug);
    }

    public function test_slug_esplicito_non_viene_sovrascritto(): void
    {
        $assoc = Associazione::factory()->create(['nome' => 'Qualunque', 'slug' => 'mio-slug']);

        $this->assertSame('mio-slug', $assoc->slug);
    }

    public function test_global_scope_usa_il_binding_tenant_pubblico(): void
    {
        $assocA = Associazione::factory()->create();
        $assocB = Associazione::factory()->create();

        Socio::factory()->count(2)->create(['associazione_id' => $assocA->id]);
        Socio::factory()->create(['associazione_id' => $assocB->id]);

        // Nessun utente autenticato, nessuna sessione tenant: vista globale
        $this->assertSame(3, Socio::count());

        app()->instance('tenant.pubblico', $assocA->id);

        $this->assertSame(2, Socio::count());
        $this->assertSame(3, Socio::allTenants()->count());

        app()->forgetInstance('tenant.pubblico');
    }

    public function test_associazione_id_autocompilata_dal_tenant_pubblico_alla_creazione(): void
    {
        $assoc = Associazione::factory()->create();

        app()->instance('tenant.pubblico', $assoc->id);

        $account = Account::factory()->create(['associazione_id' => null]);

        $this->assertSame($assoc->id, $account->associazione_id);

        app()->forgetInstance('tenant.pubblico');
    }

    public function test_guard_account_autentica_l_account(): void
    {
        $account = Account::factory()->create(['password' => 'segreta123']);

        $this->assertTrue(
            Auth::guard('account')->attempt([
                'email' => $account->email,
                'password' => 'segreta123',
            ])
        );

        $this->assertSame($account->id, Auth::guard('account')->id());
    }

    public function test_home_pubblica_404_su_slug_inesistente(): void
    {
        $this->get('/a/slug-che-non-esiste')->assertNotFound();
    }

    public function test_home_pubblica_404_su_frontend_disattivato(): void
    {
        $assoc = Associazione::factory()->create(['frontend_pubblico_attivo' => false]);

        $this->get('/a/'.$assoc->slug)->assertNotFound();
    }

    public function test_home_pubblica_renderizza_il_nome_associazione(): void
    {
        $assoc = Associazione::factory()->create([
            'nome' => 'Polisportiva Buon Vento',
            'frontend_pubblico_attivo' => true,
        ]);

        $this->get('/a/'.$assoc->slug)
            ->assertOk()
            ->assertSee('Polisportiva Buon Vento');
    }

    public function test_account_cross_tenant_viene_sloggato_dal_middleware(): void
    {
        $assocA = Associazione::factory()->create(['frontend_pubblico_attivo' => true]);
        $assocB = Associazione::factory()->create(['frontend_pubblico_attivo' => true]);

        $accountA = Account::factory()->create(['associazione_id' => $assocA->id]);

        $guardName = Auth::guard('account')->getName();

        // Sessione con login dell'account del tenant A, navigazione sul tenant B
        $this->withSession([$guardName => $accountA->id])
            ->get('/a/'.$assocB->slug)
            ->assertOk()
            ->assertSessionMissing($guardName);
    }

    public function test_account_stesso_tenant_resta_loggato(): void
    {
        $assoc = Associazione::factory()->create(['frontend_pubblico_attivo' => true]);
        $account = Account::factory()->create(['associazione_id' => $assoc->id]);

        $guardName = Auth::guard('account')->getName();

        $this->withSession([$guardName => $account->id])
            ->get('/a/'.$assoc->slug)
            ->assertOk()
            ->assertSessionHas($guardName, $account->id);
    }

    public function test_factory_prenotazione_coerente_sul_tenant(): void
    {
        $prenotazione = Prenotazione::factory()->create();

        $evento = Evento::allTenants()->find($prenotazione->evento_id);
        $account = Account::allTenants()->find($prenotazione->account_id);

        $this->assertSame($evento->associazione_id, $prenotazione->associazione_id);
        $this->assertSame($account->associazione_id, $prenotazione->associazione_id);
        $this->assertMatchesRegularExpression('/^PRN-[2-9A-HJKMNP-TV-Z]{6}$/', $prenotazione->codice);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $prenotazione->qr_token);
        $this->assertTrue($prenotazione->isAttiva());
    }

    public function test_posti_occupati_somma_solo_le_prenotazioni_attive(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(10)->create();

        Prenotazione::factory()->confermata()->create(['evento_id' => $evento->id, 'posti' => 2]);
        Prenotazione::factory()->inAttesaPagamento()->create(['evento_id' => $evento->id, 'posti' => 3]);
        Prenotazione::factory()->listaAttesa()->create(['evento_id' => $evento->id, 'posti' => 4]);
        Prenotazione::factory()->annullata()->create(['evento_id' => $evento->id, 'posti' => 1]);
        Prenotazione::factory()->scaduta()->create(['evento_id' => $evento->id, 'posti' => 1]);

        $this->assertSame(5, Prenotazione::postiOccupati($evento->id));
        $this->assertSame(5, $evento->postiDisponibili());
    }

    public function test_evento_is_prenotabile(): void
    {
        $pubblicato = Evento::factory()->pubblicato()->create([
            'data_evento' => now()->addWeek(),
        ]);
        $bozza = Evento::factory()->create(['data_evento' => now()->addWeek()]);
        $passato = Evento::factory()->pubblicato()->passato()->create();
        $iscrizioniChiuse = Evento::factory()->pubblicato()->create([
            'data_evento' => now()->addWeek(),
            'iscrizioni_al' => now()->subDay(),
        ]);

        $this->assertTrue($pubblicato->isPrenotabile());
        $this->assertFalse($bozza->isPrenotabile());
        $this->assertFalse($passato->isPrenotabile());
        $this->assertFalse($iscrizioniChiuse->isPrenotabile());
    }

    public function test_scope_pubblicati(): void
    {
        $assoc = Associazione::factory()->create();

        Evento::factory()->pubblicato()->create(['associazione_id' => $assoc->id]);
        Evento::factory()->create(['associazione_id' => $assoc->id]); // bozza

        app()->instance('tenant.pubblico', $assoc->id);

        $this->assertSame(1, Evento::pubblicati()->count());
        $this->assertSame(2, Evento::count());

        app()->forgetInstance('tenant.pubblico');
    }

    public function test_anonimizza_account_gdpr(): void
    {
        $account = Account::factory()->collegatoASocio()->create([
            'nome' => 'Mario',
            'cognome' => 'Rossi',
            'telefono' => '3331234567',
        ]);
        $prenotazione = Prenotazione::factory()->create([
            'associazione_id' => $account->associazione_id,
            'account_id' => $account->id,
            'evento_id' => Evento::factory()->create([
                'associazione_id' => $account->associazione_id,
            ])->id,
        ]);

        $account->refresh()->anonimizza();

        $anonimo = Account::allTenants()->withTrashed()->find($account->id);

        $this->assertTrue($anonimo->trashed());
        $this->assertFalse($anonimo->attivo);
        $this->assertSame('Account', $anonimo->nome);
        $this->assertSame('Anonimizzato', $anonimo->cognome);
        $this->assertStringStartsWith('anonimizzato-', $anonimo->email);
        $this->assertNull($anonimo->telefono);
        $this->assertNull($anonimo->socio_id);
        // La prenotazione (contabilità) resta intatta e collegata
        $this->assertDatabaseHas('prenotazioni', [
            'id' => $prenotazione->id,
            'account_id' => $account->id,
        ]);
    }

    public function test_account_collegato_a_socio_stessa_associazione(): void
    {
        $account = Account::factory()->collegatoASocio()->create();
        $account->refresh();

        $this->assertNotNull($account->socio_id);
        $socio = Socio::allTenants()->find($account->socio_id);
        $this->assertSame($account->associazione_id, $socio->associazione_id);
    }
}
