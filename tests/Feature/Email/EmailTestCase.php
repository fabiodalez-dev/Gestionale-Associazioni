<?php

namespace Tests\Feature\Email;

use App\Models\Associazione;
use App\Models\SmtpSetting;
use App\Models\Utente;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Base comune dei test del modulo email: ruoli/permessi spatie + tenant.
 */
abstract class EmailTestCase extends TestCase
{
    use RefreshDatabase;

    protected Associazione $associazione;

    protected Utente $admin;

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

    protected function creaOperatore(?Associazione $associazione = null): Utente
    {
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => ($associazione ?? $this->associazione)->id,
        ]);
        $operatore->assignRole('operatore');

        return $operatore;
    }

    protected function creaAdminPer(Associazione $associazione): Utente
    {
        $admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $associazione->id,
        ]);
        $admin->assignRole('admin_associazione');

        return $admin;
    }

    protected function configuraSmtp(?Associazione $associazione = null): SmtpSetting
    {
        $associazione ??= $this->associazione;

        return SmtpSetting::create([
            'associazione_id' => $associazione->id,
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_user' => 'utente',
            'smtp_pass_encrypted' => 'segretissima',
            'smtp_encryption' => 'tls',
            'from_email' => 'noreply@example.com',
            'from_name' => 'Associazione Test',
            'max_per_hour' => 100,
        ]);
    }
}
