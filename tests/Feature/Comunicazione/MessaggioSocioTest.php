<?php

namespace Tests\Feature\Comunicazione;

use App\Http\Controllers\Comunicazione\MessaggioSocioController;
use App\Models\Associazione;
use App\Models\EmailQueue;
use App\Models\Socio;
use App\Models\StoricoAttivitaSocio;

/**
 * f.12 — Email diretta al socio dalla scheda: accodamento in email_queue
 * con layout brandizzato, rispetto dell'opt-out, tracciamento nello
 * storico attività, permessi (email.create) e isolamento multi-tenant.
 */
class MessaggioSocioTest extends ComunicazioneTestCase
{
    public function test_admin_accoda_l_email_e_traccia_lo_storico(): void
    {
        $this->configuraSmtp();

        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'mario@example.com',
            'nome' => 'Mario',
            'cognome' => 'Rossi',
        ]);

        $response = $this->actingAs($this->admin)->post(route('soci.invia-email', $socio), [
            'oggetto' => 'Convocazione assemblea',
            'corpo' => "Ciao Mario,\nti aspettiamo <giovedì>.",
        ]);

        $response->assertRedirect(route('soci.show', $socio));
        $response->assertSessionHas('success');

        $coda = EmailQueue::allTenants()->firstOrFail();
        $this->assertSame('mario@example.com', $coda->to_email);
        $this->assertSame('Convocazione assemblea', $coda->subject);
        $this->assertSame($socio->id, $coda->socio_id);
        $this->assertSame($this->associazione->id, $coda->associazione_id);
        // Testo escapato (niente HTML libero) + a-capo convertiti
        $this->assertStringContainsString('&lt;giovedì&gt;', $coda->body_html);
        $this->assertStringContainsString('<br', $coda->body_html);
        // Layout brandizzato del tenant
        $this->assertStringContainsString($this->associazione->nome, $coda->body_html);

        $storico = StoricoAttivitaSocio::allTenants()->where('socio_id', $socio->id)->firstOrFail();
        $this->assertSame(MessaggioSocioController::TIPO_ATTIVITA, $storico->tipo_attivita);
        $this->assertSame($this->admin->id, $storico->utente_id);
        $this->assertStringContainsString('Convocazione assemblea', $storico->descrizione);
    }

    public function test_opt_out_blocca_l_invio(): void
    {
        $this->configuraSmtp();

        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email_opt_out' => true,
        ]);

        $this->actingAs($this->admin)
            ->post(route('soci.invia-email', $socio), [
                'oggetto' => 'Bloccata',
                'corpo' => 'Testo',
            ])
            ->assertRedirect(route('soci.show', $socio))
            ->assertSessionHas('warning');

        $this->assertSame(0, EmailQueue::allTenants()->count());
        $this->assertSame(0, StoricoAttivitaSocio::allTenants()->count());
    }

    public function test_socio_senza_email_blocca_l_invio(): void
    {
        $this->configuraSmtp();

        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => '',
        ]);

        $this->actingAs($this->admin)
            ->post(route('soci.invia-email', $socio), [
                'oggetto' => 'Senza destinatario',
                'corpo' => 'Testo',
            ])
            ->assertSessionHas('warning');

        $this->assertSame(0, EmailQueue::allTenants()->count());
    }

    public function test_invio_rifiutato_se_smtp_non_configurato(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)
            ->post(route('soci.invia-email', $socio), [
                'oggetto' => 'Niente SMTP',
                'corpo' => 'Testo',
            ])
            ->assertSessionHas('danger');

        $this->assertSame(0, EmailQueue::allTenants()->count());
    }

    public function test_operatore_senza_email_create_non_invia(): void
    {
        $this->configuraSmtp();
        $operatore = $this->creaOperatore();
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($operatore)
            ->post(route('soci.invia-email', $socio), [
                'oggetto' => 'Non permesso',
                'corpo' => 'Testo',
            ])
            ->assertForbidden();

        $this->assertSame(0, EmailQueue::allTenants()->count());
    }

    public function test_admin_non_scrive_a_soci_di_altri_tenant(): void
    {
        $this->configuraSmtp();

        $altra = Associazione::factory()->create();
        $estraneo = Socio::factory()->create(['associazione_id' => $altra->id]);

        $this->actingAs($this->admin)
            ->post(route('soci.invia-email', $estraneo), [
                'oggetto' => 'Cross tenant',
                'corpo' => 'Testo',
            ])
            ->assertNotFound();

        $this->assertSame(0, EmailQueue::allTenants()->count());
    }

    public function test_validazione_oggetto_e_corpo_obbligatori(): void
    {
        $this->configuraSmtp();
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)
            ->from(route('soci.show', $socio))
            ->post(route('soci.invia-email', $socio), ['oggetto' => '', 'corpo' => ''])
            ->assertRedirect(route('soci.show', $socio))
            ->assertSessionHasErrors(['oggetto', 'corpo']);
    }

    public function test_la_scheda_socio_mostra_il_bottone_invia_email(): void
    {
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'mario@example.com',
        ]);

        $this->actingAs($this->admin)
            ->get(route('soci.show', $socio))
            ->assertOk()
            ->assertSee('Invia email')
            ->assertSee('inviaEmailSocioModal');
    }
}
