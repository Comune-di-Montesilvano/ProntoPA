<?php

use App\Services\Segnalazioni\CoperturaTipologie;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tipologie di cui un gestore si occupa (vede, smista, riceve gli avvisi).
 * "Tutte" copre anche le tipologie future. I gestori già in servizio
 * partono con "tutte": vedono più di prima (prima solo le assegnate) e
 * nessuna tipologia diventa orfana al deploy.
 *
 * users.email nullable: i dipendenti pre-registrati "sulla fiducia" (senza
 * lettura da AD) non hanno ancora un'email fino al primo login.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gestori_tipologie', function (Blueprint $table) {
            $table->foreignId('id_utente')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('id_tipologia_segnalazione');
            $table->foreign('id_tipologia_segnalazione')
                ->references('id_tipologia_segnalazione')->on('tipologie_segnalazioni')
                ->cascadeOnDelete();
            $table->primary(['id_utente', 'id_tipologia_segnalazione']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('gestore_tutte_tipologie')->default(false);
            $table->string('email')->nullable()->change();
        });

        CoperturaTipologie::abilitaTutteAiGestoriEsistenti();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('gestore_tutte_tipologie');
        });

        Schema::dropIfExists('gestori_tipologie');
    }
};
