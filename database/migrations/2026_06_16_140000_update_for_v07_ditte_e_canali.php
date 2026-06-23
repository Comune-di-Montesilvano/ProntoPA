<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('allegati_segnalazioni', function (Blueprint $table) {
            $table->string('fase', 20)->default('prima')->after('id_utente_creazione');
        });

        Schema::table('db_azioni', function (Blueprint $table) {
            $table->boolean('flag_preventivo')->default(false)->after('flag_notifica');
        });

        DB::table('db_azioni')->where('codice', 'presenta_preventivo')->update(['flag_preventivo' => 1]);
    }

    public function down(): void
    {
        Schema::table('db_azioni', function (Blueprint $table) {
            $table->dropColumn('flag_preventivo');
        });

        Schema::table('allegati_segnalazioni', function (Blueprint $table) {
            $table->dropColumn('fase');
        });
    }
};
