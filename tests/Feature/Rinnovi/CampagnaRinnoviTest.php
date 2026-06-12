<?php

namespace Tests\Feature\Rinnovi;

use App\Models\Associazione;
use App\Models\EmailQueue;
use App\Models\Quota;
use App\Models\Socio;
use App\Models\Tessera;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * f.2 — Campagna rinnovi {anno}: pagina admin, contatori, invio massivo
 * con link di pagamento personale nella coda email per-tenant.
 */
class CampagnaRinnoviTest extends RinnoviTestCase
{
    use RefreshDatabase;

    public function test_pagina_richiede_login(): void
    {
        $this->get(route('campagna-rinnovi.index'))->assertRedirect('/login');
    }

    public function test_pagina_negata_senza_permessi_tessere(): void
    {
        $this->configuraTenant();
        $utente = $this->creaUtenteSenzaPermessi();

        $this->actingAs($utente)->get(route('campagna-rinnovi.index'))->assertForbidden();
    }

    public function test_lista_soci_attivi_senza_tessera_dell_anno_con_isolamento_tenant(): void
    {
        $this->configuraTenant();
        $anno = (int) now()->year;

        $daRinnovare = $this->creaSocio(['cognome' => 'Rossi', 'nome' => 'Mario']);

        $giaRinnovato = $this->creaSocio(['cognome' => 'Verdi', 'nome' => 'Anna']);
        Tessera::factory()->create([
            'socio_id' => $giaRinnovato->id,
            'associazione_id' => $this->associazione->id,
            'anno_validita' => $anno,
        ]);

        $sospeso = $this->creaSocio(['cognome' => 'Sospesini', 'stato' => Socio::STATO_SOSPESO]);

        $altraAssociazione = Associazione::factory()->create();
        Socio::factory()->create([
            'associazione_id' => $altraAssociazione->id,
            'cognome' => 'Altrotenant',
        ]);

        $response = $this->actingAs($this->admin)->get(route('campagna-rinnovi.index'));

        $response->assertOk();
        $response->assertSee('Campagna rinnovi '.$anno);
        $response->assertSee('Rossi');
        $response->assertDontSee('Verdi');
        $response->assertDontSee('Sospesini');
        $response->assertDontSee('Altrotenant');
    }

    public function test_invio_massivo_crea_quote_token_e_accoda_email_personali(): void
    {
        $this->configuraTenant();
        $anno = (int) now()->year;
        $tipo = $this->creaTipoSocio(30.00);
        $socio1 = $this->creaSocio(['tipo_socio_id' => $tipo->id]);
        $socio2 = $this->creaSocio();

        $response = $this->actingAs($this->admin)->post(route('campagna-rinnovi.invia'), [
            'socio_ids' => [$socio1->id, $socio2->id],
        ]);

        $response->assertRedirect(route('campagna-rinnovi.index'));
        $response->assertSessionHas('success');

        foreach ([$socio1, $socio2] as $socio) {
            /** @var Quota $quota */
            $quota = Quota::allTenants()
                ->where('socio_id', $socio->id)
                ->where('anno', $anno)
                ->firstOrFail();
            $this->assertTrue($quota->hasValidPaymentToken());

            /** @var EmailQueue $email */
            $email = EmailQueue::allTenants()
                ->where('socio_id', $socio->id)
                ->where('template_codice', 'campagna_rinnovi_'.$anno)
                ->firstOrFail();

            $this->assertSame($socio->email, $email->to_email);
            $this->assertStringContainsString($quota->payment_token, $email->body_html);
            $this->assertStringContainsString((string) $anno, $email->subject);
        }

        $this->assertSame('30.00', (string) Quota::allTenants()->where('socio_id', $socio1->id)->value('importo'));
    }

    public function test_invio_salta_soci_con_opt_out_senza_email_o_gia_rinnovati(): void
    {
        $this->configuraTenant();
        $anno = (int) now()->year;

        $optOut = $this->creaSocio(['email_opt_out' => true]);
        $senzaEmail = $this->creaSocio(['email' => '']);
        $rinnovato = $this->creaSocio();
        Tessera::factory()->create([
            'socio_id' => $rinnovato->id,
            'associazione_id' => $this->associazione->id,
            'anno_validita' => $anno,
        ]);

        $response = $this->actingAs($this->admin)->post(route('campagna-rinnovi.invia'), [
            'socio_ids' => [$optOut->id, $senzaEmail->id, $rinnovato->id],
        ]);

        $response->assertRedirect(route('campagna-rinnovi.index'));
        $this->assertSame(0, EmailQueue::allTenants()->count());
    }

    public function test_invio_non_raggiunge_soci_di_altri_tenant(): void
    {
        $this->configuraTenant();
        $altra = Associazione::factory()->create();
        $estraneo = Socio::factory()->create(['associazione_id' => $altra->id]);

        $this->actingAs($this->admin)->post(route('campagna-rinnovi.invia'), [
            'socio_ids' => [$estraneo->id],
        ])->assertRedirect(route('campagna-rinnovi.index'));

        $this->assertSame(0, EmailQueue::allTenants()->where('socio_id', $estraneo->id)->count());
        $this->assertSame(0, Quota::allTenants()->where('socio_id', $estraneo->id)->count());
    }

    public function test_invio_negato_senza_permessi(): void
    {
        $this->configuraTenant();
        $utente = $this->creaUtenteSenzaPermessi();
        $socio = $this->creaSocio();

        $this->actingAs($utente)->post(route('campagna-rinnovi.invia'), [
            'socio_ids' => [$socio->id],
        ])->assertForbidden();
    }

    public function test_invio_senza_selezione_fallisce_la_validazione(): void
    {
        $this->configuraTenant();

        $this->actingAs($this->admin)
            ->from(route('campagna-rinnovi.index'))
            ->post(route('campagna-rinnovi.invia'), ['socio_ids' => []])
            ->assertRedirect(route('campagna-rinnovi.index'))
            ->assertSessionHasErrors('socio_ids');
    }

    public function test_contatori_riflettono_inviate_e_pagate(): void
    {
        $this->configuraTenant();
        $anno = (int) now()->year;
        $socio = $this->creaSocio();

        // Invio campagna → "inviata"
        $this->actingAs($this->admin)->post(route('campagna-rinnovi.invia'), [
            'socio_ids' => [$socio->id],
        ]);

        // Pagamento della quota → "pagata"
        Quota::allTenants()
            ->where('socio_id', $socio->id)
            ->where('anno', $anno)
            ->firstOrFail()
            ->forceFill(['data_pagamento' => now()->toDateString()])
            ->save();

        $response = $this->actingAs($this->admin)->get(route('campagna-rinnovi.index'));

        $response->assertOk();
        $response->assertSeeInOrder(['Email inviate', '1'], false);
        $response->assertSeeInOrder(['Quote pagate', '1'], false);
        $response->assertSee('data-testid="contatore-inviate"', false);
    }
}
