<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 0.9.20: Sospendi non fermava la scadenza e Riprendi la ricalcolava dall'apertura, quindi non serviva.
 * Si toglie (filtro stati vuoto, come Archivia) e le sospese tornano allo stato di prima con la scadenza
 * che avevano. Per rimandare si usa "Scadenza…" con un motivo che la scuola vede.
 */
return new class extends Migration
{
    private const SOSPESA = 6;

    public function up(): void
    {
        DB::table('db_azioni')->where('codice', 'sospendi')->update(['parametri_filtro' => json_encode(['stati' => []])]);

        $sospese = DB::table('segnalazioni')
            ->where('id_stato_segnalazione', self::SOSPESA)
            ->whereNull('data_chiusura')
            ->pluck('id_segnalazione');

        foreach ($sospese as $id) {
            $prima = DB::table('stati_segnalazioni')
                ->where('id_segnalazione', $id)
                ->where('id_stato_segnalazione', '!=', self::SOSPESA)
                ->orderByDesc('data_registrazione')
                ->value('id_stato_segnalazione');
            $stato = in_array((int) $prima, [2, 3, 4, 5], true) ? (int) $prima : 2;

            DB::table('segnalazioni')->where('id_segnalazione', $id)->update(['id_stato_segnalazione' => $stato]);
            DB::table('stati_segnalazioni')->insert([
                'id_segnalazione' => $id,
                'id_stato_segnalazione' => $stato,
                'id_utente' => 0,
                'id_utente_collegato' => 0,
                'id_appalto' => 0,
                'data_registrazione' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('db_azioni')->where('codice', 'sospendi')->update(['parametri_filtro' => json_encode(['stati' => [2, 3, 4, 5]])]);
    }
};
