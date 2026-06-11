<?php

namespace Tests\Feature\Installer;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\Evento;
use App\Models\RichiestaIscrizione;
use App\Models\Socio;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DemoSeeder (installer web, opzione "dati dimostrativi"): oltre ai
 * contenuti storici (soci, tessere, quote, eventi, prenotazione demo)
 * deve produrre il materiale del modulo iscrizioni (PIANO-ISCRIZIONI):
 * form di iscrizione configurato sul tenant, evento con approvazione
 * richiesta e una richiesta di iscrizione pendente, senza factory.
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);
        $this->seed(DemoSeeder::class);
    }

    public function test_crea_il_tenant_demo_con_form_iscrizione_configurato(): void
    {
        $associazione = Associazione::query()->where('slug', 'associazione-demo')->sole();

        $this->assertTrue($associazione->iscrizioni_socio_aperte);
        $this->assertSame(Associazione::MODALITA_PREISCRIZIONE, $associazione->modalita_iscrizione);

        $config = $associazione->iscrizione_form_config;
        $this->assertIsArray($config);
        $this->assertNotEmpty($config['intro_html']);
        $this->assertNotEmpty($config['campi_standard']);
        $this->assertCount(1, $config['campi_personalizzati']);

        // Il campo personalizzato referenziato esiste davvero in config_campi
        $this->assertSame(
            'Professione',
            $associazione->campiPersonalizzati()
                ->whereKey($config['campi_personalizzati'][0])
                ->sole()
                ->nome_campo
        );
    }

    public function test_crea_un_evento_pubblicato_con_approvazione_richiesta(): void
    {
        $evento = Evento::allTenants()
            ->where('richiede_approvazione', true)
            ->sole();

        $this->assertSame(Evento::STATO_PUBBLICATO, $evento->stato_pubblicazione);
        $this->assertTrue($evento->iscrizioni_aperte);
    }

    public function test_crea_una_richiesta_di_iscrizione_pendente_con_preiscritto(): void
    {
        $richiesta = RichiestaIscrizione::allTenants()->sole();

        $this->assertSame(RichiestaIscrizione::STATO_IN_ATTESA, $richiesta->stato);
        $this->assertNotEmpty($richiesta->dati_form);

        $account = Account::allTenants()->findOrFail($richiesta->account_id);
        $this->assertNotNull($account->richiesta_socio_at);
        $this->assertNull($account->socio_id);

        $socio = Socio::allTenants()->findOrFail($richiesta->socio_id);
        $this->assertSame(Socio::STATO_IN_ATTESA, $socio->stato);
        $this->assertSame($account->email, $socio->email);
        $this->assertTrue($socio->valoriCampiPersonalizzati()->exists());
    }
}
