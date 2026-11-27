<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avvisi tenuti da parte per il riepilogo (ora | giorno): una riga per notifica e destinatario.
 * `id_utente` = 0 per i destinatari email e `email` = '' per gli utenti: colonne NOT NULL perché
 * in MariaDB i NULL sono sempre distinti e l'unique non proteggerebbe dai doppioni.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avvisi_in_attesa', function (Blueprint $table) {
            $table->id();
            $table->string('id_notifica', 36);
            $table->string('destinatario_tipo', 10);
            $table->unsignedBigInteger('id_utente')->default(0);
            $table->string('email', 255)->default('');
            $table->string('categoria', 30);
            $table->string('ritmo', 10);
            $table->unsignedBigInteger('id_segnalazione');
            $table->string('tipo', 80);
            $table->longText('notifica');
            $table->string('giro', 36)->nullable();
            $table->unsignedTinyInteger('tentativi')->default(0);
            $table->timestamp('inviata_at')->nullable();
            $table->timestamp('scartata_at')->nullable();
            $table->timestamps();

            $table->unique(['id_notifica', 'destinatario_tipo', 'id_utente', 'email'], 'avvisi_attesa_unico');
            $table->index(['inviata_at', 'scartata_at', 'ritmo'], 'avvisi_attesa_stato');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avvisi_in_attesa');
    }
};
