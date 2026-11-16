<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.0.4: colore della tipologia (nome di un tono di App\Support\Colori), scelto dall'admin.
 * Null = grigio. Le tipologie note ricevono un colore autoesplicativo dal nome.
 */
return new class extends Migration
{
    public const COLORI_INIZIALI = [
        'VERDE E GIARDINI' => 'verde',
        'SFALCI E POTATURE' => 'salvia',
        'IMPIANTO IDRICO' => 'blu',
        'FONTANE PUBBLICHE' => 'azzurro',
        'IMPIANTO ELETTRICO' => 'giallo',
        'IMPIANTO TERMICO' => 'rosso',
        'INFISSI E PORTE' => 'marrone',
        'ARREDI' => 'arancio',
        'MANUTENZIONE EDILE' => 'pietra',
        'RETE TELEFONICA / DATI' => 'viola',
        'GIOCHI' => 'rosa',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('tipologie_segnalazioni', 'colore')) {
            Schema::table('tipologie_segnalazioni', function (Blueprint $table) {
                $table->string('colore', 20)->nullable();
            });
        }

        foreach (self::COLORI_INIZIALI as $descrizione => $colore) {
            DB::table('tipologie_segnalazioni')
                ->whereRaw('UPPER(TRIM(descrizione)) = ?', [$descrizione])
                ->whereNull('colore')
                ->update(['colore' => $colore]);
        }
    }

    public function down(): void
    {
        Schema::table('tipologie_segnalazioni', function (Blueprint $table) {
            $table->dropColumn('colore');
        });
    }
};
