<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('segnalazioni', function (Blueprint $table) {
            $table->string('titolo_generato', 200)->nullable()->after('testo_segnalazione');
            $table->json('triage_suggerito')->nullable()->after('titolo_generato');
            $table->mediumText('embedding')->nullable()->after('triage_suggerito');
        });
    }

    public function down(): void
    {
        Schema::table('segnalazioni', function (Blueprint $table) {
            $table->dropColumn(['titolo_generato', 'triage_suggerito', 'embedding']);
        });
    }
};
