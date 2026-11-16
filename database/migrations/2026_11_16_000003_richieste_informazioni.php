<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1.0.2: il gestore chiede informazioni a chi ha segnalato. Una riga per richiesta;
 * al massimo una aperta (conclusa_at null) per segnalazione, garantito dal workflow.
 * Stato 11 e azione chiedi_informazioni arrivano da TabelleRiferimentoSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('richieste_informazioni', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('id_segnalazione')->index();
            $table->unsignedBigInteger('id_utente');
            $table->text('testo');
            $table->unsignedTinyInteger('stato_precedente');
            $table->dateTime('termine_at');
            $table->boolean('chiudi_senza_risposta')->default(true);
            $table->dateTime('promemoria_at')->nullable();
            $table->dateTime('conclusa_at')->nullable();
            $table->string('esito', 20)->nullable();
            $table->unsignedBigInteger('id_nota_risposta')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('richieste_informazioni');
    }
};
