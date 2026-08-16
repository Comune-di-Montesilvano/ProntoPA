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
        ]);

        // Se SETUP_TOKEN è configurato, l'admin va creato dal wizard /setup
        // (token + email + password + OTP), non da qui con la password in
        // chiaro da .env. Senza SETUP_TOKEN, comportamento legacy invariato.
        if (blank(config('app.setup_token'))) {
            $this->call(AdminUserSeeder::class);
        }
    }
}
