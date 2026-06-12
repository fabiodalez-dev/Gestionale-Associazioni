<?php

namespace Tests\Feature\EmailConferme;

use App\Mail\EmailConferme\ProvaEmail;
use App\Models\Associazione;
use App\Models\Utente;
use App\Services\Email\EmailAnteprimaService;
use Illuminate\Support\Facades\Mail;

/**
 * f.20 — Centro anteprime email: elenco delle notifiche di sistema,
 * anteprima HTML su dati di esempio (mai persistiti) e invio di prova
 * all'admin loggato. Permessi: email.view per consultare, email.create
 * per la prova.
 */
class AnteprimeEmailTest extends EmailConfermeTestCase
{
    public function test_admin_vede_il_centro_anteprime_con_il_catalogo(): void
    {
        $response = $this->actingAs($this->admin)->get(route('email-anteprime.index'));

        $response->assertOk();
        $response->assertSee('Anteprime email');
        $response->assertSee('Conferma richiesta ricevuta');
        $response->assertSee('Ricevuta quota (PDF)');
        $response->assertSee('Invia prova a me');
    }

    public function test_tutte_le_anteprime_del_catalogo_si_renderizzano(): void
    {
        $catalogo = app(EmailAnteprimaService::class)->catalogo();

        $this->assertNotEmpty($catalogo);

        foreach (array_keys($catalogo) as $codice) {
            $this->actingAs($this->admin)
                ->get(route('email-anteprime.show', ['codice' => $codice]))
                ->assertOk();
        }
    }

    public function test_le_anteprime_non_persistono_dati_di_esempio(): void
    {
        $this->actingAs($this->admin)
            ->get(route('email-anteprime.show', ['codice' => 'pagamento_quota']))
            ->assertOk();

        $this->assertDatabaseCount('soci', 0);
        $this->assertDatabaseCount('quote', 0);
        $this->assertDatabaseCount('ricevute', 0);
    }

    public function test_anteprima_brandizzata_con_il_nome_del_proprio_tenant(): void
    {
        $altra = Associazione::factory()->create(['nome' => 'Tenant Estraneo SRL']);
        $this->creaAdminPer($altra);

        $response = $this->actingAs($this->admin)
            ->get(route('email-anteprime.show', ['codice' => 'pagamento_quota']));

        $response->assertOk();
        $response->assertSee(e($this->associazione->nome), false);
        $response->assertDontSee('Tenant Estraneo SRL');
    }

    public function test_codice_inesistente_restituisce_404(): void
    {
        $this->actingAs($this->admin)
            ->get(route('email-anteprime.show', ['codice' => 'non_esiste']))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->get(route('email-anteprime.index', ['codice' => 'non_esiste']))
            ->assertNotFound();
    }

    public function test_utente_senza_permesso_email_view_non_accede(): void
    {
        $senzaPermessi = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        // Nessun ruolo assegnato: nessun permesso email.view

        $this->actingAs($senzaPermessi)
            ->get(route('email-anteprime.index'))
            ->assertForbidden();

        $this->actingAs($senzaPermessi)
            ->get(route('email-anteprime.show', ['codice' => 'pagamento_quota']))
            ->assertForbidden();
    }

    public function test_operatore_vede_ma_non_invia_la_prova(): void
    {
        $operatore = $this->creaOperatore();

        $this->actingAs($operatore)
            ->get(route('email-anteprime.index'))
            ->assertOk();

        // operatore ha email.view ma NON email.create
        $this->actingAs($operatore)
            ->post(route('email-anteprime.prova', ['codice' => 'pagamento_quota']))
            ->assertForbidden();
    }

    public function test_admin_invia_la_prova_alla_propria_email(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->admin)
            ->post(route('email-anteprime.prova', ['codice' => 'pagamento_quota']));

        $response->assertRedirect(route('email-anteprime.index', ['codice' => 'pagamento_quota']));
        $response->assertSessionHas('success');

        Mail::assertSent(ProvaEmail::class, function (ProvaEmail $mail): bool {
            return $mail->hasTo($this->admin->email)
                && str_starts_with($mail->envelope()->subject, '[Prova]');
        });
    }

    public function test_prova_su_codice_inesistente_restituisce_404(): void
    {
        Mail::fake();

        $this->actingAs($this->admin)
            ->post(route('email-anteprime.prova', ['codice' => 'non_esiste']))
            ->assertNotFound();

        Mail::assertNothingSent();
    }

    public function test_ospite_non_autenticato_viene_rediretto_al_login(): void
    {
        $this->get(route('email-anteprime.index'))->assertRedirect();
    }
}
