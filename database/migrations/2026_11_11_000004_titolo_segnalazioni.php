<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Titolo breve scritto da chi segnala (max 100), al posto del solo titolo
 * generato dall'assistente AI: una colonna sola, l'AI la riempie solo se
 * vuota (segnalazioni da API senza titolo). I titoli AI esistenti passano
 * nella nuova colonna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('segnalazioni', function (Blueprint $table) {
            $table->string('titolo', 100)->nullable()->after('testo_segnalazione');
        });

        DB::table('segnalazioni')->whereNotNull('titolo_generato')->where('titolo_generato', '!=', '')
            ->select(['id_segnalazione', 'titolo_generato'])
            ->chunkById(500, function ($righe) {
                foreach ($righe as $r) {
                    DB::table('segnalazioni')->where('id_segnalazione', $r->id_segnalazione)
                        ->update(['titolo' => mb_substr(trim($r->titolo_generato), 0, 100)]);
                }
            }, 'id_segnalazione');

        Schema::table('segnalazioni', function (Blueprint $table) {
            $table->dropColumn('titolo_generato');
        });
    }

    public function down(): void
    {
        Schema::table('segnalazioni', function (Blueprint $table) {
            $table->string('titolo_generato', 200)->nullable()->after('testo_segnalazione');
        });

        DB::table('segnalazioni')->update(['titolo_generato' => DB::raw('titolo')]);

        Schema::table('segnalazioni', function (Blueprint $table) {
            $table->dropColumn('titolo');
        });
    }
};
