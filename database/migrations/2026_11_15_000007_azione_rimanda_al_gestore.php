<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 1.0.0: operaio, caposquadra e ditta possono rimandare il lavoro al gestore
 * (da Assegnata, Assegnata a ditta, Preventivo in attesa). Ha un pannello suo:
 * la riga in db_azioni serve a coerenza e storico, non al menu delle azioni.
 */
return new class extends Migration
{
    public const AZIONE = [
        'id_azione' => 15, 'codice' => 'rimanda', 'descrizione' => 'Rimanda al gestore', 'id_stato_segnalazione' => 2,
        'competenza_azione' => 2, 'colore' => 'warning', 'flag_appalto' => 0, 'flag_operatore' => 0, 'flag_notifica' => 1,
        'ordine' => 85, 'parametri_filtro' => '{"stati":[3,4,5]}',
    ];

    public function up(): void
    {
        if (! DB::table('db_azioni')->where('codice', 'rimanda')->exists()) {
            DB::table('db_azioni')->insertOrIgnore([self::AZIONE]);
        }
    }

    public function down(): void
    {
        DB::table('db_azioni')->where('codice', 'rimanda')->delete();
    }
};
