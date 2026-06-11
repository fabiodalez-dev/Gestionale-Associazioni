<?php

namespace Tests\Feature\SidebarPages;

use App\Models\Associazione;
use App\Models\EmailQueue;
use App\Models\Utente;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pagina sidebar "Coda Invio Email" (/email/coda).
 * Varianti non coperte da tests/Feature/Email/EmailCodaTest.
 */
class EmailCodaPageTest extends TestCase
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

    private function accoda(array $attributes = []): EmailQueue
    {
        return EmailQueue::create(array_merge([
            'associazione_id' => $this->associazione->id,
            'batch_id' => (string) Str::uuid(),
            'to_email' => 'dest@example.com',
            'to_name' => 'Destinatario',
            'subject' => 'Oggetto in coda',
            'body_html' => '<p>Corpo</p>',
            'template_codice' => 'comunicazione',
            'status' => EmailQueue::STATUS_PENDING,
        ], $attributes));
    }

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get('/email/coda')->assertRedirect('/login');
    }

    public function test_admin_vede_la_pagina_con_titolo(): void
    {
        $this->actingAs($this->admin)
            ->get('/email/coda')
            ->assertOk()
            ->assertSee('Coda Invio Email');
    }

    public function test_isolamento_tenant_le_email_in_coda_di_b_non_sono_visibili_ne_conteggiate(): void
    {
        $this->accoda(['to_email' => 'tenant-a@example.com']);

        $assocB = Associazione::factory()->create();
        $this->accoda([
            'associazione_id' => $assocB->id,
            'to_email' => 'tenant-b@example.com',
        ]);

        $response = $this->actingAs($this->admin)->get('/email/coda');

        $response->assertOk()
            ->assertSee('tenant-a@example.com')
            ->assertDontSee('tenant-b@example.com');
        // Le statistiche contano solo la coda del tenant A
        $this->assertSame(1, $response->viewData('stats')['pending']);
    }

    public function test_la_statistica_inviate_oggi_conta_solo_le_email_inviate_in_giornata(): void
    {
        $this->accoda([
            'to_email' => 'oggi@example.com',
            'status' => EmailQueue::STATUS_SENT,
            'sent_at' => now(),
        ]);
        $this->accoda([
            'to_email' => 'ieri@example.com',
            'status' => EmailQueue::STATUS_SENT,
            'sent_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->admin)->get('/email/coda');

        $response->assertOk();
        $this->assertSame(1, $response->viewData('stats')['inviate_oggi']);
    }

    public function test_operatore_senza_permesso_email_view_riceve_403(): void
    {
        // Operatore creato SENZA assegnare il ruolo spatie: nessun permesso email.view
        $senzaPermessi = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($senzaPermessi)->get('/email/coda')->assertForbidden();
    }
}
