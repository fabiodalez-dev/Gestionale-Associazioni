<?php

namespace Tests\Feature\Flussi;

use App\Models\Account;
use App\Models\Adempimento;
use App\Models\AdempimentoAnno;
use App\Models\Annuncio;
use App\Models\Associazione;
use App\Models\Carica;
use App\Models\Cessazione;
use App\Models\Documento;
use App\Models\DocumentoSocio;
use App\Models\EmailLog;
use App\Models\EmailQueue;
use App\Models\EmailTemplate;
use App\Models\Evento;
use App\Models\NewsletterInviata;
use App\Models\PromemoriaInviato;
use App\Models\RichiestaIscrizione;
use App\Models\RispostaSondaggio;
use App\Models\Riunione;
use App\Models\RiunionePartecipante;
use App\Models\Scadenza;
use App\Models\Sede;
use App\Models\SmtpSetting;
use App\Models\Socio;
use App\Models\Sondaggio;
use App\Models\Spazio;
use App\Models\StoricoAttivitaSocio;
use App\Models\Tag;
use App\Models\Utente;
use App\Models\Verbale;
use App\Models\VerbaleFirmatario;
use App\Models\VotazioneRiunione;
use App\Notifications\Comunicazione\AuguriCompleannoNotification;
use App\Notifications\Struttura\ConvocazioneRiunioneNotification;
use App\Services\Comunicazione\CompleanniService;
use App\Services\Email\DefaultEmailTemplates;
use App\Services\Email\EmailService;
use App\Services\Email\TenantMailer;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Flussi reali — GOVERNANCE E COMUNICAZIONE.
 *
 * Libro soci, organi sociali, riunioni del direttivo (OdG, convocazione,
 * votazioni, bozza verbale), verbali (numerazione per anno, approvazione,
 * firmatari), documenti e Trasparenza, fascicolo socio, scadenze e
 * adempimenti, annunci/convocazioni, newsletter, sondaggi, impostazioni
 * SMTP e template, comunicazioni massive, email diretta, opt-out, coda di
 * invio e auguri di compleanno. Ogni test segue un flusso con più attori
 * (admin, socio nell'area soci, account del mini-sito, visitatore anonimo,
 * scheduler) e verifica che i dati finiscano nel database con i valori
 * giusti. Complementare ai percorsi di Percorsi/05 (non li ripete).
 */
class Flussi04_GovernanceComunicazioneFlussiTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Associazione $altraAssociazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        // Data di riferimento: metà maggio 2026
        Carbon::setTestNow('2026-05-15 10:00:00');

        $this->seed(RolesSeeder::class);

        $this->associazione = Associazione::factory()->create([
            'nome' => 'Circolo Il Faro',
            'slug' => 'circolo-faro',
            'frontend_pubblico_attivo' => true,
        ]);

        $this->altraAssociazione = Associazione::factory()->create([
            'nome' => 'Associazione Vicina',
            'slug' => 'associazione-vicina',
            'frontend_pubblico_attivo' => true,
        ]);

        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Paola',
            'cognome' => 'Segretaria',
            'email' => 'segreteria@circolo-faro.test',
        ]);
        $this->admin->assignRole('admin_associazione');
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('tenant.pubblico');
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Attori e fixture
    // ------------------------------------------------------------------

    private function comeAdmin(?Utente $utente = null): static
    {
        return $this->actingAs($utente ?? $this->admin, 'web');
    }

    private function comeOspite(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function creaSocio(array $override = [], ?Associazione $associazione = null): Socio
    {
        return Socio::factory()->create(array_merge([
            'associazione_id' => ($associazione ?? $this->associazione)->id,
        ], $override));
    }

    private function creaAdminDi(Associazione $associazione, array $override = []): Utente
    {
        $utente = Utente::factory()->adminAssociazione()->create(array_merge([
            'associazione_id' => $associazione->id,
        ], $override));
        $utente->assignRole('admin_associazione');

        return $utente;
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function configuraSmtp(array $override = []): SmtpSetting
    {
        return SmtpSetting::create(array_merge([
            'associazione_id' => $this->associazione->id,
            'smtp_host' => 'smtp.circolo-faro.test',
            'smtp_port' => 587,
            'smtp_user' => 'posta',
            'smtp_pass_encrypted' => 'segretissima',
            'smtp_encryption' => 'tls',
            'from_email' => 'noreply@circolo-faro.test',
            'from_name' => 'Circolo Il Faro',
            'max_per_hour' => 100,
        ], $override));
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function creaVerbale(array $override = [], ?Associazione $associazione = null): Verbale
    {
        $verbale = new Verbale(array_merge([
            'titolo' => 'Verbale di prova',
            'data_riunione' => '2026-02-10',
            'tipo_riunione' => 'Consiglio Direttivo',
        ], $override));
        $verbale->associazione_id = ($associazione ?? $this->associazione)->id;
        $verbale->anno = $override['anno'] ?? (int) Carbon::parse($verbale->data_riunione)->year;
        $verbale->numero = $override['numero'] ?? 1;
        $verbale->save();

        return $verbale;
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function datiRiunione(array $override = []): array
    {
        return array_merge([
            'titolo' => 'Consiglio direttivo di maggio',
            'data_ora' => '2026-05-28T21:00',
            'luogo' => 'Sede sociale',
            'stato' => Riunione::STATO_PIANIFICATA,
        ], $override);
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function datiVerbale(Verbale $verbale, array $override = []): array
    {
        return array_merge([
            'titolo' => $verbale->titolo,
            'data_riunione' => $verbale->data_riunione->toDateString(),
            'tipo_riunione' => $verbale->tipo_riunione,
            'presenti' => $verbale->presenti,
            'ordine_del_giorno' => $verbale->ordine_del_giorno,
            'deliberazioni' => $verbale->deliberazioni,
        ], $override);
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function datiScadenza(array $override = []): array
    {
        return array_merge([
            'titolo' => 'Rinnovo polizza RC',
            'descrizione' => 'Polizza responsabilità civile verso terzi',
            'data_scadenza' => now()->addDays(10)->toDateString(),
            'tipo_scadenza' => 'Generale',
            'priorita' => 'Alta',
            'stato' => Scadenza::STATO_ATTIVA,
            'assegnato_a' => '',
            'promemoria_giorni' => 7,
        ], $override);
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function datiAnnuncio(array $override = []): array
    {
        return array_merge([
            'titolo' => 'Annuncio',
            'tipo' => Annuncio::TIPO_COMUNICAZIONE,
            'corpo_html' => '<p>Testo annuncio</p>',
            'pubblicato_dal' => now()->subHour()->format('Y-m-d\TH:i'),
            'pubblicato_al' => '',
            'in_evidenza' => '0',
            'solo_soci' => '0',
        ], $override);
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function datiSmtp(array $override = []): array
    {
        return array_merge([
            'smtp_host' => 'smtp.circolo-faro.test',
            'smtp_port' => 587,
            'smtp_user' => 'posta@circolo-faro.test',
            'smtp_pass' => 'Password-Smtp-1',
            'smtp_encryption' => 'tls',
            'from_email' => 'noreply@circolo-faro.test',
            'from_name' => 'Circolo Il Faro',
            'reply_to_email' => 'segreteria@circolo-faro.test',
            'reply_to_name' => 'Segreteria',
            'max_per_hour' => 50,
        ], $override);
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function payloadSocio(array $override = []): array
    {
        return array_merge([
            'nome' => 'Mario',
            'cognome' => 'Rossi',
            'email' => 'mario.rossi@example.com',
            'data_nascita' => '1985-12-10',
            'data_iscrizione' => now()->toDateString(),
            'stato' => Socio::STATO_ATTIVO,
            'telefono' => '3331234567',
            'privacy_consenso' => '1',
        ], $override);
    }

    private function urlTenant(string $path = ''): string
    {
        return '/a/'.$this->associazione->slug.($path !== '' ? '/'.ltrim($path, '/') : '');
    }

    /**
     * Voci dello scadenzario unificato per l'admin.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function vociScadenzario(string $query = ''): Collection
    {
        $risposta = $this->comeAdmin()->get(route('scadenzario.index').$query);
        $risposta->assertOk();

        return collect($risposta->viewData('voci'));
    }

    private function mockMailerOk(array &$invii): void
    {
        $this->mock(TenantMailer::class, function ($mock) use (&$invii) {
            $mock->shouldReceive('send')
                ->andReturnUsing(function (SmtpSetting $settings, string $to) use (&$invii) {
                    $invii[] = $to;

                    return ['success' => true, 'error' => null];
                });
        });
    }

    // ==================================================================
    // 1. Libro soci
    // ==================================================================

    public function test_libro_soci_numera_per_anno_esclude_pending_e_filtra_per_anno_in_pdf_ed_excel(): void
    {
        $anna = $this->creaSocio(['nome' => 'Anna', 'cognome' => 'Primi', 'data_iscrizione' => '2024-02-01']);
        $bruno = $this->creaSocio(['nome' => 'Bruno', 'cognome' => 'Cessati', 'data_iscrizione' => '2024-06-10', 'stato' => Socio::STATO_RADIATO]);
        $carla = $this->creaSocio(['nome' => 'Carla', 'cognome' => 'Nuovi', 'data_iscrizione' => '2026-01-15']);
        $dario = $this->creaSocio(['nome' => 'Dario', 'cognome' => 'Nuovissimi', 'data_iscrizione' => '2026-03-01']);
        $elena = $this->creaSocio(['nome' => 'Elena', 'cognome' => 'Attesa', 'data_iscrizione' => '2026-02-01', 'stato' => Socio::STATO_IN_ATTESA]);
        // Socio di un altro tenant nello stesso anno: non deve comparire né spostare la numerazione
        $this->creaSocio(['nome' => 'Ospite', 'cognome' => 'Altrove', 'data_iscrizione' => '2026-01-01'], $this->altraAssociazione);

        Cessazione::factory()->create([
            'socio_id' => $bruno->id,
            'tipo' => Cessazione::TIPO_DIMISSIONI,
            'data' => '2025-12-31',
            'motivo' => 'Trasferimento in altra città',
        ]);

        // Registro completo
        $risposta = $this->comeAdmin()->get(route('libro-soci.index'));
        $risposta->assertOk()
            ->assertSee('Primi')
            ->assertSee('Cessati')
            ->assertSee('31/12/2025')
            ->assertSee('Trasferimento in altra città')
            ->assertDontSee('Attesa')
            ->assertDontSee('Altrove');

        $righe = collect($risposta->viewData('righe'));
        $this->assertCount(4, $righe);

        $progressivi = $righe->mapWithKeys(fn (array $r) => [$r['socio']->id => [$r['anno'], $r['progressivo']]]);
        $this->assertSame([2024, 1], $progressivi[$anna->id]);
        $this->assertSame([2024, 2], $progressivi[$bruno->id]);
        $this->assertSame([2026, 1], $progressivi[$carla->id]);
        $this->assertSame([2026, 2], $progressivi[$dario->id]);
        $this->assertFalse($progressivi->has($elena->id));

        $rigaBruno = $righe->first(fn (array $r) => $r['socio']->is($bruno));
        $this->assertNotNull($rigaBruno['cessazione']);
        $this->assertSame('2025-12-31', $rigaBruno['cessazione']->data->toDateString());
        $this->assertSame([2026, 2024], $risposta->viewData('anni'));

        // Filtro per anno: la numerazione resta quella del registro completo
        $filtrato = $this->comeAdmin()->get(route('libro-soci.index', ['anno' => 2026]));
        $filtrato->assertOk()->assertSee('Nuovi')->assertDontSee('Primi');
        $righe2026 = collect($filtrato->viewData('righe'));
        $this->assertSame([1, 2], $righe2026->pluck('progressivo')->all());
        $this->assertSame([$carla->id, $dario->id], $righe2026->map(fn (array $r) => $r['socio']->id)->all());

        // Export PDF ed Excel filtrati
        $pdf = $this->comeAdmin()->get(route('libro-soci.pdf', ['anno' => 2026]));
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
        $this->assertStringContainsString('libro-soci-2026-', (string) $pdf->headers->get('content-disposition'));

        $excel = $this->comeAdmin()->get(route('libro-soci.excel', ['anno' => 2026]));
        $excel->assertOk();
        $this->assertStringContainsString('libro-soci-2026-', (string) $excel->headers->get('content-disposition'));
        $this->assertStringContainsString('.xlsx', (string) $excel->headers->get('content-disposition'));
    }

    // ==================================================================
    // 2. Organi sociali
    // ==================================================================

    public function test_cariche_crea_modifica_elimina_con_socio_del_tenant_e_le_in_scadenza_compaiono_nello_scadenzario(): void
    {
        $presidente = $this->creaSocio(['nome' => 'Giorgio', 'cognome' => 'Mancini']);
        $estraneo = $this->creaSocio(['nome' => 'Intruso', 'cognome' => 'Esterno'], $this->altraAssociazione);

        $inizio = now()->subYear()->toDateString();
        $fine = now()->addDays(60)->toDateString();

        $this->comeAdmin()->post(route('organi-sociali.store'), [
            'socio_id' => $presidente->id,
            'ruolo' => 'Presidente',
            'inizio' => $inizio,
            'fine' => $fine,
            'note' => 'Eletto dall\'assemblea del 2025',
        ])->assertRedirect(route('organi-sociali.index'))->assertSessionHas('success');

        $carica = Carica::query()->where('socio_id', $presidente->id)->firstOrFail();
        $this->assertSame($this->associazione->id, $carica->associazione_id);
        $this->assertSame('Presidente', $carica->ruolo);
        $this->assertSame($inizio, $carica->inizio->toDateString());
        $this->assertSame($fine, $carica->fine->toDateString());
        $this->assertSame('Eletto dall\'assemblea del 2025', $carica->note);

        // L'elenco segnala il mandato in scadenza
        $indice = $this->comeAdmin()->get(route('organi-sociali.index'));
        $indice->assertOk()->assertSee('Mancini');
        $this->assertTrue(collect($indice->viewData('inScadenza'))->contains(fn (Carica $c) => $c->is($carica)));

        // Modifica di ruolo e fine mandato
        $nuovaFine = now()->addDays(45)->toDateString();
        $this->comeAdmin()->put(route('organi-sociali.update', $carica), [
            'socio_id' => $presidente->id,
            'ruolo' => 'Vicepresidente',
            'inizio' => $inizio,
            'fine' => $nuovaFine,
            'note' => 'Passaggio a vicepresidenza',
        ])->assertRedirect(route('organi-sociali.index'));

        $carica->refresh();
        $this->assertSame('Vicepresidente', $carica->ruolo);
        $this->assertSame($nuovaFine, $carica->fine->toDateString());
        $this->assertSame('Passaggio a vicepresidenza', $carica->note);

        // Socio di un altro tenant: rifiutato
        $this->comeAdmin()
            ->from(route('organi-sociali.index'))
            ->post(route('organi-sociali.store'), [
                'socio_id' => $estraneo->id,
                'ruolo' => 'Tesoriere',
                'inizio' => $inizio,
            ])
            ->assertRedirect(route('organi-sociali.index'))
            ->assertSessionHasErrors('socio_id');
        $this->assertDatabaseMissing('cariche', ['socio_id' => $estraneo->id]);

        // Fine mandato prima dell'inizio: rifiutata
        $this->comeAdmin()->post(route('organi-sociali.store'), [
            'socio_id' => $presidente->id,
            'ruolo' => 'Tesoriere',
            'inizio' => $inizio,
            'fine' => now()->subYears(2)->toDateString(),
        ])->assertSessionHasErrors('fine');
        $this->assertSame(1, Carica::query()->count());

        // Lo scadenzario unificato riporta la fine del mandato
        $voce = $this->vociScadenzario()->firstWhere('tipo', 'carica');
        $this->assertNotNull($voce);
        $this->assertStringContainsString('Vicepresidente', $voce['titolo']);
        $this->assertStringContainsString('Mancini', $voce['titolo']);
        $this->assertSame($nuovaFine, $voce['data']->toDateString());

        // Eliminazione
        $this->comeAdmin()->delete(route('organi-sociali.destroy', $carica))
            ->assertRedirect(route('organi-sociali.index'));
        $this->assertDatabaseMissing('cariche', ['id' => $carica->id]);
        $this->assertNull($this->vociScadenzario()->firstWhere('tipo', 'carica'));
    }

    // ==================================================================
    // 3. Riunione: OdG normalizzato e spazio del tenant
    // ==================================================================

    public function test_riunione_con_odg_normalizzato_spazio_del_tenant_e_modifica_che_rimuove_un_punto(): void
    {
        $sala = Spazio::factory()->create(['associazione_id' => $this->associazione->id, 'nome' => 'Sala consiglio']);
        $salaAltrui = Spazio::factory()->create(['associazione_id' => $this->altraAssociazione->id, 'nome' => 'Sala altrui']);

        $this->comeAdmin()->post(route('riunioni.store'), $this->datiRiunione([
            'spazio_id' => $sala->id,
            'odg' => [
                ['punto' => '   ', 'descrizione' => 'riga senza titolo'],
                ['punto' => 'Bilancio preventivo', 'descrizione' => '  Esame e approvazione  '],
                ['punto' => 'Varie ed eventuali', 'descrizione' => ''],
            ],
        ]))->assertSessionHasNoErrors();

        $riunione = Riunione::query()->where('titolo', 'Consiglio direttivo di maggio')->firstOrFail();
        $this->assertSame($this->associazione->id, $riunione->associazione_id);
        $this->assertSame($sala->id, $riunione->spazio_id);
        $this->assertSame(Riunione::STATO_PIANIFICATA, $riunione->stato);
        $this->assertSame('2026-05-28 21:00', $riunione->data_ora->format('Y-m-d H:i'));
        $this->assertSame([
            ['punto' => 'Bilancio preventivo', 'descrizione' => 'Esame e approvazione'],
            ['punto' => 'Varie ed eventuali', 'descrizione' => null],
        ], $riunione->odg);

        $this->comeAdmin()->get(route('riunioni.show', $riunione))
            ->assertOk()
            ->assertSee('Bilancio preventivo')
            ->assertSee('Sala consiglio');

        // Modifica: resta solo un punto, la riunione è stata svolta
        $this->comeAdmin()->put(route('riunioni.update', $riunione), $this->datiRiunione([
            'spazio_id' => '',
            'luogo' => 'Online',
            'stato' => Riunione::STATO_SVOLTA,
            'odg' => [['punto' => 'Varie ed eventuali', 'descrizione' => '']],
        ]))->assertRedirect(route('riunioni.show', $riunione));

        $riunione->refresh();
        $this->assertSame([['punto' => 'Varie ed eventuali', 'descrizione' => null]], $riunione->odg);
        $this->assertSame(Riunione::STATO_SVOLTA, $riunione->stato);
        $this->assertNull($riunione->spazio_id);
        $this->assertSame('Online', $riunione->luogo);

        // Spazio di un altro tenant: rifiutato, la riunione resta invariata
        $this->comeAdmin()
            ->from(route('riunioni.show', $riunione))
            ->put(route('riunioni.update', $riunione), $this->datiRiunione([
                'spazio_id' => $salaAltrui->id,
                'stato' => Riunione::STATO_SVOLTA,
            ]))
            ->assertSessionHasErrors('spazio_id');

        $this->assertNull($riunione->fresh()->spazio_id);

        // Stato non ammesso
        $this->comeAdmin()->post(route('riunioni.store'), $this->datiRiunione(['stato' => 'rimandata']))
            ->assertSessionHasErrors('stato');
        $this->assertSame(1, Riunione::query()->count());
    }

    // ==================================================================
    // 4. Convocazione: partecipanti dalle cariche attive
    // ==================================================================

    public function test_convocazione_riunione_crea_partecipanti_dalle_cariche_attive_senza_duplicati_e_salta_soci_cestinati(): void
    {
        $presidente = $this->creaSocio(['nome' => 'Piero', 'cognome' => 'Presidente']);
        $segretaria = $this->creaSocio(['nome' => 'Sara', 'cognome' => 'Segretaria']);
        $cestinato = $this->creaSocio(['nome' => 'Carlo', 'cognome' => 'Cestinato']);
        $exTesoriere = $this->creaSocio(['nome' => 'Ettore', 'cognome' => 'Scaduto']);
        $altroPresidente = $this->creaSocio(['nome' => 'Altro', 'cognome' => 'Tenant'], $this->altraAssociazione);
        $adminAltrui = $this->creaAdminDi($this->altraAssociazione);

        Carica::factory()->create(['socio_id' => $presidente->id, 'ruolo' => 'Presidente']);
        Carica::factory()->create(['socio_id' => $segretaria->id, 'ruolo' => 'Segretario', 'fine' => now()->addMonths(6)->toDateString()]);
        Carica::factory()->create(['socio_id' => $cestinato->id, 'ruolo' => 'Consigliere']);
        Carica::factory()->create(['socio_id' => $exTesoriere->id, 'ruolo' => 'Tesoriere', 'inizio' => now()->subYears(3)->toDateString(), 'fine' => now()->subMonth()->toDateString()]);
        Carica::factory()->create(['socio_id' => $altroPresidente->id, 'ruolo' => 'Presidente']);
        $cestinato->delete();

        $riunione = Riunione::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->comeAdmin()->post(route('riunioni.convoca', $riunione))
            ->assertRedirect(route('riunioni.show', $riunione))
            ->assertSessionHas('success');
        $this->comeAdmin()->post(route('riunioni.convoca', $riunione))
            ->assertRedirect(route('riunioni.show', $riunione));

        $partecipanti = RiunionePartecipante::query()->where('riunione_id', $riunione->id)->get();
        $this->assertCount(3, $partecipanti);
        $this->assertEqualsCanonicalizing(
            [$presidente->id, $segretaria->id, $cestinato->id],
            $partecipanti->pluck('socio_id')->all()
        );
        $this->assertTrue($partecipanti->every(fn (RiunionePartecipante $p) => $p->stato === RiunionePartecipante::STATO_INVITATO
            && $p->associazione_id === $this->associazione->id));
        $this->assertSame('Presidente', $partecipanti->firstWhere('socio_id', $presidente->id)->carica_ruolo);
        $this->assertSame('Segretario', $partecipanti->firstWhere('socio_id', $segretaria->id)->carica_ruolo);
        $this->assertNotNull($riunione->fresh()->convocazione_inviata_at);

        Notification::assertSentTo($presidente, ConvocazioneRiunioneNotification::class);
        Notification::assertSentTo($segretaria, ConvocazioneRiunioneNotification::class);
        Notification::assertSentTo($this->admin, ConvocazioneRiunioneNotification::class);
        Notification::assertNotSentTo($cestinato, ConvocazioneRiunioneNotification::class);
        Notification::assertNotSentTo($exTesoriere, ConvocazioneRiunioneNotification::class);
        Notification::assertNotSentTo($altroPresidente, ConvocazioneRiunioneNotification::class);
        Notification::assertNotSentTo($adminAltrui, ConvocazioneRiunioneNotification::class);

        // La conferma di presenza aggiorna il partecipante
        $invitoPresidente = $partecipanti->firstWhere('socio_id', $presidente->id);
        $this->comeAdmin()->put(route('riunioni.partecipanti.presenza', [$riunione, $invitoPresidente]), [
            'stato' => RiunionePartecipante::STATO_CONFERMATO,
        ])->assertRedirect(route('riunioni.show', $riunione));
        $this->assertSame(RiunionePartecipante::STATO_CONFERMATO, $invitoPresidente->fresh()->stato);

        // Riunione annullata: nessun invito né notifica
        $annullata = Riunione::factory()->annullata()->create(['associazione_id' => $this->associazione->id]);
        $notificheAlPresidente = Notification::sent($presidente, ConvocazioneRiunioneNotification::class)->count();

        $this->comeAdmin()->post(route('riunioni.convoca', $annullata))
            ->assertRedirect(route('riunioni.show', $annullata))
            ->assertSessionHas('warning');

        $this->assertSame(0, RiunionePartecipante::query()->where('riunione_id', $annullata->id)->count());
        $this->assertNull($annullata->fresh()->convocazione_inviata_at);
        $this->assertSame($notificheAlPresidente, Notification::sent($presidente, ConvocazioneRiunioneNotification::class)->count());
    }

    // ==================================================================
    // 5. Votazioni per punto OdG
    // ==================================================================

    public function test_votazione_per_punto_odg_aggiorna_lo_stesso_record_e_indice_fuori_odg_viene_rifiutato(): void
    {
        $riunione = Riunione::factory()->svolta()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Direttivo di aprile',
            'odg' => [
                ['punto' => 'Acquisto nuovo gazebo', 'descrizione' => null],
                ['punto' => 'Quota sociale 2027', 'descrizione' => null],
            ],
        ]);

        $this->comeAdmin()->post(route('riunioni.votazioni.store', $riunione), [
            'punto_indice' => 0, 'esito' => 'approvato', 'favorevoli' => 5, 'contrari' => 1, 'astenuti' => 0,
        ])->assertRedirect(route('riunioni.show', $riunione))->assertSessionHas('success');

        // Ripensamento sullo stesso punto: si aggiorna la riga esistente
        $this->comeAdmin()->post(route('riunioni.votazioni.store', $riunione), [
            'punto_indice' => 0, 'esito' => 'respinto', 'favorevoli' => 2, 'contrari' => 4, 'astenuti' => 0, 'note' => 'Rinviato al preventivo',
        ])->assertRedirect(route('riunioni.show', $riunione));

        $votazioni = VotazioneRiunione::query()->where('riunione_id', $riunione->id)->get();
        $this->assertCount(1, $votazioni);
        $this->assertSame('respinto', $votazioni[0]->esito);
        $this->assertSame([2, 4, 0], [(int) $votazioni[0]->favorevoli, (int) $votazioni[0]->contrari, (int) $votazioni[0]->astenuti]);
        $this->assertSame('Rinviato al preventivo', $votazioni[0]->note);
        $this->assertSame($this->associazione->id, $votazioni[0]->associazione_id);

        // Secondo punto approvato
        $this->comeAdmin()->post(route('riunioni.votazioni.store', $riunione), [
            'punto_indice' => 1, 'esito' => 'approvato', 'favorevoli' => 6, 'contrari' => 0, 'astenuti' => 0,
        ])->assertSessionHas('success');

        // Punto inesistente nell'OdG
        $this->comeAdmin()
            ->from(route('riunioni.show', $riunione))
            ->post(route('riunioni.votazioni.store', $riunione), [
                'punto_indice' => 5, 'esito' => 'approvato', 'favorevoli' => 1, 'contrari' => 0, 'astenuti' => 0,
            ])
            ->assertSessionHasErrors('punto_indice');
        $this->assertSame(2, VotazioneRiunione::query()->where('riunione_id', $riunione->id)->count());

        // Riunione ancora pianificata: le votazioni non si registrano
        $pianificata = Riunione::factory()->create(['associazione_id' => $this->associazione->id]);
        $this->comeAdmin()->post(route('riunioni.votazioni.store', $pianificata), [
            'punto_indice' => 0, 'esito' => 'approvato', 'favorevoli' => 3, 'contrari' => 0, 'astenuti' => 0,
        ])->assertRedirect(route('riunioni.show', $pianificata))->assertSessionHas('warning');
        $this->assertSame(0, VotazioneRiunione::query()->where('riunione_id', $pianificata->id)->count());

        // L'archivio decisioni elenca solo il punto approvato
        $decisioni = $this->comeAdmin()->get(route('decisioni.index'));
        $decisioni->assertOk()->assertSee('Quota sociale 2027')->assertDontSee('Acquisto nuovo gazebo');
        $titoli = collect($decisioni->viewData('decisioni'))->pluck('titolo')->all();
        $this->assertSame(['Quota sociale 2027'], $titoli);
    }

    // ==================================================================
    // 6. Bozza verbale generata dalla riunione
    // ==================================================================

    public function test_genera_verbale_da_riunione_numera_per_anno_popola_presenti_odg_delibere_e_non_si_duplica(): void
    {
        $this->creaVerbale(['titolo' => 'Assemblea di gennaio', 'data_riunione' => '2026-01-20', 'numero' => 1]);
        // Un verbale 2025 con numero alto non influenza la numerazione 2026
        $this->creaVerbale(['titolo' => 'Direttivo dicembre', 'data_riunione' => '2025-12-10', 'numero' => 7]);
        // Verbale 2026 di un altro tenant: numerazione indipendente
        $this->creaVerbale(['titolo' => 'Verbale altrui', 'data_riunione' => '2026-03-01', 'numero' => 5], $this->altraAssociazione);

        $riunione = Riunione::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Direttivo di aprile 2026',
            'data_ora' => '2026-04-20 21:00:00',
            'stato' => Riunione::STATO_SVOLTA,
            'odg' => [
                ['punto' => 'Festa di primavera', 'descrizione' => 'Organizzazione e budget'],
                ['punto' => 'Varie ed eventuali', 'descrizione' => null],
            ],
        ]);

        $presidente = $this->creaSocio(['nome' => 'Piero', 'cognome' => 'Bassi']);
        $assente = $this->creaSocio(['nome' => 'Ugo', 'cognome' => 'Invitato']);

        RiunionePartecipante::factory()->create([
            'riunione_id' => $riunione->id, 'socio_id' => $presidente->id,
            'carica_ruolo' => 'Presidente', 'stato' => RiunionePartecipante::STATO_CONFERMATO,
        ]);
        RiunionePartecipante::factory()->create([
            'riunione_id' => $riunione->id, 'socio_id' => null, 'utente_id' => $this->admin->id,
            'carica_ruolo' => null, 'stato' => RiunionePartecipante::STATO_CONFERMATO,
        ]);
        RiunionePartecipante::factory()->create([
            'riunione_id' => $riunione->id, 'socio_id' => $assente->id,
            'carica_ruolo' => 'Consigliere', 'stato' => RiunionePartecipante::STATO_INVITATO,
        ]);
        VotazioneRiunione::factory()->create([
            'riunione_id' => $riunione->id, 'punto_indice' => 0, 'esito' => 'approvato',
            'favorevoli' => 5, 'contrari' => 1, 'astenuti' => 0,
        ]);

        $risposta = $this->comeAdmin()->post(route('riunioni.genera-verbale', $riunione));

        $verbale = Verbale::query()->where('titolo', 'Direttivo di aprile 2026')->firstOrFail();
        $risposta->assertRedirect(route('verbali.index', ['edit' => $verbale->id]))->assertSessionHas('success');

        $this->assertSame($this->associazione->id, $verbale->associazione_id);
        $this->assertSame(2026, $verbale->anno);
        $this->assertSame(2, $verbale->numero);
        $this->assertSame('2/2026', $verbale->numero_completo);
        $this->assertSame('2026-04-20', $verbale->data_riunione->toDateString());
        $this->assertSame('Consiglio Direttivo', $verbale->tipo_riunione);
        $this->assertFalse((bool) $verbale->approvato);

        $this->assertStringContainsString('Piero Bassi (Presidente)', $verbale->presenti);
        $this->assertStringContainsString('Paola Segretaria', $verbale->presenti);
        $this->assertStringNotContainsString('Invitato', $verbale->presenti);
        $this->assertSame("1. Festa di primavera — Organizzazione e budget\n2. Varie ed eventuali", $verbale->ordine_del_giorno);
        $this->assertSame('Punto 1 — Festa di primavera: Approvato (favorevoli 5, contrari 1, astenuti 0).', $verbale->deliberazioni);

        $this->assertSame($verbale->id, $riunione->fresh()->verbale_id);

        // Seconda generazione: nessun duplicato
        $this->comeAdmin()->post(route('riunioni.genera-verbale', $riunione))
            ->assertRedirect(route('riunioni.show', $riunione))
            ->assertSessionHas('warning');
        $this->assertSame(3, Verbale::query()->count());

        // La bozza è modificabile dall'elenco verbali
        $this->comeAdmin()->get(route('verbali.index', ['edit' => $verbale->id]))
            ->assertOk()
            ->assertSee('Direttivo di aprile 2026');

        // Da una riunione pianificata non si genera nulla
        $pianificata = Riunione::factory()->create(['associazione_id' => $this->associazione->id]);
        $this->comeAdmin()->post(route('riunioni.genera-verbale', $pianificata))->assertSessionHas('warning');
        $this->assertSame(3, Verbale::query()->count());
    }

    // ==================================================================
    // 7. Modifica di un verbale approvato e cambio anno
    // ==================================================================

    public function test_modifica_verbale_approvato_azzera_l_approvazione_e_cambio_anno_rinumera(): void
    {
        $verbale = $this->creaVerbale([
            'titolo' => 'Direttivo di novembre',
            'data_riunione' => '2025-11-20',
            'numero' => 3,
            'presenti' => 'Tutto il direttivo',
            'approvato' => true,
            'approvato_da' => $this->admin->id,
            'data_approvazione' => '2025-12-01',
        ]);
        // Verbali 2026: il n. 1 attivo e il n. 2 finito nel cestino
        $this->creaVerbale(['titolo' => 'Assemblea 2026', 'data_riunione' => '2026-01-10', 'numero' => 1]);
        $nelCestino = $this->creaVerbale(['titolo' => 'Bozza scartata', 'data_riunione' => '2026-02-10', 'numero' => 2]);
        $this->comeAdmin()->delete(route('verbali.destroy', $nelCestino))->assertRedirect(route('verbali.index'));
        $this->assertSoftDeleted('verbali', ['id' => $nelCestino->id]);

        // 1) Modifica nello stesso anno: l'approvazione viene azzerata, il numero resta
        $this->comeAdmin()->put(route('verbali.update', $verbale), $this->datiVerbale($verbale, [
            'titolo' => 'Direttivo di novembre (rettificato)',
        ]))->assertRedirect(route('verbali.index'))->assertSessionHas('success');

        $verbale->refresh();
        $this->assertSame('Direttivo di novembre (rettificato)', $verbale->titolo);
        $this->assertFalse((bool) $verbale->approvato);
        $this->assertNull($verbale->approvato_da);
        $this->assertNull($verbale->data_approvazione);
        $this->assertSame([2025, 3], [$verbale->anno, $verbale->numero]);

        // 2) Riapprovazione
        $this->comeAdmin()->post(route('verbali.approva', $verbale))->assertSessionHas('success');
        $verbale->refresh();
        $this->assertTrue((bool) $verbale->approvato);
        $this->assertSame($this->admin->id, $verbale->approvato_da);
        $this->assertSame('2026-05-15', $verbale->data_approvazione->toDateString());

        // 3) La data viene corretta al 2026: il verbale passa alla numerazione 2026.
        //    Il n. 2/2026 è occupato dal verbale nel cestino (unique a DB su
        //    associazione+anno+numero): il numero corretto è 3/2026.
        $risposta = $this->comeAdmin()->put(route('verbali.update', $verbale), $this->datiVerbale($verbale, [
            'titolo' => 'Direttivo di gennaio 2026',
            'data_riunione' => '2026-01-25',
        ]));
        $risposta->assertRedirect(route('verbali.index'));

        $verbale->refresh();
        $this->assertSame('Direttivo di gennaio 2026', $verbale->titolo);
        $this->assertSame(2026, $verbale->anno);
        $this->assertSame(3, $verbale->numero);
        $this->assertFalse((bool) $verbale->approvato);
        $this->assertNull($verbale->approvato_da);
        $this->assertNull($verbale->data_approvazione);

        // 4) Nuova approvazione
        $this->comeAdmin()->post(route('verbali.approva', $verbale))->assertSessionHas('success');
        $verbale->refresh();
        $this->assertTrue((bool) $verbale->approvato);
        $this->assertSame($this->admin->id, $verbale->approvato_da);
        $this->assertNotNull($verbale->data_approvazione);
    }

    // ==================================================================
    // 8. Firmatari del verbale
    // ==================================================================

    public function test_firmatari_verbale_socio_utente_o_nominativo_libero_con_firma_una_sola_volta_e_isolamento(): void
    {
        $verbale = $this->creaVerbale(['titolo' => 'Assemblea straordinaria', 'tipo_riunione' => 'Assemblea Straordinaria']);
        $altroVerbale = $this->creaVerbale(['titolo' => 'Commissione eventi', 'data_riunione' => '2026-03-03', 'numero' => 2, 'tipo_riunione' => 'Commissione']);

        $presidente = $this->creaSocio(['nome' => 'Giorgio', 'cognome' => 'Mancini']);
        $socioAltrui = $this->creaSocio([], $this->altraAssociazione);
        $segretario = $this->creaAdminDi($this->associazione, ['nome' => 'Luca', 'cognome' => 'Verbalizzante']);
        $utenteAltrui = $this->creaAdminDi($this->altraAssociazione);

        $this->comeAdmin()->post(route('verbali.firmatari.store', $verbale), ['socio_id' => $presidente->id, 'ruolo' => 'Presidente'])
            ->assertRedirect(route('verbali.index'));
        $this->comeAdmin()->post(route('verbali.firmatari.store', $verbale), ['utente_id' => $segretario->id, 'ruolo' => 'Segretario'])
            ->assertRedirect(route('verbali.index'));
        $this->comeAdmin()->post(route('verbali.firmatari.store', $verbale), ['nome' => 'Avv. Sara Conti', 'ruolo' => 'Scrutatore'])
            ->assertRedirect(route('verbali.index'));

        // Nessuno dei tre campi
        $this->comeAdmin()->post(route('verbali.firmatari.store', $verbale), ['ruolo' => 'Fantasma'])
            ->assertSessionHasErrors('socio_id');
        // Socio e utente di un altro tenant
        $this->comeAdmin()->post(route('verbali.firmatari.store', $verbale), ['socio_id' => $socioAltrui->id])
            ->assertSessionHasErrors('socio_id');
        $this->comeAdmin()->post(route('verbali.firmatari.store', $verbale), ['utente_id' => $utenteAltrui->id])
            ->assertSessionHasErrors('utente_id');

        $firmatari = VerbaleFirmatario::query()->where('verbale_id', $verbale->id)->get();
        $this->assertCount(3, $firmatari);
        $this->assertTrue($firmatari->every(fn (VerbaleFirmatario $f) => $f->associazione_id === $this->associazione->id && $f->firmato_at === null));
        $this->assertSame('Presidente', $firmatari->firstWhere('socio_id', $presidente->id)->ruolo);
        $this->assertSame('Segretario', $firmatari->firstWhere('utente_id', $segretario->id)->ruolo);
        $this->assertSame('Scrutatore', $firmatari->firstWhere('nome', 'Avv. Sara Conti')->ruolo);

        // Firma del presidente
        $firmaPresidente = $firmatari->firstWhere('socio_id', $presidente->id);
        $this->comeAdmin()->post(route('verbali.firmatari.firma', [$verbale, $firmaPresidente]))
            ->assertRedirect(route('verbali.index'))
            ->assertSessionHas('success');
        $firmatoAt = $firmaPresidente->fresh()->firmato_at;
        $this->assertNotNull($firmatoAt);
        $this->assertSame('2026-05-15 10:00:00', $firmatoAt->format('Y-m-d H:i:s'));

        // Una seconda firma il giorno dopo non sovrascrive la prima
        Carbon::setTestNow('2026-05-16 09:00:00');
        $this->comeAdmin()->post(route('verbali.firmatari.firma', [$verbale, $firmaPresidente]))
            ->assertSessionHas('success');
        $this->assertSame('2026-05-15 10:00:00', $firmaPresidente->fresh()->firmato_at->format('Y-m-d H:i:s'));

        // Firmatario di un altro verbale usato con l'URL di questo: 404
        $firmatarioAltroVerbale = VerbaleFirmatario::query()->create([
            'associazione_id' => $this->associazione->id,
            'verbale_id' => $altroVerbale->id,
            'nome' => 'Mario Commissario',
        ]);
        $this->comeAdmin()->post(route('verbali.firmatari.firma', [$verbale, $firmatarioAltroVerbale]))->assertNotFound();
        $this->comeAdmin()->delete(route('verbali.firmatari.destroy', [$verbale, $firmatarioAltroVerbale]))->assertNotFound();
        $this->assertNull($firmatarioAltroVerbale->fresh()->firmato_at);

        // Il PDF riporta i firmatari
        $this->comeAdmin()->get(route('verbali.pdf', $verbale))->assertOk();

        // Rimozione di un firmatario
        $libero = $firmatari->firstWhere('nome', 'Avv. Sara Conti');
        $this->comeAdmin()->delete(route('verbali.firmatari.destroy', [$verbale, $libero]))->assertRedirect(route('verbali.index'));
        $this->assertDatabaseMissing('verbale_firmatari', ['id' => $libero->id]);
        $this->assertSame(2, VerbaleFirmatario::query()->where('verbale_id', $verbale->id)->count());
    }

    // ==================================================================
    // 9. Documento in Trasparenza
    // ==================================================================

    public function test_documento_caricato_pubblicato_in_trasparenza_con_categoria_e_rimosso_torna_privato(): void
    {
        Storage::fake('local');

        $this->comeAdmin()->post(route('documenti.store'), [
            'document_file' => UploadedFile::fake()->create('bilancio-consuntivo-2025.pdf', 120, 'application/pdf'),
            'descrizione' => 'Bilancio consuntivo approvato dall\'assemblea',
            'categoria' => 'Bilanci',
        ])->assertRedirect(route('documenti.index'))->assertSessionHas('success');

        $documento = Documento::query()->where('nome_file', 'bilancio-consuntivo-2025.pdf')->firstOrFail();
        $this->assertSame($this->associazione->id, $documento->associazione_id);
        $this->assertSame(120, (int) $documento->dimensione_kb);
        $this->assertSame($this->admin->id, $documento->caricato_da);
        $this->assertSame('Bilanci', $documento->categoria);
        $this->assertSame('Bilancio consuntivo approvato dall\'assemblea', $documento->descrizione);
        $this->assertNotSame('', (string) $documento->percorso_file);
        $this->assertNotNull($documento->getFirstMedia(Documento::COLLEZIONE_FILE));
        $this->assertFalse((bool) $documento->pubblico);

        // Pubblicazione senza categoria pubblica: rifiutata
        $this->comeAdmin()
            ->from(route('documenti.index'))
            ->post(route('documenti.trasparenza', $documento), [])
            ->assertSessionHasErrors('categoria_pubblica');
        $this->comeAdmin()
            ->post(route('documenti.trasparenza', $documento), ['categoria_pubblica' => 'segreti'])
            ->assertSessionHasErrors('categoria_pubblica');
        $this->assertFalse((bool) $documento->fresh()->pubblico);

        $this->comeAdmin()
            ->from(route('documenti.index'))
            ->post(route('documenti.trasparenza', $documento), ['categoria_pubblica' => 'bilancio'])
            ->assertRedirect(route('documenti.index'))
            ->assertSessionHas('success');

        $documento->refresh();
        $this->assertTrue((bool) $documento->pubblico);
        $this->assertSame('bilancio', $documento->categoria_pubblica);

        // Il visitatore lo vede e lo scarica
        $this->comeOspite();
        $this->get($this->urlTenant('trasparenza'))
            ->assertOk()
            ->assertSee('bilancio-consuntivo-2025.pdf');
        $this->get($this->urlTenant('trasparenza/'.$documento->id.'/download'))
            ->assertOk()
            ->assertDownload('bilancio-consuntivo-2025.pdf');
        // Lo slug di un altro tenant non lo espone
        $this->get('/a/'.$this->altraAssociazione->slug.'/trasparenza/'.$documento->id.'/download')->assertNotFound();

        // L'admin lo rimuove dalla Trasparenza (toggle)
        $this->comeAdmin()
            ->from(route('documenti.index'))
            ->post(route('documenti.trasparenza', $documento))
            ->assertSessionHas('success');

        $documento->refresh();
        $this->assertFalse((bool) $documento->pubblico);
        $this->assertNull($documento->categoria_pubblica);

        $this->comeOspite();
        $this->get($this->urlTenant('trasparenza'))->assertOk()->assertDontSee('bilancio-consuntivo-2025.pdf');
        $this->get($this->urlTenant('trasparenza/'.$documento->id.'/download'))->assertNotFound();

        // Resta scaricabile dall'area admin
        $this->comeAdmin()->get(route('documenti.download', $documento))->assertOk();
    }

    // ==================================================================
    // 10. Link temporaneo firmato
    // ==================================================================

    public function test_link_temporaneo_documento_funziona_senza_login_e_scade(): void
    {
        Storage::fake('local');

        $this->comeAdmin()->post(route('documenti.store'), [
            'document_file' => UploadedFile::fake()->create('statuto-2026.pdf', 30, 'application/pdf'),
            'categoria' => 'Statuto',
        ])->assertRedirect(route('documenti.index'));
        $documento = Documento::query()->where('nome_file', 'statuto-2026.pdf')->firstOrFail();

        $risposta = $this->comeAdmin()
            ->from(route('documenti.index'))
            ->post(route('documenti.link-temporaneo', $documento));
        $risposta->assertRedirect(route('documenti.index'))
            ->assertSessionHas('link_temporaneo_nome', 'statuto-2026.pdf');
        $url = (string) session('link_temporaneo');
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('expires=', $url);

        // Il destinatario del link scarica senza login
        $this->comeOspite();
        $this->get($url)->assertOk()->assertDownload('statuto-2026.pdf');

        // Un URL manomesso non funziona
        $this->get(str_replace('signature=', 'signature=00', $url))->assertForbidden();

        // Cestino e ripristino non perdono il file
        $this->comeAdmin()->delete(route('documenti.destroy', $documento))->assertRedirect(route('documenti.index'));
        $this->assertSoftDeleted('documenti', ['id' => $documento->id]);

        // Nel cestino il link (ancora valido) non serve più il documento
        $this->comeOspite();
        $this->get($url)->assertNotFound();

        $this->comeAdmin()->post(route('documenti.ripristina', $documento->id))->assertRedirect(route('documenti.index'));
        $documento = Documento::query()->findOrFail($documento->id);
        $media = $documento->getFirstMedia(Documento::COLLEZIONE_FILE);
        $this->assertNotNull($media);
        $this->assertFileExists($media->getPath());
        $this->comeAdmin()->get(route('documenti.download', $documento))->assertOk()->assertDownload('statuto-2026.pdf');

        // Dopo 61 minuti il link è scaduto
        Carbon::setTestNow(now()->addMinutes(61));
        $this->comeOspite();
        $this->get($url)->assertForbidden();
    }

    // ==================================================================
    // 11. Documento del fascicolo socio con scadenza
    // ==================================================================

    public function test_documento_socio_con_scadenza_compare_nello_scadenzario_e_nel_fascicolo_e_il_cestino_lo_nasconde(): void
    {
        Storage::fake('local');

        $socio = $this->creaSocio(['nome' => 'Marta', 'cognome' => 'Atleta']);
        $socioAltrui = $this->creaSocio([], $this->altraAssociazione);
        $scadenza = now()->addDays(30)->toDateString();

        $this->comeAdmin()->post(route('documenti-socio.store'), [
            'socio_id' => $socio->id,
            'document_file' => UploadedFile::fake()->create('certificato-medico.pdf', 50, 'application/pdf'),
            'descrizione' => 'Certificato medico agonistico',
            'data_scadenza' => $scadenza,
        ])->assertRedirect(route('documenti-socio.index', ['socio_id' => $socio->id]))->assertSessionHas('success');

        // Socio di un altro tenant: rifiutato
        $this->comeAdmin()->post(route('documenti-socio.store'), [
            'socio_id' => $socioAltrui->id,
            'document_file' => UploadedFile::fake()->create('intruso.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('socio_id');

        $documento = DocumentoSocio::query()->where('nome_file', 'certificato-medico.pdf')->firstOrFail();
        $this->assertSame($socio->id, $documento->socio_id);
        $this->assertSame($this->associazione->id, $documento->associazione_id);
        $this->assertSame($this->admin->id, $documento->caricato_da);
        $this->assertSame($scadenza, $documento->data_scadenza->toDateString());
        $this->assertSame('Certificato medico agonistico', $documento->descrizione);
        $this->assertNotSame('', (string) $documento->percorso_file);
        $this->assertSame(1, DocumentoSocio::query()->count());

        // Scadenzario e fascicolo del socio
        $voce = $this->vociScadenzario()->firstWhere('tipo', 'documento');
        $this->assertNotNull($voce);
        $this->assertStringContainsString('Atleta', $voce['titolo']);
        $this->assertSame('certificato-medico.pdf', $voce['dettaglio']);
        $this->assertSame($scadenza, $voce['data']->toDateString());

        $this->comeAdmin()->get(route('soci.show', $socio))->assertOk()->assertSee('certificato-medico.pdf');
        $this->comeAdmin()->get(route('documenti-socio.index', ['socio_id' => $socio->id]))->assertOk()->assertSee('certificato-medico.pdf');

        // Nel cestino: sparisce da scadenzario e fascicolo
        $this->comeAdmin()
            ->from(route('soci.show', $socio))
            ->delete(route('documenti-socio.destroy', $documento))
            ->assertRedirect(route('soci.show', $socio));
        $this->assertSoftDeleted('documenti_socio', ['id' => $documento->id]);
        $this->assertNull($this->vociScadenzario()->firstWhere('tipo', 'documento'));
        $this->comeAdmin()->get(route('soci.show', $socio))->assertOk()->assertDontSee('certificato-medico.pdf');

        // Ripristino
        $this->comeAdmin()
            ->from(route('soci.show', $socio))
            ->post(route('documenti-socio.ripristina', $documento->id))
            ->assertRedirect(route('soci.show', $socio));
        $this->assertNotSoftDeleted('documenti_socio', ['id' => $documento->id]);
        $this->assertNotNull($this->vociScadenzario()->firstWhere('tipo', 'documento'));
    }

    // ==================================================================
    // 12. Scadenza completata e riattivata
    // ==================================================================

    public function test_scadenza_completata_e_poi_riattivata_in_modifica_azzera_completamento_e_promemoria(): void
    {
        $tesoriere = $this->creaAdminDi($this->associazione, ['nome' => 'Tino', 'cognome' => 'Tesoriere']);
        $utenteAltrui = $this->creaAdminDi($this->altraAssociazione);

        $this->comeAdmin()->post(route('scadenze.store'), $this->datiScadenza(['assegnato_a' => $tesoriere->id]))
            ->assertRedirect(route('scadenze.index'))
            ->assertSessionHas('success');

        $scadenza = Scadenza::query()->where('titolo', 'Rinnovo polizza RC')->firstOrFail();
        $this->assertSame($this->associazione->id, $scadenza->associazione_id);
        $this->assertSame($tesoriere->id, $scadenza->assegnato_a);
        $this->assertSame('Alta', $scadenza->priorita);
        $this->assertSame(7, $scadenza->promemoria_giorni);
        $this->assertSame(now()->addDays(10)->toDateString(), $scadenza->data_scadenza->toDateString());

        // Lo scheduler aveva già mandato il promemoria
        $scadenza->forceFill(['promemoria_inviato_at' => now()->subDay()])->save();

        // Completamento
        $this->comeAdmin()->post(route('scadenze.completa', $scadenza))->assertSessionHas('success');
        $scadenza->refresh();
        $this->assertSame(Scadenza::STATO_COMPLETATA, $scadenza->stato);
        $this->assertSame($this->admin->id, $scadenza->completata_da);
        $this->assertNotNull($scadenza->completata_at);

        // Una completata non si ricompleta
        $this->comeAdmin()->post(route('scadenze.completa', $scadenza))->assertSessionHas('warning');

        // Riattivazione dalla modifica
        $this->comeAdmin()->put(route('scadenze.update', $scadenza), $this->datiScadenza([
            'assegnato_a' => $tesoriere->id,
            'data_scadenza' => now()->addDays(20)->toDateString(),
            'stato' => Scadenza::STATO_ATTIVA,
        ]))->assertRedirect(route('scadenze.index'));

        $scadenza->refresh();
        $this->assertSame(Scadenza::STATO_ATTIVA, $scadenza->stato);
        $this->assertNull($scadenza->completata_da);
        $this->assertNull($scadenza->completata_at);
        $this->assertNull($scadenza->promemoria_inviato_at);
        $this->assertSame(now()->addDays(20)->toDateString(), $scadenza->data_scadenza->toDateString());

        // Assegnatario di un altro tenant: rifiutato, assegnazione invariata
        $this->comeAdmin()
            ->from(route('scadenze.index'))
            ->put(route('scadenze.update', $scadenza), $this->datiScadenza(['assegnato_a' => $utenteAltrui->id]))
            ->assertSessionHasErrors('assegnato_a');
        $this->assertSame($tesoriere->id, $scadenza->fresh()->assegnato_a);

        // Assegnatario svuotato dal form → NULL
        $this->comeAdmin()->put(route('scadenze.update', $scadenza), $this->datiScadenza([
            'data_scadenza' => now()->addDays(20)->toDateString(),
        ]))->assertSessionHasNoErrors();
        $this->assertNull($scadenza->fresh()->assegnato_a);

        // Lo scadenzario unificato la mostra di nuovo
        $this->assertNotNull($this->vociScadenzario()->firstWhere('titolo', 'Rinnovo polizza RC'));

        // Export CSV
        $csv = $this->comeAdmin()->get(route('scadenze.esporta-csv'));
        $csv->assertOk();
        $contenuto = $csv->streamedContent();
        $this->assertStringContainsString('Rinnovo polizza RC', $contenuto);
        $this->assertStringContainsString(now()->addDays(20)->format('d/m/Y'), $contenuto);
        $this->assertStringContainsString('Attiva', $contenuto);
    }

    // ==================================================================
    // 13. Adempimenti ricorrenti
    // ==================================================================

    public function test_adempimenti_seed_toggle_anno_e_stato_checklist_per_anno(): void
    {
        $this->comeAdmin()->post(route('adempimenti.seed'))
            ->assertRedirect(route('adempimenti.index'))
            ->assertSessionHas('success', 'Aggiunti '.count(Adempimento::SUGGERITI).' adempimenti suggeriti.');
        $this->assertSame(count(Adempimento::SUGGERITI), Adempimento::query()->count());
        $this->assertTrue(Adempimento::query()->get()->every(fn (Adempimento $a) => $a->associazione_id === $this->associazione->id && $a->attivo));

        // Seconda volta: nessun duplicato
        $this->comeAdmin()->post(route('adempimenti.seed'))
            ->assertSessionHas('success', 'Tutti gli adempimenti suggeriti sono già presenti.');
        $this->assertSame(count(Adempimento::SUGGERITI), Adempimento::query()->count());

        // L'altro tenant parte da zero
        $this->assertSame(0, Adempimento::allTenants()->where('associazione_id', $this->altraAssociazione->id)->count());

        $bilancio = Adempimento::query()->where('titolo', 'Approvazione bilancio annuale')->firstOrFail();

        // Spunta 2026
        $this->comeAdmin()
            ->from(route('scadenzario.index'))
            ->post(route('adempimenti.toggle-anno', $bilancio), ['anno' => 2026])
            ->assertRedirect(route('scadenzario.index'));
        $riga = AdempimentoAnno::query()->where('adempimento_id', $bilancio->id)->where('anno', 2026)->firstOrFail();
        $this->assertNotNull($riga->completato_at);
        $this->assertSame($this->admin->id, $riga->completato_da);

        // De-spunta
        $this->comeAdmin()->post(route('adempimenti.toggle-anno', $bilancio), ['anno' => 2026]);
        $riga->refresh();
        $this->assertNull($riga->completato_at);
        $this->assertNull($riga->completato_da);

        // Rispunta (stessa riga, nessun duplicato)
        $this->comeAdmin()->post(route('adempimenti.toggle-anno', $bilancio), ['anno' => 2026]);
        $riga->refresh();
        $this->assertNotNull($riga->completato_at);
        $this->assertSame($this->admin->id, $riga->completato_da);
        $this->assertSame(1, AdempimentoAnno::query()->where('adempimento_id', $bilancio->id)->count());

        // Anno non valido
        $this->comeAdmin()->post(route('adempimenti.toggle-anno', $bilancio), ['anno' => 1990])->assertSessionHasErrors('anno');

        // La checklist 2026 nello scadenzario mostra 1 completato su 4
        $this->comeAdmin()->get(route('scadenzario.index'))
            ->assertOk()
            ->assertSee('Adempimenti ricorrenti 2026')
            ->assertSee('1/'.count(Adempimento::SUGGERITI).' completati');
        // Il 2027 è ancora da fare
        $this->comeAdmin()->get(route('scadenzario.index', ['adempimenti_anno' => 2027]))
            ->assertOk()
            ->assertSee('0/'.count(Adempimento::SUGGERITI).' completati');

        // Disattivazione: checkbox assente nel form → attivo false
        $assicurazione = Adempimento::query()->where('titolo', 'Rinnovo assicurazione volontari')->firstOrFail();
        $this->comeAdmin()->put(route('adempimenti.update', $assicurazione), [
            'titolo' => 'Rinnovo assicurazione volontari',
            'descrizione' => 'Polizza infortuni volontari',
            'mese_scadenza' => 2,
        ])->assertRedirect(route('adempimenti.index'));
        $assicurazione->refresh();
        $this->assertFalse((bool) $assicurazione->attivo);
        $this->assertSame(2, (int) $assicurazione->mese_scadenza);
        $this->assertSame('Polizza infortuni volontari', $assicurazione->descrizione);

        $this->comeAdmin()->get(route('scadenzario.index'))
            ->assertOk()
            ->assertDontSee('Rinnovo assicurazione volontari')
            ->assertSee('1/'.(count(Adempimento::SUGGERITI) - 1).' completati');

        // Riattivazione con la checkbox spuntata
        $this->comeAdmin()->put(route('adempimenti.update', $assicurazione), [
            'titolo' => 'Rinnovo assicurazione volontari',
            'mese_scadenza' => 2,
            'attivo' => '1',
        ]);
        $this->assertTrue((bool) $assicurazione->fresh()->attivo);
    }

    // ==================================================================
    // 14. Annunci: bozza, solo soci, pubblico
    // ==================================================================

    public function test_annuncio_senza_data_pubblicazione_non_compare_in_bacheca_ne_novita_e_con_date_e_solo_soci_compare_solo_ai_soci(): void
    {
        $this->comeAdmin()->post(route('annunci.store'), $this->datiAnnuncio([
            'titolo' => 'Bozza gita autunnale',
            'pubblicato_dal' => '',
        ]))->assertRedirect(route('annunci.index'));

        $this->comeAdmin()->post(route('annunci.store'), $this->datiAnnuncio([
            'titolo' => 'Riservato: nuove chiavi della sede',
            'solo_soci' => '1',
        ]))->assertRedirect(route('annunci.index'));

        $this->comeAdmin()->post(route('annunci.store'), $this->datiAnnuncio([
            'titolo' => 'Festa di primavera aperta a tutti',
            'corpo_html' => '<p>Vi aspettiamo <strong>sabato</strong>!</p><script>alert("xss")</script>',
            'in_evidenza' => '1',
        ]))->assertRedirect(route('annunci.index'));

        // Annuncio pubblicato ma scaduto ieri
        $this->comeAdmin()->post(route('annunci.store'), $this->datiAnnuncio([
            'titolo' => 'Avviso scaduto',
            'pubblicato_dal' => now()->subWeek()->format('Y-m-d\TH:i'),
            'pubblicato_al' => now()->subDay()->format('Y-m-d\TH:i'),
        ]))->assertRedirect(route('annunci.index'));

        // Annuncio pubblico di un altro tenant
        Annuncio::factory()->create(['associazione_id' => $this->altraAssociazione->id, 'titolo' => 'Annuncio del vicino']);

        $bozza = Annuncio::query()->where('titolo', 'Bozza gita autunnale')->firstOrFail();
        $this->assertNull($bozza->pubblicato_dal);
        $this->assertFalse($bozza->isPubblicato());
        $this->assertSame($this->admin->id, $bozza->creato_da);

        $pubblico = Annuncio::query()->where('titolo', 'Festa di primavera aperta a tutti')->firstOrFail();
        $this->assertStringNotContainsString('<script', $pubblico->corpo_html);
        $this->assertStringContainsString('<strong>sabato</strong>', $pubblico->corpo_html);
        $this->assertTrue($pubblico->in_evidenza);
        $this->assertTrue(Annuncio::query()->where('titolo', 'Riservato: nuove chiavi della sede')->firstOrFail()->solo_soci);

        // Mini-sito pubblico: solo l'annuncio pubblico e pubblicato
        $this->comeOspite();
        $this->get($this->urlTenant('novita'))
            ->assertOk()
            ->assertSee('Festa di primavera aperta a tutti')
            ->assertDontSee('Riservato: nuove chiavi della sede')
            ->assertDontSee('Bozza gita autunnale')
            ->assertDontSee('Avviso scaduto')
            ->assertDontSee('Annuncio del vicino')
            ->assertDontSee('alert("xss")', false);

        // Bacheca dell'area soci: pubblico + riservato, mai la bozza
        $socio = $this->creaSocio(['password_hash' => 'password']);
        $bacheca = $this->actingAs($socio, 'socio')->get(route('area-soci.bacheca'));
        $bacheca->assertOk()
            ->assertSee('Festa di primavera aperta a tutti')
            ->assertSee('Riservato: nuove chiavi della sede')
            ->assertDontSee('Bozza gita autunnale')
            ->assertDontSee('Avviso scaduto')
            ->assertDontSee('Annuncio del vicino');
        $this->assertSame(2, $bacheca->viewData('annunci')->total());

        // Quando l'admin dà una data alla bozza, compare
        $this->comeAdmin()->put(route('annunci.update', $bozza), $this->datiAnnuncio([
            'titolo' => 'Bozza gita autunnale',
        ]))->assertRedirect(route('annunci.index'));
        $this->assertTrue($bozza->fresh()->isPubblicato());

        $this->comeOspite();
        $this->get($this->urlTenant('novita'))->assertOk()->assertSee('Bozza gita autunnale');
    }

    // ==================================================================
    // 15. Convocazione assemblea come annuncio
    // ==================================================================

    public function test_convocazione_con_dettagli_assemblea_e_cambio_tipo_a_comunicazione_azzera_i_campi_assemblea(): void
    {
        $assemblea = Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Assemblea ordinaria',
            'data_evento' => now()->addWeeks(3),
        ]);
        $eventoAltrui = Evento::factory()->create(['associazione_id' => $this->altraAssociazione->id]);

        $this->comeAdmin()->post(route('annunci.store'), $this->datiAnnuncio([
            'titolo' => 'Convocazione assemblea ordinaria',
            'tipo' => Annuncio::TIPO_CONVOCAZIONE,
            'data_assemblea' => '2026-06-06T18:30',
            'luogo_assemblea' => 'Teatro parrocchiale',
            'ordine_del_giorno' => "1. Bilancio\n2. Rinnovo cariche",
            'evento_id' => $assemblea->id,
        ]))->assertRedirect(route('annunci.index'))->assertSessionHasNoErrors();

        $annuncio = Annuncio::query()->where('titolo', 'Convocazione assemblea ordinaria')->firstOrFail();
        $this->assertSame(Annuncio::TIPO_CONVOCAZIONE, $annuncio->tipo);
        $this->assertSame('2026-06-06 18:30', $annuncio->data_assemblea->format('Y-m-d H:i'));
        $this->assertSame('Teatro parrocchiale', $annuncio->luogo_assemblea);
        $this->assertSame("1. Bilancio\n2. Rinnovo cariche", $annuncio->ordine_del_giorno);
        $this->assertSame($assemblea->id, $annuncio->evento_id);

        // Convocazione senza data assemblea: rifiutata
        $this->comeAdmin()->post(route('annunci.store'), $this->datiAnnuncio([
            'titolo' => 'Convocazione incompleta',
            'tipo' => Annuncio::TIPO_CONVOCAZIONE,
        ]))->assertSessionHasErrors('data_assemblea');

        // Evento di un altro tenant: rifiutato
        $this->comeAdmin()->post(route('annunci.store'), $this->datiAnnuncio([
            'titolo' => 'Convocazione con evento altrui',
            'tipo' => Annuncio::TIPO_CONVOCAZIONE,
            'data_assemblea' => '2026-06-06T18:30',
            'evento_id' => $eventoAltrui->id,
        ]))->assertSessionHasErrors('evento_id');
        $this->assertDatabaseMissing('annunci', ['titolo' => 'Convocazione con evento altrui']);

        // Riportata a comunicazione: i dettagli assemblea vengono azzerati
        $this->comeAdmin()->put(route('annunci.update', $annuncio), $this->datiAnnuncio([
            'titolo' => 'Comunicazione sull\'assemblea',
            'tipo' => Annuncio::TIPO_COMUNICAZIONE,
            'data_assemblea' => '2026-06-06T18:30',
            'luogo_assemblea' => 'Teatro parrocchiale',
            'ordine_del_giorno' => '1. Bilancio',
            'evento_id' => $assemblea->id,
        ]))->assertRedirect(route('annunci.index'));

        $annuncio->refresh();
        $this->assertSame(Annuncio::TIPO_COMUNICAZIONE, $annuncio->tipo);
        $this->assertSame('Comunicazione sull\'assemblea', $annuncio->titolo);
        $this->assertNull($annuncio->data_assemblea);
        $this->assertNull($annuncio->luogo_assemblea);
        $this->assertNull($annuncio->ordine_del_giorno);
        $this->assertNull($annuncio->evento_id);

        // Una comunicazione non si invia come convocazione
        $this->creaSocio(['email' => 'socio@example.test']);
        $this->comeAdmin()->post(route('annunci.invia-convocazione', $annuncio))
            ->assertRedirect(route('annunci.index'))
            ->assertSessionHas('error');
        $this->assertNull($annuncio->fresh()->convocazione_inviata_at);
        Notification::assertNothingSent();
    }

    // ==================================================================
    // 16. Newsletter: solo annunci pubblicati del tenant
    // ==================================================================

    public function test_newsletter_con_annunci_di_altro_tenant_o_non_pubblicati_viene_rifiutata_e_lo_storico_salva_ids_e_conteggio(): void
    {
        $this->configuraSmtp();

        $pubblicatoA = Annuncio::factory()->create(['associazione_id' => $this->associazione->id, 'titolo' => 'Gita al lago']);
        $bozzaA = Annuncio::factory()->create(['associazione_id' => $this->associazione->id, 'titolo' => 'Bozza riservata', 'pubblicato_dal' => null]);
        $pubblicatoB = Annuncio::factory()->create(['associazione_id' => $this->altraAssociazione->id, 'titolo' => 'Annuncio del vicino']);

        $anna = $this->creaSocio(['nome' => 'Anna', 'email' => 'anna@example.test']);
        $luca = $this->creaSocio(['nome' => 'Luca', 'email' => 'luca@example.test']);
        $this->creaSocio(['email' => 'optout@example.test', 'email_opt_out' => true]);
        $this->creaSocio(['email' => 'sospeso@example.test', 'stato' => Socio::STATO_SOSPESO]);
        $this->creaSocio(['email' => '']);
        $this->creaSocio(['email' => 'altrove@example.test'], $this->altraAssociazione);

        // Bozza propria + annuncio di un altro tenant: nulla di pubblicato
        $this->comeAdmin()
            ->from(route('newsletter.index'))
            ->post(route('newsletter.store'), [
                'titolo' => 'Newsletter di maggio',
                'annunci' => [$bozzaA->id, $pubblicatoB->id],
            ])
            ->assertRedirect(route('newsletter.index'))
            ->assertSessionHas('warning');
        $this->assertSame(0, NewsletterInviata::query()->count());
        $this->assertSame(0, EmailQueue::query()->count());

        // Anche l'anteprima rifiuta gli annunci altrui
        $this->comeAdmin()
            ->from(route('newsletter.index'))
            ->post(route('newsletter.anteprima'), ['titolo' => 'Prova', 'annunci' => [$pubblicatoB->id]])
            ->assertSessionHas('warning');

        // Invio con l'annuncio pubblicato (la bozza mescolata viene ignorata)
        $this->comeAdmin()
            ->post(route('newsletter.store'), [
                'titolo' => 'Newsletter di maggio',
                'annunci' => [$pubblicatoA->id, $bozzaA->id],
            ])
            ->assertRedirect(route('newsletter.index'))
            ->assertSessionHas('success');

        $inviata = NewsletterInviata::query()->firstOrFail();
        $this->assertSame($this->associazione->id, $inviata->associazione_id);
        $this->assertSame('Newsletter di maggio', $inviata->titolo);
        $this->assertSame([$pubblicatoA->id], $inviata->annunci_ids);
        $this->assertSame(2, (int) $inviata->inviata_a);
        $this->assertSame($this->admin->id, $inviata->inviata_da);
        $this->assertNotNull($inviata->inviata_at);

        $coda = EmailQueue::query()->get();
        $this->assertCount(2, $coda);
        $this->assertEqualsCanonicalizing(['anna@example.test', 'luca@example.test'], $coda->pluck('to_email')->all());
        $this->assertTrue($coda->every(fn (EmailQueue $e) => $e->template_codice === 'newsletter'
            && $e->subject === 'Newsletter di maggio'
            && $e->status === EmailQueue::STATUS_PENDING
            && str_contains($e->body_html, 'Gita al lago')
            && ! str_contains($e->body_html, 'Bozza riservata')));
        $this->assertSame(1, $coda->pluck('batch_id')->unique()->count());
        $this->assertSame($anna->id, $coda->firstWhere('to_email', 'anna@example.test')->socio_id);
        $this->assertSame($luca->id, $coda->firstWhere('to_email', 'luca@example.test')->socio_id);

        // Lo storico mostra l'invio
        $this->comeAdmin()->get(route('newsletter.index'))->assertOk()->assertSee('Newsletter di maggio');
    }

    // ==================================================================
    // 17. Sondaggio: account collegato e socio sono la stessa persona
    // ==================================================================

    public function test_sondaggio_opzioni_da_textarea_voto_account_collegato_e_socio_sono_la_stessa_persona(): void
    {
        $this->comeAdmin()->post(route('sondaggi.store'), [
            'domanda' => 'Spostiamo la cena sociale a giugno?',
            'opzioni_testo' => "  Sì  \n\n No \n",
            'aperto_dal' => now()->subHour()->format('Y-m-d\TH:i'),
            'aperto_al' => now()->addWeek()->format('Y-m-d\TH:i'),
            'solo_soci' => '0',
        ])->assertSessionHasNoErrors();

        $sondaggio = Sondaggio::query()->where('domanda', 'Spostiamo la cena sociale a giugno?')->firstOrFail();
        $this->assertSame(['Sì', 'No'], $sondaggio->opzioni);
        $this->assertSame($this->associazione->id, $sondaggio->associazione_id);

        // Una sola opzione: rifiutato
        $this->comeAdmin()->post(route('sondaggi.store'), [
            'domanda' => 'Domanda con una sola risposta?',
            'opzioni_testo' => "Unica\n\n",
        ])->assertSessionHasErrors('opzioni');

        $socio = $this->creaSocio(['nome' => 'Rita', 'cognome' => 'Doppia', 'password_hash' => 'password']);
        $accountCollegato = Account::factory()->create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $socio->id,
        ]);

        // Rita vota dal mini-sito con l'account collegato
        $this->actingAs($accountCollegato, 'account')
            ->post($this->urlTenant('account/sondaggi/'.$sondaggio->id.'/vota'), ['opzione' => 0])
            ->assertRedirect(route('pubblico.account.sondaggi', $this->associazione->slug))
            ->assertSessionHas('success');

        $risposte = RispostaSondaggio::query()->where('sondaggio_id', $sondaggio->id)->get();
        $this->assertCount(1, $risposte);
        $this->assertSame((new Socio)->getMorphClass(), $risposte[0]->votante_type);
        $this->assertSame($socio->id, $risposte[0]->votante_id);
        $this->assertSame(0, (int) $risposte[0]->opzione);

        // ...poi ci riprova dall'area soci: è la stessa persona
        $this->actingAs($socio, 'socio')
            ->post(route('area-soci.sondaggi.vota', $sondaggio), ['opzione' => 1])
            ->assertRedirect(route('area-soci.sondaggi'))
            ->assertSessionHas('warning', 'Hai già votato questo sondaggio.');
        $this->assertSame(1, RispostaSondaggio::query()->where('sondaggio_id', $sondaggio->id)->count());

        // Opzione inesistente
        $altroSocio = $this->creaSocio(['password_hash' => 'password']);
        $this->actingAs($altroSocio, 'socio')
            ->post(route('area-soci.sondaggi.vota', $sondaggio), ['opzione' => 7])
            ->assertSessionHas('danger');
        $this->assertSame(1, RispostaSondaggio::query()->where('sondaggio_id', $sondaggio->id)->count());

        // Sondaggio riservato ai soci: un account non collegato non lo vede e non può votarlo
        $riservato = Sondaggio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'domanda' => 'Domanda riservata ai soci?',
            'solo_soci' => true,
        ]);
        $simpatizzante = Account::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($simpatizzante, 'account')
            ->get($this->urlTenant('account/sondaggi'))
            ->assertOk()
            ->assertSee('Spostiamo la cena sociale a giugno?')
            ->assertDontSee('Domanda riservata ai soci?');
        $this->actingAs($simpatizzante, 'account')
            ->post($this->urlTenant('account/sondaggi/'.$riservato->id.'/vota'), ['opzione' => 0])
            ->assertNotFound();
        $this->assertSame(0, RispostaSondaggio::query()->where('sondaggio_id', $riservato->id)->count());

        // L'account collegato invece vota il riservato (come socio)
        $this->actingAs($accountCollegato, 'account')
            ->post($this->urlTenant('account/sondaggi/'.$riservato->id.'/vota'), ['opzione' => 1])
            ->assertSessionHas('success');
        $this->assertSame($socio->id, RispostaSondaggio::query()->where('sondaggio_id', $riservato->id)->firstOrFail()->votante_id);

        // Risultati per l'admin
        $this->assertSame([0 => 1, 1 => 0], $sondaggio->fresh()->risultati());
        $show = $this->comeAdmin()->get(route('sondaggi.show', $sondaggio));
        $show->assertOk();
        $this->assertSame([0 => 1, 1 => 0], $show->viewData('risultati'));
        $this->assertSame(1, $show->viewData('totaleVoti'));
    }

    // ==================================================================
    // 18. Modifica e cancellazione di un sondaggio con voti
    // ==================================================================

    public function test_modifica_sondaggio_con_voti_esistenti_mantiene_le_risposte_e_la_cancellazione_le_elimina_in_cascata(): void
    {
        $sondaggio = Sondaggio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'domanda' => 'Quale menù per la cena?',
            'opzioni' => ['Pesce', 'Carne', 'Vegetariano'],
        ]);
        $s1 = $this->creaSocio(['password_hash' => 'password']);
        $s2 = $this->creaSocio(['password_hash' => 'password']);

        $this->actingAs($s1, 'socio')->post(route('area-soci.sondaggi.vota', $sondaggio), ['opzione' => 0])->assertSessionHas('success');
        $this->actingAs($s2, 'socio')->post(route('area-soci.sondaggi.vota', $sondaggio), ['opzione' => 2])->assertSessionHas('success');
        $this->assertSame(2, RispostaSondaggio::query()->where('sondaggio_id', $sondaggio->id)->count());

        // Un sondaggio di un altro tenant non è modificabile
        $altrui = Sondaggio::factory()->create(['associazione_id' => $this->altraAssociazione->id]);
        $this->comeAdmin()->put(route('sondaggi.update', $altrui), [
            'domanda' => 'Hackerato?', 'opzioni_testo' => "A\nB",
        ])->assertNotFound();

        // Modifica della domanda e della chiusura (opzioni invariate)
        $chiusura = now()->addDays(3)->format('Y-m-d\TH:i');
        $this->comeAdmin()->put(route('sondaggi.update', $sondaggio), [
            'domanda' => 'Quale menù scegliamo per la cena sociale?',
            'opzioni_testo' => "Pesce\nCarne\nVegetariano",
            'aperto_dal' => now()->subDay()->format('Y-m-d\TH:i'),
            'aperto_al' => $chiusura,
            'solo_soci' => '1',
        ])->assertRedirect(route('sondaggi.show', $sondaggio));

        $sondaggio->refresh();
        $this->assertSame('Quale menù scegliamo per la cena sociale?', $sondaggio->domanda);
        $this->assertSame($chiusura, $sondaggio->aperto_al->format('Y-m-d\TH:i'));
        $this->assertTrue($sondaggio->solo_soci);
        $this->assertSame(['Pesce', 'Carne', 'Vegetariano'], $sondaggio->opzioni);
        $this->assertSame(2, RispostaSondaggio::query()->where('sondaggio_id', $sondaggio->id)->count());
        $this->assertSame([0 => 1, 1 => 0, 2 => 1], $sondaggio->risultati());

        // Chiusura anticipata nel passato precedente all'apertura: rifiutata
        $this->comeAdmin()->put(route('sondaggi.update', $sondaggio), [
            'domanda' => 'Quale menù scegliamo per la cena sociale?',
            'opzioni_testo' => "Pesce\nCarne\nVegetariano",
            'aperto_dal' => now()->format('Y-m-d\TH:i'),
            'aperto_al' => now()->subDays(2)->format('Y-m-d\TH:i'),
        ])->assertSessionHasErrors('aperto_al');

        // Cancellazione: le risposte spariscono in cascata
        $this->comeAdmin()->delete(route('sondaggi.destroy', $sondaggio))->assertRedirect(route('sondaggi.index'));
        $this->assertDatabaseMissing('sondaggi', ['id' => $sondaggio->id]);
        $this->assertSame(0, DB::table('risposte_sondaggio')->where('sondaggio_id', $sondaggio->id)->count());
        $this->assertDatabaseHas('sondaggi', ['id' => $altrui->id]);
    }

    // ==================================================================
    // 19. Impostazioni SMTP
    // ==================================================================

    public function test_impostazioni_smtp_prima_volta_richiede_password_seed_template_e_secondo_salvataggio_senza_password_la_mantiene(): void
    {
        // Primo salvataggio senza password: rifiutato
        $this->comeAdmin()
            ->from(route('email-impostazioni.index'))
            ->post(route('email-impostazioni.store'), $this->datiSmtp(['smtp_pass' => '']))
            ->assertRedirect(route('email-impostazioni.index'))
            ->assertSessionHasErrors('smtp_pass');
        $this->assertSame(0, SmtpSetting::query()->count());
        $this->assertSame(0, EmailTemplate::query()->count());

        // Un altro tenant ha già il suo SMTP: non deve influenzare il nostro primo salvataggio
        SmtpSetting::create([
            'associazione_id' => $this->altraAssociazione->id,
            'smtp_host' => 'smtp.vicina.test', 'smtp_port' => 25, 'smtp_user' => '',
            'smtp_pass_encrypted' => 'altra', 'smtp_encryption' => 'none',
            'from_email' => 'noreply@vicina.test', 'from_name' => 'Vicina', 'max_per_hour' => 10,
        ]);
        $this->comeAdmin()
            ->post(route('email-impostazioni.store'), $this->datiSmtp(['smtp_pass' => '']))
            ->assertSessionHasErrors('smtp_pass');

        // Salvataggio completo con tutti i flag automatici
        $this->comeAdmin()->post(route('email-impostazioni.store'), $this->datiSmtp([
            'auto_benvenuto' => '1',
            'auto_scadenza_tessera' => '1',
            'auto_scadenza_quota' => '1',
            'auto_rinnovo_tessera' => '1',
            'auto_pagamento_quota' => '1',
        ]))->assertRedirect(route('email-impostazioni.index'))->assertSessionHas('success');

        $settings = SmtpSetting::query()->firstOrFail();
        $this->assertSame(1, SmtpSetting::query()->count());
        $this->assertSame($this->associazione->id, $settings->associazione_id);
        $this->assertSame('smtp.circolo-faro.test', $settings->smtp_host);
        $this->assertSame(587, $settings->smtp_port);
        $this->assertSame('posta@circolo-faro.test', $settings->smtp_user);
        $this->assertSame('Password-Smtp-1', $settings->smtp_pass_encrypted);
        $this->assertSame('tls', $settings->smtp_encryption);
        $this->assertSame('segreteria@circolo-faro.test', $settings->reply_to_email);
        $this->assertSame(50, $settings->max_per_hour);
        foreach (['auto_benvenuto', 'auto_scadenza_tessera', 'auto_scadenza_quota', 'auto_rinnovo_tessera', 'auto_pagamento_quota'] as $flag) {
            $this->assertTrue((bool) $settings->{$flag}, "Il flag {$flag} deve essere attivo.");
        }

        // La password non è in chiaro nel database
        $cifrata = (string) DB::table('smtp_settings')->where('id', $settings->id)->value('smtp_pass_encrypted');
        $this->assertNotSame('Password-Smtp-1', $cifrata);
        $this->assertStringNotContainsString('Password-Smtp-1', $cifrata);

        // Seed dei template di default
        $this->assertSame(8, EmailTemplate::query()->count());
        $this->assertEqualsCanonicalizing(DefaultEmailTemplates::ORDER, EmailTemplate::query()->pluck('codice')->all());

        // Secondo salvataggio senza password, host cambiato e due flag spenti
        $this->comeAdmin()->post(route('email-impostazioni.store'), $this->datiSmtp([
            'smtp_host' => 'mail.nuovo-provider.test',
            'smtp_pass' => '',
            'auto_benvenuto' => '1',
            'auto_pagamento_quota' => '1',
        ]))->assertSessionHasNoErrors()->assertSessionHas('success');

        $settings->refresh();
        $this->assertSame(1, SmtpSetting::query()->count());
        $this->assertSame('mail.nuovo-provider.test', $settings->smtp_host);
        $this->assertSame($cifrata, (string) DB::table('smtp_settings')->where('id', $settings->id)->value('smtp_pass_encrypted'));
        $this->assertSame('Password-Smtp-1', $settings->smtp_pass_encrypted);
        $this->assertTrue((bool) $settings->auto_benvenuto);
        $this->assertFalse((bool) $settings->auto_scadenza_tessera);
        $this->assertFalse((bool) $settings->auto_scadenza_quota);
        $this->assertFalse((bool) $settings->auto_rinnovo_tessera);
        $this->assertTrue((bool) $settings->auto_pagamento_quota);

        // Seed idempotente e SMTP dell'altro tenant intatto
        $this->assertSame(8, EmailTemplate::query()->count());
        $this->comeAdmin()->get(route('email-templates.index'))->assertOk();
        $this->assertSame(8, EmailTemplate::query()->count());
        $this->assertSame('smtp.vicina.test', SmtpSetting::allTenants()->where('associazione_id', $this->altraAssociazione->id)->value('smtp_host'));

        $this->assertTrue(app(EmailService::class)->isConfigured($this->associazione->fresh()));
        $this->comeAdmin()->get(route('email-impostazioni.index'))
            ->assertOk()
            ->assertSee('mail.nuovo-provider.test')
            ->assertDontSee('Password-Smtp-1');
    }

    // ==================================================================
    // 20. Template di benvenuto personalizzato
    // ==================================================================

    public function test_template_email_modificato_viene_usato_dall_email_di_benvenuto_e_disattivato_non_viene_inviato(): void
    {
        $this->configuraSmtp(['auto_benvenuto' => true]);
        $this->comeAdmin()->get(route('email-templates.index'))->assertOk();
        $this->assertSame(8, EmailTemplate::query()->count());

        // L'admin personalizza il template di benvenuto con i placeholder dell'editor
        $this->comeAdmin()->put(route('email-templates.update', 'benvenuto'), [
            'oggetto' => "Benvenuto {NOME} in {NOME_ASSOCIAZIONE}!\r\n",
            'corpo_html' => '<p>Ciao {NOME} {COGNOME}, il tuo numero socio è {NUMERO_SOCIO}.</p><script>alert(1)</script>',
            'corpo_json' => '{"blocchi":[{"tipo":"testo"}]}',
            'attivo' => '1',
        ])->assertRedirect(route('email-templates.index', ['t' => 'benvenuto']))->assertSessionHas('success');

        $template = EmailTemplate::query()->where('codice', 'benvenuto')->firstOrFail();
        $this->assertSame('Benvenuto {NOME} in {NOME_ASSOCIAZIONE}!', $template->oggetto);
        $this->assertStringNotContainsString('<script', $template->corpo_html);
        $this->assertSame('{"blocchi":[{"tipo":"testo"}]}', $template->corpo_json);
        $this->assertTrue((bool) $template->attivo);

        // Codice inesistente
        $this->comeAdmin()->put(route('email-templates.update', 'inventato'), [
            'oggetto' => 'x', 'corpo_html' => '<p>x</p>',
        ])->assertNotFound();

        // L'admin registra un nuovo socio: parte il benvenuto
        $this->comeAdmin()->post(route('soci.store'), $this->payloadSocio())->assertSessionHasNoErrors();
        $mario = Socio::query()->where('email', 'mario.rossi@example.com')->firstOrFail();

        $benvenuti = EmailQueue::query()->where('template_codice', 'benvenuto')->get();
        $this->assertCount(1, $benvenuti);
        $email = $benvenuti[0];
        $this->assertSame('mario.rossi@example.com', $email->to_email);
        $this->assertSame($mario->id, $email->socio_id);
        $this->assertSame(EmailQueue::STATUS_PENDING, $email->status);
        // I placeholder del template ({NOME}, {NOME_ASSOCIAZIONE}...) devono essere sostituiti
        $this->assertSame('Benvenuto Mario in Circolo Il Faro!', $email->subject);
        $this->assertStringContainsString('Ciao Mario Rossi, il tuo numero socio è '.$mario->numero_socio.'.', $email->body_html);
        $this->assertStringNotContainsString('{NOME}', $email->body_html);
        $this->assertStringNotContainsString('<script', $email->body_html);

        // Template disattivato (checkbox non spuntata): nessun benvenuto
        $this->comeAdmin()->put(route('email-templates.update', 'benvenuto'), [
            'oggetto' => 'Benvenuto {NOME} in {NOME_ASSOCIAZIONE}!',
            'corpo_html' => $template->corpo_html,
        ])->assertSessionHasNoErrors();
        $this->assertFalse((bool) $template->fresh()->attivo);

        $this->comeAdmin()->post(route('soci.store'), $this->payloadSocio([
            'nome' => 'Lucia', 'cognome' => 'Bianchi', 'email' => 'lucia.bianchi@example.com',
        ]))->assertSessionHasNoErrors();
        $this->assertTrue(Socio::query()->where('email', 'lucia.bianchi@example.com')->exists());
        $this->assertSame(1, EmailQueue::query()->where('template_codice', 'benvenuto')->count());
    }

    // ==================================================================
    // 21. Comunicazione massiva per tag e sede
    // ==================================================================

    public function test_comunicazione_massiva_per_tag_e_sede_seleziona_i_destinatari_giusti_e_rispetta_opt_out(): void
    {
        $this->configuraSmtp();

        $tag = Tag::create(['associazione_id' => $this->associazione->id, 'nome_tag' => 'Coro', 'colore' => '#3366ff']);
        $sede = Sede::create(['associazione_id' => $this->associazione->id, 'nome' => 'Sede Nord', 'citta' => 'Bergamo']);

        $corista = $this->creaSocio(['nome' => 'Gino', 'email' => 'gino@example.test']);
        $coristaOptOut = $this->creaSocio(['nome' => 'Ada', 'email' => 'ada@example.test', 'email_opt_out' => true]);
        $socioNord = $this->creaSocio(['nome' => 'Nora', 'email' => 'nora@example.test', 'sede_id' => $sede->id]);
        $this->creaSocio(['nome' => 'Altro', 'email' => 'altro@example.test']);
        $corista->tags()->attach($tag->id);
        $coristaOptOut->tags()->attach($tag->id);

        // Conteggi in tempo reale per il form
        $this->comeAdmin()->getJson(route('comunicazioni.count', ['recipient_type' => 'tag', 'recipient_filter_id' => $tag->id]))
            ->assertOk()->assertJson(['success' => true, 'count' => 1]);
        $this->comeAdmin()->getJson(route('comunicazioni.count', ['recipient_type' => 'section', 'recipient_filter_id' => $sede->id]))
            ->assertOk()->assertJson(['count' => 1]);
        $this->comeAdmin()->getJson(route('comunicazioni.count', ['recipient_type' => 'all']))
            ->assertOk()->assertJson(['count' => 3]);

        // Invio al tag
        $this->comeAdmin()->post(route('comunicazioni.store'), [
            'subject' => 'Prove del coro per {NOME}',
            'corpo_html' => '<p>Ciao {NOME}, prove giovedì.</p>',
            'recipient_type' => 'tag',
            'recipient_filter_id' => $tag->id,
        ])->assertRedirect(route('comunicazioni.index'))->assertSessionHas('success');

        $perTag = EmailQueue::query()->where('subject', 'like', 'Prove del coro%')->get();
        $this->assertCount(1, $perTag);
        $this->assertSame('gino@example.test', $perTag[0]->to_email);
        $this->assertSame('Prove del coro per Gino', $perTag[0]->subject);
        $this->assertSame($corista->id, $perTag[0]->socio_id);
        $this->assertNotEmpty($perTag[0]->batch_id);
        $this->assertSame('comunicazione', $perTag[0]->template_codice);
        $this->assertStringContainsString('Ciao Gino, prove giovedì.', $perTag[0]->body_html);

        // Invio alla sede
        $this->comeAdmin()->post(route('comunicazioni.store'), [
            'subject' => 'Chiusura Sede Nord',
            'corpo_html' => '<p>La sede resta chiusa lunedì.</p>',
            'recipient_type' => 'section',
            'recipient_filter_id' => $sede->id,
        ])->assertSessionHas('success');

        $perSede = EmailQueue::query()->where('subject', 'Chiusura Sede Nord')->get();
        $this->assertCount(1, $perSede);
        $this->assertSame($socioNord->id, $perSede[0]->socio_id);
        $this->assertNotSame($perTag[0]->batch_id, $perSede[0]->batch_id);

        $this->assertSame(0, EmailQueue::query()->where('to_email', 'ada@example.test')->count());
        $this->assertSame(2, EmailQueue::query()->count());

        // Filtro mancante per il tipo tag
        $this->comeAdmin()
            ->from(route('comunicazioni.index'))
            ->post(route('comunicazioni.store'), [
                'subject' => 'Senza filtro',
                'corpo_html' => '<p>x</p>',
                'recipient_type' => 'tag',
            ])
            ->assertRedirect(route('comunicazioni.index'))
            ->assertSessionHasErrors('recipient_filter_id');

        // Tag di un altro tenant: nessun destinatario
        $tagAltrui = Tag::create(['associazione_id' => $this->altraAssociazione->id, 'nome_tag' => 'Coro', 'colore' => '#000000']);
        $socioAltrui = $this->creaSocio(['email' => 'vicino@example.test'], $this->altraAssociazione);
        $socioAltrui->tags()->attach($tagAltrui->id);
        $this->comeAdmin()->post(route('comunicazioni.store'), [
            'subject' => 'Furto di destinatari',
            'corpo_html' => '<p>x</p>',
            'recipient_type' => 'tag',
            'recipient_filter_id' => $tagAltrui->id,
        ])->assertSessionHas('warning');
        $this->assertSame(2, EmailQueue::allTenants()->count());
    }

    // ==================================================================
    // 22. Email diretta dalla scheda socio
    // ==================================================================

    public function test_email_diretta_al_socio_e_tracciata_nello_storico_e_bloccata_per_opt_out_o_senza_email(): void
    {
        $smtp = $this->configuraSmtp();

        $socio = $this->creaSocio(['nome' => 'Elena', 'cognome' => 'Galli', 'email' => 'elena@example.test']);
        $optOut = $this->creaSocio(['email' => 'optout@example.test', 'email_opt_out' => true]);
        $senzaEmail = $this->creaSocio(['email' => '']);
        $altrui = $this->creaSocio(['email' => 'altrui@example.test'], $this->altraAssociazione);

        $this->comeAdmin()->post(route('soci.invia-email', $socio), [
            'oggetto' => 'Certificato medico',
            'corpo' => "Ciao Elena,\nportaci il certificato <b>entro venerdì</b>.",
        ])->assertRedirect(route('soci.show', $socio))->assertSessionHas('success');

        $email = EmailQueue::query()->where('socio_id', $socio->id)->firstOrFail();
        $this->assertSame('elena@example.test', $email->to_email);
        $this->assertSame('Elena Galli', $email->to_name);
        $this->assertSame('Certificato medico', $email->subject);
        $this->assertSame(3, (int) $email->priority);
        $this->assertSame('email_diretta', $email->template_codice);
        $this->assertSame(EmailQueue::STATUS_PENDING, $email->status);
        $this->assertStringContainsString('Ciao Elena,<br', $email->body_html);
        $this->assertStringContainsString('&lt;b&gt;entro venerdì&lt;/b&gt;', $email->body_html);

        $storico = StoricoAttivitaSocio::query()->where('socio_id', $socio->id)->where('tipo_attivita', 'email_diretta')->firstOrFail();
        $this->assertSame($this->admin->id, $storico->utente_id);
        $this->assertSame('Email inviata: Certificato medico', $storico->descrizione);
        $this->assertSame(['oggetto' => 'Certificato medico'], $storico->dettagli_json);

        // Socio con opt-out
        $this->comeAdmin()->post(route('soci.invia-email', $optOut), ['oggetto' => 'x', 'corpo' => 'y'])
            ->assertRedirect(route('soci.show', $optOut))
            ->assertSessionHas('warning');
        // Socio senza email
        $this->comeAdmin()->post(route('soci.invia-email', $senzaEmail), ['oggetto' => 'x', 'corpo' => 'y'])
            ->assertSessionHas('warning');
        // Socio di un altro tenant
        $this->comeAdmin()->post(route('soci.invia-email', $altrui), ['oggetto' => 'x', 'corpo' => 'y'])->assertNotFound();
        // Campi mancanti
        $this->comeAdmin()->post(route('soci.invia-email', $socio), ['oggetto' => ''])->assertSessionHasErrors(['oggetto', 'corpo']);

        $this->assertSame(1, EmailQueue::allTenants()->count());
        $this->assertSame(1, StoricoAttivitaSocio::query()->where('tipo_attivita', 'email_diretta')->count());

        // Senza SMTP configurato
        $smtp->delete();
        $this->comeAdmin()->post(route('soci.invia-email', $socio), ['oggetto' => 'Di nuovo', 'corpo' => 'Testo'])
            ->assertRedirect(route('soci.show', $socio))
            ->assertSessionHas('danger');
        $this->assertSame(1, EmailQueue::query()->count());
    }

    // ==================================================================
    // 23. Opt-out: area soci e link di disiscrizione
    // ==================================================================

    public function test_opt_out_tramite_token_area_soci_e_unsubscribe_double_opt_out_disattivano_e_le_campagne_lo_saltano(): void
    {
        $this->configuraSmtp();

        $tokenAnna = str_repeat('a1', 32);
        $tokenBruno = str_repeat('b2', 32);
        $anna = $this->creaSocio(['nome' => 'Anna', 'email' => 'anna@example.test', 'email_opt_out_token' => $tokenAnna]);
        $bruno = $this->creaSocio(['nome' => 'Bruno', 'email' => 'bruno@example.test', 'email_opt_out_token' => $tokenBruno]);
        $carla = $this->creaSocio(['nome' => 'Carla', 'email' => 'carla@example.test']);

        // 1) Anna dal link "opt-out" dell'area soci: GET = solo conferma
        $this->comeOspite();
        $this->get(route('area-soci.optout', $tokenAnna))->assertOk();
        $this->assertFalse((bool) $anna->fresh()->email_opt_out);

        $this->post(route('area-soci.optout.store', $tokenAnna))->assertOk();
        $this->assertTrue((bool) $anna->fresh()->email_opt_out);

        // Token sconosciuto (formato valido)
        $this->get(route('area-soci.optout', str_repeat('f0', 32)))->assertNotFound();

        // 2) Bruno dal link di disiscrizione delle email (double opt-out)
        $this->get(route('email.unsubscribe.show', [$bruno->id, $tokenBruno]))->assertOk();
        $this->assertFalse((bool) $bruno->fresh()->email_opt_out);

        // POST senza conferma esplicita: nulla cambia
        $this->post(route('email.unsubscribe.confirm', [$bruno->id, $tokenBruno]))->assertOk();
        $this->assertFalse((bool) $bruno->fresh()->email_opt_out);

        // Token di un altro socio: rifiutato
        $this->post(route('email.unsubscribe.confirm', [$bruno->id, $tokenAnna]), ['confirm_unsubscribe' => '1'])->assertNotFound();
        $this->assertFalse((bool) $bruno->fresh()->email_opt_out);

        $this->post(route('email.unsubscribe.confirm', [$bruno->id, $tokenBruno]), ['confirm_unsubscribe' => '1'])->assertOk();
        $this->assertTrue((bool) $bruno->fresh()->email_opt_out);

        // Ripetere non è un errore
        $this->get(route('email.unsubscribe.show', [$bruno->id, $tokenBruno]))->assertOk();

        // 3) La comunicazione a tutti salta entrambi
        $this->comeAdmin()->post(route('comunicazioni.store'), [
            'subject' => 'Assemblea di giugno',
            'corpo_html' => '<p>Ciao {NOME}, ti aspettiamo. <a href="{LINK_DISISCRIZIONE}">Disiscriviti</a></p>',
            'recipient_type' => 'all',
        ])->assertSessionHas('success');

        $coda = EmailQueue::query()->get();
        $this->assertSame(['carla@example.test'], $coda->pluck('to_email')->all());
        $this->assertSame($carla->id, $coda[0]->socio_id);

        // Il link di disiscrizione nella mail di Carla funziona davvero
        $carla->refresh();
        $this->assertNotEmpty($carla->email_opt_out_token);
        $this->assertStringContainsString(route('email.unsubscribe.show', [$carla->id, $carla->email_opt_out_token]), $coda[0]->body_html);
    }

    // ==================================================================
    // 24. Coda email con limite orario
    // ==================================================================

    public function test_coda_email_processa_con_limite_orario_registra_log_e_retry_riporta_in_pending(): void
    {
        $this->configuraSmtp(['max_per_hour' => 2]);
        $invii = [];
        $this->mockMailerOk($invii);

        $servizio = app(EmailService::class);
        foreach (['uno@example.test', 'due@example.test', 'tre@example.test'] as $i => $destinatario) {
            $servizio->queueEmail($this->associazione->id, $destinatario, 'Socio '.$i, 'Avviso '.$i, '<p>Avviso</p>');
            Carbon::setTestNow(now()->addSecond());
        }
        // Email in coda di un altro tenant: non deve essere toccata
        EmailQueue::allTenants()->create([
            'associazione_id' => $this->altraAssociazione->id, 'batch_id' => 'b', 'to_email' => 'vicino@example.test',
            'to_name' => '', 'subject' => 'Vicino', 'body_html' => '<p>x</p>', 'priority' => 5, 'status' => EmailQueue::STATUS_PENDING,
        ]);

        // Elaborazione manuale dal pannello
        $this->comeAdmin()->postJson(route('email-coda.process'))
            ->assertOk()
            ->assertJson(['success' => true, 'sent' => 2, 'failed' => 0, 'remaining' => 1]);

        $this->assertSame(['uno@example.test', 'due@example.test'], $invii);
        $this->assertSame(2, EmailQueue::query()->where('status', EmailQueue::STATUS_SENT)->count());
        $log = EmailLog::query()->where('status', EmailLog::STATUS_SENT)->get();
        $this->assertCount(2, $log);
        $this->assertTrue($log->every(fn (EmailLog $l) => $l->sent_by === $this->admin->id && $l->associazione_id === $this->associazione->id));

        // Limite orario raggiunto: la terza resta in coda
        $this->comeAdmin()->postJson(route('email-coda.process'))
            ->assertJson(['sent' => 0, 'remaining' => 1]);
        $this->assertCount(2, $invii);

        // Simula un fallimento definitivo sulla terza
        $terza = EmailQueue::query()->where('to_email', 'tre@example.test')->firstOrFail();
        $terza->forceFill(['status' => EmailQueue::STATUS_FAILED, 'attempts' => 3, 'error_message' => '550 Mailbox unavailable'])->save();

        // Il retry di una già inviata non è ammesso
        $inviata = EmailQueue::query()->where('to_email', 'uno@example.test')->firstOrFail();
        $this->comeAdmin()->post(route('email-coda.retry', $inviata))->assertSessionHas('warning');
        $this->assertSame(EmailQueue::STATUS_SENT, $inviata->fresh()->status);

        $this->comeAdmin()->post(route('email-coda.retry', $terza))
            ->assertRedirect(route('email-coda.index'))
            ->assertSessionHas('success');
        $terza->refresh();
        $this->assertSame(EmailQueue::STATUS_PENDING, $terza->status);
        $this->assertSame(0, (int) $terza->attempts);
        $this->assertNull($terza->error_message);

        // Statistiche della pagina coda
        $pagina = $this->comeAdmin()->get(route('email-coda.index'));
        $pagina->assertOk()->assertSee('tre@example.test')->assertDontSee('vicino@example.test');
        $stats = $pagina->viewData('stats');
        $this->assertSame(1, $stats['pending']);
        $this->assertSame(0, $stats['failed']);
        $this->assertSame(2, $stats['inviate_oggi']);

        // Annulla tutte le pendenti (solo del tenant)
        $this->comeAdmin()->post(route('email-coda.cancel-pending'))
            ->assertRedirect(route('email-coda.index'))
            ->assertSessionHas('success', '1 email pendenti annullate.');
        $this->assertSame(EmailQueue::STATUS_CANCELLED, $terza->fresh()->status);
        $this->assertSame(EmailQueue::STATUS_PENDING, EmailQueue::allTenants()->where('to_email', 'vicino@example.test')->value('status'));

        // Dopo un'ora il limite si libera ma non c'è più nulla da inviare
        Carbon::setTestNow(now()->addMinutes(61));
        $this->comeAdmin()->postJson(route('email-coda.process'))->assertJson(['sent' => 0, 'remaining' => 0]);
        $this->assertCount(2, $invii);
    }

    // ==================================================================
    // 25. Auguri di compleanno e archivio decisioni
    // ==================================================================

    public function test_auguri_compleanno_inviati_una_volta_l_anno_solo_se_attivi_e_decisioni_archivio_filtra_per_anno_e_ricerca(): void
    {
        // Auguri spenti: nessun invio
        $festeggiato = $this->creaSocio(['nome' => 'Franco', 'email' => 'franco@example.test', 'data_nascita' => '1970-05-15']);
        $festeggiataOptOut = $this->creaSocio(['nome' => 'Olga', 'email' => 'olga@example.test', 'data_nascita' => '1982-05-15', 'email_opt_out' => true]);
        $domani = $this->creaSocio(['nome' => 'Dino', 'data_nascita' => '1990-05-16']);
        $sospeso = $this->creaSocio(['nome' => 'Sergio', 'data_nascita' => '1975-05-15', 'stato' => Socio::STATO_SOSPESO]);
        $altrui = $this->creaSocio(['nome' => 'Vicino', 'data_nascita' => '1980-05-15'], $this->altraAssociazione);

        $servizio = app(CompleanniService::class);
        $this->assertSame(0, $servizio->inviaAuguri($this->associazione->fresh()));

        // L'admin attiva gli auguri dalle configurazioni
        $this->comeAdmin()->post(route('configurazioni.comunicazione'), ['auguri_automatici' => '1'])
            ->assertRedirect(route('configurazioni.index', ['section' => 'comunicazione']));
        $this->assertTrue((bool) $this->associazione->fresh()->auguri_automatici);

        $this->assertSame(1, $servizio->inviaAuguri($this->associazione->fresh()));
        $this->assertSame(0, $servizio->inviaAuguri($this->associazione->fresh()));

        $promemoria = PromemoriaInviato::allTenants()->where('tipo', CompleanniService::TIPO_COMPLEANNO)->get();
        $this->assertCount(1, $promemoria);
        $this->assertSame($festeggiato->id, $promemoria[0]->socio_id);
        $this->assertSame(2026, (int) $promemoria[0]->step);
        $this->assertSame($this->associazione->id, $promemoria[0]->associazione_id);
        $this->assertNull($promemoria[0]->tessera_id);

        Notification::assertSentToTimes($festeggiato, AuguriCompleannoNotification::class, 1);
        foreach ([$festeggiataOptOut, $domani, $sospeso, $altrui] as $escluso) {
            Notification::assertNotSentTo($escluso, AuguriCompleannoNotification::class);
        }

        // L'anno dopo gli auguri ripartono
        Carbon::setTestNow('2027-05-15 09:00:00');
        $this->assertSame(1, $servizio->inviaAuguri($this->associazione->fresh()));
        $this->assertSame(2, PromemoriaInviato::allTenants()->where('tipo', CompleanniService::TIPO_COMPLEANNO)->count());
        Carbon::setTestNow('2026-05-15 10:00:00');

        // ----- Archivio decisioni -----
        $riunione2025 = Riunione::factory()->create([
            'associazione_id' => $this->associazione->id, 'titolo' => 'Direttivo ottobre 2025',
            'data_ora' => '2025-10-10 21:00:00', 'stato' => Riunione::STATO_SVOLTA,
            'odg' => [['punto' => 'Acquisto furgone sociale', 'descrizione' => null]],
        ]);
        $riunione2026 = Riunione::factory()->create([
            'associazione_id' => $this->associazione->id, 'titolo' => 'Direttivo marzo 2026',
            'data_ora' => '2026-03-12 21:00:00', 'stato' => Riunione::STATO_SVOLTA,
            'odg' => [
                ['punto' => 'Rinnovo convenzione comunale', 'descrizione' => null],
                ['punto' => 'Aumento quota', 'descrizione' => null],
            ],
        ]);
        VotazioneRiunione::factory()->create(['riunione_id' => $riunione2025->id, 'punto_indice' => 0, 'esito' => 'approvato']);
        VotazioneRiunione::factory()->create(['riunione_id' => $riunione2026->id, 'punto_indice' => 0, 'esito' => 'approvato', 'note' => 'Durata triennale']);
        VotazioneRiunione::factory()->create(['riunione_id' => $riunione2026->id, 'punto_indice' => 1, 'esito' => 'respinto']);

        $ammesso = $this->creaSocio(['nome' => 'Bruno', 'cognome' => 'Neri']);
        RichiestaIscrizione::factory()->conSocio($ammesso)->approvata($this->admin)->create([
            'associazione_id' => $this->associazione->id,
            'delibera_numero' => 'D-2026/04',
            'delibera_data' => '2026-02-20',
        ]);
        // Decisione di un altro tenant
        $riunioneAltrui = Riunione::factory()->svolta()->create([
            'associazione_id' => $this->altraAssociazione->id,
            'odg' => [['punto' => 'Decisione del vicino', 'descrizione' => null]],
        ]);
        VotazioneRiunione::factory()->create(['riunione_id' => $riunioneAltrui->id, 'punto_indice' => 0, 'esito' => 'approvato']);

        $tutte = $this->comeAdmin()->get(route('decisioni.index'));
        $tutte->assertOk();
        $this->assertEqualsCanonicalizing(
            ['Acquisto furgone sociale', 'Rinnovo convenzione comunale', 'Ammissione socio: Bruno Neri'],
            collect($tutte->viewData('decisioni'))->pluck('titolo')->all()
        );
        $this->assertSame([2026, 2025], $tutte->viewData('anni'));

        $anno2026 = $this->comeAdmin()->get(route('decisioni.index', ['anno' => 2026]));
        $this->assertEqualsCanonicalizing(
            ['Rinnovo convenzione comunale', 'Ammissione socio: Bruno Neri'],
            collect($anno2026->viewData('decisioni'))->pluck('titolo')->all()
        );

        $ricerca = $this->comeAdmin()->get(route('decisioni.index', ['search' => 'furgone']));
        $this->assertSame(['Acquisto furgone sociale'], collect($ricerca->viewData('decisioni'))->pluck('titolo')->all());

        $ricercaSocio = $this->comeAdmin()->get(route('decisioni.index', ['search' => 'Neri', 'anno' => 2026]));
        $ricercaSocio->assertOk();
        $this->assertSame(['Ammissione socio: Bruno Neri'], collect($ricercaSocio->viewData('decisioni'))->pluck('titolo')->all());

        $ricercaNote = $this->comeAdmin()->get(route('decisioni.index', ['search' => 'triennale']));
        $this->assertSame(['Rinnovo convenzione comunale'], collect($ricercaNote->viewData('decisioni'))->pluck('titolo')->all());
    }
}
