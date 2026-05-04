<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allegati_segnalazioni', function (Blueprint $table) {
            $table->id('id_allegato');
            $table->unsignedBigInteger('id_segnalazione');
            $table->string('percorso');
            $table->string('tipo', 191);
            $table->string('nome_originale', 191);
            $table->unsignedBigInteger('dimensione')->default(0);
            $table->unsignedBigInteger('id_utente_creazione');
            $table->timestamps();

            $table->foreign('id_segnalazione')
                ->references('id_segnalazione')
                ->on('segnalazioni')
                ->cascadeOnDelete();

            $table->foreign('id_utente_creazione')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->index('id_utente_creazione');
            $table->index('tipo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allegati_segnalazioni');
    }
};
