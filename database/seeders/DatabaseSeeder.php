<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TabelleRiferimentoSeeder::class,
            IstitutiPlessiSeeder::class,
            ImpostazioniSeeder::class,
            RolesAndPermissionsSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
