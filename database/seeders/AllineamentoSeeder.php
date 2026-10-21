<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Lanciato dall'entrypoint a OGNI avvio del container: porta su
 * un'installazione esistente i dati di riferimento, i ruoli/permessi e le
 * impostazioni introdotti dagli aggiornamenti. Tutti i seeder chiamati qui
 * devono essere idempotenti e non toccare mai dati già configurati.
 */
class AllineamentoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TabelleRiferimentoSeeder::class,
            RolesAndPermissionsSeeder::class,
            ImpostazioniSeeder::class,
        ]);
    }
}
