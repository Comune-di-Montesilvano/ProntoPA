<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_configurazioni', function (Blueprint $table) {
            $table->id('id_sla');
            $table->unsignedBigInteger('id_tipologia_segnalazione')->nullable();
            $table->unsignedBigInteger('id_specializzazione')->nullable();
            $table->unsignedTinyInteger('livello_priorita');
            $table->unsignedInteger('ore_target');
            $table->unsignedInteger('ore_warning');
            $table->string('descrizione', 100)->nullable();

            $table->index(['id_tipologia_segnalazione', 'id_specializzazione', 'livello_priorita'], 'sla_lookup_idx');

            $table->foreign('id_tipologia_segnalazione')
                  ->references('id_tipologia_segnalazione')
                  ->on('tipologie_segnalazioni')
                  ->nullOnDelete();

            $table->foreign('id_specializzazione')
                  ->references('id_specializzazione')
                  ->on('db_specializzazioni')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_configurazioni');
    }
};
