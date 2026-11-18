<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1.0.9: invito a collegare Telegram in cima agli elenchi; "Ignora" lo nasconde fino a questa data
 * (30 giorni), uguale su ogni dispositivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'telegram_invito_nascosto_fino')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('telegram_invito_nascosto_fino')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('telegram_invito_nascosto_fino');
        });
    }
};
