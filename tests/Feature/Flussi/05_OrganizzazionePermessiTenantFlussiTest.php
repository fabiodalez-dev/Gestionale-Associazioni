<?php

namespace Tests\Feature\Flussi;

use App\Mail\Piattaforma\NuovaRichiestaRegistrazioneMail;
use App\Mail\Piattaforma\VerificaEmailRegistrazioneMail;
use App\Models\Annuncio;
use App\Models\ApiKey;
use App\Models\Associazione;
use App\Models\Attrezzatura;
use App\Models\CategoriaMovimento;
use App\Models\CategoriaSocio;
use App\Models\ContattoEsterno;
use App\Models\Documento;
use App\Models\Evento;
use App\Models\EventoPartecipante;
use App\Models\Mansione;
use App\Models\MilestoneProgetto;
use App\Models\Movimento;
use App\Models\Nucleo;
use App\Models\OpportunitaCrm;
use App\Models\Prenotazione;
use App\Models\PrenotazioneSpazio;
use App\Models\PrestitoAttrezzatura;
use App\Models\Progetto;
use App\Models\Quota;
use App\Models\RichiestaRegistrazione;
use App\Models\Riunione;
use App\Models\Sede;
use App\Models\Socio;
use App\Models\Sondaggio;
use App\Models\Spazio;
use App\Models\Sponsor;
use App\Models\Tag;
use App\Models\Tessera;
use App\Models\TipoSocio;
use App\Models\Turno;
use App\Models\TurnoAssegnazione;
use App\Models\Utente;
use App\Models\Verbale;
use App\Notifications\VolontariTurni\CandidaturaTurnoNotification;
use App\Notifications\VolontariTurni\RinunciaTurnoNotification;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Flussi reali — organizzazione, permessi e multi-tenant.
 *
 * Volontari e turni, spazi e attrezzature, progetti, CRM, contatti e
 * sponsor, ruoli e perimetro per sede, isolamento tra associazioni, API v1
 * (chiavi e token), registrazione automatica e cestino. Ogni test segue più
 * richieste HTTP fatte da attori diversi (admin, operatori limitati, soci
 * nell'area riservata, client API, super admin) e verifica sul database che
 * i dati siano salvati — o rifiutati — con i valori giusti.
 */
class Flussi05_OrganizzazionePermessiTenantFlussiTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Lanterna-Velluto-Fiume-42';

    private Associazione $a;

    private Associazione $b;

    private Utente $adminA;

    private Utente $adminB;

    private Utente $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);
        config(['honeypot.enabled' => false]);
        Carbon::setTestNow('2026-06-01 09:00:00');

        $this->superAdmin = Utente::factory()->superAdmin()->create(['email' => 'super@piattaforma.test']);
        $this->superAdmin->assignRole(Utente::RUOLO_SUPER_ADMIN);

        $this->a = Associazione::factory()->create([
            'nome' => 'Circolo Il Faro',
            'slug' => 'circolo-il-faro',
            'frontend_pubblico_attivo' => true,
        ]);
        $this->b = Associazione::factory()->create(['nome' => 'Polisportiva Aurora', 'slug' => 'polisportiva-aurora']);

        $this->adminA = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->a->id,
            'nome' => 'Giovanna',
            'cognome' => 'Mantovani',
            'email' => 'giovanna@ilfaro.test',
        ]);
        $this->adminA->assignRole(Utente::RUOLO_ADMIN_ASSOCIAZIONE);

        $this->adminB = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->b->id,
            'email' => 'presidenza@aurora.test',
        ]);
        $this->adminB->assignRole(Utente::RUOLO_ADMIN_ASSOCIAZIONE);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('tenant.pubblico');
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    /**
     * Come un nuovo visitatore: nessuna sessione né utente dal passo precedente.
     */
    private function esci(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    private function sede(Associazione $associazione, string $nome): Sede
    {
        return Sede::query()->create(['associazione_id' => $associazione->id, 'nome' => $nome, 'citta' => 'Ferrara']);
    }

    /**
     * @param  array<string, mixed>  $attributi
     */
    private function socio(Associazione $associazione, string $nome, string $cognome, array $attributi = []): Socio
    {
        return Socio::factory()->create(array_merge([
            'associazione_id' => $associazione->id,
            'nome' => $nome,
            'cognome' => $cognome,
            'email' => strtolower($nome.'.'.$cognome).'@'.$associazione->slug.'.test',
        ], $attributi));
    }

    /**
     * Utente dello staff con i ruoli spatie indicati ed eventuali sedi.
     *
     * @param  list<string>  $ruoli
     * @param  list<string>  $sedi
     */
    private function staff(Associazione $associazione, string $nome, array $ruoli = ['operatore'], array $sedi = []): Utente
    {
        $utente = Utente::factory()->operatore()->create([
            'associazione_id' => $associazione->id,
            'nome' => $nome,
            'cognome' => 'Staff',
            'email' => strtolower($nome).'.staff@'.$associazione->slug.'.test',
            'password_hash' => self::PASSWORD,
        ]);
        $utente->syncRoles($ruoli);

        if ($sedi !== []) {
            $utente->assegnaSedi($sedi);
        }

        return $utente->fresh();
    }

    /**
     * @param  list<string>  $permessi
     * @param  array<string, mixed>  $attributi
     */
    private function chiaveApi(?Associazione $associazione, array $permessi, array $attributi = []): ApiKey
    {
        return ApiKey::query()->create(array_merge([
            'associazione_id' => $associazione?->id,
            'api_key' => bin2hex(random_bytes(32)),
            'nome' => 'Chiave '.($associazione->nome ?? 'globale'),
            'permessi' => $permessi,
            'attiva' => true,
        ], $attributi));
    }

    /**
     * @param  array<string, mixed>  $dati
     */
    private function api(string $metodo, string $uri, string $credenziale, array $dati = []): TestResponse
    {
        return $this->json($metodo, $uri, $dati, ['Authorization' => 'Bearer '.$credenziale]);
    }

    /**
     * Dati completi del form socio (modifica dalla segreteria).
     *
     * @return array<string, mixed>
     */
    private function formSocio(Socio $socio, array $override = []): array
    {
        return array_merge([
            'numero_socio' => $socio->numero_socio,
            'nome' => $socio->nome,
            'cognome' => $socio->cognome,
            'email' => $socio->email,
            'data_nascita' => '1985-04-12',
            'data_iscrizione' => '2026-01-10',
            'stato' => Socio::STATO_ATTIVO,
        ], $override);
    }

    /**
     * @return array<string, mixed>
     */
    private function moduloRegistrazione(array $override = []): array
    {
        return array_merge([
            'nome' => 'Banda Musicale Vallata',
            'tipo' => 'APS',
            'email' => 'info@bandavallata.test',
            'telefono' => '051 123456',
            'citta' => 'Bologna',
            'provincia' => 'bo',
            'numero_soci_stimato' => 80,
            'admin_nome' => 'Ruggero',
            'admin_cognome' => 'Tosi',
            'admin_email' => 'ruggero.tosi@bandavallata.test',
            'admin_password' => self::PASSWORD,
            'privacy' => '1',
        ], $override);
    }

    private function linkVerifica(string $email): string
    {
        $url = null;
        Mail::assertSent(VerificaEmailRegistrazioneMail::class, function (VerificaEmailRegistrazioneMail $m) use ($email, &$url) {
            if ($m->hasTo($email)) {
                $url = $m->url;
            }

            return $m->hasTo($email);
        });
        $this->assertNotNull($url, "Nessuna email di conferma inviata a {$email}");

        return (string) $url;
    }

    // ------------------------------------------------------------------
    // 1. Profilo volontario
    // ------------------------------------------------------------------

    public function test_profilo_volontario_salva_disponibilita_normalizzata_mansioni_del_tenant_e_scarta_mansioni_altrui(): void
    {
        $mansioneA = Mansione::factory()->create(['associazione_id' => $this->a->id, 'nome' => 'Accoglienza']);
        $mansioneB = Mansione::factory()->create(['associazione_id' => $this->b->id, 'nome' => 'Cucina']);
        $lia = $this->socio($this->a, 'Lia', 'Serra', ['password_hash' => 'password', 'volontario_attivo' => false]);

        // Una fascia inesistente viene rifiutata: nulla cambia
        $this->actingAs($this->adminA)
            ->from(route('soci.show', $lia))
            ->put(route('volontari.profilo.update', $lia), [
                'volontario_attivo' => '1',
                'disponibilita' => ['lun' => ['mattina', 'notte']],
                'mansioni' => [$mansioneA->id],
            ])
            ->assertSessionHasErrors('disponibilita.lun.1');
        $this->assertFalse((bool) $lia->fresh()->volontario_attivo);
        $this->assertDatabaseCount('socio_mansioni', 0);

        // Giorno inventato e giorno vuoto spariscono, le fasce si riordinano; la mansione di B è scartata
        $this->put(route('volontari.profilo.update', $lia), [
            'volontario_attivo' => '1',
            'disponibilita' => [
                'lun' => ['sera', 'mattina'],
                'xyz' => ['mattina'],
                'mar' => [],
                'sab' => ['pomeriggio'],
            ],
            'mansioni' => [$mansioneA->id, $mansioneB->id],
        ])->assertRedirect(route('soci.show', [$lia, 'tab' => 'volontariato']))
            ->assertSessionHas('success', 'Profilo volontario aggiornato.');

        $lia->refresh();
        $this->assertTrue($lia->volontario_attivo);
        $this->assertSame(['lun' => ['mattina', 'sera'], 'sab' => ['pomeriggio']], $lia->disponibilita);
        $this->assertSame(
            ['lun' => ['mattina', 'sera'], 'sab' => ['pomeriggio']],
            json_decode((string) DB::table('soci')->where('id', $lia->id)->value('disponibilita'), true)
        );
        $this->assertDatabaseCount('socio_mansioni', 1);
        $this->assertDatabaseHas('socio_mansioni', ['socio_id' => $lia->id, 'mansione_id' => $mansioneA->id]);
        $this->assertDatabaseMissing('socio_mansioni', ['mansione_id' => $mansioneB->id]);

        // Il volontario aggiorna da solo la disponibilità: le mansioni (certificate dall'admin) restano
        $this->actingAs($lia, 'socio')
            ->patch(route('area-soci.volontariato.disponibilita'), [
                'volontario_attivo' => '1',
                'disponibilita' => ['dom' => ['mattina'], 'ven' => ['sera']],
            ])
            ->assertRedirect(route('area-soci.volontariato'))
            ->assertSessionHas('success', 'Disponibilità aggiornata.');

        $lia->refresh();
        $this->assertSame(['ven' => ['sera'], 'dom' => ['mattina']], $lia->disponibilita);
        $this->assertTrue($lia->volontario_attivo);
        $this->assertSame([$mansioneA->id], $lia->mansioni()->pluck('mansioni.id')->all());

        // L'elenco volontari filtrato per mansione mostra Lia
        $elenco = $this->actingAs($this->adminA)->get(route('volontari.index', ['mansione' => $mansioneA->id]));
        $elenco->assertOk()->assertSee('Serra');
        $this->assertSame([$lia->id], $elenco->viewData('soci')->pluck('id')->all());
    }

    // ------------------------------------------------------------------
    // 2. Candidature concorrenti su un turno con un solo posto
    // ------------------------------------------------------------------

    public function test_turno_con_posti_limitati_candidature_concorrenti_conferma_e_rinuncia_liberano_il_posto(): void
    {
        Notification::fake();

        $turno = Turno::factory()->create([
            'associazione_id' => $this->a->id,
            'titolo' => 'Banco libri sabato',
            'data' => '2026-06-13',
            'posti' => 1,
            'auto_conferma' => false,
        ]);
        $anna = $this->socio($this->a, 'Anna', 'Boscolo', ['password_hash' => 'password', 'volontario_attivo' => true]);
        $bruno = $this->socio($this->a, 'Bruno', 'Ferri', ['password_hash' => 'password', 'volontario_attivo' => true]);
        $staffVolontario = $this->staff($this->a, 'Vito', ['volontario']);

        // Anna si candida: proposta, l'admin viene avvisato
        $this->actingAs($anna, 'socio')
            ->post(route('area-soci.volontariato.candidati', $turno))
            ->assertRedirect(route('area-soci.volontariato'))
            ->assertSessionHas('success', 'Candidatura inviata: attendi la conferma.');

        $assegnazioneAnna = TurnoAssegnazione::query()->where('turno_id', $turno->id)->where('socio_id', $anna->id)->sole();
        $this->assertSame(TurnoAssegnazione::STATO_PROPOSTO, $assegnazioneAnna->stato);
        $this->assertSame($this->a->id, $assegnazioneAnna->associazione_id);
        Notification::assertSentTo($this->adminA, CandidaturaTurnoNotification::class);
        Notification::assertNotSentTo($this->adminB, CandidaturaTurnoNotification::class);
        // Lo staff in sola lettura non gestisce le candidature: niente avviso
        Notification::assertNotSentTo($staffVolontario, CandidaturaTurnoNotification::class);

        // Bruno arriva dopo: la proposta di Anna occupa già l'unico posto
        $this->actingAs($bruno, 'socio')
            ->from(route('area-soci.volontariato'))
            ->post(route('area-soci.volontariato.candidati', $turno))
            ->assertRedirect(route('area-soci.volontariato'))
            ->assertSessionHas('error', 'Non ci sono più posti disponibili per questo turno.');
        $this->assertFalse(TurnoAssegnazione::query()->where('socio_id', $bruno->id)->exists());

        // Anna non può candidarsi due volte
        $this->actingAs($anna, 'socio')
            ->post(route('area-soci.volontariato.candidati', $turno))
            ->assertSessionHas('error', 'Risulti già candidato a questo turno.');
        $this->assertSame(1, TurnoAssegnazione::query()->where('turno_id', $turno->id)->count());

        // L'admin conferma Anna (la proposta occupa già il suo posto)
        $this->actingAs($this->adminA)
            ->post(route('turni.conferma', [$turno, $assegnazioneAnna]))
            ->assertSessionHas('success', 'Candidatura confermata.');
        $this->assertSame(TurnoAssegnazione::STATO_CONFERMATO, $assegnazioneAnna->fresh()->stato);

        // Bruno non può rinunciare al posto di Anna
        $this->actingAs($bruno, 'socio')
            ->post(route('area-soci.volontariato.rinuncia', $assegnazioneAnna), ['motivo' => 'Tentativo'])
            ->assertForbidden();
        $this->assertSame(TurnoAssegnazione::STATO_CONFERMATO, $assegnazioneAnna->fresh()->stato);

        // Anna rinuncia con motivo: lo slot si libera
        $this->actingAs($anna, 'socio')
            ->post(route('area-soci.volontariato.rinuncia', $assegnazioneAnna), ['motivo' => 'Febbre improvvisa'])
            ->assertRedirect(route('area-soci.volontariato'))
            ->assertSessionHas('success', 'Hai rinunciato al turno.');

        $this->assertDatabaseHas('turni_assegnazioni', [
            'id' => $assegnazioneAnna->id,
            'stato' => TurnoAssegnazione::STATO_RINUNCIATO,
            'motivo_rinuncia' => 'Febbre improvvisa',
            'ore_effettive' => null,
        ]);
        Notification::assertSentTo($this->adminA, RinunciaTurnoNotification::class);
        Notification::assertNotSentTo($staffVolontario, RinunciaTurnoNotification::class);

        // Ora Bruno entra
        $this->actingAs($bruno, 'socio')
            ->post(route('area-soci.volontariato.candidati', $turno))
            ->assertSessionHas('success', 'Candidatura inviata: attendi la conferma.');
        $this->assertDatabaseHas('turni_assegnazioni', [
            'turno_id' => $turno->id,
            'socio_id' => $bruno->id,
            'stato' => TurnoAssegnazione::STATO_PROPOSTO,
        ]);
        $this->assertSame(0, $turno->fresh()->postiDisponibili());
    }

    // ------------------------------------------------------------------
    // 3. Presenze, ore manuali, registro e attestato
    // ------------------------------------------------------------------

    public function test_registra_presenze_chiude_il_turno_salva_ore_solo_per_i_confermati_e_il_registro_ore_e_l_attestato_le_sommano(): void
    {
        $turno = Turno::factory()->create([
            'associazione_id' => $this->a->id,
            'titolo' => 'Allestimento sagra',
            'data' => '2026-06-05',
            'posti' => 3,
        ]);
        $marco = $this->socio($this->a, 'Marco', 'Riva', ['volontario_attivo' => true]);
        $teo = $this->socio($this->a, 'Teo', 'Bassi', ['volontario_attivo' => true]);
        $ugo = $this->socio($this->a, 'Ugo', 'Neri', ['volontario_attivo' => true]);

        $assMarco = TurnoAssegnazione::factory()->confermata()->create(['turno_id' => $turno->id, 'associazione_id' => $this->a->id, 'socio_id' => $marco->id]);
        $assTeo = TurnoAssegnazione::factory()->confermata()->create(['turno_id' => $turno->id, 'associazione_id' => $this->a->id, 'socio_id' => $teo->id]);
        $assUgo = TurnoAssegnazione::factory()->create(['turno_id' => $turno->id, 'associazione_id' => $this->a->id, 'socio_id' => $ugo->id]);

        Carbon::setTestNow('2026-06-12 18:00:00');

        // Marco 3,5 ore, Teo assente; le ore inviate per il proposto Ugo sono ignorate
        $this->actingAs($this->adminA)
            ->post(route('turni.presenze', $turno), [
                'ore' => [$assMarco->id => '3.5', $assTeo->id => '', $assUgo->id => '2'],
            ])
            ->assertRedirect(route('turni.show', $turno))
            ->assertSessionHas('success', 'Presenze registrate (1).');

        $this->assertDatabaseHas('turni_assegnazioni', ['id' => $assMarco->id, 'ore_effettive' => 3.5, 'stato' => TurnoAssegnazione::STATO_CONFERMATO]);
        $this->assertEqualsWithDelta(3.5, (float) $assMarco->fresh()->ore_effettive, 0.001);
        $this->assertNull($assTeo->fresh()->ore_effettive);
        $this->assertNull($assUgo->fresh()->ore_effettive);
        $this->assertSame(TurnoAssegnazione::STATO_PROPOSTO, $assUgo->fresh()->stato);
        $this->assertSame(Turno::STATO_CHIUSO, $turno->fresh()->stato);

        // Ore manuali fuori turno per Marco: non nel futuro, poi valide
        $this->from(route('registro-ore.index'))->post(route('registro-ore.manuali'), [
            'socio_id' => $marco->id, 'ore' => 2, 'data' => '2026-06-20', 'descrizione' => 'Riordino magazzino',
        ])->assertSessionHasErrors('data');

        $this->post(route('registro-ore.manuali'), [
            'socio_id' => $marco->id, 'ore' => 2, 'data' => '2026-06-10', 'descrizione' => 'Riordino magazzino',
        ])->assertRedirect(route('registro-ore.index', ['anno' => 2026]))->assertSessionHas('success', 'Ore registrate.');

        $turnoManuale = Turno::query()->where('titolo', 'like', 'Ore manuali%')->sole();
        $this->assertSame('Ore manuali — Riordino magazzino', $turnoManuale->titolo);
        $this->assertSame(Turno::STATO_CHIUSO, $turnoManuale->stato);
        $this->assertSame('2026-06-10', $turnoManuale->data->toDateString());
        $assManuale = TurnoAssegnazione::query()->where('turno_id', $turnoManuale->id)->sole();
        $this->assertSame($marco->id, $assManuale->socio_id);
        $this->assertSame(TurnoAssegnazione::STATO_CONFERMATO, $assManuale->stato);
        $this->assertEqualsWithDelta(2.0, (float) $assManuale->ore_effettive, 0.001);

        // Un socio di B non riceve ore da A
        $estraneo = $this->socio($this->b, 'Piero', 'Estraneo');
        $this->from(route('registro-ore.index'))->post(route('registro-ore.manuali'), [
            'socio_id' => $estraneo->id, 'ore' => 1, 'data' => '2026-06-10',
        ])->assertSessionHasErrors('socio_id');

        // Registro dell'anno: due voci di Marco, 5,5 ore
        $registro = $this->get(route('registro-ore.index', ['anno' => 2026]));
        $registro->assertOk()->assertSee('Marco Riva')->assertSee('Allestimento sagra');
        $this->assertCount(2, $registro->viewData('voci'));
        $this->assertEqualsWithDelta(5.5, $registro->viewData('totale'), 0.001);

        // Il turno manuale non compare nella pianificazione
        $pianificazione = $this->get(route('turni.index'))->assertOk();
        $this->assertSame([$turno->id], collect($pianificazione->viewData('turni')->items())->pluck('id')->all());

        $attestato = $this->get(route('registro-ore.attestato', [$marco, 'anno' => 2026]));
        $attestato->assertOk();
        $this->assertSame('application/pdf', $attestato->headers->get('content-type'));
        $this->assertStringContainsString('attestato-volontariato-marco-riva-2026.pdf', (string) $attestato->headers->get('content-disposition'));

        // Eliminata la voce manuale spariscono assegnazione e turno-contenitore
        $this->delete(route('registro-ore.destroy', $assManuale))->assertSessionHas('success', 'Voce del registro rimossa.');
        $this->assertDatabaseMissing('turni_assegnazioni', ['id' => $assManuale->id]);
        $this->assertDatabaseMissing('turni', ['id' => $turnoManuale->id]);

        // Eliminata la voce del turno reale si azzerano solo le ore
        $this->delete(route('registro-ore.destroy', $assMarco))->assertSessionHas('success');
        $this->assertDatabaseHas('turni_assegnazioni', ['id' => $assMarco->id, 'ore_effettive' => null, 'stato' => TurnoAssegnazione::STATO_CONFERMATO]);
        $this->assertEqualsWithDelta(0.0, $this->get(route('registro-ore.index', ['anno' => 2026]))->viewData('totale'), 0.001);
    }

    // ------------------------------------------------------------------
    // 4. Collegamenti del turno con dati di altri tenant
    // ------------------------------------------------------------------

    public function test_turno_con_mansione_evento_spazio_progetto_di_altro_tenant_viene_rifiutato_e_con_i_propri_salvato(): void
    {
        $mansioneA = Mansione::factory()->create(['associazione_id' => $this->a->id, 'nome' => 'Cucina']);
        $eventoA = Evento::factory()->create(['associazione_id' => $this->a->id, 'titolo' => 'Sagra di giugno']);
        $spazioA = Spazio::factory()->create(['associazione_id' => $this->a->id, 'nome' => 'Salone']);
        $progettoA = Progetto::factory()->create(['associazione_id' => $this->a->id, 'nome' => 'Feste di quartiere']);

        $mansioneB = Mansione::factory()->create(['associazione_id' => $this->b->id]);
        $eventoB = Evento::factory()->create(['associazione_id' => $this->b->id]);
        $spazioB = Spazio::factory()->create(['associazione_id' => $this->b->id]);
        $progettoB = Progetto::factory()->create(['associazione_id' => $this->b->id]);

        $dati = [
            'titolo' => 'Cucina della sagra',
            'data' => '2026-06-06', // sabato
            'ora_inizio' => '09:00',
            'ora_fine' => '13:00',
            'posti' => 3,
            'stato' => Turno::STATO_APERTO,
            'auto_conferma' => '1',
            'note' => 'Portare il grembiule',
        ];

        $this->actingAs($this->adminA)
            ->from(route('turni.create'))
            ->post(route('turni.store'), $dati + [
                'mansione_id' => $mansioneB->id,
                'evento_id' => $eventoB->id,
                'spazio_id' => $spazioB->id,
                'progetto_id' => $progettoB->id,
            ])
            ->assertRedirect(route('turni.create'))
            ->assertSessionHasErrors(['mansione_id', 'evento_id', 'spazio_id', 'progetto_id']);
        $this->assertSame(0, Turno::allTenants()->count());

        $this->post(route('turni.store'), $dati + [
            'mansione_id' => $mansioneA->id,
            'evento_id' => $eventoA->id,
            'spazio_id' => $spazioA->id,
            'progetto_id' => $progettoA->id,
        ])->assertSessionHasNoErrors()->assertSessionHas('success', 'Turno creato.');

        $turno = Turno::query()->sole();
        $this->assertDatabaseHas('turni', [
            'id' => $turno->id,
            'associazione_id' => $this->a->id,
            'mansione_id' => $mansioneA->id,
            'evento_id' => $eventoA->id,
            'spazio_id' => $spazioA->id,
            'progetto_id' => $progettoA->id,
            'stato' => Turno::STATO_APERTO,
            'auto_conferma' => true,
            'posti' => 3,
            'note' => 'Portare il grembiule',
        ]);

        // In modifica i collegamenti altrui restano vietati
        $this->from(route('turni.edit', $turno))->put(route('turni.update', $turno), $dati + ['mansione_id' => $mansioneB->id])
            ->assertSessionHasErrors('mansione_id');
        $this->assertSame($mansioneA->id, $turno->fresh()->mansione_id);

        // Suggeriti: chi ha la mansione e la disponibilità del sabato mattina viene prima
        $esperta = $this->socio($this->a, 'Elsa', 'Cuoca', ['volontario_attivo' => true, 'disponibilita' => ['sab' => ['mattina']]]);
        $esperta->mansioni()->attach($mansioneA->id);
        $disponibile = $this->socio($this->a, 'Dino', 'Libero', ['volontario_attivo' => true, 'disponibilita' => ['sab' => ['mattina']]]);
        $this->socio($this->a, 'Nora', 'Inattiva', ['volontario_attivo' => false]);
        $this->socio($this->b, 'Bea', 'Altrove', ['volontario_attivo' => true, 'disponibilita' => ['sab' => ['mattina']]]);

        $suggeriti = $this->get(route('turni.show', $turno))->assertOk()->viewData('suggeriti');
        $this->assertSame([$esperta->id, $disponibile->id], $suggeriti->pluck('socio.id')->all());
        $this->assertTrue($suggeriti[0]['ha_mansione']);
        $this->assertTrue($suggeriti[0]['disponibile']);
        $this->assertSame(3, $suggeriti[0]['score']);
        $this->assertFalse($suggeriti[1]['ha_mansione']);
        $this->assertSame(1, $suggeriti[1]['score']);

        // Con auto_conferma la candidatura dell'esperta è confermata subito
        $esperta->forceFill(['password_hash' => 'password'])->save();
        $this->actingAs($esperta->fresh(), 'socio')
            ->post(route('area-soci.volontariato.candidati', $turno))
            ->assertSessionHas('success', 'Candidatura confermata. Grazie!');
        $this->assertDatabaseHas('turni_assegnazioni', ['turno_id' => $turno->id, 'socio_id' => $esperta->id, 'stato' => TurnoAssegnazione::STATO_CONFERMATO]);
    }

    // ------------------------------------------------------------------
    // 5. Spazi con perimetro di sede e prenotazioni sovrapposte
    // ------------------------------------------------------------------

    public function test_spazio_con_sede_fuori_perimetro_e_prenotazioni_sovrapposte_dentro_transazione(): void
    {
        $sedeX = $this->sede($this->a, 'Sede Centro');
        $sedeY = $this->sede($this->a, 'Sede Lido');
        $sedeB = $this->sede($this->b, 'Sede Aurora');
        $operatore = $this->staff($this->a, 'Ennio', ['operatore'], [$sedeX->id]);

        $form = ['nome' => 'Sala riunioni', 'descrizione' => 'Primo piano', 'capienza' => 30, 'attivo' => '1'];

        // Sede di un'altra sede dell'associazione, di un altro tenant o vuota: rifiutate
        $this->actingAs($operatore)->from(route('spazi.index'))
            ->post(route('spazi.store'), $form + ['sede_id' => $sedeY->id])
            ->assertRedirect(route('spazi.index'))
            ->assertSessionHasErrors('sede_id');
        $this->post(route('spazi.store'), $form + ['sede_id' => $sedeB->id])->assertSessionHasErrors('sede_id');
        $this->post(route('spazi.store'), $form)->assertSessionHasErrors('sede_id');
        $this->assertSame(0, Spazio::allTenants()->count());

        $this->post(route('spazi.store'), $form + ['sede_id' => $sedeX->id])
            ->assertRedirect(route('spazi.index'))
            ->assertSessionHas('success', 'Spazio creato con successo.');

        $sala = Spazio::query()->sole();
        $this->assertDatabaseHas('spazi', ['id' => $sala->id, 'associazione_id' => $this->a->id, 'sede_id' => $sedeX->id, 'attivo' => true, 'capienza' => 30]);

        // Prima prenotazione 10-12
        $this->post(route('spazi.prenotazioni.store'), [
            'spazio_id' => $sala->id, 'titolo' => 'Corso di ceramica', 'dal' => '2026-06-10T10:00', 'al' => '2026-06-10T12:00',
        ])->assertRedirect(route('spazi.prenotazioni.index', ['spazio' => $sala->id]))->assertSessionHas('success');

        $ceramica = PrenotazioneSpazio::query()->where('titolo', 'Corso di ceramica')->sole();
        $this->assertSame($operatore->id, $ceramica->richiedente_id);

        // Sovrapposta 11-13: rifiutata col nome della prenotazione in conflitto
        $this->from(route('spazi.prenotazioni.index'))->post(route('spazi.prenotazioni.store'), [
            'spazio_id' => $sala->id, 'titolo' => 'Gruppo di lettura', 'dal' => '2026-06-10T11:00', 'al' => '2026-06-10T13:00',
        ])->assertSessionHasErrors('dal');
        $this->assertStringContainsString('"Corso di ceramica"', session('errors')->first('dal'));
        $this->assertSame(1, PrenotazioneSpazio::query()->count());

        // La ceramica si sposta alle 12-14: non va in conflitto con sé stessa
        $this->put(route('spazi.prenotazioni.update', $ceramica), [
            'spazio_id' => $sala->id, 'titolo' => 'Corso di ceramica', 'dal' => '2026-06-10T12:00', 'al' => '2026-06-10T14:00',
        ])->assertSessionHasNoErrors()->assertSessionHas('success', 'Prenotazione aggiornata con successo.');
        $ceramica->refresh();
        $this->assertSame('2026-06-10 12:00', $ceramica->dal->format('Y-m-d H:i'));
        $this->assertSame('2026-06-10 14:00', $ceramica->al->format('Y-m-d H:i'));

        // Ora 11-12 è libera (adiacente)
        $this->post(route('spazi.prenotazioni.store'), [
            'spazio_id' => $sala->id, 'titolo' => 'Gruppo di lettura', 'dal' => '2026-06-10T11:00', 'al' => '2026-06-10T12:00',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame(2, PrenotazioneSpazio::query()->where('spazio_id', $sala->id)->count());
        $this->assertDatabaseHas('prenotazioni_spazi', ['titolo' => 'Gruppo di lettura', 'richiedente_id' => $operatore->id, 'associazione_id' => $this->a->id]);

        // Uno spazio di B non è prenotabile da A
        $spazioB = Spazio::factory()->create(['associazione_id' => $this->b->id]);
        $this->actingAs($this->adminA)->post(route('spazi.prenotazioni.store'), [
            'spazio_id' => $spazioB->id, 'titolo' => 'Intrusione', 'dal' => '2026-06-11T10:00', 'al' => '2026-06-11T11:00',
        ])->assertSessionHasErrors('spazio_id');
        $this->assertFalse(PrenotazioneSpazio::allTenants()->where('titolo', 'Intrusione')->exists());
    }

    // ------------------------------------------------------------------
    // 6. Attrezzature: prestiti, disponibilità e quantità
    // ------------------------------------------------------------------

    public function test_attrezzatura_prestito_oltre_disponibilita_restituzione_e_riduzione_quantita_sotto_i_prestiti(): void
    {
        $pietro = $this->socio($this->a, 'Pietro', 'Landi');
        $chiara = $this->socio($this->a, 'Chiara', 'Moro');
        $estraneo = $this->socio($this->b, 'Oscar', 'Fuori');

        $this->actingAs($this->adminA)->post(route('attrezzature.store'), [
            'nome' => 'Gazebo 3x3',
            'quantita' => 3,
            'valore_unitario' => '12,5',
            'stato' => Attrezzatura::STATO_BUONO,
            'ubicazione' => 'Magazzino',
        ])->assertRedirect(route('attrezzature.index'))->assertSessionHasNoErrors();

        $gazebo = Attrezzatura::query()->sole();
        $this->assertSame('12.50', (string) $gazebo->valore_unitario);
        $this->assertSame(3, (int) $gazebo->quantita);
        $this->assertSame($this->a->id, $gazebo->associazione_id);

        $prestito = fn (Socio $socio, int $quantita) => $this->from(route('prestiti.index'))->post(route('prestiti.store'), [
            'attrezzatura_id' => $gazebo->id,
            'socio_id' => $socio->id,
            'quantita' => $quantita,
            'dal' => '2026-06-01',
            'restituzione_prevista' => '2026-06-08',
        ]);

        $prestito($pietro, 2)->assertRedirect(route('prestiti.index'))->assertSessionHas('success', 'Prestito registrato con successo.');
        $prestitoPietro = PrestitoAttrezzatura::query()->sole();
        $this->assertSame('2026-06-08', $prestitoPietro->restituzione_prevista->toDateString());
        $this->assertNull($prestitoPietro->restituito_at);
        $this->assertSame(1, $gazebo->fresh()->quantitaDisponibile());

        // Oltre la disponibilità e a un socio di B: rifiutati
        $prestito($chiara, 2)->assertSessionHasErrors('quantita');
        $this->assertStringContainsString('solo 1 disponibili', session('errors')->first('quantita'));
        $prestito($estraneo, 1)->assertSessionHasErrors('socio_id');
        $this->assertSame(1, PrestitoAttrezzatura::query()->count());

        // Non si scende sotto i pezzi in prestito
        $modifica = ['nome' => 'Gazebo 3x3', 'quantita' => 1, 'valore_unitario' => '12,50', 'stato' => Attrezzatura::STATO_BUONO];
        $this->from(route('attrezzature.index'))->put(route('attrezzature.update', $gazebo), $modifica)
            ->assertSessionHasErrors('quantita');
        $this->assertSame(3, (int) $gazebo->fresh()->quantita);

        // Né si elimina con un prestito attivo
        $this->delete(route('attrezzature.destroy', $gazebo))
            ->assertRedirect(route('attrezzature.index'))
            ->assertSessionHas('error', 'Impossibile eliminare: ci sono prestiti ancora attivi per questa attrezzatura.');
        $this->assertDatabaseHas('attrezzature', ['id' => $gazebo->id]);

        // Pietro restituisce
        Carbon::setTestNow('2026-06-07 17:30:00');
        $this->post(route('prestiti.restituisci', $prestitoPietro))->assertSessionHas('success');
        $this->assertSame('2026-06-07', $prestitoPietro->fresh()->restituito_at->toDateString());
        $this->assertSame(3, $gazebo->fresh()->quantitaDisponibile());

        // Una seconda restituzione non sposta la data
        Carbon::setTestNow('2026-06-09 10:00:00');
        $this->post(route('prestiti.restituisci', $prestitoPietro))->assertSessionHas('success');
        $this->assertSame('2026-06-07', $prestitoPietro->fresh()->restituito_at->toDateString());

        $this->put(route('attrezzature.update', $gazebo), $modifica)->assertRedirect(route('attrezzature.index'))->assertSessionHasNoErrors();
        $this->assertSame(1, (int) $gazebo->fresh()->quantita);

        $this->delete(route('attrezzature.destroy', $gazebo))->assertSessionHas('success', 'Attrezzatura eliminata con successo.');
        $this->assertDatabaseMissing('attrezzature', ['id' => $gazebo->id]);
    }

    // ------------------------------------------------------------------
    // 7. Progetto: milestone, eventi, turni e movimenti
    // ------------------------------------------------------------------

    public function test_progetto_con_milestone_eventi_turni_e_movimenti_produce_rendiconto_ore_e_report_pdf(): void
    {
        $responsabileB = $this->staff($this->b, 'Bianca');
        $form = [
            'nome' => 'Orto sociale',
            'descrizione' => 'Recupero dell\'area verde',
            'dal' => '2026-06-01',
            'al' => '2026-12-31',
            'stato' => Progetto::STATO_ATTIVO,
            'budget' => '1500',
        ];

        // Responsabile di un altro tenant: rifiutato
        $this->actingAs($this->adminA)->from(route('progetti.create'))
            ->post(route('progetti.store'), $form + ['responsabile_id' => $responsabileB->id])
            ->assertSessionHasErrors('responsabile_id');
        $this->assertSame(0, Progetto::allTenants()->count());

        $this->post(route('progetti.store'), $form + ['responsabile_id' => $this->adminA->id])->assertSessionHasNoErrors();
        $progetto = Progetto::query()->sole();
        $this->assertDatabaseHas('progetti', ['id' => $progetto->id, 'associazione_id' => $this->a->id, 'responsabile_id' => $this->adminA->id, 'stato' => Progetto::STATO_ATTIVO]);
        $this->assertSame('1500.00', (string) $progetto->budget);

        foreach (['Bonifica', 'Semina', 'Raccolto'] as $i => $titolo) {
            $this->post(route('progetti.milestone.store', $progetto), ['titolo' => $titolo, 'scadenza' => '2026-0'.(7 + $i).'-15', 'ordine' => $i])
                ->assertRedirect(route('progetti.show', $progetto));
        }
        $this->assertSame(3, MilestoneProgetto::query()->where('progetto_id', $progetto->id)->count());

        foreach (['Bonifica', 'Semina'] as $titolo) {
            $milestone = MilestoneProgetto::query()->where('titolo', $titolo)->sole();
            $this->post(route('progetti.milestone.toggle', [$progetto, $milestone]))->assertSessionHas('success', 'Milestone completata.');
            $this->assertNotNull($milestone->fresh()->completata_at);
        }
        $this->assertNull(MilestoneProgetto::query()->where('titolo', 'Raccolto')->sole()->completata_at);
        $this->assertSame(67, $progetto->fresh()->percentualeAvanzamento());

        // Una milestone di un altro progetto non si tocca da questo
        $altro = Progetto::factory()->create(['associazione_id' => $this->a->id]);
        $this->post(route('progetti.milestone.toggle', [$altro, MilestoneProgetto::query()->where('titolo', 'Raccolto')->sole()]))->assertNotFound();

        // Evento collegato e turno con ore registrate
        Evento::factory()->create(['associazione_id' => $this->a->id, 'titolo' => 'Festa del raccolto', 'progetto_id' => $progetto->id]);
        $this->post(route('turni.store'), [
            'titolo' => 'Zappatura', 'data' => '2026-06-03', 'ora_inizio' => '08:00', 'ora_fine' => '12:00',
            'posti' => 2, 'progetto_id' => $progetto->id,
        ])->assertSessionHasNoErrors();
        $turno = Turno::query()->where('titolo', 'Zappatura')->sole();
        $volontario = $this->socio($this->a, 'Rino', 'Zappa');
        $this->post(route('turni.assegna', $turno), ['socio_id' => $volontario->id, 'conferma' => '1'])->assertSessionHas('success');
        $assegnazione = TurnoAssegnazione::query()->where('turno_id', $turno->id)->sole();
        $this->post(route('turni.presenze', $turno), ['ore' => [$assegnazione->id => '4']])->assertSessionHas('success');

        // Movimenti imputati al progetto
        $uscita = CategoriaMovimento::factory()->uscita()->create(['associazione_id' => $this->a->id, 'nome' => 'Attrezzi']);
        $entrata = CategoriaMovimento::factory()->entrata()->create(['associazione_id' => $this->a->id, 'nome' => 'Contributi']);
        $this->post(route('prima-nota.store'), ['tipo' => Movimento::TIPO_USCITA, 'importo' => '100', 'data' => '2026-06-02', 'categoria_id' => $uscita->id, 'descrizione' => 'Vanghe', 'progetto_id' => $progetto->id])
            ->assertSessionHasNoErrors();
        $this->post(route('prima-nota.store'), ['tipo' => Movimento::TIPO_ENTRATA, 'importo' => '50', 'data' => '2026-06-02', 'categoria_id' => $entrata->id, 'descrizione' => 'Contributo comune', 'progetto_id' => $progetto->id])
            ->assertSessionHasNoErrors();
        // Un movimento senza progetto non entra nel rendiconto
        $this->post(route('prima-nota.store'), ['tipo' => Movimento::TIPO_USCITA, 'importo' => '999', 'data' => '2026-06-02', 'categoria_id' => $uscita->id])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, Movimento::query()->where('progetto_id', $progetto->id)->count());

        $pagina = $this->get(route('progetti.show', $progetto));
        $pagina->assertOk()->assertSee('Orto sociale')->assertSee('Festa del raccolto')->assertSee('Zappatura');
        $rendiconto = $pagina->viewData('rendiconto');
        $this->assertEqualsWithDelta(50.0, $rendiconto['entrate'], 0.001);
        $this->assertEqualsWithDelta(100.0, $rendiconto['uscite'], 0.001);
        $this->assertEqualsWithDelta(-50.0, $rendiconto['saldo'], 0.001);
        $this->assertEqualsWithDelta(1400.0, $rendiconto['scostamento'], 0.001);
        $this->assertSame(2, $rendiconto['numeroMovimenti']);
        $ore = $pagina->viewData('oreVolontariato');
        $this->assertEqualsWithDelta(4.0, $ore['totale'], 0.001);
        $this->assertSame('Zappa Rino', $ore['perSocio'][0]['socio']);

        $pdf = $this->get(route('progetti.report-pdf', $progetto));
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));

        // Un progetto di B non è imputabile da A
        $progettoB = Progetto::factory()->create(['associazione_id' => $this->b->id]);
        $this->post(route('prima-nota.store'), ['tipo' => Movimento::TIPO_ENTRATA, 'importo' => '10', 'data' => '2026-06-02', 'categoria_id' => $entrata->id, 'progetto_id' => $progettoB->id])
            ->assertSessionHasErrors('progetto_id');
    }

    // ------------------------------------------------------------------
    // 8. CRM a colonne
    // ------------------------------------------------------------------

    public function test_crm_scheda_in_fase_finale_diventa_vinta_sposta_riordina_e_riapri_la_riporta_aperta(): void
    {
        $responsabileB = $this->staff($this->b, 'Bianca');

        $this->actingAs($this->adminA)->post(route('crm.store'), [
            'pipeline' => 'adesioni', 'fase' => 'contatto', 'titolo' => 'Famiglia Bianchi', 'valore' => '60', 'responsabile_id' => $this->adminA->id,
        ])->assertRedirect(route('crm.index', ['pipeline' => 'adesioni']))->assertSessionHas('success');
        $this->post(route('crm.store'), ['pipeline' => 'adesioni', 'fase' => 'contatto', 'titolo' => 'Studio Rossi'])->assertSessionHas('success');
        $this->post(route('crm.store'), ['pipeline' => 'adesioni', 'fase' => 'iscritto', 'titolo' => 'Già iscritto'])->assertSessionHas('success');

        $bianchi = OpportunitaCrm::query()->where('titolo', 'Famiglia Bianchi')->sole();
        $rossi = OpportunitaCrm::query()->where('titolo', 'Studio Rossi')->sole();
        $giaIscritto = OpportunitaCrm::query()->where('titolo', 'Già iscritto')->sole();
        $this->assertSame([1, 2], [$bianchi->posizione, $rossi->posizione]);
        $this->assertNull($bianchi->esito);
        $this->assertSame($this->a->id, $bianchi->associazione_id);
        // Creata direttamente in fase finale è già vinta
        $this->assertSame('vinta', $giaIscritto->esito);
        $this->assertNotNull($giaIscritto->chiusa_at);

        // Responsabile di un altro tenant e fase di un'altra pipeline: rifiutati
        $this->from(route('crm.index'))->post(route('crm.store'), ['pipeline' => 'adesioni', 'fase' => 'contatto', 'titolo' => 'X', 'responsabile_id' => $responsabileB->id])
            ->assertSessionHasErrors('responsabile_id');
        $this->post(route('crm.store'), ['pipeline' => 'adesioni', 'fase' => 'firmato', 'titolo' => 'X'])->assertSessionHasErrors('fase');
        $this->assertFalse(OpportunitaCrm::query()->where('titolo', 'X')->exists());

        // Trascinata nella colonna finale: vinta, riordinata; la scheda di B nell'ordine non viene toccata
        $schedaB = OpportunitaCrm::query()->forceCreate(['associazione_id' => $this->b->id, 'pipeline' => 'adesioni', 'fase' => 'contatto', 'posizione' => 7, 'titolo' => 'Scheda B']);
        $this->patchJson(route('crm.sposta', $bianchi), ['fase' => 'iscritto', 'ordine' => [$schedaB->id, $bianchi->id, $giaIscritto->id]])
            ->assertOk()
            ->assertJson(['ok' => true, 'esito' => 'vinta']);

        $bianchi->refresh();
        $this->assertSame('iscritto', $bianchi->fase);
        $this->assertSame('vinta', $bianchi->esito);
        $this->assertSame('2026-06-01 09:00:00', $bianchi->chiusa_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, $bianchi->posizione);
        $this->assertSame(1, $giaIscritto->fresh()->posizione);
        $this->assertDatabaseHas('crm_opportunita', ['id' => $schedaB->id, 'fase' => 'contatto', 'posizione' => 7, 'esito' => null]);

        // Una fase inesistente risponde 422 JSON
        $this->patchJson(route('crm.sposta', $rossi), ['fase' => 'firmato', 'ordine' => [$rossi->id]])
            ->assertStatus(422)->assertJsonPath('ok', false);

        // Studio Rossi non va a buon fine
        $this->post(route('crm.persa', $rossi))->assertSessionHas('success');
        $rossi->refresh();
        $this->assertSame('persa', $rossi->esito);
        $this->assertNotNull($rossi->chiusa_at);
        $this->assertSame('contatto', $rossi->fase);

        // La vinta riaperta torna nella prima colonna, senza esito
        $this->post(route('crm.riapri', $bianchi))->assertSessionHas('success');
        $bianchi->refresh();
        $this->assertNull($bianchi->esito);
        $this->assertNull($bianchi->chiusa_at);
        $this->assertSame('contatto', $bianchi->fase);

        // La pipeline non si cambia in modifica
        $this->from(route('crm.index'))->put(route('crm.update', $bianchi), ['pipeline' => 'sponsor', 'fase' => 'contatto', 'titolo' => 'Famiglia Bianchi'])
            ->assertSessionHasErrors('pipeline');
        $this->assertSame('adesioni', $bianchi->fresh()->pipeline);

        // Spostata in fase finale dal form di modifica: vinta; riportata indietro: di nuovo aperta
        $this->put(route('crm.update', $bianchi), ['fase' => 'iscritto', 'titolo' => 'Famiglia Bianchi'])->assertSessionHasNoErrors();
        $this->assertSame('vinta', $bianchi->fresh()->esito);
        $this->put(route('crm.update', $bianchi), ['fase' => 'proposta', 'titolo' => 'Famiglia Bianchi'])->assertSessionHasNoErrors();
        $this->assertNull($bianchi->fresh()->esito);
        $this->assertNull($bianchi->fresh()->chiusa_at);

        // L'admin di B non vede né sposta le schede di A
        $this->actingAs($this->adminB)->patchJson(route('crm.sposta', $bianchi), ['fase' => 'iscritto', 'ordine' => [$bianchi->id]])->assertNotFound();
        $this->assertSame('proposta', $bianchi->fresh()->fase);
    }

    // ------------------------------------------------------------------
    // 9. Contatti esterni, sponsor e sito pubblico
    // ------------------------------------------------------------------

    public function test_contatti_esterni_con_tag_normalizzati_sponsor_collegato_e_visibilita_sul_sito_per_periodo(): void
    {
        $this->actingAs($this->adminA)->post(route('contatti-esterni.store'), [
            'tipo' => ContattoEsterno::TIPO_SPONSOR,
            'nome' => 'Tipografia Gialla',
            'referente' => 'Sergio Gialli',
            'email' => 'info@tipografiagialla.test',
            'tag' => 'stampa, eventi, stampa, ',
        ])->assertRedirect(route('contatti-esterni.index'))->assertSessionHasNoErrors();

        $contatto = ContattoEsterno::query()->sole();
        $this->assertSame(['stampa', 'eventi'], $contatto->tag);
        $this->assertSame(['stampa', 'eventi'], json_decode((string) DB::table('contatti_esterni')->where('id', $contatto->id)->value('tag'), true));
        $this->assertSame($this->a->id, $contatto->associazione_id);

        // Sponsor collegato al contatto di un altro tenant: rifiutato
        $contattoB = ContattoEsterno::factory()->create(['associazione_id' => $this->b->id]);
        $sponsor = [
            'nome' => 'Tipografia Gialla',
            'livello' => Sponsor::LIVELLO_ORO,
            'sito_url' => 'https://tipografiagialla.test',
            'dal' => '2026-05-31',
            'al' => '2026-06-02',
            'visibile_sul_sito' => '1',
        ];
        $this->from(route('sponsor.index'))->post(route('sponsor.store'), $sponsor + ['contatto_esterno_id' => $contattoB->id])
            ->assertSessionHasErrors('contatto_esterno_id');
        $this->assertSame(0, Sponsor::allTenants()->count());

        $this->post(route('sponsor.store'), $sponsor + ['contatto_esterno_id' => $contatto->id])->assertSessionHasNoErrors()->assertSessionHas('success', 'Sponsor creato.');
        // Uno sponsor nascosto e uno di un altro tenant non compaiono
        $this->post(route('sponsor.store'), ['nome' => 'Bar Nascosto', 'livello' => Sponsor::LIVELLO_BRONZO])->assertSessionHasNoErrors();
        Sponsor::factory()->create(['associazione_id' => $this->b->id, 'nome' => 'Sponsor della Aurora', 'visibile_sul_sito' => true, 'dal' => null, 'al' => null]);

        $this->assertDatabaseHas('sponsor', [
            'nome' => 'Tipografia Gialla',
            'associazione_id' => $this->a->id,
            'contatto_esterno_id' => $contatto->id,
            'livello' => Sponsor::LIVELLO_ORO,
            'visibile_sul_sito' => true,
        ]);
        $this->assertDatabaseHas('sponsor', ['nome' => 'Bar Nascosto', 'visibile_sul_sito' => false]);

        // Il visitatore vede il sostenitore nel periodo
        $this->esci();
        $this->get(route('pubblico.home.show', $this->a->slug))
            ->assertOk()
            ->assertSee('Tipografia Gialla')
            ->assertDontSee('Bar Nascosto')
            ->assertDontSee('Sponsor della Aurora');

        // Tre giorni dopo il periodo è finito
        Carbon::setTestNow('2026-06-04 09:00:00');
        app()->forgetInstance('tenant.pubblico');
        $this->get(route('pubblico.home.show', $this->a->slug))
            ->assertOk()
            ->assertDontSee('Tipografia Gialla');
    }

    // ------------------------------------------------------------------
    // 10. Matrice ruoli per modulo
    // ------------------------------------------------------------------

    public function test_matrice_ruoli_tesoriere_consigliere_e_volontario_applicano_i_permessi_per_modulo(): void
    {
        $utente = $this->staff($this->a, 'Tullio');
        $socio = $this->socio($this->a, 'Anna', 'Boscolo');
        $categoria = CategoriaMovimento::factory()->entrata()->create(['associazione_id' => $this->a->id, 'nome' => 'Offerte']);

        $this->actingAs($this->adminA)->put(route('ruoli.update', $utente), ['ruoli' => ['tesoriere']])
            ->assertRedirect(route('ruoli.index'))
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'Tesoriere'));
        $this->assertDatabaseHas('model_has_roles', ['model_id' => $utente->id, 'role_id' => DB::table('roles')->where('name', 'tesoriere')->value('id')]);
        $this->assertSame(1, DB::table('model_has_roles')->where('model_id', $utente->id)->count());

        // Tesoriere: soci in sola lettura, quote e prima nota complete
        $this->actingAs($utente->fresh())->post(route('soci.store'), [
            'nome' => 'Nuovo', 'cognome' => 'Socio', 'email' => 'nuovo@mail.test', 'data_nascita' => '1980-02-02',
            'data_iscrizione' => '2026-06-01', 'stato' => Socio::STATO_ATTIVO,
        ])->assertForbidden();
        $this->assertFalse(Socio::query()->where('email', 'nuovo@mail.test')->exists());

        $this->post(route('quote.store'), ['socio_id' => $socio->id, 'anno' => 2026, 'importo' => '30', 'data_scadenza' => '2026-12-31', 'tipo' => 'Tesseramento'])
            ->assertRedirect(route('quote.index'))->assertSessionHas('success', 'Quota creata.');
        $quota = Quota::query()->where('socio_id', $socio->id)->sole();
        $this->assertSame('30.00', (string) $quota->importo);

        $this->post(route('prima-nota.store'), ['tipo' => Movimento::TIPO_ENTRATA, 'importo' => '15', 'data' => '2026-06-01', 'categoria_id' => $categoria->id, 'descrizione' => 'Offerta'])
            ->assertRedirect(route('prima-nota.index'))->assertSessionHas('success', 'Movimento registrato.');
        $this->assertDatabaseHas('movimenti', ['descrizione' => 'Offerta', 'registrato_da' => $utente->id, 'associazione_id' => $this->a->id]);

        $this->delete(route('quote.destroy', $quota))->assertRedirect();
        $this->assertSoftDeletedOrMissing('quote', $quota->id);

        // Diventa consigliere: consultazione senza modifiche
        $this->actingAs($this->adminA)->put(route('ruoli.update', $utente), ['ruoli' => ['consigliere']])->assertRedirect(route('ruoli.index'));
        $this->assertSame(['consigliere'], $utente->fresh()->getRoleNames()->all());

        $this->actingAs($utente->fresh())->get(route('quote.index'))->assertOk();
        $this->post(route('quote.store'), ['socio_id' => $socio->id, 'anno' => 2026, 'importo' => '30', 'data_scadenza' => '2026-12-31', 'tipo' => 'Tesseramento'])
            ->assertForbidden();
        $this->post(route('prima-nota.store'), ['tipo' => Movimento::TIPO_ENTRATA, 'importo' => '5', 'data' => '2026-06-01', 'categoria_id' => $categoria->id, 'descrizione' => 'Vietato'])
            ->assertForbidden();
        $this->get(route('turni.index'))->assertOk();
        $this->assertSame(0, Quota::query()->where('socio_id', $socio->id)->count());
        $this->assertFalse(Movimento::query()->where('descrizione', 'Vietato')->exists());

        // Diventa volontario: turni in lettura, nessuna pianificazione, niente quote
        $this->actingAs($this->adminA)->put(route('ruoli.update', $utente), ['ruoli' => ['volontario']])->assertRedirect(route('ruoli.index'));
        $this->actingAs($utente->fresh())->get(route('turni.index'))->assertOk();
        $this->post(route('turni.store'), ['titolo' => 'Turno vietato', 'data' => '2026-06-10', 'ora_inizio' => '09:00', 'ora_fine' => '10:00', 'posti' => 1])
            ->assertForbidden();
        $this->get(route('quote.index'))->assertForbidden();
        $this->get(route('soci.index'))->assertForbidden();
        $this->assertFalse(Turno::query()->where('titolo', 'Turno vietato')->exists());

        // Un ruolo inesistente o l'assegnazione a un admin vengono rifiutati
        $this->actingAs($this->adminA)->from(route('ruoli.index'))->put(route('ruoli.update', $utente), ['ruoli' => ['imperatore']])
            ->assertSessionHasErrors('ruoli.0');
        $this->put(route('ruoli.update', $this->adminA), ['ruoli' => ['volontario']])->assertForbidden();
        $this->assertSame(['volontario'], $utente->fresh()->getRoleNames()->all());
    }

    private function assertSoftDeletedOrMissing(string $tabella, string $id): void
    {
        $riga = DB::table($tabella)->where('id', $id)->first();
        $this->assertTrue($riga === null || (property_exists($riga, 'deleted_at') && $riga->deleted_at !== null), "{$tabella} {$id} non eliminato");
    }

    // ------------------------------------------------------------------
    // 11. Responsabile di sede
    // ------------------------------------------------------------------

    public function test_responsabile_di_sede_vede_solo_soci_tessere_quote_e_prenotazioni_della_propria_sede(): void
    {
        $sedeX = $this->sede($this->a, 'Sede Centro');
        $sedeY = $this->sede($this->a, 'Sede Lido');
        $utente = $this->staff($this->a, 'Rosa', ['operatore']);

        $this->actingAs($this->adminA)->put(route('ruoli.update', $utente), ['ruoli' => ['responsabile_sede'], 'sedi' => [$sedeX->id]])
            ->assertRedirect(route('ruoli.index'))
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'sedi Sede Centro'));
        $this->assertDatabaseHas('utenti', ['id' => $utente->id, 'limitato_sedi' => true]);
        $this->assertSame(1, DB::table('utente_sedi')->where('utente_id', $utente->id)->count());
        $this->assertDatabaseHas('utente_sedi', ['utente_id' => $utente->id, 'sede_id' => $sedeX->id]);

        $socioX = $this->socio($this->a, 'Carla', 'Centrale', ['sede_id' => $sedeX->id]);
        $socioY = $this->socio($this->a, 'Dario', 'Lidense', ['sede_id' => $sedeY->id]);
        $tesseraX = Tessera::factory()->create(['associazione_id' => $this->a->id, 'socio_id' => $socioX->id]);
        $tesseraY = Tessera::factory()->create(['associazione_id' => $this->a->id, 'socio_id' => $socioY->id]);
        $quotaX = Quota::factory()->create(['associazione_id' => $this->a->id, 'socio_id' => $socioX->id]);
        $quotaY = Quota::factory()->create(['associazione_id' => $this->a->id, 'socio_id' => $socioY->id]);

        $this->esci();
        $this->actingAs($utente->fresh());

        $soci = $this->get(route('soci.index', ['tessera_stato' => 'all', 'ajax' => 1]))->assertOk()->json();
        $this->assertSame(1, $soci['count']);
        $this->assertStringContainsString('Centrale', $soci['html']);
        $this->assertStringNotContainsString('Lidense', $soci['html']);

        $tessere = $this->get(route('tessere.index', ['ajax' => 1, 'stato' => 'all']))->assertOk()->json();
        $this->assertSame(1, $tessere['count']);
        $this->assertStringContainsString($tesseraX->numero_tessera, $tessere['html']);
        $this->assertStringNotContainsString($tesseraY->numero_tessera, $tessere['html']);

        $quote = $this->get(route('quote.index'))->assertOk()->viewData('quote');
        $this->assertSame([$quotaX->id], collect($quote->items())->pluck('id')->all());

        // Fuori perimetro: 404
        $this->get(route('soci.show', $socioY))->assertNotFound();
        $this->put(route('quote.update', $quotaY), ['importo' => '1'])->assertNotFound();
        $this->get(route('soci.show', $socioX))->assertOk()->assertSee('Centrale');

        // Non sposta il proprio socio in un'altra sede
        $this->from(route('soci.edit', $socioX))->put(route('soci.update', $socioX), $this->formSocio($socioX, ['sede_id' => $sedeY->id]))
            ->assertSessionHasErrors('sede_id');
        $this->assertSame($sedeX->id, $socioX->fresh()->sede_id);

        // Non crea quote per un socio di un'altra sede
        $this->from(route('quote.index'))->post(route('quote.store'), ['socio_id' => $socioY->id, 'anno' => 2026, 'importo' => '25', 'data_scadenza' => '2026-12-31', 'tipo' => 'Tesseramento'])
            ->assertSessionHasErrors('socio_id');
        $this->assertSame(1, Quota::allTenants()->withoutGlobalScope('sede')->where('socio_id', $socioY->id)->count());

        // Per il proprio socio sì
        $this->post(route('quote.store'), ['socio_id' => $socioX->id, 'anno' => 2027, 'importo' => '25', 'data_scadenza' => '2027-12-31', 'tipo' => 'Tesseramento'])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, Quota::query()->where('socio_id', $socioX->id)->count());

        // Eventi della propria sede e di tutta l'associazione, prenotazioni filtrate allo stesso modo
        $eventoX = Evento::factory()->create(['associazione_id' => $this->a->id, 'titolo' => 'Cena del Centro', 'sede_id' => $sedeX->id]);
        $eventoY = Evento::factory()->create(['associazione_id' => $this->a->id, 'titolo' => 'Regata del Lido', 'sede_id' => $sedeY->id]);
        Prenotazione::factory()->create(['evento_id' => $eventoX->id, 'associazione_id' => $this->a->id]);
        Prenotazione::factory()->create(['evento_id' => $eventoY->id, 'associazione_id' => $this->a->id]);
        $this->assertSame([$eventoX->id], Prenotazione::query()->pluck('evento_id')->all());
        $this->get(route('eventi.prenotazioni.index', $eventoY))->assertNotFound();
        $this->get(route('eventi.prenotazioni.index', $eventoX))->assertOk();
    }

    // ------------------------------------------------------------------
    // 12. Sede eliminata dopo l'assegnazione
    // ------------------------------------------------------------------

    public function test_utente_limitato_a_una_sede_poi_eliminata_non_vede_piu_nulla_finche_l_admin_non_lo_riassegna(): void
    {
        $sedeX = $this->sede($this->a, 'Sede Centro');
        $sedeY = $this->sede($this->a, 'Sede Lido');
        $utente = $this->staff($this->a, 'Ennio', ['operatore'], [$sedeX->id]);
        $this->socio($this->a, 'Carla', 'Centrale', ['sede_id' => $sedeX->id]);
        $this->socio($this->a, 'Dario', 'Lidense', ['sede_id' => $sedeY->id]);
        $this->socio($this->a, 'Elsa', 'Senzasede');

        $conta = fn () => $this->actingAs($utente->fresh())->get(route('soci.index', ['tessera_stato' => 'all', 'ajax' => 1]))->assertOk()->json('count');

        $this->assertSame(1, $conta());

        // L'admin elimina la sede: l'operatore non vede più alcun socio (il limite resta)
        $this->actingAs($this->adminA)->delete(route('sedi.destroy', $sedeX->id))
            ->assertRedirect(route('configurazioni.index', ['section' => 'sedi']))
            ->assertSessionHas('success', 'Sede eliminata.');
        $this->assertDatabaseMissing('sedi', ['id' => $sedeX->id]);
        $this->assertSame(0, DB::table('utente_sedi')->where('utente_id', $utente->id)->count());
        $this->assertDatabaseHas('utenti', ['id' => $utente->id, 'limitato_sedi' => true]);

        $this->assertSame(0, $conta());
        $this->assertSame(0, $this->actingAs($utente->fresh())->get(route('quote.index'))->viewData('quote')->total());

        // Riassegnato senza sedi: vede tutta l'associazione
        $this->actingAs($this->adminA)->put(route('ruoli.update', $utente), ['ruoli' => ['operatore'], 'sedi' => []])
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'tutte le sedi'));
        $this->assertDatabaseHas('utenti', ['id' => $utente->id, 'limitato_sedi' => false]);
        $this->assertSame(3, $conta());

        // Una sede di un'altra associazione non è assegnabile
        $sedeB = $this->sede($this->b, 'Sede Aurora');
        $this->actingAs($this->adminA)->from(route('ruoli.index'))->put(route('ruoli.update', $utente), ['ruoli' => ['operatore'], 'sedi' => [$sedeB->id]])
            ->assertSessionHasErrors('sedi.0');
        $this->assertSame(0, DB::table('utente_sedi')->where('utente_id', $utente->id)->count());
    }

    // ------------------------------------------------------------------
    // 13. Isolamento: l'admin di A non tocca i dati di B
    // ------------------------------------------------------------------

    public function test_admin_di_a_non_puo_leggere_o_modificare_record_di_b_su_tutte_le_entita_principali(): void
    {
        $b = $this->b->id;
        $socio = $this->socio($this->b, 'Bea', 'Aurora');
        $tessera = Tessera::factory()->create(['associazione_id' => $b, 'socio_id' => $socio->id]);
        $quota = Quota::factory()->create(['associazione_id' => $b, 'socio_id' => $socio->id]);
        $evento = Evento::factory()->create(['associazione_id' => $b]);
        $prenotazione = Prenotazione::factory()->create(['evento_id' => $evento->id, 'associazione_id' => $b]);
        $documento = Documento::query()->create(['associazione_id' => $b, 'nome_file' => 'statuto.pdf', 'percorso_file' => 'documenti/statuto.pdf', 'categoria' => 'Statuto', 'dimensione_kb' => 10]);
        $verbale = Verbale::query()->create(['associazione_id' => $b, 'titolo' => 'Assemblea', 'numero' => 1, 'anno' => 2026, 'data_riunione' => '2026-05-01', 'tipo_riunione' => 'Assemblea']);
        $riunione = Riunione::factory()->create(['associazione_id' => $b]);
        $turno = Turno::factory()->create(['associazione_id' => $b]);
        $spazio = Spazio::factory()->create(['associazione_id' => $b]);
        $progetto = Progetto::factory()->create(['associazione_id' => $b]);
        $contatto = ContattoEsterno::factory()->create(['associazione_id' => $b]);
        $sondaggio = Sondaggio::factory()->create(['associazione_id' => $b]);
        $annuncio = Annuncio::factory()->create(['associazione_id' => $b]);

        $modelli = compact('socio', 'tessera', 'quota', 'evento', 'prenotazione', 'documento', 'verbale', 'riunione', 'turno', 'spazio', 'progetto', 'contatto', 'sondaggio', 'annuncio');
        $prima = [];
        foreach ($modelli as $nome => $modello) {
            $prima[$nome] = (array) DB::table($modello->getTable())->where('id', $modello->getKey())->first();
        }

        Carbon::setTestNow('2026-06-02 10:00:00');
        $this->actingAs($this->adminA);

        $richieste = [
            ['get', route('soci.show', $socio)], ['get', route('soci.edit', $socio)],
            ['put', route('soci.update', $socio)], ['delete', route('soci.destroy', $socio)],
            ['put', route('tessere.update', $tessera)], ['delete', route('tessere.destroy', $tessera)], ['get', route('tessere.pdf', $tessera)],
            ['put', route('quote.update', $quota)], ['delete', route('quote.destroy', $quota)], ['get', route('quote.ricevuta', $quota)],
            ['post', route('quote.pagamento', $quota)],
            ['put', route('eventi.update', $evento)], ['delete', route('eventi.destroy', $evento)], ['get', route('eventi.prenotazioni.index', $evento)],
            ['post', route('eventi.prenotazioni.annulla', [$evento, $prenotazione])],
            ['get', route('documenti.download', $documento)], ['delete', route('documenti.destroy', $documento)],
            ['put', route('verbali.update', $verbale)], ['delete', route('verbali.destroy', $verbale)], ['post', route('verbali.approva', $verbale)],
            ['get', route('riunioni.show', $riunione)], ['put', route('riunioni.update', $riunione)], ['delete', route('riunioni.destroy', $riunione)],
            ['get', route('turni.show', $turno)], ['get', route('turni.edit', $turno)], ['put', route('turni.update', $turno)], ['delete', route('turni.destroy', $turno)],
            ['put', route('spazi.update', $spazio)], ['delete', route('spazi.destroy', $spazio)],
            ['get', route('progetti.show', $progetto)], ['get', route('progetti.edit', $progetto)], ['put', route('progetti.update', $progetto)],
            ['delete', route('progetti.destroy', $progetto)], ['get', route('progetti.report-pdf', $progetto)],
            ['put', route('contatti-esterni.update', $contatto)], ['delete', route('contatti-esterni.destroy', $contatto)],
            ['get', route('sondaggi.show', $sondaggio)], ['get', route('sondaggi.edit', $sondaggio)], ['put', route('sondaggi.update', $sondaggio)], ['delete', route('sondaggi.destroy', $sondaggio)],
            ['get', route('annunci.edit', $annuncio)], ['put', route('annunci.update', $annuncio)], ['delete', route('annunci.destroy', $annuncio)],
        ];

        $nonIsolate = [];
        foreach ($richieste as [$metodo, $url]) {
            $stato = $this->{$metodo}($url, ['titolo' => 'Manomesso', 'nome' => 'Manomesso'])->getStatusCode();
            if ($stato !== 404) {
                $nonIsolate[] = strtoupper($metodo).' '.$url.' → '.$stato;
            }
        }
        $this->assertSame([], $nonIsolate, 'Richieste su record di B non respinte con 404');

        foreach ($modelli as $nome => $modello) {
            $this->assertEquals(
                $prima[$nome],
                (array) DB::table($modello->getTable())->where('id', $modello->getKey())->first(),
                "Il record {$nome} di B è stato modificato"
            );
        }

        // Le liste di A non mostrano nulla di B
        $this->assertSame(0, $this->get(route('soci.index', ['tessera_stato' => 'all', 'ajax' => 1]))->json('count'));
        $this->assertSame(0, $this->get(route('turni.index'))->viewData('turni')->total());
    }

    // ------------------------------------------------------------------
    // 14. Super admin senza tenant
    // ------------------------------------------------------------------

    public function test_super_admin_senza_associazione_selezionata_viene_rediretto_dalle_rotte_tenant_e_dopo_la_selezione_opera_sul_tenant_giusto(): void
    {
        $chiaveA = $this->chiaveApi($this->a, ['soci:read'], ['nome' => 'Chiave del Faro']);
        $chiaveB = $this->chiaveApi($this->b, ['soci:read'], ['nome' => 'Chiave della Aurora']);
        $globale = $this->chiaveApi(null, ['*'], ['nome' => 'Chiave di piattaforma']);

        $this->actingAs($this->superAdmin);

        // Nessuna associazione selezionata: le pagine tenant rimandano alla dashboard
        $this->get(route('soci.index'))->assertRedirect(route('dashboard'))->assertSessionHas('warning', 'Seleziona prima un\'associazione.');
        $this->get(route('turni.index'))->assertRedirect(route('dashboard'));
        $this->getJson(route('soci.index'))->assertForbidden()->assertJsonPath('code', 'TENANT_NOT_SELECTED');
        $this->post(route('soci.store'), ['nome' => 'Fantasma', 'cognome' => 'Globale', 'email' => 'fantasma@mail.test', 'data_iscrizione' => '2026-06-01', 'stato' => Socio::STATO_ATTIVO])
            ->assertRedirect(route('dashboard'));
        $this->assertFalse(Socio::allTenants()->where('email', 'fantasma@mail.test')->exists());

        // La pagina chiavi API (senza middleware tenant) mostra tutte le chiavi
        $chiavi = $this->get(route('api-keys.index'))->assertOk()->viewData('chiavi');
        $this->assertEqualsCanonicalizing([$chiaveA->id, $chiaveB->id, $globale->id], $chiavi->pluck('id')->all());

        // Sceglie A e iscrive un socio
        $this->post(route('tenant.switch'), ['associazione_id' => $this->a->id])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('success', 'Stai operando su: Circolo Il Faro');
        $this->post(route('soci.store'), [
            'nome' => 'Sara', 'cognome' => 'Superiore', 'email' => 'sara.superiore@mail.test',
            'data_nascita' => '1990-01-01', 'data_iscrizione' => '2026-06-01', 'stato' => Socio::STATO_ATTIVO,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('soci', ['email' => 'sara.superiore@mail.test', 'associazione_id' => $this->a->id]);

        // Con A selezionata vede le chiavi di A e quelle globali
        $this->assertEqualsCanonicalizing([$chiaveA->id, $globale->id], $this->get(route('api-keys.index'))->viewData('chiavi')->pluck('id')->all());

        // Passa a B: il socio di A non compare
        $this->post(route('tenant.switch'), ['associazione_id' => $this->b->id])->assertSessionHas('success', 'Stai operando su: Polisportiva Aurora');
        $lista = $this->get(route('soci.index', ['tessera_stato' => 'all', 'ajax' => 1]))->assertOk()->json();
        $this->assertSame(0, $lista['count']);
        $this->assertStringNotContainsString('Superiore', $lista['html']);
        $socioA = Socio::allTenants()->where('email', 'sara.superiore@mail.test')->sole();
        $this->get(route('soci.show', $socioA))->assertNotFound();

        // Un'associazione disattivata non è selezionabile
        $spenta = Associazione::factory()->inattiva()->create();
        $this->post(route('tenant.switch'), ['associazione_id' => $spenta->id])->assertSessionHas('error');
        $this->assertSame($this->b->id, session('associazione_id'));

        // Torna alla vista globale
        $this->post(route('tenant.switch'), ['associazione_id' => ''])->assertSessionHas('success', 'Vista globale attivata.');
        $this->get(route('soci.index'))->assertRedirect(route('dashboard'));
    }

    // ------------------------------------------------------------------
    // 15. Rule::exists scopate nel form socio
    // ------------------------------------------------------------------

    public function test_rule_exists_scopate_per_tenant_su_tag_categoria_tipo_e_sede_nel_form_socio(): void
    {
        $socio = $this->socio($this->a, 'Anna', 'Boscolo');
        $tipoA = TipoSocio::query()->create(['associazione_id' => $this->a->id, 'nome' => 'Ordinario']);
        $categoriaA = CategoriaSocio::query()->create(['associazione_id' => $this->a->id, 'nome' => 'Adulti']);
        $tagA = Tag::query()->create(['associazione_id' => $this->a->id, 'nome_tag' => 'Coro', 'colore' => '#ff0000']);
        $tagA2 = Tag::query()->create(['associazione_id' => $this->a->id, 'nome_tag' => 'Teatro', 'colore' => '#00ff00']);
        $sedeA = $this->sede($this->a, 'Sede Centro');

        $tipoB = TipoSocio::query()->create(['associazione_id' => $this->b->id, 'nome' => 'Ordinario']);
        $categoriaB = CategoriaSocio::query()->create(['associazione_id' => $this->b->id, 'nome' => 'Adulti']);
        $tagB = Tag::query()->create(['associazione_id' => $this->b->id, 'nome_tag' => 'Coro', 'colore' => '#ff0000']);
        $sedeB = $this->sede($this->b, 'Sede Aurora');

        $prima = (array) DB::table('soci')->where('id', $socio->id)->first();

        $this->actingAs($this->adminA)->from(route('soci.edit', $socio))
            ->put(route('soci.update', $socio), $this->formSocio($socio, [
                'telefono' => '3331112222',
                'tipo_socio_id' => $tipoB->id,
                'categoria_socio_id' => $categoriaB->id,
                'tag_ids' => [$tagB->id],
                'sede_id' => $sedeB->id,
            ]))
            ->assertRedirect(route('soci.edit', $socio))
            ->assertSessionHasErrors(['tipo_socio_id', 'categoria_socio_id', 'tag_ids.0', 'sede_id']);

        $this->assertEquals($prima, (array) DB::table('soci')->where('id', $socio->id)->first());
        $this->assertSame(0, DB::table('socio_tags')->where('socio_id', $socio->id)->count());

        $this->put(route('soci.update', $socio), $this->formSocio($socio, [
            'telefono' => '3331112222',
            'tipo_socio_id' => $tipoA->id,
            'categoria_socio_id' => $categoriaA->id,
            'tag_ids' => [$tagA->id, $tagA2->id],
            'sede_id' => $sedeA->id,
        ]))->assertRedirect(route('soci.show', $socio))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('soci', [
            'id' => $socio->id,
            'tipo_socio_id' => $tipoA->id,
            'categoria_socio_id' => $categoriaA->id,
            'sede_id' => $sedeA->id,
            'telefono' => '3331112222',
            'associazione_id' => $this->a->id,
        ]);
        $this->assertEqualsCanonicalizing([$tagA->id, $tagA2->id], DB::table('socio_tags')->where('socio_id', $socio->id)->pluck('tag_id')->all());

        // Togliendo un tag la pivot si sincronizza
        $this->put(route('soci.update', $socio), $this->formSocio($socio, [
            'tipo_socio_id' => $tipoA->id, 'categoria_socio_id' => $categoriaA->id, 'tag_ids' => [$tagA2->id], 'sede_id' => $sedeA->id,
        ]))->assertSessionHasNoErrors();
        $this->assertSame([$tagA2->id], DB::table('socio_tags')->where('socio_id', $socio->id)->pluck('tag_id')->all());
    }

    // ------------------------------------------------------------------
    // 16. API: chiave di tenant e chiave globale
    // ------------------------------------------------------------------

    public function test_chiave_api_tenant_legge_solo_i_propri_soci_e_quella_globale_filtra_per_associazione(): void
    {
        $this->socio($this->a, 'Anna', 'Faretti');
        $this->socio($this->a, 'Bruno', 'Faretti');
        $socioB = $this->socio($this->b, 'Carla', 'Aurorini');

        $chiaveA = $this->chiaveApi($this->a, ['soci:read']);
        $globale = $this->chiaveApi(null, ['soci:read']);
        $this->assertNull($chiaveA->ultimo_utilizzo);

        Carbon::setTestNow('2026-06-01 11:15:00');

        $lista = $this->api('GET', '/api/v1/soci', $chiaveA->api_key)->assertOk()->json();
        $this->assertSame(2, $lista['total']);
        $this->assertSame(['Faretti', 'Faretti'], array_column($lista['soci'], 'cognome'));

        $this->api('GET', '/api/v1/soci/'.$socioB->id, $chiaveA->api_key)->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->api('GET', '/api/v1/soci/search?q=Aurorini', $chiaveA->api_key)->assertOk()->assertJsonPath('count', 0);
        $this->api('GET', '/api/v1/soci/search?q=Faretti', $chiaveA->api_key)->assertOk()->assertJsonPath('count', 2);
        // Il filtro associazione_id è ignorato per le chiavi di tenant
        $this->assertSame(2, $this->api('GET', '/api/v1/soci?associazione_id='.$this->b->id, $chiaveA->api_key)->json('total'));

        $this->assertSame('2026-06-01 11:15:00', $chiaveA->fresh()->ultimo_utilizzo->format('Y-m-d H:i:s'));

        // Chiave globale: tutte le associazioni, filtrabili
        $this->assertSame(3, $this->api('GET', '/api/v1/soci', $globale->api_key)->json('total'));
        $soloB = $this->api('GET', '/api/v1/soci?associazione_id='.$this->b->id, $globale->api_key)->assertOk()->json();
        $this->assertSame(1, $soloB['total']);
        $this->assertSame($socioB->id, $soloB['soci'][0]['id']);
        $this->api('GET', '/api/v1/soci/'.$socioB->id, $globale->api_key)->assertOk()->assertJsonPath('socio.associazione_id', $this->b->id);

        // Elenco associazioni solo per le chiavi globali
        $this->api('GET', '/api/v1/associazioni', $chiaveA->api_key)->assertForbidden()->assertJsonPath('code', 'global_only');
        $this->api('GET', '/api/v1/associazioni', $globale->api_key)->assertOk();

        // Permesso mancante e chiave inesistente
        $this->api('GET', '/api/v1/quote', $chiaveA->api_key)->assertForbidden()->assertJsonPath('code', 'permission_denied');
        $this->api('GET', '/api/v1/soci', str_repeat('a', 64))->assertUnauthorized()->assertJsonPath('code', 'auth_invalid');
        $this->getJson('/api/v1/soci')->assertUnauthorized()->assertJsonPath('code', 'auth_missing');
    }

    // ------------------------------------------------------------------
    // 17. API: aggiornamento socio
    // ------------------------------------------------------------------

    public function test_api_update_socio_whitelist_email_duplicata_e_stato_non_valido(): void
    {
        $socio = $this->socio($this->a, 'Anna', 'Boscolo', ['cap' => '44121', 'note' => null]);
        $altro = $this->socio($this->a, 'Bruno', 'Ferri');
        $socioB = $this->socio($this->b, 'Carla', 'Aurorini');
        $chiave = $this->chiaveApi($this->a, ['soci:write']);
        $url = '/api/v1/soci/'.$socio->id;

        // Solo i campi in whitelist; associazione_id e numero_socio ignorati
        $this->api('PUT', $url, $chiave->api_key, [
            'nome' => '  Annalisa ',
            'cap' => '40121',
            'note' => 'Aggiornata dal gestionale esterno',
            'campo_ignorato' => 'x',
            'associazione_id' => $this->b->id,
            'numero_socio' => '999999',
        ])->assertOk()->assertJson(['success' => true, 'message' => 'Socio aggiornato.']);

        $this->assertDatabaseHas('soci', [
            'id' => $socio->id,
            'nome' => 'Annalisa',
            'cap' => '40121',
            'note' => 'Aggiornata dal gestionale esterno',
            'associazione_id' => $this->a->id,
            'numero_socio' => $socio->numero_socio,
        ]);

        // Email già usata da un altro socio dello stesso tenant: 409
        $this->api('PUT', $url, $chiave->api_key, ['email' => $altro->email])
            ->assertStatus(409)->assertJsonPath('code', 'duplicate_email');
        $this->assertSame($socio->email, $socio->fresh()->email);

        // La stessa email in un altro tenant è ammessa
        $this->api('PUT', $url, $chiave->api_key, ['email' => $socioB->email])->assertOk();
        $this->assertSame($socioB->email, $socio->fresh()->email);

        // Stato non ammesso: 400
        $this->api('PUT', $url, $chiave->api_key, ['stato' => 'Pippo'])->assertStatus(400)->assertJsonPath('code', 'invalid_stato');
        $this->assertSame(Socio::STATO_ATTIVO, $socio->fresh()->stato);
        $this->api('PUT', $url, $chiave->api_key, ['stato' => 'Sospeso'])->assertOk();
        $this->assertSame('Sospeso', $socio->fresh()->stato);

        // Body vuoto o senza campi utili
        $this->call('PUT', $url, [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$chiave->api_key, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '')
            ->assertStatus(400)->assertJsonPath('code', 'empty_body');
        $this->api('PUT', $url, $chiave->api_key, ['campo_ignorato' => 'x'])->assertStatus(400)->assertJsonPath('code', 'no_valid_fields');

        // Socio di un altro tenant: 404, nessuna modifica
        $this->api('PUT', '/api/v1/soci/'.$socioB->id, $chiave->api_key, ['nome' => 'Intruso'])->assertNotFound();
        $this->assertSame('Carla', $socioB->fresh()->nome);

        // Valori fuori schema (CAP di 10 cifre, nome nullo) vanno rifiutati con 400, non salvati né trasformati in 500
        $this->api('PUT', $url, $chiave->api_key, ['cap' => '4012199999'])->assertStatus(400)->assertJsonPath('code', 'invalid_field');
        $this->assertSame('40121', $socio->fresh()->cap);
        $this->api('PUT', $url, $chiave->api_key, ['nome' => null])->assertStatus(400)->assertJsonPath('code', 'invalid_field');
        $this->assertSame('Annalisa', $socio->fresh()->nome);
    }

    // ------------------------------------------------------------------
    // 18. Token Sanctum personale
    // ------------------------------------------------------------------

    public function test_token_sanctum_personale_eredita_perimetro_sede_e_abilities_del_proprietario(): void
    {
        $sedeX = $this->sede($this->a, 'Sede Centro');
        $sedeY = $this->sede($this->a, 'Sede Lido');
        $utente = $this->staff($this->a, 'Rosa', ['responsabile_sede'], [$sedeX->id]);

        $socioX = $this->socio($this->a, 'Carla', 'Centrale', ['sede_id' => $sedeX->id]);
        $socioY = $this->socio($this->a, 'Dario', 'Lidense', ['sede_id' => $sedeY->id]);
        $tesseraX = Tessera::factory()->create(['associazione_id' => $this->a->id, 'socio_id' => $socioX->id]);
        $tesseraY = Tessera::factory()->create(['associazione_id' => $this->a->id, 'socio_id' => $socioY->id]);
        Tessera::factory()->create(['associazione_id' => $this->b->id, 'socio_id' => $this->socio($this->b, 'Bea', 'Aurora')->id]);

        // Crea il token personale dalla pagina chiavi API
        $this->actingAs($utente)->post(route('api-tokens.store'), ['nome' => 'Scanner ingresso', 'abilities' => ['tessere:read']])
            ->assertRedirect(route('api-keys.index'))
            ->assertSessionHas('nuovo_token');
        $token = (string) session('nuovo_token');
        $record = PersonalAccessToken::query()->sole();
        $this->assertSame($utente->id, $record->tokenable_id);
        $this->assertSame(['tessere:read'], $record->abilities);

        // Il client esterno usa il token (nessuna sessione web)
        $this->esci();
        $tessere = $this->api('GET', '/api/v1/tessere', $token)->assertOk()->json();
        $this->assertSame(1, $tessere['total']);
        $this->assertSame($tesseraX->id, $tessere['tessere'][0]['id']);
        $this->api('GET', '/api/v1/tessere/'.$tesseraY->id, $token)->assertNotFound();
        $this->assertNotNull($record->fresh()->last_used_at);

        // Ability mancante
        $this->api('GET', '/api/v1/soci', $token)->assertForbidden()->assertJsonPath('code', 'permission_denied');

        // Revoca: il token sparisce e smette di funzionare
        $this->actingAs($utente->fresh())->delete(route('api-tokens.destroy', $record->id))->assertSessionHas('success', 'Token revocato.');
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $record->id]);
        $this->esci();
        $this->api('GET', '/api/v1/tessere', $token)->assertUnauthorized()->assertJsonPath('code', 'auth_invalid');

        // Un utente senza permesso di modifica dei soci non si crea un token che li modifica
        $volontario = $this->staff($this->a, 'Vito', ['volontario']);
        $tokenVolontario = $volontario->createToken('Scorciatoia', ['soci:write', 'tessere:read'])->plainTextToken;
        $this->esci();
        $this->api('PUT', '/api/v1/soci/'.$socioX->id, $tokenVolontario, ['nome' => 'Hackerato'])
            ->assertForbidden()->assertJsonPath('code', 'permission_denied');
        $this->api('GET', '/api/v1/tessere', $tokenVolontario)->assertForbidden();
        $this->assertSame('Carla', $socioX->fresh()->nome);

        // Il proprietario promosso a operatore usa lo stesso token
        $volontario->syncRoles(['operatore']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->api('PUT', '/api/v1/soci/'.$socioX->id, $tokenVolontario, ['nome' => 'Carlotta'])->assertOk();
        $this->assertSame('Carlotta', $socioX->fresh()->nome);
    }

    // ------------------------------------------------------------------
    // 19. Chiave scaduta o disattivata
    // ------------------------------------------------------------------

    public function test_chiave_api_scaduta_o_disattivata_viene_rifiutata_e_il_toggle_la_riattiva(): void
    {
        $scaduta = $this->chiaveApi($this->a, ['*'], ['scadenza' => '2026-05-31']);
        $oggi = $this->chiaveApi($this->a, ['*'], ['scadenza' => '2026-06-01']);
        $chiave = $this->chiaveApi($this->a, ['*']);

        // Anche il ping passa dalla porta v1: chiave scaduta → 403 key_expired (audit run-1)
        $this->api('GET', '/api/v1/', $scaduta->api_key)->assertForbidden()->assertJsonPath('code', 'key_expired');
        $this->api('GET', '/api/v1/soci', $scaduta->api_key)->assertForbidden()->assertJsonPath('code', 'key_expired');
        // Valida per tutto il giorno della scadenza
        $this->api('GET', '/api/v1/', $oggi->api_key)->assertOk();

        $this->api('GET', '/api/v1/', $chiave->api_key)->assertOk()
            ->assertJson(['success' => true, 'message' => 'API key valida.', 'globale' => false, 'associazione' => 'Circolo Il Faro']);

        // L'admin la disattiva
        $this->actingAs($this->adminA)->patch(route('api-keys.toggle', $chiave))
            ->assertRedirect(route('api-keys.index'))->assertSessionHas('success', 'Chiave disattivata.');
        $this->assertDatabaseHas('api_keys', ['id' => $chiave->id, 'attiva' => false]);

        $this->esci();
        $this->api('GET', '/api/v1/', $chiave->api_key)->assertForbidden()->assertJsonPath('code', 'key_disabled');
        $this->api('GET', '/api/v1/soci', $chiave->api_key)->assertForbidden()->assertJsonPath('code', 'key_disabled');

        // L'admin di B non può riattivarla
        $this->actingAs($this->adminB)->patch(route('api-keys.toggle', $chiave))->assertForbidden();
        $this->assertDatabaseHas('api_keys', ['id' => $chiave->id, 'attiva' => false]);

        $this->actingAs($this->adminA)->patch(route('api-keys.toggle', $chiave))->assertSessionHas('success', 'Chiave attivata.');
        $this->assertDatabaseHas('api_keys', ['id' => $chiave->id, 'attiva' => true]);

        $this->esci();
        $this->api('GET', '/api/v1/', $chiave->api_key)->assertOk()->assertJsonPath('message', 'API key valida.');
    }

    // ------------------------------------------------------------------
    // 20. Check-in evento via API con chiave globale
    // ------------------------------------------------------------------

    public function test_checkin_evento_via_api_con_chiave_globale_usa_il_tenant_dell_evento_e_rifiuta_socio_di_altro_tenant(): void
    {
        $evento = Evento::factory()->create(['associazione_id' => $this->a->id, 'titolo' => 'Concerto d\'estate']);
        $socioA = $this->socio($this->a, 'Anna', 'Boscolo', ['numero_socio' => '0042']);
        $tesseraA = Tessera::factory()->create(['associazione_id' => $this->a->id, 'socio_id' => $socioA->id, 'numero_tessera' => '20260042']);
        $socioB = $this->socio($this->b, 'Carla', 'Aurorini', ['numero_socio' => '7777']);
        Tessera::factory()->create(['associazione_id' => $this->b->id, 'socio_id' => $socioB->id, 'numero_tessera' => '20267777']);

        $globale = $this->chiaveApi(null, ['eventi:write']);
        $url = '/api/v1/eventi/'.$evento->id.'/checkin';

        // Socio di un'altra associazione: per id non trovato, per numero o tessera non identificato
        $this->api('POST', $url, $globale->api_key, ['socio_id' => $socioB->id])->assertNotFound()->assertJsonPath('code', 'socio_not_found');
        $this->api('POST', $url, $globale->api_key, ['numero_socio' => '7777'])->assertStatus(400)->assertJsonPath('code', 'missing_socio');
        $this->api('POST', $url, $globale->api_key, ['numero_tessera' => '20267777'])->assertStatus(400)->assertJsonPath('code', 'missing_socio');
        $this->assertSame(0, EventoPartecipante::allTenants()->count());

        // Socio di A con la sua tessera
        $this->api('POST', $url, $globale->api_key, ['numero_tessera' => $tesseraA->numero_tessera])
            ->assertOk()
            ->assertJson(['success' => true, 'already_checked_in' => false, 'evento' => 'Concerto d\'estate'])
            ->assertJsonPath('socio.numero_socio', '0042');

        $this->assertDatabaseHas('eventi_partecipanti', [
            'evento_id' => $evento->id,
            'socio_id' => $socioA->id,
            'associazione_id' => $this->a->id,
            'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO,
        ]);

        // Ripetuto: già registrato, nessun doppione
        $this->api('POST', $url, $globale->api_key, ['numero_socio' => '0042'])->assertOk()->assertJsonPath('already_checked_in', true);
        $this->assertSame(1, EventoPartecipante::allTenants()->where('evento_id', $evento->id)->count());

        // Una chiave di B non vede l'evento di A, una in sola lettura non fa check-in
        $chiaveB = $this->chiaveApi($this->b, ['eventi:write']);
        $this->api('POST', $url, $chiaveB->api_key, ['socio_id' => $socioB->id])->assertNotFound()->assertJsonPath('code', 'not_found');
        $lettura = $this->chiaveApi($this->a, ['eventi:read']);
        $this->api('POST', $url, $lettura->api_key, ['socio_id' => $socioA->id])->assertForbidden();

        // Un socio sospeso viene respinto
        $sospeso = $this->socio($this->a, 'Sergio', 'Sospeso', ['stato' => Socio::STATO_SOSPESO]);
        $this->api('POST', $url, $globale->api_key, ['socio_id' => $sospeso->id])->assertStatus(400)->assertJsonPath('code', 'socio_not_active');
        $this->assertSame(1, EventoPartecipante::allTenants()->count());
    }

    // ------------------------------------------------------------------
    // 21. Registrazione automatica di una nuova associazione
    // ------------------------------------------------------------------

    public function test_nuova_associazione_registrata_in_modalita_automatica_con_email_admin_gia_esistente_passa_dal_super_admin(): void
    {
        Mail::fake();
        config(['piattaforma.registrazione_associazioni' => 'automatica', 'piattaforma.registrazione_notifica_email' => null]);

        // L'email dell'amministratore è già un utente della piattaforma: rifiutata subito
        $this->from(route('registrazione.create'))
            ->post(route('registrazione.store'), $this->moduloRegistrazione(['admin_email' => 'giovanna@ilfaro.test']))
            ->assertSessionHasErrors('admin_email');
        $this->assertSame(0, RichiestaRegistrazione::query()->count());

        // Dati liberi: dopo la conferma dell'email l'associazione nasce da sola
        $this->post(route('registrazione.store'), $this->moduloRegistrazione())->assertRedirect(route('registrazione.inviata'));
        $richiesta = RichiestaRegistrazione::query()->sole();
        $this->assertSame(RichiestaRegistrazione::STATO_IN_ATTESA, $richiesta->stato);
        $this->assertNull($richiesta->email_verificata_at);

        $this->get($this->linkVerifica('ruggero.tosi@bandavallata.test'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', fn (string $m) => str_contains($m, 'Banda Musicale Vallata è attiva'));

        $richiesta->refresh();
        $this->assertSame(RichiestaRegistrazione::STATO_APPROVATA, $richiesta->stato);
        $this->assertNotNull($richiesta->associazione_id);
        $this->assertNull($richiesta->gestita_da);
        $banda = Associazione::query()->findOrFail($richiesta->associazione_id);
        $this->assertSame('Banda Musicale Vallata', $banda->nome);
        $this->assertTrue((bool) $banda->attiva);
        $this->assertDatabaseHas('sedi', ['associazione_id' => $banda->id, 'nome' => 'Sede Principale', 'citta' => 'Bologna']);

        $ruggero = Utente::query()->where('email', 'ruggero.tosi@bandavallata.test')->sole();
        $this->assertSame(Utente::RUOLO_ADMIN_ASSOCIAZIONE, $ruggero->ruolo);
        $this->assertSame($banda->id, $ruggero->associazione_id);
        $this->assertTrue($ruggero->hasRole(Utente::RUOLO_ADMIN_ASSOCIAZIONE));
        Mail::assertNotSent(NuovaRichiestaRegistrazioneMail::class);

        // Il nuovo admin accede e vede solo la sua associazione
        $this->post('/login', ['email' => 'ruggero.tosi@bandavallata.test', 'password' => self::PASSWORD])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($ruggero);
        $this->assertSame($banda->id, session('associazione_id'));
        $this->assertSame(0, $this->get(route('soci.index', ['tessera_stato' => 'all', 'ajax' => 1]))->json('count'));
        $this->esci();

        // Seconda richiesta: la P.IVA viene occupata da un'altra associazione prima della conferma
        $this->post(route('registrazione.store'), $this->moduloRegistrazione([
            'nome' => 'Coro Alpino',
            'email' => 'info@coroalpino.test',
            'partita_iva' => '01234567890',
            'admin_nome' => 'Marta',
            'admin_cognome' => 'Ferraresi',
            'admin_email' => 'marta@coroalpino.test',
        ]))->assertSessionHasNoErrors();
        $doppione = RichiestaRegistrazione::query()->where('admin_email', 'marta@coroalpino.test')->sole();
        Associazione::factory()->create(['nome' => 'Coro Alpino (già iscritto)', 'partita_iva' => '01234567890']);

        $this->get($this->linkVerifica('marta@coroalpino.test'))->assertOk();
        $doppione->refresh();
        $this->assertSame(RichiestaRegistrazione::STATO_IN_ATTESA, $doppione->stato);
        $this->assertNotNull($doppione->email_verificata_at);
        $this->assertNull($doppione->associazione_id);
        $this->assertFalse(Utente::query()->where('email', 'marta@coroalpino.test')->exists());
        Mail::assertSent(NuovaRichiestaRegistrazioneMail::class, fn ($m) => $m->hasTo('super@piattaforma.test'));

        // Il super admin prova ad approvare: avviso di doppione, nulla viene creato
        $this->actingAs($this->superAdmin)->from(route('richieste-registrazione.index'))
            ->post(route('richieste-registrazione.approva', $doppione))
            ->assertRedirect(route('richieste-registrazione.index'))
            ->assertSessionHas('warning', fn (string $m) => str_contains($m, 'partita IVA'));
        $this->assertSame(RichiestaRegistrazione::STATO_IN_ATTESA, $doppione->fresh()->stato);
        $this->assertSame(1, Associazione::query()->where('partita_iva', '01234567890')->count());
    }

    // ------------------------------------------------------------------
    // 22. L'operatore non configura
    // ------------------------------------------------------------------

    public function test_operatore_non_puo_configurare_ruoli_sedi_modulo_iscrizione_o_chiavi_api(): void
    {
        $operatore = $this->staff($this->a, 'Pietro');
        $collega = $this->staff($this->a, 'Lucia');
        $sediPrima = Sede::allTenants()->count();
        $chiaviPrima = ApiKey::query()->count();
        $impostazioniPrima = DB::table('associazioni')->where('id', $this->a->id)->value('updated_at');

        $this->actingAs($operatore);
        $this->get(route('ruoli.index'))->assertForbidden();
        $this->put(route('ruoli.update', $collega), ['ruoli' => ['presidente']])->assertForbidden();
        $this->put(route('ruoli.update', $operatore), ['ruoli' => ['presidente']])->assertForbidden();
        $this->post(route('sedi.save'), ['nome' => 'Sede abusiva', 'citta' => 'Ferrara'])->assertForbidden();
        $this->get(route('configurazioni.modulo-iscrizione'))->assertForbidden();
        $this->post(route('configurazioni.modulo-iscrizione.salva'), ['campi' => []])->assertForbidden();
        $this->get(route('api-keys.index'))->assertForbidden();
        $this->post(route('api-keys.store'), ['nome' => 'Chiave abusiva', 'permessi' => ['*']])->assertForbidden();

        $this->assertSame(['operatore'], $collega->fresh()->getRoleNames()->all());
        $this->assertSame(['operatore'], $operatore->fresh()->getRoleNames()->all());
        $this->assertSame($sediPrima, Sede::allTenants()->count());
        $this->assertSame($chiaviPrima, ApiKey::query()->count());
        $this->assertFalse(ApiKey::query()->where('nome', 'Chiave abusiva')->exists());
        $this->assertSame($impostazioniPrima, DB::table('associazioni')->where('id', $this->a->id)->value('updated_at'));

        // Nemmeno disattiva o elimina le chiavi esistenti dell'associazione
        $chiave = $this->chiaveApi($this->a, ['soci:read']);
        $this->patch(route('api-keys.toggle', $chiave))->assertForbidden();
        $this->delete(route('api-keys.destroy', $chiave))->assertForbidden();
        $this->assertDatabaseHas('api_keys', ['id' => $chiave->id, 'attiva' => true]);
    }

    // ------------------------------------------------------------------
    // 23. Perimetro sede sulla prima nota
    // ------------------------------------------------------------------

    public function test_perimetro_sede_sulla_prima_nota_e_sul_registro_modifiche_lascia_visibili_i_movimenti_senza_sede(): void
    {
        $sedeX = $this->sede($this->a, 'Sede Centro');
        $sedeY = $this->sede($this->a, 'Sede Lido');
        $categoria = CategoriaMovimento::factory()->entrata()->create(['associazione_id' => $this->a->id, 'nome' => 'Offerte']);
        $utente = $this->staff($this->a, 'Rosa', ['responsabile_sede'], [$sedeX->id]);

        $movX = Movimento::factory()->entrata()->create(['associazione_id' => $this->a->id, 'categoria_id' => $categoria->id, 'importo' => 40, 'sede_id' => $sedeX->id, 'descrizione' => 'Offerte Centro']);
        $movY = Movimento::factory()->entrata()->create(['associazione_id' => $this->a->id, 'categoria_id' => $categoria->id, 'importo' => 70, 'sede_id' => $sedeY->id, 'descrizione' => 'Offerte Lido']);
        $movComune = Movimento::factory()->entrata()->create(['associazione_id' => $this->a->id, 'categoria_id' => $categoria->id, 'importo' => 25, 'sede_id' => null, 'descrizione' => 'Contributo comune']);

        $pagina = $this->actingAs($utente)->get(route('prima-nota.index'))->assertOk();
        $this->assertEqualsCanonicalizing([$movX->id, $movComune->id], collect($pagina->viewData('movimenti')->items())->pluck('id')->all());
        $this->assertEqualsWithDelta(65.0, $pagina->viewData('totaleEntrate'), 0.001);
        $pagina->assertDontSee('Offerte Lido');

        // Il movimento dell'altra sede non è raggiungibile, quello comune non è modificabile
        $this->put(route('prima-nota.update', $movY), ['tipo' => 'entrata', 'importo' => '1', 'data' => '2026-06-01', 'categoria_id' => $categoria->id, 'sede_id' => $sedeX->id])->assertNotFound();
        $this->put(route('prima-nota.update', $movComune), ['tipo' => 'entrata', 'importo' => '1', 'data' => '2026-06-01', 'categoria_id' => $categoria->id, 'sede_id' => $sedeX->id])->assertForbidden();
        $this->assertSame('25.00', (string) $movComune->fresh()->importo);

        $nuovo = ['tipo' => Movimento::TIPO_ENTRATA, 'importo' => '12,00', 'data' => '2026-06-01', 'categoria_id' => $categoria->id, 'descrizione' => 'Lotteria'];
        $this->from(route('prima-nota.index'))->post(route('prima-nota.store'), $nuovo + ['importo' => '12', 'sede_id' => $sedeY->id])->assertSessionHasErrors('sede_id');
        $this->post(route('prima-nota.store'), array_merge($nuovo, ['importo' => '12']))->assertSessionHasErrors('sede_id');
        $this->assertFalse(Movimento::allTenants()->withoutGlobalScope('sede')->where('descrizione', 'Lotteria')->exists());

        $this->post(route('prima-nota.store'), array_merge($nuovo, ['importo' => '12', 'sede_id' => $sedeX->id]))
            ->assertRedirect(route('prima-nota.index'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('movimenti', ['descrizione' => 'Lotteria', 'sede_id' => $sedeX->id, 'registrato_da' => $utente->id, 'associazione_id' => $this->a->id]);
        $this->assertEqualsWithDelta(77.0, $this->get(route('prima-nota.index'))->viewData('totaleEntrate'), 0.001);

        // L'admin vede tutto
        $this->assertEqualsWithDelta(147.0, $this->actingAs($this->adminA)->get(route('prima-nota.index'))->viewData('totaleEntrate'), 0.001);
    }

    // ------------------------------------------------------------------
    // 24. Nucleo familiare
    // ------------------------------------------------------------------

    public function test_nucleo_familiare_scioglimento_e_rimozione_capofamiglia_mantengono_i_soci(): void
    {
        $padre = $this->socio($this->a, 'Roberto', 'Rossellini');
        $madre = $this->socio($this->a, 'Giulia', 'Rossellini');
        $figlia = $this->socio($this->a, 'Chiara', 'Rossellini');
        $estraneo = $this->socio($this->b, 'Nando', 'Aurorini');
        $cancellato = $this->socio($this->a, 'Paolo', 'Rossellini');
        $cancellato->delete();

        $this->actingAs($this->adminA)->from(route('nuclei.index'))
            ->post(route('nuclei.store'), ['nome' => 'Famiglia Rossellini', 'capofamiglia_id' => $padre->id])
            ->assertRedirect(route('nuclei.index'))->assertSessionHas('success');
        $nucleo = Nucleo::query()->sole();
        $this->assertSame($padre->id, $nucleo->capofamiglia_id);
        $this->assertSame($this->a->id, $nucleo->associazione_id);

        $this->post(route('nuclei.membri.store', $nucleo), ['socio_id' => $madre->id])->assertSessionHas('success');
        $this->post(route('nuclei.membri.store', $nucleo), ['socio_id' => $figlia->id])->assertSessionHas('success');
        $this->assertSame(3, Socio::query()->where('nucleo_id', $nucleo->id)->count());

        // Membro di un altro tenant o nel cestino: rifiutati
        $this->post(route('nuclei.membri.store', $nucleo), ['socio_id' => $estraneo->id])->assertSessionHasErrors('socio_id');
        $this->assertNull($estraneo->fresh()->nucleo_id);
        $this->post(route('nuclei.membri.store', $nucleo), ['socio_id' => $cancellato->id])->assertSessionHasErrors('socio_id');
        $this->assertNull(Socio::withTrashed()->find($cancellato->id)->nucleo_id);
        $this->from(route('nuclei.index'))->post(route('nuclei.store'), ['nome' => 'Nucleo altrui', 'capofamiglia_id' => $estraneo->id])->assertSessionHasErrors('capofamiglia_id');

        // Il capofamiglia lascia il nucleo: resta senza capofamiglia, gli altri rimangono
        $this->delete(route('nuclei.membri.destroy', [$nucleo, $padre]))->assertSessionHas('success');
        $this->assertNull($padre->fresh()->nucleo_id);
        $this->assertNull($nucleo->fresh()->capofamiglia_id);
        $this->assertSame(2, Socio::query()->where('nucleo_id', $nucleo->id)->count());

        // Scioglimento: i soci restano intatti
        $dati = fn () => Socio::query()->whereIn('id', [$padre->id, $madre->id, $figlia->id])->orderBy('id')->get(['id', 'nome', 'cognome', 'email', 'stato'])->toArray();
        $prima = $dati();
        $this->delete(route('nuclei.destroy', $nucleo))->assertSessionHas('success', 'Nucleo «Famiglia Rossellini» sciolto: i soci non sono stati modificati.');
        $this->assertDatabaseMissing('nuclei', ['id' => $nucleo->id]);
        $this->assertSame(0, Socio::withTrashed()->whereNotNull('nucleo_id')->count());
        $this->assertSame($prima, $dati());
        $this->assertSame(3, Socio::query()->count());
    }

    // ------------------------------------------------------------------
    // 25. Cestino unificato
    // ------------------------------------------------------------------

    public function test_cestino_unificato_mostra_solo_i_tipi_permessi_e_ripristina_nel_tenant_giusto(): void
    {
        $socioA = $this->socio($this->a, 'Anna', 'Cestinata');
        $evento = Evento::factory()->create(['associazione_id' => $this->a->id, 'titolo' => 'Gita annullata']);
        $documento = Documento::query()->create(['associazione_id' => $this->a->id, 'nome_file' => 'bilancio-vecchio.pdf', 'percorso_file' => 'documenti/bilancio.pdf', 'categoria' => 'Bilanci', 'dimensione_kb' => 12]);
        $verbale = Verbale::query()->create(['associazione_id' => $this->a->id, 'titolo' => 'Verbale da rifare', 'numero' => 3, 'anno' => 2026, 'data_riunione' => '2026-05-01', 'tipo_riunione' => 'Direttivo']);
        $socioB = $this->socio($this->b, 'Bea', 'Altrove');
        $socioB->delete();

        // L'admin sposta tutto nel cestino
        $this->actingAs($this->adminA);
        $this->delete(route('soci.destroy', $socioA))->assertRedirect();
        $this->delete(route('eventi.destroy', $evento))->assertRedirect();
        $this->delete(route('documenti.destroy', $documento))->assertRedirect();
        $this->delete(route('verbali.destroy', $verbale))->assertRedirect();
        $this->assertSoftDeleted('soci', ['id' => $socioA->id]);
        $this->assertSoftDeleted('eventi', ['id' => $evento->id]);
        $this->assertSoftDeleted('documenti', ['id' => $documento->id]);
        $this->assertSoftDeleted('verbali', ['id' => $verbale->id]);

        $tutto = $this->get(route('cestino.index'))->assertOk()->viewData('elementi');
        $this->assertEqualsCanonicalizing(['soci', 'eventi', 'documenti', 'verbali'], collect($tutto->items())->pluck('tipo')->all());

        // Operatore senza permesso sui documenti
        $operatore = Utente::factory()->operatore()->create(['associazione_id' => $this->a->id]);
        $operatore->syncPermissions(['soci.view', 'soci.delete', 'eventi.view', 'eventi.delete', 'verbali.view', 'verbali.delete']);

        $vista = $this->actingAs($operatore->fresh())->get(route('cestino.index'));
        $vista->assertOk()->assertSee('Cestinata')->assertSee('Gita annullata')->assertSee('Verbale da rifare')
            ->assertDontSee('bilancio-vecchio.pdf')->assertDontSee('Altrove');
        $this->assertEqualsCanonicalizing(['soci', 'eventi', 'verbali'], collect($vista->viewData('elementi')->items())->pluck('tipo')->all());
        $this->assertSame([], collect($vista->viewData('elementi')->items())->where('tipo', 'documenti')->all());

        // Non ripristina un documento né il socio di un'altra associazione
        $this->post(route('cestino.ripristina', ['documenti', $documento->id]))->assertForbidden();
        $this->post(route('cestino.ripristina', ['soci', $socioB->id]))->assertNotFound();
        $this->post(route('cestino.ripristina', ['inventato', $socioA->id]))->assertNotFound();
        $this->assertSoftDeleted('soci', ['id' => $socioB->id]);
        $this->assertSoftDeleted('documenti', ['id' => $documento->id]);

        // Ripristina il proprio socio
        $this->post(route('cestino.ripristina', ['soci', $socioA->id]))->assertRedirect(route('cestino.index'))->assertSessionHas('success', 'Socio ripristinato con successo.');
        $this->assertDatabaseHas('soci', ['id' => $socioA->id, 'deleted_at' => null, 'associazione_id' => $this->a->id]);

        // L'eliminazione definitiva dei verbali spetta agli amministratori
        $this->delete(route('cestino.elimina-definitivamente', ['verbali', $verbale->id]))->assertForbidden();
        $this->assertSoftDeleted('verbali', ['id' => $verbale->id]);

        $this->actingAs($this->adminA)->delete(route('cestino.elimina-definitivamente', ['verbali', $verbale->id]))->assertSessionHas('success', 'Verbale eliminato definitivamente.');
        $this->assertDatabaseMissing('verbali', ['id' => $verbale->id]);

        // L'admin di A non elimina definitivamente il socio di B
        $this->delete(route('cestino.elimina-definitivamente', ['soci', $socioB->id]))->assertNotFound();
        $this->assertSoftDeleted('soci', ['id' => $socioB->id]);
    }
}
