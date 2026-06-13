<?php

namespace Tests\Feature\Foundation;

use App\Models\Adempimento;
use App\Models\AdempimentoAnno;
use App\Models\Associazione;
use App\Models\Attrezzatura;
use App\Models\ContattoEsterno;
use App\Models\Evento;
use App\Models\Mansione;
use App\Models\MilestoneProgetto;
use App\Models\Movimento;
use App\Models\PrenotazioneSpazio;
use App\Models\PrestitoAttrezzatura;
use App\Models\Progetto;
use App\Models\RichiestaIscrizione;
use App\Models\Riunione;
use App\Models\RiunionePartecipante;
use App\Models\Socio;
use App\Models\Spazio;
use App\Models\Sponsor;
use App\Models\Turno;
use App\Models\TurnoAssegnazione;
use App\Models\Utente;
use App\Models\VotazioneRiunione;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke test delle fondamenta "Organizzazione 3" (docs/PIANO-ORGANIZZAZIONE-3.md):
 * schema additivo, model nuovi (factory + tenancy), estensioni ai model
 * condivisi (Socio, Evento, Movimento, RichiestaIscrizione) e voci sidebar.
 */
class Organizzazione3FoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creano_tutti_i_model_nuovi_con_tenant_coerente(): void
    {
        $mansione = Mansione::factory()->create();
        $turno = Turno::factory()->create();
        $assegnazione = TurnoAssegnazione::factory()->create();
        $spazio = Spazio::factory()->create();
        $prenotazione = PrenotazioneSpazio::factory()->create();
        $attrezzatura = Attrezzatura::factory()->create();
        $prestito = PrestitoAttrezzatura::factory()->create();
        $progetto = Progetto::factory()->create();
        $milestone = MilestoneProgetto::factory()->create();
        $riunione = Riunione::factory()->create();
        $partecipante = RiunionePartecipante::factory()->create();
        $votazione = VotazioneRiunione::factory()->create();
        $adempimento = Adempimento::factory()->create();
        $adempimentoAnno = AdempimentoAnno::factory()->create();
        $contatto = ContattoEsterno::factory()->create();
        $sponsor = Sponsor::factory()->create();

        $this->assertNotNull($mansione->associazione_id);
        $this->assertNotNull($turno->associazione_id);
        $this->assertNotNull($spazio->associazione_id);
        $this->assertNotNull($attrezzatura->associazione_id);
        $this->assertNotNull($progetto->associazione_id);
        $this->assertNotNull($riunione->associazione_id);
        $this->assertNotNull($adempimento->associazione_id);
        $this->assertNotNull($contatto->associazione_id);
        $this->assertNotNull($sponsor->associazione_id);

        $this->assertSame($assegnazione->turno->associazione_id, $assegnazione->associazione_id);
        $this->assertSame($assegnazione->socio->associazione_id, $assegnazione->associazione_id);
        $this->assertSame($prenotazione->spazio->associazione_id, $prenotazione->associazione_id);
        $this->assertSame($prestito->attrezzatura->associazione_id, $prestito->associazione_id);
        $this->assertSame($prestito->socio->associazione_id, $prestito->associazione_id);
        $this->assertSame($milestone->progetto->associazione_id, $milestone->associazione_id);
        $this->assertSame($partecipante->riunione->associazione_id, $partecipante->associazione_id);
        $this->assertSame($partecipante->socio->associazione_id, $partecipante->associazione_id);
        $this->assertSame($votazione->riunione->associazione_id, $votazione->associazione_id);
        $this->assertSame($adempimentoAnno->adempimento->associazione_id, $adempimentoAnno->associazione_id);
    }

    public function test_i_model_nuovi_sono_scopati_per_tenant(): void
    {
        $turnoA = Turno::factory()->create();
        Turno::factory()->create(); // altro tenant
        Progetto::factory()->create(['associazione_id' => $turnoA->associazione_id]);
        Progetto::factory()->create(); // altro tenant

        $admin = Utente::factory()->adminAssociazione()
            ->create(['associazione_id' => $turnoA->associazione_id]);
        $this->actingAs($admin);

        $this->assertSame(1, Turno::count());
        $this->assertSame(2, Turno::allTenants()->count());
        $this->assertSame(1, Progetto::count());
        $this->assertSame(2, Progetto::allTenants()->count());
    }

    public function test_socio_volontario_disponibilita_mansioni_e_referral(): void
    {
        $socio = Socio::factory()->create([
            'volontario_attivo' => true,
            'disponibilita' => ['lun' => ['mattina', 'sera'], 'sab' => ['pomeriggio']],
        ]);

        // f.23: codice referral generato automaticamente alla creazione
        $this->assertNotNull($socio->codice_referral);
        $this->assertSame(10, strlen($socio->codice_referral));

        $socio = $socio->fresh();
        $this->assertTrue($socio->volontario_attivo);
        $this->assertSame(['lun' => ['mattina', 'sera'], 'sab' => ['pomeriggio']], $socio->disponibilita);

        // f.2: mansioni assegnate via pivot socio_mansioni
        $mansione = Mansione::factory()->create(['associazione_id' => $socio->associazione_id]);
        $socio->mansioni()->attach($mansione->id);
        $this->assertTrue($socio->mansioni()->first()->is($mansione));
        $this->assertTrue($mansione->soci()->first()->is($socio));

        // f.23: la richiesta di iscrizione aggancia il referral del socio
        $richiesta = RichiestaIscrizione::factory()->create([
            'associazione_id' => $socio->associazione_id,
            'referral_codice' => $socio->codice_referral,
        ]);
        $this->assertTrue($richiesta->socioReferente->is($socio));
        $this->assertTrue($socio->iscrizioniReferral()->first()->is($richiesta));
    }

    public function test_codice_referral_unico(): void
    {
        $socio = Socio::factory()->create();

        $this->expectException(QueryException::class);

        Socio::factory()->create(['codice_referral' => $socio->codice_referral]);
    }

    public function test_turno_assegnazione_unica_per_turno_e_socio(): void
    {
        $assegnazione = TurnoAssegnazione::factory()->confermata()->create();

        $this->assertSame(
            1,
            Turno::allTenants()->find($assegnazione->turno_id)->postiDisponibili()
        );

        $this->expectException(QueryException::class);

        TurnoAssegnazione::factory()->create([
            'associazione_id' => $assegnazione->associazione_id,
            'turno_id' => $assegnazione->turno_id,
            'socio_id' => $assegnazione->socio_id,
        ]);
    }

    public function test_turno_durata_ore_e_collegamenti(): void
    {
        $evento = Evento::factory()->create();
        $spazio = Spazio::factory()->create(['associazione_id' => $evento->associazione_id]);
        $progetto = Progetto::factory()->create(['associazione_id' => $evento->associazione_id]);

        $turno = Turno::factory()->create([
            'associazione_id' => $evento->associazione_id,
            'evento_id' => $evento->id,
            'spazio_id' => $spazio->id,
            'progetto_id' => $progetto->id,
            'ora_inizio' => '09:00:00',
            'ora_fine' => '12:30:00',
        ]);

        $this->assertSame(3.5, $turno->durataOre());
        $this->assertTrue($turno->evento->is($evento));
        $this->assertTrue($turno->spazio->is($spazio));
        $this->assertTrue($turno->progetto->is($progetto));
        $this->assertTrue($evento->turni()->first()->is($turno));
        $this->assertTrue($progetto->turni()->first()->is($turno));
    }

    public function test_prenotazione_spazio_rileva_conflitti_di_overlap(): void
    {
        $esistente = PrenotazioneSpazio::factory()->create([
            'dal' => now()->addDay()->setTime(10, 0),
            'al' => now()->addDay()->setTime(12, 0),
        ]);

        $conflitti = PrenotazioneSpazio::allTenants()->inConflittoCon(
            $esistente->spazio_id,
            now()->addDay()->setTime(11, 0),
            now()->addDay()->setTime(13, 0),
        )->get();

        $this->assertSame([$esistente->id], $conflitti->pluck('id')->all());

        // Intervallo adiacente (inizia quando finisce l'altra): nessun conflitto
        $this->assertSame(0, PrenotazioneSpazio::allTenants()->inConflittoCon(
            $esistente->spazio_id,
            now()->addDay()->setTime(12, 0),
            now()->addDay()->setTime(14, 0),
        )->count());

        // La prenotazione stessa viene ignorata in fase di modifica
        $this->assertSame(0, PrenotazioneSpazio::allTenants()->inConflittoCon(
            $esistente->spazio_id,
            now()->addDay()->setTime(11, 0),
            now()->addDay()->setTime(13, 0),
            $esistente->id,
        )->count());
    }

    public function test_attrezzatura_disponibilita_e_prestiti_in_ritardo(): void
    {
        $attrezzatura = Attrezzatura::factory()->create(['quantita' => 5]);
        PrestitoAttrezzatura::factory()->create([
            'associazione_id' => $attrezzatura->associazione_id,
            'attrezzatura_id' => $attrezzatura->id,
            'quantita' => 2,
        ]);
        PrestitoAttrezzatura::factory()->restituito()->create([
            'associazione_id' => $attrezzatura->associazione_id,
            'attrezzatura_id' => $attrezzatura->id,
            'quantita' => 2,
        ]);
        $inRitardo = PrestitoAttrezzatura::factory()->inRitardo()->create([
            'associazione_id' => $attrezzatura->associazione_id,
            'attrezzatura_id' => $attrezzatura->id,
            'quantita' => 1,
        ]);

        $this->assertSame(3, $attrezzatura->quantitaInPrestito());
        $this->assertSame(2, $attrezzatura->quantitaDisponibile());
        $this->assertTrue($inRitardo->isInRitardo());
        $this->assertSame(
            [$inRitardo->id],
            PrestitoAttrezzatura::allTenants()->inRitardo()->pluck('id')->all()
        );
    }

    public function test_progetto_con_milestone_movimenti_e_avanzamento(): void
    {
        $progetto = Progetto::factory()->create();
        MilestoneProgetto::factory()->completata()->create([
            'associazione_id' => $progetto->associazione_id,
            'progetto_id' => $progetto->id,
        ]);
        $scaduta = MilestoneProgetto::factory()->scaduta()->create([
            'associazione_id' => $progetto->associazione_id,
            'progetto_id' => $progetto->id,
        ]);

        $this->assertSame(50, $progetto->percentualeAvanzamento());
        $this->assertTrue($scaduta->isScaduta());

        // f.13: movimento di prima nota imputato al progetto
        $movimento = Movimento::factory()->entrata()->create([
            'associazione_id' => $progetto->associazione_id,
            'progetto_id' => $progetto->id,
        ]);
        $this->assertTrue($movimento->progetto->is($progetto));
        $this->assertTrue($progetto->movimenti()->first()->is($movimento));

        // f.14: evento collegato al progetto
        $evento = Evento::factory()->create([
            'associazione_id' => $progetto->associazione_id,
            'progetto_id' => $progetto->id,
        ]);
        $this->assertTrue($progetto->eventi()->first()->is($evento));
    }

    public function test_riunione_con_odg_partecipanti_e_votazioni(): void
    {
        $riunione = Riunione::factory()->svolta()->create();

        $this->assertTrue($riunione->isSvolta());
        $this->assertCount(2, $riunione->odg);

        RiunionePartecipante::factory()->confermato()->create([
            'associazione_id' => $riunione->associazione_id,
            'riunione_id' => $riunione->id,
            'carica_ruolo' => 'Presidente',
        ]);
        $votazione = VotazioneRiunione::factory()->create([
            'associazione_id' => $riunione->associazione_id,
            'riunione_id' => $riunione->id,
            'punto_indice' => 0,
        ]);

        $this->assertSame(1, $riunione->partecipanti()->confermati()->count());
        $this->assertTrue($votazione->isApprovata());
        $this->assertSame('Approvazione bilancio', $votazione->puntoOdg());
        $this->assertSame(
            [$votazione->id],
            VotazioneRiunione::allTenants()->approvate()->pluck('id')->all()
        );
    }

    public function test_votazione_unica_per_riunione_e_punto(): void
    {
        $votazione = VotazioneRiunione::factory()->create(['punto_indice' => 0]);

        // Stesso punto su altra riunione dello stesso tenant: consentito
        $altraRiunione = Riunione::factory()->svolta()->create([
            'associazione_id' => $votazione->associazione_id,
        ]);
        VotazioneRiunione::factory()->create([
            'associazione_id' => $votazione->associazione_id,
            'riunione_id' => $altraRiunione->id,
            'punto_indice' => 0,
        ]);

        $this->expectException(QueryException::class);

        VotazioneRiunione::factory()->create([
            'associazione_id' => $votazione->associazione_id,
            'riunione_id' => $votazione->riunione_id,
            'punto_indice' => 0,
        ]);
    }

    public function test_adempimento_checklist_annuale_unica_per_anno(): void
    {
        $anno = (int) now()->format('Y');
        $riga = AdempimentoAnno::factory()->completato()->create(['anno' => $anno]);
        $adempimento = $riga->adempimento;

        $this->assertTrue($riga->isCompletato());
        $this->assertTrue($adempimento->isCompletatoPerAnno($anno));
        $this->assertFalse($adempimento->isCompletatoPerAnno($anno - 1));

        // Anno diverso per lo stesso adempimento: consentito
        AdempimentoAnno::factory()->create([
            'associazione_id' => $riga->associazione_id,
            'adempimento_id' => $riga->adempimento_id,
            'anno' => $anno - 1,
        ]);

        $this->expectException(QueryException::class);

        AdempimentoAnno::factory()->create([
            'associazione_id' => $riga->associazione_id,
            'adempimento_id' => $riga->adempimento_id,
            'anno' => $anno,
        ]);
    }

    public function test_sponsor_visibili_sul_sito_solo_nel_periodo(): void
    {
        $visibile = Sponsor::factory()->visibile()->create();
        Sponsor::factory()->visibile()->scaduto()->create([
            'associazione_id' => $visibile->associazione_id,
        ]);
        Sponsor::factory()->create([ // non visibile
            'associazione_id' => $visibile->associazione_id,
        ]);

        $this->assertTrue($visibile->isNelPeriodo());
        $this->assertSame(
            [$visibile->id],
            Sponsor::allTenants()->visibiliSulSito()->pluck('id')->all()
        );

        // f.22: sponsor agganciato alla scheda mini-CRM
        $contatto = ContattoEsterno::factory()->sponsor()->create([
            'associazione_id' => $visibile->associazione_id,
        ]);
        $visibile->update(['contatto_esterno_id' => $contatto->id]);
        $this->assertTrue($visibile->fresh()->contattoEsterno->is($contatto));
        $this->assertTrue($contatto->sponsorizzazioni()->first()->is($visibile));
    }

    public function test_evento_galleria_e_spazio(): void
    {
        $spazio = Spazio::factory()->create();
        $evento = Evento::factory()->create([
            'associazione_id' => $spazio->associazione_id,
            'spazio_id' => $spazio->id,
        ]);

        // f.24: default galleria non pubblica + collection 'galleria' registrata
        $this->assertFalse($evento->galleria_pubblica);
        $evento->registerMediaCollections();
        $collections = collect($evento->mediaCollections)->pluck('name');
        $this->assertTrue($collections->contains(Evento::COLLEZIONE_GALLERIA));

        // f.11: evento collegato allo spazio
        $this->assertTrue($evento->spazio->is($spazio));
        $this->assertTrue($spazio->eventi()->first()->is($evento));
    }

    public function test_sidebar_mostra_le_nuove_voci_organizzazione_e_relazioni(): void
    {
        $this->seed(RolesSeeder::class);

        $assoc = Associazione::factory()->create();
        $admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $assoc->id]);
        $admin->assignRole('admin_associazione');

        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Organizzazione');
        $response->assertSee('Volontari e turni');
        $response->assertSee('Spazi');
        $response->assertSee('Inventario');
        $response->assertSee('Prestiti');
        $response->assertSee('Progetti');
        $response->assertSee('Riunioni');
        $response->assertSee('Decisioni');
        $response->assertSee('Relazioni');
        $response->assertSee('Contatti esterni');
        $response->assertSee('Sponsor');
        $response->assertSee('Ambasciatori');
        $response->assertSee('Statistiche annuali');
    }
}
