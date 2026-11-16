<?php

use App\Models\NotaSegnalazione;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1.0.2: da dove viene una nota (null = scritta da una persona). Le note automatiche
 * già a DB si riconoscono dal prefisso del testo, che resta com'è.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('note_segnalazioni', function (Blueprint $table) {
            $table->string('origine', 20)->nullable()->after('visibile_impresa');
        });

        NotaSegnalazione::riempiOrigini();
    }

    public function down(): void
    {
        Schema::table('note_segnalazioni', function (Blueprint $table) {
            $table->dropColumn('origine');
        });
    }
};
