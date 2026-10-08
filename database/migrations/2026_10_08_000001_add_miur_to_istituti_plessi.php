<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Anagrafe MIUR (0.8.0): i plessi selezionati dall'admin seguono l'open data
// MIUR (fonte_dati = 'miur'); nomi e indirizzi MIUR superano i 50 caratteri.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plessi', function (Blueprint $table) {
            $table->string('fonte_dati', 30)->default('manuale')->after('recapiti');
            $table->string('nome', 255)->nullable()->change();
            $table->string('indirizzo', 255)->nullable()->change();
        });

        Schema::table('istituti', function (Blueprint $table) {
            $table->string('descrizione', 255)->change();
        });
    }

    public function down(): void
    {
        Schema::table('plessi', function (Blueprint $table) {
            $table->dropColumn('fonte_dati');
        });
    }
};
