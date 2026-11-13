<?php

use App\Services\Tipologie\UnisciTipologie;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.0.0: le tipologie le gestisce l'admin (attiva/disattiva, unisci) e il seeder non le ricrea più.
 * I due "SFALCI E POTATURE" (uno per gruppo) diventano uno: resta il più usato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tipologie_segnalazioni', function (Blueprint $table) {
            $table->boolean('attiva')->default(true);
            // Le tipologie nuove non hanno gruppo (i gruppi spariscono con la migration 000006).
            $table->unsignedBigInteger('id_gruppo')->nullable()->change();
        });
        // L'unione dei due "SFALCI E POTATURE" la fa la 000002 dopo aver convertito gli appalti
        // per gruppo: unendo prima, l'appalto del gruppo della tipologia assorbita la perderebbe.
    }

    public function unisciSfalci(): void
    {
        $doppi = DB::table('tipologie_segnalazioni')
            ->where('descrizione', 'SFALCI E POTATURE')
            ->pluck('id_tipologia_segnalazione')
            ->map(fn ($id) => (int) $id);

        if ($doppi->count() < 2) {
            return;
        }

        $uso = fn (int $id) => DB::table('segnalazioni')->where('id_tipologia_segnalazione', $id)->count();
        $resta = $doppi->sortByDesc(fn (int $id) => [$uso($id), -$id])->first();

        foreach ($doppi->reject(fn (int $id) => $id === $resta) as $id) {
            (new UnisciTipologie)->unisci($id, $resta);
        }
    }

    public function down(): void
    {
        Schema::table('tipologie_segnalazioni', function (Blueprint $table) {
            $table->dropColumn('attiva');
        });
    }
};
