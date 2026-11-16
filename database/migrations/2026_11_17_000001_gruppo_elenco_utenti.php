<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1.0.5: "Raggruppa per" scelto da ciascun utente nell'elenco gestione, ricordato su ogni dispositivo.
 * null = predefinito (sede, poi tipologia); '' = nessun gruppo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'gruppo_elenco')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('gruppo_elenco', 20)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('gruppo_elenco');
        });
    }
};
