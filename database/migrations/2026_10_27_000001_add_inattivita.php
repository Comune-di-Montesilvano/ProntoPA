<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sla_configurazioni', function (Blueprint $table) {
            // Giorni lavorativi senza attività prima dell'avviso e, dopo l'avviso, prima dell'archiviazione.
            // Vuoti = funzione spenta per la regola.
            $table->unsignedSmallInteger('giorni_avviso_inattivita')->nullable()->after('ore_warning');
            $table->unsignedSmallInteger('giorni_archiviazione_inattivita')->nullable()->after('giorni_avviso_inattivita');
        });

        Schema::table('segnalazioni', function (Blueprint $table) {
            $table->timestamp('avviso_inattivita_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sla_configurazioni', function (Blueprint $table) {
            $table->dropColumn(['giorni_avviso_inattivita', 'giorni_archiviazione_inattivita']);
        });
        Schema::table('segnalazioni', function (Blueprint $table) {
            $table->dropColumn('avviso_inattivita_at');
        });
    }
};
