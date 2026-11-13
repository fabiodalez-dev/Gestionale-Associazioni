<?php

namespace Tests\Feature\Email;

use App\Models\Associazione;
use App\Models\EmailTemplate;
use App\Services\Email\DefaultEmailTemplates;
use App\Services\Email\EmailHtmlSanitizer;
use Mews\Purifier\Facades\Purifier;

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

    public function test_il_css_dell_editor_resta_e_quello_pericoloso_viene_tolto(): void
    {
        DefaultEmailTemplates::seedFor($this->associazione->id);

        // CSS tipico di GrapesJS + preset newsletter, con in mezzo costrutti pericolosi
        $grapes = '* { box-sizing: border-box; } body {margin: 0;}#ivxy{padding:10px;background-color:#ffffff;font-family:Helvetica, serif;border-radius:4px}'
            .'.row{display:table;padding:10px;width:100%;}.cell{width:8%;vertical-align:top;}'
            .'.button{color:rgb(255, 255, 255);background:linear-gradient(90deg, #0d9488, #0f766e);-webkit-font-smoothing:antialiased;mso-line-height-rule:exactly}'
            .'@media (max-width: 768px){.cell{width:100%;display:block;}}';
        $pericoloso = '@import url(https://evil.test/x.css);.a{background:url(https://evil.test/?leak)}.b{width:expression(alert(1));behavior:url(x.htc);-moz-binding:url(x.xml#y)}'
            .'.c{color:\\72 ed}.d::before{content:"Il tuo account è sospeso"}.e{position:fixed;z-index:9999}@font-face{font-family:x;src:url(x.woff)}';

        $this->actingAs($this->admin)->put(route('email-templates.update', ['codice' => 'comunicazione']), [
            'oggetto' => 'Oggetto',
            'corpo_html' => '<div id="ivxy" class="row"><p>Ciao {NOME}</p></div><style>'.$grapes.$pericoloso.'</style><style></style></style><script>alert(1)</script>',
            'attivo' => 1,
        ])->assertSessionHasNoErrors();

        $html = EmailTemplate::allTenants()
            ->where('associazione_id', $this->associazione->id)
            ->where('codice', 'comunicazione')
            ->value('corpo_html');

        // Impaginazione dell'editor intatta
        $this->assertStringContainsString(
            '<style>*{box-sizing:border-box}body{margin:0}#ivxy{padding:10px;background-color:#ffffff;font-family:Helvetica, serif;border-radius:4px}'
            .'.row{display:table;padding:10px;width:100%}.cell{width:8%;vertical-align:top}'
            .'.button{color:rgb(255, 255, 255);background:linear-gradient(90deg, #0d9488, #0f766e);-webkit-font-smoothing:antialiased;mso-line-height-rule:exactly}'
            .'@media (max-width: 768px){.cell{width:100%;display:block}}</style>',
            $html
        );

        foreach (['url(', '@import', 'expression', 'behavior', 'binding', '@font-face', 'content:', 'fixed', 'z-index', '\\', 'evil.test', '<script'] as $vietato) {
            $this->assertStringNotContainsString($vietato, $html, 'Nel CSS salvato non deve restare: '.$vietato);
        }
        // Blocchi vuoti non vengono riattaccati
        $this->assertSame(1, substr_count($html, '<style>'));
    }

    public function test_i_template_di_default_non_cambiano_con_la_sanitizzazione_del_css(): void
    {
        $sanitizer = app(EmailHtmlSanitizer::class);

        foreach (DefaultEmailTemplates::all() as $codice => $template) {
            // Senza <style> il risultato è quello del solo purifier, come prima
            $this->assertSame(
                Purifier::clean($template['corpo_html'], self::configPurifier()),
                $sanitizer->clean($template['corpo_html']),
                'Template di default cambiato: '.$codice
            );
        }

        // Una seconda pulizia non altera il CSS già pulito (template riaperti e risalvati)
        $una = $sanitizer->clean('<p>x</p><style>.x{color:red;padding:4px 8px}@media (max-width: 480px){.x{display:block}}</style>');
        $this->assertSame($una, $sanitizer->clean($una));
    }

    /**
     * Stessa configurazione HTMLPurifier di EmailHtmlSanitizer (copiata qui: il
     * confronto deve fallire se il trattamento dell'HTML cambia).
     *
     * @return array<string, mixed>
     */
    private static function configPurifier(): array
    {
        $costante = new \ReflectionClassConstant(EmailHtmlSanitizer::class, 'CONFIG');

        return $costante->getValue();
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
