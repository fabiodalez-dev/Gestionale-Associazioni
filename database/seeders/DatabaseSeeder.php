<?php

namespace Database\Seeders;

use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Quota;
use App\Models\Sede;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\TipoSocio;
use App\Models\Utente;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed: ruoli/permessi + dati dimostrativi minimi.
     */
    public function run(): void
    {
        $this->call(RolesSeeder::class);

        // Super admin di piattaforma (nessuna associazione)
        $superAdmin = Utente::factory()->superAdmin()->create([
            'nome' => 'Super',
            'cognome' => 'Admin',
            'email' => 'superadmin@example.com',
        ]);
        $superAdmin->assignRole('super_admin');

        // Associazione demo con sede, tipo socio e admin
        $associazione = Associazione::factory()->create([
            'nome' => 'Associazione Demo',
            'email' => 'info@associazione-demo.test',
        ]);

        $sede = Sede::create([
            'associazione_id' => $associazione->id,
            'nome' => 'Sede Centrale',
            'citta' => $associazione->citta,
            'provincia' => $associazione->provincia,
        ]);

        $tipoSocio = TipoSocio::create([
            'associazione_id' => $associazione->id,
            'nome' => 'Ordinario',
            'descrizione' => 'Socio ordinario',
        ]);

        $admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $associazione->id,
            'nome' => 'Mario',
            'cognome' => 'Rossi',
            'email' => 'admin@associazione-demo.test',
        ]);
        $admin->assignRole('admin_associazione');

        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $associazione->id,
            'email' => 'operatore@associazione-demo.test',
        ]);
        $operatore->assignRole('operatore');

        // Soci demo con tessera e quota
        Socio::factory()
            ->count(5)
            ->sequence(fn ($sequence) => ['numero_socio' => str_pad((string) ($sequence->index + 1), 3, '0', STR_PAD_LEFT)])
            ->create([
                'associazione_id' => $associazione->id,
                'sede_id' => $sede->id,
                'tipo_socio_id' => $tipoSocio->id,
            ])
            ->each(function (Socio $socio): void {
                Tessera::factory()->create(['socio_id' => $socio->id]);
                Quota::factory()->create(['socio_id' => $socio->id]);
            });

        Evento::factory()->create([
            'associazione_id' => $associazione->id,
            'titolo' => 'Assemblea annuale',
        ]);
    }
}
