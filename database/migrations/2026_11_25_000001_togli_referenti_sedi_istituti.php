<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1.0.19: referente della sede e dirigente dell'istituto (nomi ereditati dal vecchio programma, mai
 * aggiornati, assenti nei dati aperti MIUR) sono dati inutili per il committente: colonne eliminate,
 * senza ritorno. Restano email e recapiti. Il referente delle ditte (`imprese.referente`) resta.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('plessi', 'referente')) {
            Schema::table('plessi', function (Blueprint $table) {
                $table->dropColumn('referente');
            });
        }
        if (Schema::hasColumn('istituti', 'dirigente')) {
            Schema::table('istituti', function (Blueprint $table) {
                $table->dropColumn('dirigente');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('plessi', 'referente')) {
            Schema::table('plessi', function (Blueprint $table) {
                $table->string('referente', 50)->nullable();
            });
        }
        if (! Schema::hasColumn('istituti', 'dirigente')) {
            Schema::table('istituti', function (Blueprint $table) {
                $table->string('dirigente', 50)->nullable();
            });
        }
    }
};
