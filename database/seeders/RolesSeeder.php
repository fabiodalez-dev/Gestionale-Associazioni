<?php

namespace Database\Seeders;

use App\Support\RuoliAssociazione;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Crea ruoli e permessi {modulo}.{azione} secondo la matrice di App\Support\RuoliAssociazione.
 * Idempotente: rieseguirlo (anche dopo un aggiornamento) allinea i permessi di ogni ruolo.
 */
class RolesSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (RuoliAssociazione::tuttiIPermessi() as $permesso) {
            Permission::findOrCreate($permesso, 'web');
        }

        foreach (RuoliAssociazione::ruoli() as $ruolo) {
            Role::findOrCreate($ruolo, 'web')->syncPermissions(RuoliAssociazione::permessi($ruolo));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
