<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('segnalazioni', function (Blueprint $table) {
            $table->boolean('segnalazione_urgente')->default(false)->after('id_provenienza');
            $table->unsignedTinyInteger('livello_priorita')->default(2)->after('segnalazione_urgente');
            $table->unsignedBigInteger('id_specializzazione')->nullable()->after('livello_priorita');
            $table->tinyInteger('ubicazione_tipo')->nullable()->after('id_specializzazione');

            $table->index('livello_priorita');
            $table->foreign('id_specializzazione')
                  ->references('id_specializzazione')
                  ->on('db_specializzazioni')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('segnalazioni', function (Blueprint $table) {
            $table->dropForeign(['id_specializzazione']);
            $table->dropIndex(['livello_priorita']);
            $table->dropColumn(['segnalazione_urgente', 'livello_priorita', 'id_specializzazione', 'ubicazione_tipo']);
        });
    }
};
