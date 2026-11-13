<?php

namespace App\Services\Tipologie;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unisce la tipologia $da in $in: segnalazioni, regole di scadenza, gestori e appalti passano a $in,
 * poi $da sparisce. SQL puro (niente model): la usa anche la migration che unisce i doppioni.
 */
class UnisciTipologie
{
    public function unisci(int $da, int $in): void
    {
        if ($da === $in) {
            return;
        }

        DB::transaction(function () use ($da, $in) {
            DB::table('segnalazioni')->where('id_tipologia_segnalazione', $da)->update(['id_tipologia_segnalazione' => $in]);
            DB::table('sla_configurazioni')->where('id_tipologia_segnalazione', $da)->update(['id_tipologia_segnalazione' => $in]);

            foreach (['gestori_tipologie' => 'id_utente', 'appalti_tipologie' => 'id_appalto'] as $pivot => $chiave) {
                if (! Schema::hasTable($pivot)) {
                    continue;
                }
                // Chi le aveva tutte e due ne tiene una sola (chiave primaria composta).
                $giaIn = DB::table($pivot)->where('id_tipologia_segnalazione', $in)->pluck($chiave)->all();
                DB::table($pivot)->where('id_tipologia_segnalazione', $da)->whereIn($chiave, $giaIn)->delete();
                DB::table($pivot)->where('id_tipologia_segnalazione', $da)->update(['id_tipologia_segnalazione' => $in]);
            }

            DB::table('tipologie_segnalazioni')->where('id_tipologia_segnalazione', $da)->delete();
        });
    }
}
