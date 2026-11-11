<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Attivo" (l'ente è usabile: sedi, predelega dall'admin) separato da
 * "accetta richieste di delega via SPID" (compare nella ricerca delle persone
 * esterne). Un asilo nido comunale caricato a mano: attivo, senza richieste.
 *
 * Esistenti a true: comportamento invariato. Non si può dedurre da
 * fonte_dati, le scuole importate dal legacy sono "manuale" ma vere scuole.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('istituti', function (Blueprint $table) {
            $table->boolean('richieste_delega_spid')->default(true)->after('attivo');
        });
    }

    public function down(): void
    {
        Schema::table('istituti', function (Blueprint $table) {
            $table->dropColumn('richieste_delega_spid');
        });
    }
};
