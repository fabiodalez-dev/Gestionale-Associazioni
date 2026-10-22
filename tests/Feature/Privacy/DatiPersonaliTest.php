<?php

namespace Tests\Feature\Privacy;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\DocumentoSocio;
use App\Models\Quota;
use App\Models\Socio;
use App\Models\Utente;
use App\Notifications\Privacy\DatiPersonaliPronti;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class DatiPersonaliTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Socio $socio;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('personal-data-exports');
        Storage::fake('local');
        Notification::fake();

        $this->associazione = Associazione::factory()->create(['frontend_pubblico_attivo' => true]);
        $this->socio = Socio::factory()->conAccessoAreaSoci()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Giulia',
            'cognome' => 'Bianchi',
            'note' => 'Nota riservata della segreteria',
        ]);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('tenant.pubblico');

        parent::tearDown();
    }

    /**
     * @return array{0: string, 1: DatiPersonaliPronti}
     */
    private function archivioNotificato(object $titolare): array
    {
        $notifica = null;
        Notification::assertSentTo($titolare, DatiPersonaliPronti::class, function (DatiPersonaliPronti $n) use (&$notifica) {
            $notifica = $n;

            return true;
        });

        return [$notifica->zipFilename, $notifica];
    }

    /**
     * @return array<string, string> nome nel file ZIP => contenuto
     */
    private function contenutoZip(string $file): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('personal-data-exports')->path($file)) === true);
        $contenuto = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nome = (string) $zip->getNameIndex($i);
            $contenuto[$nome] = (string) $zip->getFromIndex($i);
        }
        $zip->close();

        return $contenuto;
    }

    public function test_il_socio_riceve_l_archivio_completo_via_email(): void
    {
        Quota::factory()->create(['associazione_id' => $this->associazione->id, 'socio_id' => $this->socio->id, 'anno' => 2026]);
        Storage::disk('local')->put('documenti_soci/carta.pdf', 'contenuto-documento');
        DocumentoSocio::create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $this->socio->id,
            'nome_file' => 'carta identità.pdf',
            'percorso_file' => 'documenti_soci/carta.pdf',
            'tipo_documento' => 'identita',
        ]);

        $this->actingAs($this->socio, 'socio')
            ->post(route('area-soci.dati-personali.richiedi'))
            ->assertRedirect()
            ->assertSessionHas('success');

        [$file] = $this->archivioNotificato($this->socio);
        $this->assertStringStartsWith($this->socio->id.'_', $file);
        $contenuto = $this->contenutoZip($file);

        $this->assertArrayHasKey('dati.json', $contenuto);
        $this->assertStringStartsWith('%PDF', $contenuto['riepilogo.pdf']);
        $documenti = array_values(array_filter(array_keys($contenuto), fn ($n) => str_starts_with($n, 'documenti/')));
        $this->assertCount(1, $documenti);
        $this->assertSame('contenuto-documento', $contenuto[$documenti[0]]);

        $dati = json_decode($contenuto['dati.json'], true);
        $this->assertSame('Giulia', $dati['anagrafica']['nome']);
        $this->assertSame($this->associazione->nome, $dati['associazione']);
        $this->assertSame(2026, $dati['quote'][0]['anno']);
        $this->assertSame($documenti[0], $dati['documenti'][0]['file_nell_archivio']);

        // Niente segreti né note interne
        $this->assertStringNotContainsString('password', $contenuto['dati.json']);
        $this->assertStringNotContainsString('token', $contenuto['dati.json']);
        $this->assertStringNotContainsString('Nota riservata', $contenuto['dati.json']);

        $this->assertDatabaseHas('activity_log', ['subject_id' => $this->socio->id, 'event' => 'dati_personali_richiesti']);
    }

    public function test_una_sola_richiesta_ogni_24_ore(): void
    {
        $this->actingAs($this->socio, 'socio')->post(route('area-soci.dati-personali.richiedi'))->assertSessionHas('success');
        $this->actingAs($this->socio, 'socio')->post(route('area-soci.dati-personali.richiedi'))->assertSessionHas('error');

        Notification::assertSentToTimes($this->socio, DatiPersonaliPronti::class, 1);
    }

    public function test_il_link_scarica_solo_per_il_titolare_autenticato(): void
    {
        $this->actingAs($this->socio, 'socio')->post(route('area-soci.dati-personali.richiedi'));
        [$file, $notifica] = $this->archivioNotificato($this->socio);

        $mail = $notifica->toMail($this->socio);
        $url = $mail->actionUrl;
        $this->assertStringStartsWith(rtrim((string) config('app.url'), '/').'/area-soci/dati-personali/', $url);
        $percorso = (string) parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

        $this->actingAs($this->socio, 'socio')->get($percorso)->assertOk()->assertDownload('dati-personali.zip');

        // Un altro socio con il link valido
        $altro = Socio::factory()->conAccessoAreaSoci()->create(['associazione_id' => $this->associazione->id]);
        $this->actingAs($altro, 'socio')->get($percorso)->assertForbidden();

        // Senza firma o con firma alterata
        $this->actingAs($this->socio, 'socio')->get('/area-soci/dati-personali/'.$file)->assertForbidden();
        $this->actingAs($this->socio, 'socio')->get(str_replace('signature=', 'signature=0', $percorso))->assertForbidden();

        // Archivio scaduto e cancellato
        Storage::disk('personal-data-exports')->delete($file);
        $this->actingAs($this->socio, 'socio')->get($percorso)->assertNotFound();
    }

    public function test_l_account_riceve_e_scarica_il_proprio_archivio(): void
    {
        $account = Account::factory()->create(['associazione_id' => $this->associazione->id, 'nome' => 'Paolo']);

        $this->actingAs($account, 'account')
            ->post('/a/'.$this->associazione->slug.'/account/privacy/archivio')
            ->assertSessionHas('success');

        [$file, $notifica] = $this->archivioNotificato($account);
        $dati = json_decode($this->contenutoZip($file)['dati.json'], true);
        $this->assertSame('Paolo', $dati['profilo']['nome']);
        $this->assertArrayNotHasKey('password', $dati['profilo']);

        $url = $notifica->toMail($account)->actionUrl;
        $percorso = (string) parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
        $this->assertStringContainsString('/a/'.$this->associazione->slug.'/account/privacy/archivio/', $percorso);
        $this->actingAs($account, 'account')->get($percorso)->assertOk();
    }

    public function test_la_segreteria_scarica_l_archivio_del_socio_della_propria_associazione(): void
    {
        $admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->associazione->id]);

        $risposta = $this->actingAs($admin)->get(route('soci.dati-personali', $this->socio));
        $risposta->assertOk();
        $this->assertStringContainsString('dati-personali-bianchi-giulia.zip', (string) $risposta->headers->get('content-disposition'));
        $this->assertStringStartsWith('PK', $risposta->streamedContent());
        $this->assertDatabaseHas('activity_log', ['subject_id' => $this->socio->id, 'event' => 'dati_personali_esportati']);

        $altroAdmin = Utente::factory()->adminAssociazione()->create(['associazione_id' => Associazione::factory()->create()->id]);
        $this->actingAs($altroAdmin)->get(route('soci.dati-personali', $this->socio))->assertNotFound();
    }
}
