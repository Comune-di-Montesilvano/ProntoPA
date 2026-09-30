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

        // Nessun utente creato qui: i dipendenti (admin compreso) entrano da
        // Active Directory, il primo admin è chi sta in PRONTOPA_ADMIN. In
        // sviluppo: LDAP_HOST=mock → mock.admin/mock.admin.
    }
}
