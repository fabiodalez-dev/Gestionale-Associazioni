<?php

namespace Tests\Feature\Foundation;

use App\Models\Accesso;
use App\Models\Annuncio;
use App\Models\Associazione;
use App\Models\AttivitaAdmin;
use App\Models\CategoriaMovimento;
use App\Models\Donazione;
use App\Models\Evento;
use App\Models\Movimento;
use App\Models\NewsletterInviata;
use App\Models\NotaSocio;
use App\Models\Nucleo;
use App\Models\Quota;
use App\Models\RichiestaModificaDati;
use App\Models\RispostaSondaggio;
use App\Models\Socio;
use App\Models\Sondaggio;
use App\Models\SpesaEvento;
use App\Models\Utente;
use Database\Seeders\RolesSeeder;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke test delle fondamenta "Gestione 2" (docs/PIANO-GESTIONE-2.md):
 * schema additivo, model nuovi (factory + tenancy), estensioni ai model
 * condivisi, subscriber audit accessi e voci sidebar.
 */
class Gestione2FoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creano_tutti_i_model_nuovi_con_tenant_coerente(): void
    {
        $nota = NotaSocio::factory()->create();
        $attivita = AttivitaAdmin::factory()->create();
        $annuncio = Annuncio::factory()->create();
        $newsletter = NewsletterInviata::factory()->create();
        $risposta = RispostaSondaggio::factory()->create();
        $categoria = CategoriaMovimento::factory()->create();
        $movimento = Movimento::factory()->create();
        $donazione = Donazione::factory()->create();
        $spesa = SpesaEvento::factory()->create();
        $richiesta = RichiestaModificaDati::factory()->create();
        $nucleo = Nucleo::factory()->create();
        $accesso = Accesso::factory()->create();

        $this->assertSame($nota->socio->associazione_id, $nota->associazione_id);
        $this->assertSame($risposta->sondaggio->associazione_id, $risposta->associazione_id);
        $this->assertSame($risposta->votante->associazione_id, $risposta->associazione_id);
        $this->assertSame($spesa->evento->associazione_id, $spesa->associazione_id);
        $this->assertSame($richiesta->socio->associazione_id, $richiesta->associazione_id);
        $this->assertTrue($richiesta->origine->is($richiesta->socio));
        $this->assertNotNull($attivita->associazione_id);
        $this->assertNotNull($annuncio->associazione_id);
        $this->assertNotNull($newsletter->associazione_id);
        $this->assertNotNull($categoria->associazione_id);
        $this->assertNotNull($movimento->associazione_id);
        $this->assertNotNull($donazione->associazione_id);
        $this->assertNotNull($nucleo->associazione_id);
        $this->assertSame(Accesso::ESITO_LOGIN, $accesso->esito);
    }

    public function test_i_model_nuovi_sono_scopati_per_tenant(): void
    {
        $movimentoA = Movimento::factory()->create();
        Movimento::factory()->create(); // altro tenant

        $admin = Utente::factory()->adminAssociazione()
            ->create(['associazione_id' => $movimentoA->associazione_id]);
        $this->actingAs($admin);

        $this->assertSame(1, Movimento::count());
        $this->assertSame(2, Movimento::allTenants()->count());
    }

    public function test_risposta_sondaggio_unica_per_sondaggio_e_votante(): void
    {
        $risposta = RispostaSondaggio::factory()->create();

        // Stesso votante su altro sondaggio dello stesso tenant: consentito
        $altroSondaggio = Sondaggio::factory()->create([
            'associazione_id' => $risposta->associazione_id,
        ]);
        RispostaSondaggio::factory()->create([
            'sondaggio_id' => $altroSondaggio->id,
            'associazione_id' => $risposta->associazione_id,
            'votante_type' => $risposta->votante_type,
            'votante_id' => $risposta->votante_id,
        ]);

        $this->expectException(QueryException::class);

        RispostaSondaggio::factory()->create([
            'sondaggio_id' => $risposta->sondaggio_id,
            'associazione_id' => $risposta->associazione_id,
            'votante_type' => $risposta->votante_type,
            'votante_id' => $risposta->votante_id,
        ]);
    }

    public function test_movimento_unico_per_origine_e_morph_one(): void
    {
        $quota = Quota::factory()->create();

        $movimento = Movimento::factory()->entrata()->create([
            'associazione_id' => $quota->associazione_id,
            'origine_type' => Quota::class,
            'origine_id' => $quota->id,
        ]);

        $this->assertTrue(
            Quota::withoutGlobalScopes()->find($quota->id)->movimento->is($movimento)
        );
        $this->assertTrue($movimento->origine->is($quota));
        $this->assertSame(-10.0, Movimento::factory()->uscita()->make(['importo' => 10])->importoConSegno());

        $this->expectException(QueryException::class);

        Movimento::factory()->create([
            'associazione_id' => $quota->associazione_id,
            'origine_type' => Quota::class,
            'origine_id' => $quota->id,
        ]);
    }

    public function test_socio_con_nucleo_tutore_e_minorenne(): void
    {
        $nucleo = Nucleo::factory()->create();
        $socio = Socio::factory()->create([
            'associazione_id' => $nucleo->associazione_id,
            'nucleo_id' => $nucleo->id,
            'data_nascita' => now()->subYears(10)->toDateString(),
            'tutore_nome' => 'Mario Rossi',
            'tutore_telefono' => '+39 333 1234567',
            'tutore_email' => 'tutore@example.com',
        ]);
        $nucleo->update(['capofamiglia_id' => $socio->id]);

        $this->assertTrue($socio->nucleo->is($nucleo));
        $this->assertTrue($nucleo->fresh()->capofamiglia->is($socio));
        $this->assertTrue($nucleo->membri()->first()->is($socio));
        $this->assertTrue($socio->isMinorenne());
        $this->assertTrue($socio->hasTutore());

        $adulto = Socio::factory()->create([
            'associazione_id' => $nucleo->associazione_id,
            'data_nascita' => now()->subYears(30)->toDateString(),
        ]);
        $this->assertFalse($adulto->isMinorenne());
        $this->assertFalse($adulto->hasTutore());
        // Senza data di nascita non si può affermare la minore età
        $adulto->data_nascita = null;
        $this->assertFalse($adulto->isMinorenne());
    }

    public function test_nota_socio_con_pin_e_relazioni(): void
    {
        $nota = NotaSocio::factory()->create();
        NotaSocio::factory()->fissata()->create([
            'socio_id' => $nota->socio_id,
            'associazione_id' => $nota->associazione_id,
        ]);

        $socio = Socio::withoutGlobalScopes()->find($nota->socio_id);
        $this->assertSame(2, $socio->noteInterne()->count());
        $this->assertSame(1, NotaSocio::withoutGlobalScopes()->fissate()->count());
    }

    public function test_evento_con_budget_e_spese(): void
    {
        $evento = Evento::factory()->create(['budget' => 150.50]);
        SpesaEvento::factory()->count(2)->create([
            'evento_id' => $evento->id,
            'associazione_id' => $evento->associazione_id,
            'importo' => 10,
        ]);

        $evento = $evento->fresh();
        $this->assertSame('150.50', $evento->budget);
        $this->assertSame(2, $evento->spese()->count());
        $this->assertSame(20.0, (float) $evento->spese()->sum('importo'));
    }

    public function test_associazione_auguri_automatici_default_disattivo(): void
    {
        $assoc = Associazione::factory()->create();

        $this->assertFalse($assoc->fresh()->auguri_automatici);

        $assoc->update(['auguri_automatici' => true]);
        $this->assertTrue($assoc->fresh()->auguri_automatici);
    }

    public function test_annuncio_finestra_di_pubblicazione_e_solo_soci(): void
    {
        $pubblico = Annuncio::factory()->create();
        Annuncio::factory()->bozza()->create(['associazione_id' => $pubblico->associazione_id]);
        Annuncio::factory()->scaduto()->create(['associazione_id' => $pubblico->associazione_id]);
        Annuncio::factory()->soloSoci()->create(['associazione_id' => $pubblico->associazione_id]);

        $this->assertTrue($pubblico->isPubblicato());
        $this->assertSame(2, Annuncio::withoutGlobalScopes()->pubblicati()->count());
        $this->assertSame(
            [$pubblico->id],
            Annuncio::withoutGlobalScopes()->pubblici()->pluck('id')->all()
        );
    }

    public function test_sondaggio_apertura_e_risultati_aggregati(): void
    {
        $sondaggio = Sondaggio::factory()->create(['opzioni' => ['Sì', 'No', 'Non so']]);
        Sondaggio::factory()->chiuso()->create(['associazione_id' => $sondaggio->associazione_id]);

        $this->assertTrue($sondaggio->isAperto());
        $this->assertSame(1, Sondaggio::withoutGlobalScopes()->aperti()->count());

        RispostaSondaggio::factory()->count(2)->create([
            'sondaggio_id' => $sondaggio->id,
            'associazione_id' => $sondaggio->associazione_id,
            'opzione' => 0,
        ]);
        RispostaSondaggio::factory()->create([
            'sondaggio_id' => $sondaggio->id,
            'associazione_id' => $sondaggio->associazione_id,
            'opzione' => 2,
        ]);

        $this->assertSame([0 => 2, 1 => 0, 2 => 1], $sondaggio->risultati());
    }

    public function test_attivita_admin_aperte_e_in_scadenza(): void
    {
        $oggi = AttivitaAdmin::factory()->create(['scadenza' => now()->toDateString()]);
        AttivitaAdmin::factory()->create([
            'associazione_id' => $oggi->associazione_id,
            'scadenza' => now()->addDays(3)->toDateString(),
        ]);
        AttivitaAdmin::factory()->completata()->create([
            'associazione_id' => $oggi->associazione_id,
            'scadenza' => now()->toDateString(),
        ]);

        $this->assertFalse($oggi->isCompletata());
        $this->assertSame(2, AttivitaAdmin::withoutGlobalScopes()->aperte()->count());
        $this->assertSame(
            [$oggi->id],
            AttivitaAdmin::withoutGlobalScopes()->inScadenzaIl(now())->pluck('id')->all()
        );
    }

    public function test_richiesta_modifica_dati_in_attesa_e_gestione(): void
    {
        $inAttesa = RichiestaModificaDati::factory()->create([
            'proposte' => ['telefono' => '333 0000000', 'citta' => 'Milano'],
        ]);
        RichiestaModificaDati::factory()->approvata()->create([
            'associazione_id' => $inAttesa->associazione_id,
            'socio_id' => $inAttesa->socio_id,
        ]);

        $this->assertTrue($inAttesa->isInAttesa());
        $this->assertSame(['telefono' => '333 0000000', 'citta' => 'Milano'], $inAttesa->fresh()->proposte);
        $this->assertSame(
            [$inAttesa->id],
            RichiestaModificaDati::withoutGlobalScopes()->inAttesa()->pluck('id')->all()
        );
    }

    public function test_donazione_pagabile_e_nome_pubblico(): void
    {
        $donazione = Donazione::factory()->pagata()->create(['donatore_nome' => 'Anna Verdi']);

        $this->assertTrue($donazione->isPagata());
        $this->assertSame('donazione', $donazione->getPagabileTipo());
        $this->assertSame($donazione->associazione_id, $donazione->getAssociazioneId());
        $this->assertSame((float) $donazione->importo, $donazione->getImporto());
        $this->assertSame('Anna Verdi', $donazione->nomePubblico());

        $anonima = Donazione::factory()->anonima()->create([
            'associazione_id' => $donazione->associazione_id,
        ]);
        $this->assertSame('Anonimo', $anonima->nomePubblico());
        $this->assertSame(1, Donazione::withoutGlobalScopes()->pagate()->count());
    }

    public function test_subscriber_registra_login_failed_e_logout_in_accessi(): void
    {
        $assoc = Associazione::factory()->create();
        $utente = Utente::factory()->adminAssociazione()->create(['associazione_id' => $assoc->id]);

        // Il tentativo su email sconosciuta avviene a sessione pulita (nessun
        // contesto tenant): il subscriber non può attribuire l'associazione
        event(new Failed('web', null, ['email' => 'sconosciuto@example.com', 'password' => 'x']));
        event(new Login('web', $utente, false));
        event(new Failed('web', $utente, ['email' => $utente->email, 'password' => 'sbagliata']));
        event(new Logout('web', $utente));

        $accessi = Accesso::allTenants()->orderBy('created_at')->get();
        $this->assertCount(4, $accessi);

        $login = $accessi->firstWhere('esito', Accesso::ESITO_LOGIN);
        $this->assertSame($assoc->id, $login->associazione_id);
        $this->assertSame('web', $login->guard);
        $this->assertSame($utente->id, $login->autenticabile_id);
        $this->assertSame($utente->email, $login->email_tentata);
        $this->assertTrue($login->autenticabile->is($utente));

        $fallitoSconosciuto = $accessi->firstWhere('email_tentata', 'sconosciuto@example.com');
        $this->assertSame(Accesso::ESITO_FALLITO, $fallitoSconosciuto->esito);
        $this->assertNull($fallitoSconosciuto->associazione_id);
        $this->assertNull($fallitoSconosciuto->autenticabile_id);

        $this->assertSame(1, Accesso::allTenants()->where('esito', Accesso::ESITO_LOGOUT)->count());
        $this->assertSame(
            2,
            Accesso::query()->fallitiRecentiPerEmail($utente->email)->count()
                + Accesso::query()->fallitiRecentiPerEmail('sconosciuto@example.com')->count()
        );
    }

    public function test_sidebar_mostra_le_nuove_voci_e_la_ricerca_globale(): void
    {
        $this->seed(RolesSeeder::class);

        $assoc = Associazione::factory()->create();
        $admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $assoc->id]);
        $admin->assignRole('admin_associazione');

        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('globalSearchBtn');
        $response->assertSee('Attività');
        $response->assertSee('Scadenzario');
        $response->assertSee('Richieste modifiche');
        $response->assertSee('Economia');
        $response->assertSee('Prima nota');
        $response->assertSee('Report economico');
        $response->assertSee('Donazioni');
        $response->assertSee('Annunci');
        $response->assertSee('Newsletter');
        $response->assertSee('Sondaggi');
        $response->assertSee('Registro modifiche');
        $response->assertSee('Cestino');
        $response->assertSee('Audit accessi');
    }
}
