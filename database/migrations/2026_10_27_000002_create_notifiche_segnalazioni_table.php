<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Notifiche inviate che riguardano una segnalazione (email, Telegram): scritte da TracciaNotificaInviata.
        Schema::create('notifiche_segnalazioni', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_segnalazione')->index();
            $table->string('tipo', 80);
            $table->string('canale', 30);
            $table->unsignedBigInteger('id_utente')->nullable();
            $table->string('destinatario', 191);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifiche_segnalazioni');
    }
};
