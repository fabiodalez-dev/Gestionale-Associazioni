<?php

namespace Tests\Feature\SidebarPages;

use App\Models\Associazione;
use App\Models\EmailLog;
use App\Models\Utente;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pagina sidebar "Storico Email" (/email/log).
 * Varianti non coperte da tests/Feature/Email/EmailLogTest.
 */
class EmailLogPageTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);

        $this->associazione = Associazione::factory()->create();
        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $this->admin->assignRole('admin_associazione');
    }

    private function logga(array $attributes = []): EmailLog
    {
        return EmailLog::create(array_merge([
            'associazione_id' => $this->associazione->id,
            'batch_id' => (string) Str::uuid(),
            'to_email' => 'dest@example.com',
            'to_name' => 'Destinatario',
            'subject' => 'Oggetto loggato',
            'template_codice' => 'comunicazione',
            'body_html' => '<p>Corpo</p>',
            'status' => EmailLog::STATUS_SENT,
            'sent_at' => now(),
        ], $attributes));
    }

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get('/email/log')->assertRedirect('/login');
    }

    public function test_admin_vede_la_pagina_con_titolo(): void
    {
        $this->actingAs($this->admin)
            ->get('/email/log')
            ->assertOk()
            ->assertSee('Storico Email');
    }

    public function test_isolamento_tenant_paginazione_e_filtro_template_contano_solo_i_log_di_a(): void
    {
        $this->logga(['to_email' => 'a1@example.com']);
        $this->logga(['to_email' => 'a2@example.com']);

        $assocB = Associazione::factory()->create();
        $this->logga([
            'associazione_id' => $assocB->id,
            'to_email' => 'b1@example.com',
            'template_codice' => 'solo_di_b',
        ]);

        $response = $this->actingAs($this->admin)->get('/email/log');

        $response->assertOk();
        $this->assertSame(2, $response->viewData('logs')->total());
        // Il dropdown dei template non espone i codici usati dal tenant B
        $this->assertFalse($response->viewData('templateCodes')->contains('solo_di_b'));
    }

    public function test_filtro_per_intervallo_di_date_mostra_solo_le_email_nel_periodo(): void
    {
        $this->logga([
            'to_email' => 'vecchia@example.com',
            'sent_at' => now()->subDays(10),
        ]);
        $this->logga([
            'to_email' => 'recente@example.com',
            'sent_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('email-log.index', ['from' => now()->subDays(2)->toDateString()]))
            ->assertOk()
            ->assertSee('recente@example.com')
            ->assertDontSee('vecchia@example.com');

        $this->actingAs($this->admin)
            ->get(route('email-log.index', ['to' => now()->subDays(5)->toDateString()]))
            ->assertOk()
            ->assertSee('vecchia@example.com')
            ->assertDontSee('recente@example.com');
    }

    public function test_operatore_senza_permesso_email_view_riceve_403(): void
    {
        // Operatore creato SENZA assegnare il ruolo spatie: nessun permesso email.view
        $senzaPermessi = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($senzaPermessi)->get('/email/log')->assertForbidden();
    }
}
