<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imprese', function (Blueprint $table) {
            $table->id('id_impresa');
            $table->string('ragione_sociale', 100)->nullable();
            $table->string('partita_iva', 20)->nullable();
            $table->string('referente', 50)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('cellulare', 20)->nullable();
            $table->string('password')->nullable();          // password accesso portale imprese
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('imprese_specializzazioni', function (Blueprint $table) {
            $table->id('id_associazione');
            $table->unsignedBigInteger('id_impresa');
            $table->unsignedBigInteger('id_specializzazione');

            $table->foreign('id_impresa')->references('id_impresa')->on('imprese')->cascadeOnDelete();
            $table->foreign('id_specializzazione')->references('id_specializzazione')->on('db_specializzazioni')->cascadeOnDelete();
        });

        Schema::create('appalti', function (Blueprint $table) {
            $table->id('id_appalto');
            $table->unsignedBigInteger('id_gruppo');
            $table->string('descrizione', 100)->nullable();
            $table->unsignedBigInteger('id_impresa')->nullable();
            $table->string('CIG', 50)->nullable();
            $table->decimal('importo_appalto', 10, 2)->nullable();
            $table->boolean('valido')->default(true);
            $table->timestamps();

            $table->foreign('id_gruppo')->references('id_gruppo')->on('gruppi_segnalazioni')->restrictOnDelete();
            $table->foreign('id_impresa')->references('id_impresa')->on('imprese')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appalti');
        Schema::dropIfExists('imprese_specializzazioni');
        Schema::dropIfExists('imprese');
    }
};
