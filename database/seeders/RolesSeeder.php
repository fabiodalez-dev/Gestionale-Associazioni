<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Crea i 3 ruoli applicativi e i permessi base per modulo (<modulo>.<azione>).
 */
class RolesSeeder extends Seeder
{
    /** @var list<string> */
    public const MODULES = [
        'soci',
        'tessere',
        'quote',
        'eventi',
        'email',
        'documenti',
        'struttura',
        'api',
    ];

    /** @var list<string> */
    public const ACTIONS = ['view', 'create', 'update', 'delete'];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [];
        foreach (self::MODULES as $module) {
            foreach (self::ACTIONS as $action) {
                $permissions[] = Permission::findOrCreate("{$module}.{$action}", 'web');
            }
        }

        // super_admin: tutti i permessi
        $superAdmin = Role::findOrCreate('super_admin', 'web');
        $superAdmin->syncPermissions($permissions);

        // admin_associazione: tutti i permessi (limitati al proprio tenant dal global scope)
        $adminAssociazione = Role::findOrCreate('admin_associazione', 'web');
        $adminAssociazione->syncPermissions($permissions);

        // operatore: gestione operativa, senza configurazione/struttura/API
        $operatore = Role::findOrCreate('operatore', 'web');
        $operatore->syncPermissions([
            'soci.view', 'soci.create', 'soci.update', 'soci.delete',
            'tessere.view', 'tessere.create', 'tessere.update', 'tessere.delete',
            'quote.view', 'quote.create', 'quote.update', 'quote.delete',
            'eventi.view', 'eventi.create', 'eventi.update', 'eventi.delete',
            'documenti.view', 'documenti.create',
            'email.view',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
