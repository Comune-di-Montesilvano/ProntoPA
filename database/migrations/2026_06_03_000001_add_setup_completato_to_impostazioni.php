<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Per installazioni esistenti (con utenti), il setup è già completato.
        // Per installazioni fresche (nessun utente), il wizard deve partire.
        $valore = DB::table('users')->exists() ? '1' : '0';

        DB::table('impostazioni')->insertOrIgnore([
            'chiave'      => 'setup_completato',
            'valore'      => $valore,
            'tipo'        => 'boolean',
            'gruppo'      => 'sistema',
            'descrizione' => 'Setup iniziale completato (1=sì, 0=mostra wizard al primo avvio)',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('impostazioni')->where('chiave', 'setup_completato')->delete();
    }
};
