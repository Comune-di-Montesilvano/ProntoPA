<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Prima della 0.9.19 la fine lavori era "c'è una foto dopo". Ora conta solo fine_lavori_at
 * (azzerato a ogni assegnazione): lo ricaviamo dalla foto più recente per le segnalazioni
 * ancora assegnate, così chi aveva già rendicontato non deve rifarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ultime = DB::table('allegati_segnalazioni')
            ->where('fase', 'dopo')
            ->groupBy('id_segnalazione')
            ->selectRaw('id_segnalazione, MAX(created_at) as ultima');

        DB::table('segnalazioni')
            ->whereIn('id_stato_segnalazione', [3, 4])
            ->whereNull('data_chiusura')
            ->whereNull('fine_lavori_at')
            ->joinSub($ultime, 'u', 'u.id_segnalazione', '=', 'segnalazioni.id_segnalazione')
            ->select('segnalazioni.id_segnalazione', 'u.ultima')
            ->orderBy('segnalazioni.id_segnalazione')
            ->get()
            // Foto di un'assegnazione precedente: dopo è stata riassegnata, il lavoro nuovo non è rendicontato.
            ->reject(fn ($r) => DB::table('stati_segnalazioni')
                ->where('id_segnalazione', $r->id_segnalazione)
                ->whereIn('id_stato_segnalazione', [2, 3, 4])
                ->where('data_registrazione', '>', $r->ultima)
                ->exists())
            ->each(fn ($r) => DB::table('segnalazioni')
                ->where('id_segnalazione', $r->id_segnalazione)
                ->update(['fine_lavori_at' => $r->ultima]));
    }

    public function down(): void
    {
        // Dato ricavato: nessun ripristino necessario.
    }
};
