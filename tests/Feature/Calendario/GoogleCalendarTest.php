<?php

namespace Tests\Feature\Calendario;

use App\Jobs\SincronizzaEventoGoogleCalendar;
use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Utente;
use App\Services\Calendario\CalendarioEsterno;
use App\Services\Calendario\GoogleCalendarSpatie;
use App\Settings\GoogleCalendarSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/** Finto client: registra le chiamate al posto di Google. */
class CalendarioFinto implements CalendarioEsterno
{
    /** @var array<string, array<string, mixed>> */
    public array $eventi = [];

    /** @var list<string> */
    public array $chiamate = [];

    public ?string $errore = null;

    private int $contatore = 0;

    public function salva(GoogleCalendarSettings $impostazioni, array $dati, ?string $idEsterno): string
    {
        if ($this->errore !== null) {
            throw new RuntimeException($this->errore);
        }
        $id = $idEsterno ?? 'g'.(++$this->contatore);
        $this->chiamate[] = ($idEsterno === null ? 'crea:' : 'aggiorna:').$id.'@'.$impostazioni->calendar_id;
        $this->eventi[$id] = $dati;

        return $id;
    }

    public function elimina(GoogleCalendarSettings $impostazioni, string $idEsterno): void
    {
        $this->chiamate[] = 'elimina:'.$idEsterno;
        unset($this->eventi[$idEsterno]);
    }

    public function verifica(GoogleCalendarSettings $impostazioni): string
    {
        return 'Calendario di prova';
    }
}

class GoogleCalendarTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private CalendarioFinto $google;

    private const CHIAVE = '{"type":"service_account","client_email":"sync@progetto.iam.gserviceaccount.com","private_key":"-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----\n","client_id":"1"}';

    protected function setUp(): void
    {
        parent::setUp();

        $this->google = new CalendarioFinto;
        $this->app->instance(CalendarioEsterno::class, $this->google);
        $this->associazione = Associazione::factory()->create();
    }

    private function attiva(?Associazione $associazione = null, string $calendario = 'cal@group.calendar.google.com'): void
    {
        [$cifrate, $email] = GoogleCalendarSettings::cifraCredenziali(self::CHIAVE);
        GoogleCalendarSettings::per($associazione ?? $this->associazione)->aggiorna([
            'attiva' => true, 'calendar_id' => $calendario, 'credenziali_cifrate' => $cifrate, 'service_account_email' => $email,
        ]);
    }

    public function test_spenta_di_default_nessuna_sincronizzazione(): void
    {
        Queue::fake();
        Evento::factory()->pubblicato()->create(['associazione_id' => $this->associazione->id]);

        $this->assertFalse(GoogleCalendarSettings::per($this->associazione)->attiva);
        Queue::assertNothingPushed();
    }

    public function test_ciclo_di_vita_dell_evento_sul_calendario(): void
    {
        $this->attiva();

        $evento = Evento::factory()->pubblicato()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Assemblea',
            'descrizione' => '<p>Ordine del <b>giorno</b></p>',
            'data_evento' => '2026-12-01 18:00:00',
            'data_fine' => null,
        ]);
        $evento->refresh();

        $this->assertSame('g1', $evento->google_event_id);
        $this->assertSame('Ordine del giorno', $this->google->eventi['g1']['descrizione']);
        $this->assertSame('2026-12-01 20:00:00', $this->google->eventi['g1']['fine']->format('Y-m-d H:i:s'));

        // Campo visibile modificato → aggiornamento; campo non visibile → nessuna chiamata
        $evento->update(['titolo' => 'Assemblea ordinaria']);
        $evento->update(['capienza' => 99]);
        $this->assertSame(['crea:g1@cal@group.calendar.google.com', 'aggiorna:g1@cal@group.calendar.google.com'], $this->google->chiamate);
        $this->assertSame('Assemblea ordinaria', $this->google->eventi['g1']['titolo']);

        // Tornato in bozza → rimosso; ripubblicato → ricreato
        $evento->update(['stato_pubblicazione' => Evento::STATO_BOZZA]);
        $this->assertNull($evento->fresh()->google_event_id);
        $this->assertArrayNotHasKey('g1', $this->google->eventi);

        $evento->update(['stato_pubblicazione' => Evento::STATO_PUBBLICATO]);
        $this->assertSame('g2', $evento->fresh()->google_event_id);

        // Eliminato (cestino) → rimosso; eliminazione definitiva dopo il ripristino
        $evento->delete();
        $this->assertArrayNotHasKey('g2', $this->google->eventi);
        $evento->restore();
        $id = (string) $evento->fresh()->google_event_id;
        $this->assertArrayHasKey($id, $this->google->eventi);
        $evento->fresh()->forceDelete();
        $this->assertArrayNotHasKey($id, $this->google->eventi);
    }

    public function test_le_bozze_non_vengono_pubblicate(): void
    {
        $this->attiva();

        $evento = Evento::factory()->create(['associazione_id' => $this->associazione->id, 'stato_pubblicazione' => Evento::STATO_BOZZA]);

        $this->assertNull($evento->fresh()->google_event_id);
        $this->assertSame([], $this->google->chiamate);
    }

    public function test_ogni_associazione_usa_il_proprio_calendario(): void
    {
        $altra = Associazione::factory()->create();
        $this->attiva($this->associazione, 'a@group.calendar.google.com');
        $this->attiva($altra, 'b@group.calendar.google.com');

        Evento::factory()->pubblicato()->create(['associazione_id' => $this->associazione->id]);
        Evento::factory()->pubblicato()->create(['associazione_id' => $altra->id]);

        $this->assertSame(['crea:g1@a@group.calendar.google.com', 'crea:g2@b@group.calendar.google.com'], $this->google->chiamate);
    }

    public function test_un_errore_viene_registrato_e_poi_azzerato(): void
    {
        $this->attiva();
        $this->google->errore = 'Calendario non condiviso con il service account';
        $evento = Evento::factory()->pubblicato()->make(['associazione_id' => $this->associazione->id]);
        $evento->saveQuietly();

        try {
            (new SincronizzaEventoGoogleCalendar($evento->id))->handle($this->google);
            $this->fail('Il job doveva rilanciare l\'errore per riprovare');
        } catch (RuntimeException) {
        }
        $this->assertSame('Calendario non condiviso con il service account', GoogleCalendarSettings::per($this->associazione->fresh())->ultimo_errore);

        $this->google->errore = null;
        (new SincronizzaEventoGoogleCalendar($evento->id))->handle($this->google);
        $this->assertNull(GoogleCalendarSettings::per($this->associazione->fresh())->ultimo_errore);
    }

    public function test_configurazione_dalla_pagina_dell_associazione(): void
    {
        $admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($admin)->get(route('configurazioni.index', ['section' => 'google-calendar']))
            ->assertOk()->assertSee('Sincronizzazione con Google Calendar');

        $this->actingAs($admin)->post(route('configurazioni.google-calendar'), [
            'attiva' => '1',
            'calendar_id' => 'cal@group.calendar.google.com',
            'credenziali_file' => UploadedFile::fake()->createWithContent('chiave.json', self::CHIAVE),
        ])->assertRedirect()->assertSessionHas('success');

        $impostazioni = GoogleCalendarSettings::per($this->associazione->fresh());
        $this->assertTrue($impostazioni->attivaEConfigurata());
        $this->assertSame('sync@progetto.iam.gserviceaccount.com', $impostazioni->service_account_email);
        $this->assertSame('sync@progetto.iam.gserviceaccount.com', $impostazioni->credenziali()['client_email']);

        // Cifrate a riposo e mai rimostrate
        $payload = (string) DB::table('settings')->where('name', 'credenziali_cifrate')->value('payload');
        $this->assertStringNotContainsString('PRIVATE KEY', $payload);
        $this->actingAs($admin)->get(route('configurazioni.index', ['section' => 'google-calendar']))
            ->assertOk()->assertDontSee('PRIVATE KEY')->assertSee('sync@progetto.iam.gserviceaccount.com');

        // Verifica connessione e sincronizzazione degli eventi futuri
        $this->actingAs($admin)->post(route('configurazioni.google-calendar.verifica'))
            ->assertSessionHas('success', 'Connessione riuscita al calendario «Calendario di prova».');

        Queue::fake();
        Evento::factory()->pubblicato()->create(['associazione_id' => $this->associazione->id, 'data_evento' => now()->addWeek()]);
        Evento::factory()->pubblicato()->passato()->create(['associazione_id' => $this->associazione->id]);
        $this->actingAs($admin)->post(route('configurazioni.google-calendar.sincronizza'))
            ->assertSessionHas('success', 'Sincronizzazione accodata per 1 eventi futuri.');

        // Salvataggio senza nuove credenziali: restano quelle di prima
        $this->actingAs($admin)->post(route('configurazioni.google-calendar'), ['attiva' => '1', 'calendar_id' => 'altro@group.calendar.google.com']);
        $this->assertNotNull(GoogleCalendarSettings::per($this->associazione->fresh())->credenziali());

        // Rimozione: credenziali cancellate e sincronizzazione spenta
        $this->actingAs($admin)->post(route('configurazioni.google-calendar'), ['attiva' => '1', 'calendar_id' => 'altro@group.calendar.google.com', 'rimuovi_credenziali' => '1']);
        $this->assertFalse(GoogleCalendarSettings::per($this->associazione->fresh())->attiva);
        $this->assertNull(GoogleCalendarSettings::per($this->associazione->fresh())->credenziali());
    }

    public function test_json_non_valido_e_accesso_riservato_agli_admin(): void
    {
        $admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->associazione->id]);
        $this->actingAs($admin)->post(route('configurazioni.google-calendar'), [
            'calendar_id' => 'cal@group.calendar.google.com',
            'credenziali_json' => '{"type":"authorized_user"}',
        ])->assertSessionHasErrors('credenziali_json');

        $operatore = Utente::factory()->operatore()->create(['associazione_id' => $this->associazione->id]);
        $this->actingAs($operatore)->withSession(['associazione_id' => $this->associazione->id])
            ->post(route('configurazioni.google-calendar'), ['attiva' => '0'])->assertForbidden();
    }

    public function test_il_client_reale_rifiuta_credenziali_mancanti(): void
    {
        $this->expectException(RuntimeException::class);

        app(GoogleCalendarSpatie::class)->verifica(GoogleCalendarSettings::per($this->associazione));
    }
}
