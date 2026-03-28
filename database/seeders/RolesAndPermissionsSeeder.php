<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles/permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Crea i ruoli se non esistono
        $roles = ['admin', 'gestore', 'segnalatore', 'impresa'];
        foreach ($roles as $nome) {
            Role::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
        }

        // Assegna ruoli Spatie agli utenti esistenti in base ai campi boolean legacy
        User::where('amministratore', true)->each(function (User $user) {
            $user->syncRoles(['admin']);
        });

        User::where('amministratore', false)
            ->where('gestore_segnalazioni', true)
            ->each(function (User $user) {
                $user->syncRoles(['gestore']);
            });

        // Gli utenti senza nessun flag boolean sono segnalatori
        User::where('amministratore', false)
            ->where('gestore_segnalazioni', false)
            ->whereDoesntHave('roles')
            ->each(function (User $user) {
                $user->syncRoles(['segnalatore']);
            });
    }
}
