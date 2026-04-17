<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('impostazioni', function (Blueprint $table) {
            $table->boolean('publication_enabled')
                  ->default(false)
                  ->comment('Abilita pubblicazione automatica segnalazioni')
                  ->after('valore');

            $table->unsignedTinyInteger('publication_auto_state_id')
                  ->nullable()
                  ->comment('ID stato a cui trigger pubblicazione automatica (es. 2=In carico)')
                  ->after('publication_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('impostazioni', function (Blueprint $table) {
            $table->dropColumn(['publication_enabled', 'publication_auto_state_id']);
        });
    }
};
