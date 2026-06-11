<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adesioni_segnalazioni', function (Blueprint $table) {
            $table->id('id_adesione');
            $table->unsignedBigInteger('id_segnalazione');
            $table->unsignedBigInteger('id_utente');
            $table->string('segnalante', 255)->nullable();
            $table->string('telefono', 50)->nullable();
            $table->string('email', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('id_segnalazione')
                ->references('id_segnalazione')
                ->on('segnalazioni')
                ->cascadeOnDelete();

            $table->foreign('id_utente')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->index('id_segnalazione');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adesioni_segnalazioni');
    }
};
