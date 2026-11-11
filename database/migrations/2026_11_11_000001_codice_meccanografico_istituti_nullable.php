<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Organizzazioni non scolastiche (asili nido comunali, uffici) non hanno
 * codice meccanografico MIUR: la colonna era NOT NULL dallo schema legacy
 * e il form, che la tratta come facoltativa, finiva in errore 500.
 * Allineata a plessi.codice_meccanografico (già nullable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('istituti', function (Blueprint $table) {
            $table->string('codice_meccanografico', 50)->nullable()->default(null)->change();
        });

        DB::table('istituti')->where('codice_meccanografico', '')->update(['codice_meccanografico' => null]);
    }

    public function down(): void
    {
        DB::table('istituti')->whereNull('codice_meccanografico')->update(['codice_meccanografico' => '']);

        Schema::table('istituti', function (Blueprint $table) {
            $table->string('codice_meccanografico', 50)->default('')->nullable(false)->change();
        });
    }
};
