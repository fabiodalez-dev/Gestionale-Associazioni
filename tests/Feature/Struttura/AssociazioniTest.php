<?php

namespace Tests\Feature\Struttura;

use App\Models\Associazione;
use App\Models\Sede;
use App\Models\TesseraTemplate;
use App\Models\TipoSocio;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssociazioniTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_vede_l_elenco_associazioni(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();
        $associazione = Associazione::factory()->create(['nome' => 'Polisportiva Test']);

        $response = $this->actingAs($superAdmin)->get('/associazioni');

        $response->assertOk();
        $response->assertSee('Polisportiva Test');
    }

    public function test_admin_associazione_non_accede_alle_associazioni(): void
    {
        $admin = Utente::factory()->adminAssociazione()->create();

        $this->actingAs($admin)->get('/associazioni')->assertForbidden();
        $this->actingAs($admin)->post('/associazioni', ['nome' => 'X', 'email' => 'x@x.it'])->assertForbidden();
    }

    public function test_creazione_associazione_con_sede_e_admin(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->post('/associazioni', [
            'nome' => 'Nuova Associazione',
            'email' => 'nuova@example.com',
            'provincia' => 'MI',
            'cap' => '20100',
            'tipo_scadenza_default' => 'annuale',
            'giorni_notifica_scadenza' => 15,
            'attiva' => '1',
            'create_sede' => '1',
            'sede_nome' => '',
            'create_admin' => '1',
            'admin_nome' => 'Mario',
            'admin_cognome' => 'Bianchi',
            'admin_email' => 'mario.bianchi@example.com',
            'admin_password' => 'Lampione-Viola-Treno-42',
        ]);

        $response->assertRedirect(route('associazioni.index'));
        $response->assertSessionHas('success');

        $associazione = Associazione::where('email', 'nuova@example.com')->first();
        $this->assertNotNull($associazione);
        $this->assertSame('annuale', $associazione->tipo_scadenza_default);

        // Sede iniziale con nome di default
        $this->assertDatabaseHas('sedi', [
            'associazione_id' => $associazione->id,
            'nome' => 'Sede Principale',
        ]);

        // Admin contestuale collegato e con ruolo forzato
        $this->assertDatabaseHas('utenti', [
            'email' => 'mario.bianchi@example.com',
            'associazione_id' => $associazione->id,
            'ruolo' => Utente::RUOLO_ADMIN_ASSOCIAZIONE,
        ]);
    }

    public function test_email_duplicata_blocca_la_creazione(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();
        Associazione::factory()->create(['email' => 'doppia@example.com']);

        $response = $this->actingAs($superAdmin)->post('/associazioni', [
            'nome' => 'Doppione',
            'email' => 'doppia@example.com',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertSame(1, Associazione::where('email', 'doppia@example.com')->count());
    }

    public function test_clonazione_configurazioni_rimappa_i_template_tessera(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();

        $sorgente = Associazione::factory()->create();
        $tipo = TipoSocio::create([
            'associazione_id' => $sorgente->id,
            'nome' => 'Ordinario',
            'descrizione' => 'Tipo base',
        ]);
        TesseraTemplate::create([
            'associazione_id' => $sorgente->id,
            'tipo_socio_id' => $tipo->id,
            'titolo' => 'Template Tessera',
            'contenuto' => 'Tessera {NUMERO_TESSERA}',
            'attivo' => true,
        ]);
        Sede::create(['associazione_id' => $sorgente->id, 'nome' => 'Sede Clonabile']);

        $response = $this->actingAs($superAdmin)->post('/associazioni', [
            'nome' => 'Destinazione',
            'email' => 'destinazione@example.com',
            'clone_enable' => '1',
            'clone_from_assoc_id' => $sorgente->id,
            'clone_tipi' => '1',
            'clone_sedi' => '1',
            'clone_templates' => '1',
        ]);

        $response->assertSessionHasNoErrors();

        $destinazione = Associazione::where('email', 'destinazione@example.com')->firstOrFail();

        $tipoClonato = TipoSocio::query()->forAssociazione($destinazione->id)->where('nome', 'Ordinario')->first();
        $this->assertNotNull($tipoClonato);
        $this->assertNotSame($tipo->id, $tipoClonato->id);

        // Il template clonato punta al NUOVO tipo (rimappato)
        $templateClonato = TesseraTemplate::query()->forAssociazione($destinazione->id)->first();
        $this->assertNotNull($templateClonato);
        $this->assertSame($tipoClonato->id, $templateClonato->tipo_socio_id);

        $this->assertDatabaseHas('sedi', ['associazione_id' => $destinazione->id, 'nome' => 'Sede Clonabile']);
    }

    public function test_eliminazione_richiede_conferma_del_nome(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();
        $associazione = Associazione::factory()->create(['nome' => 'Da Eliminare']);

        // Nome sbagliato → nessuna eliminazione
        $this->actingAs($superAdmin)
            ->delete('/associazioni/'.$associazione->id, ['conferma_nome' => 'Sbagliato'])
            ->assertSessionHasErrors('conferma_nome');
        $this->assertDatabaseHas('associazioni', ['id' => $associazione->id]);

        // Nome corretto → eliminata, utenti scollegati
        $admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $associazione->id]);

        $this->actingAs($superAdmin)
            ->delete('/associazioni/'.$associazione->id, ['conferma_nome' => 'Da Eliminare'])
            ->assertRedirect(route('associazioni.index'));

        $this->assertDatabaseMissing('associazioni', ['id' => $associazione->id]);
        $this->assertNull($admin->fresh()->associazione_id);
    }

    public function test_rimozione_admin_scollega_senza_cambiare_ruolo(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();
        $associazione = Associazione::factory()->create();
        $admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $associazione->id]);

        $this->actingAs($superAdmin)
            ->post('/associazioni/'.$associazione->id.'/rimuovi-admin', ['utente_id' => $admin->id])
            ->assertRedirect();

        $admin->refresh();
        $this->assertNull($admin->associazione_id);
        $this->assertSame(Utente::RUOLO_ADMIN_ASSOCIAZIONE, $admin->ruolo);
    }

    public function test_ricerca_utenti_solo_super_admin_ed_esclude_super_admin(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create(['nome' => 'Capo', 'cognome' => 'Supremo']);
        $libero = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => null,
            'nome' => 'Disponibile',
            'cognome' => 'Libero',
        ]);
        Utente::factory()->adminAssociazione()->create(['nome' => 'Occupato', 'cognome' => 'Collegato']);

        // admin_associazione → 403
        $this->actingAs(Utente::factory()->adminAssociazione()->create())
            ->get('/api/utenti-search?q=Disponibile')
            ->assertForbidden();

        $response = $this->actingAs($superAdmin)->get('/api/utenti-search?available=1&q=ile');

        $response->assertOk();
        $items = $response->json('items');
        $ids = array_column($items, 'id');

        $this->assertContains($libero->id, $ids);
        // Nessun super_admin nei risultati e nessun utente già collegato
        foreach ($items as $item) {
            $this->assertNotSame(Utente::RUOLO_SUPER_ADMIN, $item['ruolo']);
            $this->assertNull($item['associazione_id']);
        }
    }
}
