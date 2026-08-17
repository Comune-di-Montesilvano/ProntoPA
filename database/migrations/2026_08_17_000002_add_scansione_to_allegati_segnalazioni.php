<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('allegati_segnalazioni', function (Blueprint $table) {
            // in_attesa: appena caricato, job non ancora passato (o ClamAV
            // non raggiungibile — degrada in silenzio, resta così)
            // pulito / infetto: esito scansione ClamAV
            // errore: scansione tentata ma fallita (non un verdetto)
            $table->string('stato_scansione', 20)->default('in_attesa')->after('dimensione');
            $table->timestamp('scansionato_at')->nullable()->after('stato_scansione');
        });
    }

    public function down(): void
    {
        Schema::table('allegati_segnalazioni', function (Blueprint $table) {
            $table->dropColumn(['stato_scansione', 'scansionato_at']);
        });
    }
};
