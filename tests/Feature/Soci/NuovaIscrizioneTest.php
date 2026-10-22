<?php

namespace Tests\Feature\Soci;

use App\Models\Associazione;
use App\Models\Socio;
use App\Models\Utente;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nuova iscrizione a passi: pagina e controlli automatici (email, codice fiscale).
 */
class NuovaIscrizioneTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->associazione = Associazione::factory()->create(['nome' => 'Polisportiva Aurora']);
        $this->admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->associazione->id]);
        $this->admin->assignRole(Utente::RUOLO_ADMIN_ASSOCIAZIONE);
        $this->actingAs($this->admin)->withSession(['associazione_id' => $this->associazione->id]);
    }

    public function test_pagina_a_passi_con_riepilogo_e_controlli(): void
    {
        $this->get(route('soci.create'))->assertOk()
            ->assertSee('Nuova iscrizione')
            ->assertSee('Anagrafica')->assertSee('Tesseramento')->assertSee('Dati aggiuntivi')->assertSee('Privacy e conferma')
            ->assertSee('Controlli automatici')
            ->assertSee('data-verifica-url="'.route('soci.verifica-dati').'"', false);
    }

    public function test_controlli_su_email_e_codice_fiscale(): void
    {
        Socio::factory()->create(['associazione_id' => $this->associazione->id, 'email' => 'giulia@test.it', 'codice_fiscale' => 'BNCGLI88C52A944I']);
        // Stessi dati in un'altra associazione: non contano
        Socio::factory()->create(['email' => 'altro@test.it', 'codice_fiscale' => 'FRRLCU09A01A944P']);

        $this->getJson(route('soci.verifica-dati', ['email' => 'GIULIA@test.it', 'codice_fiscale' => 'bncgli88c52a944i']))
            ->assertOk()
            ->assertJsonPath('email.duplicato', true)
            ->assertJsonPath('codice_fiscale.valido', true)
            ->assertJsonPath('codice_fiscale.duplicato', true)
            ->assertJsonPath('codice_fiscale.data_nascita', '1988-03-12')
            ->assertJsonPath('codice_fiscale.sesso', 'F');

        $this->getJson(route('soci.verifica-dati', ['email' => 'altro@test.it', 'codice_fiscale' => 'FRRLCU09A01A944P']))
            ->assertJsonPath('email.duplicato', false)
            ->assertJsonPath('codice_fiscale.duplicato', false);

        $this->getJson(route('soci.verifica-dati', ['codice_fiscale' => 'BNCGLI88C52A944K']))
            ->assertJsonPath('codice_fiscale.valido', false)
            ->assertJsonPath('codice_fiscale.data_nascita', null);
    }

    public function test_iscrizione_completa_dal_modulo_a_passi(): void
    {
        $this->post(route('soci.store'), [
            'nome' => 'Giulia',
            'cognome' => 'Bianchi',
            'email' => 'giulia.bianchi@test.it',
            'codice_fiscale' => 'BNCGLI88C52A944I',
            'data_nascita' => '1988-03-12',
            'data_iscrizione' => now()->toDateString(),
            'stato' => Socio::STATO_ATTIVO,
            'genera_tessera' => '1',
            'note' => 'Iscritta allo sportello',
            'privacy_consenso' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $socio = Socio::query()->where('email', 'giulia.bianchi@test.it')->sole();
        $this->assertSame('Iscritta allo sportello', $socio->note);
        $this->assertSame(1, $socio->tessere()->count());
    }

    public function test_solo_chi_puo_creare_soci_usa_i_controlli(): void
    {
        $operatore = Utente::factory()->operatore()->create(['associazione_id' => $this->associazione->id]);
        $this->actingAs($operatore)->getJson(route('soci.verifica-dati', ['email' => 'x@test.it']))->assertForbidden();
    }
}
