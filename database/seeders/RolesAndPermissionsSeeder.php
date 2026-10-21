<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles/permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Crea i ruoli se non esistono
        $roles = ['admin', 'gestore', 'operaio', 'segnalatore', 'impresa'];
        foreach ($roles as $nome) {
            Role::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
        }

        // Permission: inserimento segnalazioni per conto di terzi (URP)
        $perConto = Permission::firstOrCreate([
            'name'       => 'segnalazioni.per-conto',
            'guard_name' => 'web',
        ]);
        Role::findByName('admin')->givePermissionTo($perConto);
        Role::findByName('gestore')->givePermissionTo($perConto);

        // Gira a ogni avvio (AllineamentoSeeder): mai toccare i ruoli degli
        // utenti, li decidono il login AD (dai gruppi) e l'admin.
    }
}
