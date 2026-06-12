<?php

namespace Tests\Feature\Comunicazione;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\RispostaSondaggio;
use App\Models\Socio;
use App\Models\Sondaggio;

/**
 * f.11 — Sondaggi: CRUD admin con risultati a barre %, voto dall'area
 * soci e dall'area account (un voto per votante, identità unica per gli
 * account collegati a un socio), solo_soci e isolamento multi-tenant.
 */
class SondaggiTest extends ComunicazioneTestCase
{
    // ── CRUD admin ───────────────────────────────────────────────────

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get(route('sondaggi.index'))->assertRedirect('/login');
    }

    public function test_admin_crea_un_sondaggio_con_opzioni_da_textarea(): void
    {
        $response = $this->actingAs($this->admin)->post(route('sondaggi.store'), [
            'domanda' => 'Quale gita preferisci?',
            'opzioni_testo' => "Mare\nMontagna\n\n  Città d'arte  ",
            'aperto_dal' => now()->subHour()->format('Y-m-d\TH:i'),
            'aperto_al' => now()->addWeek()->format('Y-m-d\TH:i'),
            'solo_soci' => '0',
        ]);

        $sondaggio = Sondaggio::allTenants()->where('domanda', 'Quale gita preferisci?')->firstOrFail();
        $response->assertRedirect(route('sondaggi.show', $sondaggio));

        $this->assertSame($this->associazione->id, $sondaggio->associazione_id);
        $this->assertSame(['Mare', 'Montagna', "Città d'arte"], $sondaggio->opzioni);
        $this->assertTrue($sondaggio->isAperto());
    }

    public function test_admin_apre_le_pagine_di_creazione_e_modifica(): void
    {
        $this->actingAs($this->admin)
            ->get(route('sondaggi.create'))
            ->assertOk()
            ->assertViewIs('comunicazione.sondaggi.form')
            ->assertSee('Nuovo sondaggio');

        $sondaggio = Sondaggio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)
            ->get(route('sondaggi.edit', $sondaggio))
            ->assertOk()
            ->assertSee('Modifica sondaggio')
            ->assertSee($sondaggio->domanda);
    }

    public function test_validazione_servono_almeno_due_opzioni(): void
    {
        $this->actingAs($this->admin)
            ->from(route('sondaggi.create'))
            ->post(route('sondaggi.store'), [
                'domanda' => 'Domanda senza opzioni?',
                'opzioni_testo' => "Unica\n",
                'solo_soci' => '0',
            ])
            ->assertRedirect(route('sondaggi.create'))
            ->assertSessionHasErrors('opzioni');
    }

    public function test_pagina_risultati_mostra_voti_e_percentuali(): void
    {
        $sondaggio = Sondaggio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'opzioni' => ['Mare', 'Montagna'],
        ]);
        RispostaSondaggio::factory()->count(3)->create([
            'sondaggio_id' => $sondaggio->id,
            'associazione_id' => $this->associazione->id,
            'opzione' => 0,
        ]);
        RispostaSondaggio::factory()->create([
            'sondaggio_id' => $sondaggio->id,
            'associazione_id' => $this->associazione->id,
            'opzione' => 1,
        ]);

        $this->actingAs($this->admin)
            ->get(route('sondaggi.show', $sondaggio))
            ->assertOk()
            ->assertViewIs('comunicazione.sondaggi.show')
            ->assertSee('4 voti totali')
            ->assertSee('3 voti (75%)')
            ->assertSee('1 voto (25%)');
    }

    public function test_admin_non_accede_ai_sondaggi_di_altri_tenant(): void
    {
        $altra = Associazione::factory()->create();
        $estraneo = Sondaggio::factory()->create(['associazione_id' => $altra->id]);

        $this->actingAs($this->admin)->get(route('sondaggi.show', $estraneo))->assertNotFound();
        $this->actingAs($this->admin)->delete(route('sondaggi.destroy', $estraneo))->assertNotFound();
    }

    public function test_operatore_vede_ma_non_crea_sondaggi(): void
    {
        $operatore = $this->creaOperatore();

        $this->actingAs($operatore)->get(route('sondaggi.index'))->assertOk();
        $this->actingAs($operatore)->post(route('sondaggi.store'), [
            'domanda' => 'Non permesso?',
            'opzioni_testo' => "A\nB",
            'solo_soci' => '0',
        ])->assertForbidden();
    }

    public function test_destroy_elimina_sondaggio_e_risposte(): void
    {
        $sondaggio = Sondaggio::factory()->create(['associazione_id' => $this->associazione->id]);
        RispostaSondaggio::factory()->create([
            'sondaggio_id' => $sondaggio->id,
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('sondaggi.destroy', $sondaggio))
            ->assertRedirect(route('sondaggi.index'));

        $this->assertSame(0, Sondaggio::allTenants()->whereKey($sondaggio->id)->count());
        $this->assertSame(0, RispostaSondaggio::allTenants()->where('sondaggio_id', $sondaggio->id)->count());
    }

    // ── Voto dall'area soci ──────────────────────────────────────────

    public function test_socio_vota_un_sondaggio_aperto_inclusi_i_solo_soci(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $sondaggio = Sondaggio::factory()->soloSoci()->create([
            'associazione_id' => $this->associazione->id,
            'opzioni' => ['Sì', 'No'],
        ]);

        $this->actingAs($socio, 'socio')
            ->get(route('area-soci.sondaggi'))
            ->assertOk()
            ->assertSee($sondaggio->domanda);

        $this->actingAs($socio, 'socio')
            ->post(route('area-soci.sondaggi.vota', $sondaggio), ['opzione' => 1])
            ->assertRedirect(route('area-soci.sondaggi'))
            ->assertSessionHas('success');

        $risposta = RispostaSondaggio::allTenants()->firstOrFail();
        $this->assertSame($socio->id, $risposta->votante_id);
        $this->assertSame(1, $risposta->opzione);
    }

    public function test_socio_non_vota_due_volte(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $sondaggio = Sondaggio::factory()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($socio, 'socio')
            ->post(route('area-soci.sondaggi.vota', $sondaggio), ['opzione' => 0])
            ->assertSessionHas('success');

        $this->actingAs($socio, 'socio')
            ->post(route('area-soci.sondaggi.vota', $sondaggio), ['opzione' => 1])
            ->assertSessionHas('warning');

        $this->assertSame(1, RispostaSondaggio::allTenants()->count());
        $this->assertSame(0, RispostaSondaggio::allTenants()->firstOrFail()->opzione);
    }

    public function test_socio_non_vota_un_sondaggio_chiuso_o_con_opzione_inesistente(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $chiuso = Sondaggio::factory()->chiuso()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $this->actingAs($socio, 'socio')
            ->post(route('area-soci.sondaggi.vota', $chiuso), ['opzione' => 0])
            ->assertSessionHas('warning');

        $aperto = Sondaggio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'opzioni' => ['A', 'B'],
        ]);
        $this->actingAs($socio, 'socio')
            ->post(route('area-soci.sondaggi.vota', $aperto), ['opzione' => 9])
            ->assertSessionHas('danger');

        $this->assertSame(0, RispostaSondaggio::allTenants()->count());
    }

    public function test_socio_non_vota_sondaggi_di_altri_tenant(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $altra = Associazione::factory()->create();
        $estraneo = Sondaggio::factory()->create(['associazione_id' => $altra->id]);

        $this->actingAs($socio, 'socio')
            ->post(route('area-soci.sondaggi.vota', $estraneo), ['opzione' => 0])
            ->assertNotFound();
    }

    public function test_dopo_il_voto_il_socio_vede_i_risultati(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $sondaggio = Sondaggio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'opzioni' => ['Mare', 'Montagna'],
        ]);

        $this->actingAs($socio, 'socio')
            ->post(route('area-soci.sondaggi.vota', $sondaggio), ['opzione' => 0]);

        $this->actingAs($socio, 'socio')
            ->get(route('area-soci.sondaggi'))
            ->assertOk()
            ->assertSee('Hai votato')
            ->assertSee('1 voto totale');
    }

    // ── Voto dall'area account ───────────────────────────────────────

    public function test_account_non_collegato_vota_solo_i_sondaggi_non_riservati(): void
    {
        $account = Account::factory()->create(['associazione_id' => $this->associazione->id]);

        $pubblico = Sondaggio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'domanda' => 'Sondaggio aperto a tutti?',
        ]);
        $riservato = Sondaggio::factory()->soloSoci()->create([
            'associazione_id' => $this->associazione->id,
            'domanda' => 'Sondaggio riservato ai soci?',
        ]);

        $this->actingAs($account, 'account')
            ->get($this->urlTenant('account/sondaggi'))
            ->assertOk()
            ->assertSee('Sondaggio aperto a tutti?')
            ->assertDontSee('Sondaggio riservato ai soci?');

        // Vota il pubblico: votante = Account
        $this->actingAs($account, 'account')
            ->post($this->urlTenant('account/sondaggi/'.$pubblico->id.'/vota'), ['opzione' => 0])
            ->assertSessionHas('success');

        $risposta = RispostaSondaggio::allTenants()->firstOrFail();
        $this->assertSame($account->id, $risposta->votante_id);

        // Il riservato non è votabile
        $this->actingAs($account, 'account')
            ->post($this->urlTenant('account/sondaggi/'.$riservato->id.'/vota'), ['opzione' => 0])
            ->assertNotFound();
    }

    public function test_account_collegato_a_un_socio_vota_come_quel_socio(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $account = Account::factory()->create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $socio->id,
        ]);
        $sondaggio = Sondaggio::factory()->soloSoci()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($account, 'account')
            ->post($this->urlTenant('account/sondaggi/'.$sondaggio->id.'/vota'), ['opzione' => 0])
            ->assertSessionHas('success');

        $risposta = RispostaSondaggio::allTenants()->firstOrFail();
        $this->assertSame($socio->id, $risposta->votante_id);

        // Lo stesso tesserato non può rivotare dall'area soci
        $this->actingAs($socio, 'socio')
            ->post(route('area-soci.sondaggi.vota', $sondaggio), ['opzione' => 1])
            ->assertSessionHas('warning');

        $this->assertSame(1, RispostaSondaggio::allTenants()->count());
    }
}
