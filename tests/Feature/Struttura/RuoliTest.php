<?php

namespace Tests\Feature\Struttura;

use App\Models\Associazione;
use App\Models\Movimento;
use App\Models\SmtpSetting;
use App\Models\Socio;
use App\Models\Turno;
use App\Models\Utente;
use App\Support\RuoliAssociazione;
use Database\Seeders\RolesSeeder;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class RuoliTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);

        $this->associazione = Associazione::factory()->create();
        $this->admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->associazione->id]);
        $this->admin->assignRole('admin_associazione');
    }

    /**
     * @param  list<string>  $ruoli
     */
    private function staff(array $ruoli, ?Associazione $associazione = null): Utente
    {
        $utente = Utente::factory()->operatore()->create(['associazione_id' => ($associazione ?? $this->associazione)->id]);
        $utente->syncRoles($ruoli);

        return $utente;
    }

    public function test_operatore_conserva_esattamente_i_permessi_precedenti(): void
    {
        $precedenti = [
            'soci.view', 'soci.create', 'soci.update', 'soci.delete',
            'tessere.view', 'tessere.create', 'tessere.update', 'tessere.delete',
            'quote.view', 'quote.create', 'quote.update', 'quote.delete',
            'eventi.view', 'eventi.create', 'eventi.update', 'eventi.delete',
            'documenti.view', 'documenti.create',
            'verbali.view', 'verbali.create', 'verbali.update', 'verbali.delete',
            'scadenze.view', 'scadenze.create', 'scadenze.update', 'scadenze.delete',
            'email.view',
        ];
        $vecchiModuli = ['soci', 'tessere', 'quote', 'eventi', 'email', 'documenti', 'verbali', 'scadenze', 'struttura', 'api'];
        $attuali = array_values(array_filter(
            RuoliAssociazione::permessi('operatore'),
            fn (string $p) => in_array(explode('.', $p)[0], $vecchiModuli, true),
        ));

        $this->assertEqualsCanonicalizing($precedenti, $attuali);
    }

    public function test_le_cariche_hanno_i_permessi_attesi(): void
    {
        $tesoriere = $this->staff(['tesoriere']);
        $this->assertTrue($tesoriere->can('economia.create'));
        $this->assertTrue($tesoriere->can('quote.delete'));
        $this->assertTrue($tesoriere->can('soci.view'));
        $this->assertFalse($tesoriere->can('soci.update'));

        $volontario = $this->staff(['volontario']);
        $this->assertTrue($volontario->can('volontari.view'));
        $this->assertFalse($volontario->can('soci.view'));
        $this->assertFalse($volontario->can('volontari.create'));

        $presidente = $this->staff(['presidente']);
        $this->assertTrue($presidente->can('struttura.update'));
        $this->assertFalse($presidente->can('api.view'));
        $this->assertFalse($presidente->can('soci.delete'));
        $this->assertFalse($this->staff(['vicepresidente'])->can('eventi.delete'));

        // I permessi di più ruoli si sommano
        $doppio = $this->staff(['volontario', 'tesoriere']);
        $this->assertTrue($doppio->can('volontari.view'));
        $this->assertTrue($doppio->can('economia.update'));
    }

    public function test_le_policy_dei_moduli_usano_i_nuovi_permessi(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        $tesoriere = $this->staff(['tesoriere']);
        $volontario = $this->staff(['volontario']);

        $this->assertTrue(Gate::forUser($tesoriere)->allows('viewAny', Movimento::class));
        $this->assertTrue(Gate::forUser($tesoriere)->allows('create', Movimento::class));
        $this->assertFalse(Gate::forUser($tesoriere)->allows('delete', $socio));
        $this->assertFalse(Gate::forUser($volontario)->allows('viewAny', Movimento::class));
        $this->assertTrue(Gate::forUser($volontario)->allows('viewAny', Turno::class));
        $this->assertFalse(Gate::forUser($volontario)->allows('create', Turno::class));
    }

    public function test_solo_l_admin_modifica_l_smtp_dell_associazione(): void
    {
        foreach (['presidente', 'vicepresidente', 'segretario'] as $carica) {
            $utente = $this->staff([$carica]);
            $this->assertTrue($utente->can('email.view'), $carica);
            $this->assertFalse(Gate::forUser($utente)->allows('update', SmtpSetting::class), $carica);
        }
        $this->assertTrue(Gate::forUser($this->admin)->allows('update', SmtpSetting::class));
    }

    public function test_admin_assegna_cariche_a_un_operatore(): void
    {
        $operatore = $this->staff(['operatore']);

        $this->actingAs($this->admin)->get('/ruoli')
            ->assertOk()
            ->assertSee('Ruoli e permessi')
            ->assertSee($operatore->email)
            ->assertSee('Tesoriere');

        $this->actingAs($this->admin)
            ->put('/ruoli/'.$operatore->id, ['ruoli' => ['tesoriere', 'segretario']])
            ->assertRedirect(route('ruoli.index'))
            ->assertSessionHas('success');

        $this->assertEqualsCanonicalizing(['tesoriere', 'segretario'], $operatore->fresh()->getRoleNames()->all());
    }

    public function test_limiti_di_chi_puo_assegnare_ruoli(): void
    {
        $altra = Associazione::factory()->create();
        $operatoreAltra = $this->staff(['operatore'], $altra);
        $altroAdmin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->associazione->id]);
        $operatore = $this->staff(['operatore']);

        // Operatori di altre associazioni, amministratori e ruoli di sistema non sono modificabili
        $this->actingAs($this->admin)->put('/ruoli/'.$operatoreAltra->id, ['ruoli' => ['presidente']])->assertForbidden();
        $this->actingAs($this->admin)->put('/ruoli/'.$altroAdmin->id, ['ruoli' => ['presidente']])->assertForbidden();
        $this->actingAs($this->admin)->put('/ruoli/'.$operatore->id, ['ruoli' => ['admin_associazione']])->assertSessionHasErrors('ruoli.0');
        $this->actingAs($this->admin)->put('/ruoli/'.$operatore->id, ['ruoli' => []])->assertSessionHasErrors('ruoli');

        // Un operatore non accede alla pagina
        $this->actingAs($operatore)->get('/ruoli')->assertForbidden();
        $this->assertSame(['operatore'], $operatore->fresh()->getRoleNames()->all());
    }

    public function test_il_login_conserva_le_cariche_e_toglie_ruoli_di_sistema_scaduti(): void
    {
        $tesoriere = $this->staff(['tesoriere']);
        event(new Login('web', $tesoriere, false));
        $this->assertSame(['tesoriere'], $tesoriere->fresh()->getRoleNames()->all());

        // Ex admin declassato a operatore: perde admin_associazione e riceve operatore
        $declassato = $this->staff(['admin_associazione']);
        event(new Login('web', $declassato, false));
        $this->assertSame(['operatore'], $declassato->fresh()->getRoleNames()->all());
    }

    public function test_modifica_dal_super_admin_conserva_le_cariche_dell_operatore(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();
        $superAdmin->assignRole('super_admin');
        $operatore = $this->staff(['tesoriere']);

        $this->actingAs($superAdmin)->put('/amministratori/'.$operatore->id, [
            'nome' => 'Nuovo nome',
            'email' => $operatore->email,
            'ruolo' => Utente::RUOLO_OPERATORE,
            'associazione_id' => $this->associazione->id,
        ])->assertRedirect(route('amministratori.index'));

        $this->assertSame(['tesoriere'], $operatore->fresh()->getRoleNames()->all());
    }
}
