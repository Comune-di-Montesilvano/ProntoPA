<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.0.0: via gruppi di tipologie (le tipologie restano tutte, elenco piatto)
 * e coordinate per segnalazione (la posizione è quella della sede).
 * Senza ritorno.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['tipologie_segnalazioni', 'appalti'] as $tabella) {
            if (Schema::hasColumn($tabella, 'id_gruppo')) {
                Schema::table($tabella, function (Blueprint $table) {
                    $table->dropForeign(['id_gruppo']);
                });
                Schema::table($tabella, fn (Blueprint $table) => $table->dropColumn('id_gruppo'));
            }
        }

        $coordinate = array_values(array_filter(['latitudine', 'longitudine', 'zoom'], fn ($c) => Schema::hasColumn('segnalazioni', $c)));
        if ($coordinate !== []) {
            Schema::table('segnalazioni', fn (Blueprint $table) => $table->dropColumn($coordinate));
        }

        Schema::dropIfExists('gruppi_segnalazioni');

        DB::table('impostazioni')->where('chiave', 'dedup_raggio_metri')->delete();
    }

    public function down(): void
    {
        // Dati eliminati: nessun ritorno (si ripristina dal backup pre-aggiornamento).
    }
};
