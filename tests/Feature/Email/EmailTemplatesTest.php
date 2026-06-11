<?php

namespace Tests\Feature\Email;

use App\Models\Associazione;
use App\Models\EmailTemplate;
use App\Services\Email\DefaultEmailTemplates;

class EmailTemplatesTest extends EmailTestCase
{
    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get(route('email-templates.index'))->assertRedirect('/login');
    }

    public function test_prima_apertura_seedda_gli_8_template_di_default(): void
    {
        $this->actingAs($this->admin)
            ->get(route('email-templates.index'))
            ->assertOk()
            ->assertViewIs('email.templates');

        $this->assertSame(
            8,
            EmailTemplate::allTenants()->where('associazione_id', $this->associazione->id)->count()
        );
        $this->assertNotNull(
            EmailTemplate::allTenants()
                ->where('associazione_id', $this->associazione->id)
                ->where('codice', 'benvenuto')
                ->first()
        );
    }

    public function test_admin_aggiorna_oggetto_e_stato_del_template(): void
    {
        DefaultEmailTemplates::seedFor($this->associazione->id);

        $response = $this->actingAs($this->admin)->put(route('email-templates.update', ['codice' => 'benvenuto']), [
            'oggetto' => 'Ciao {NOME}, benvenuto!',
            'corpo_html' => '<p>Benvenuto {NOME} {COGNOME}</p>',
            'corpo_json' => null,
            // 'attivo' assente => disattivato
        ]);

        $response->assertRedirect(route('email-templates.index', ['t' => 'benvenuto']));
        $response->assertSessionHas('success');

        $template = EmailTemplate::allTenants()
            ->where('associazione_id', $this->associazione->id)
            ->where('codice', 'benvenuto')
            ->first();

        $this->assertSame('Ciao {NOME}, benvenuto!', $template->oggetto);
        $this->assertFalse($template->attivo);
        $this->assertStringContainsString('{NOME}', $template->corpo_html);
    }

    public function test_il_corpo_html_viene_sanitizzato_con_purifier(): void
    {
        DefaultEmailTemplates::seedFor($this->associazione->id);

        $this->actingAs($this->admin)->put(route('email-templates.update', ['codice' => 'comunicazione']), [
            'oggetto' => 'Oggetto',
            'corpo_html' => '<p onclick="evil()">Ciao {NOME}</p><script>alert(1)</script><a href="javascript:evil()">link</a><style>.x{color:red}</style>',
            'attivo' => 1,
        ])->assertSessionHasNoErrors();

        $template = EmailTemplate::allTenants()
            ->where('associazione_id', $this->associazione->id)
            ->where('codice', 'comunicazione')
            ->first();

        $this->assertStringNotContainsString('<script', $template->corpo_html);
        $this->assertStringNotContainsString('onclick', $template->corpo_html);
        $this->assertStringNotContainsString('javascript:', $template->corpo_html);
        // Placeholder e blocco <style> di GrapesJS preservati
        $this->assertStringContainsString('Ciao {NOME}', $template->corpo_html);
        $this->assertStringContainsString('<style>.x{color:red}</style>', $template->corpo_html);
    }

    public function test_validazione_oggetto_obbligatorio(): void
    {
        DefaultEmailTemplates::seedFor($this->associazione->id);

        $this->actingAs($this->admin)->put(route('email-templates.update', ['codice' => 'benvenuto']), [
            'oggetto' => '',
            'corpo_html' => '<p>x</p>',
        ])->assertSessionHasErrors('oggetto');
    }

    public function test_operatore_puo_consultare_ma_non_modificare(): void
    {
        DefaultEmailTemplates::seedFor($this->associazione->id);
        $operatore = $this->creaOperatore();

        $this->actingAs($operatore)->get(route('email-templates.index'))->assertOk();

        $this->actingAs($operatore)->put(route('email-templates.update', ['codice' => 'benvenuto']), [
            'oggetto' => 'Hack',
            'corpo_html' => '<p>x</p>',
        ])->assertForbidden();
    }

    public function test_isolamento_tenant_l_aggiornamento_non_tocca_i_template_di_b(): void
    {
        DefaultEmailTemplates::seedFor($this->associazione->id);

        $assocB = Associazione::factory()->create();
        DefaultEmailTemplates::seedFor($assocB->id);

        $this->actingAs($this->admin)->put(route('email-templates.update', ['codice' => 'benvenuto']), [
            'oggetto' => 'Oggetto modificato da A',
            'corpo_html' => '<p>nuovo</p>',
            'attivo' => 1,
        ]);

        $templateB = EmailTemplate::allTenants()
            ->where('associazione_id', $assocB->id)
            ->where('codice', 'benvenuto')
            ->first();

        $this->assertSame('Benvenuto in {NOME_ASSOCIAZIONE}, {NOME}!', $templateB->oggetto);
    }
}
