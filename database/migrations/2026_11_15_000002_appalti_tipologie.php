<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.0.0: gli appalti si legano alle tipologie (come i gestori), non più ai gruppi.
 * Ogni appalto prende le tipologie del suo gruppo; gruppo senza tipologie → tutte.
 * SQL puro: gira anche nel ripristino di backup vecchi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appalti_tipologie', function (Blueprint $table) {
            $table->unsignedBigInteger('id_appalto');
            $table->unsignedBigInteger('id_tipologia_segnalazione');
            $table->primary(['id_appalto', 'id_tipologia_segnalazione']);
            $table->foreign('id_appalto')->references('id_appalto')->on('appalti')->cascadeOnDelete();
            $table->foreign('id_tipologia_segnalazione')->references('id_tipologia_segnalazione')->on('tipologie_segnalazioni')->cascadeOnDelete();
        });

        Schema::table('appalti', function (Blueprint $table) {
            $table->boolean('tutte_tipologie')->default(false);
            $table->unsignedBigInteger('id_gruppo')->nullable()->change();
        });

        $this->converti();

        // Dopo la conversione: UnisciTipologie sposta anche le righe di appalti_tipologie.
        (require __DIR__.'/2026_11_15_000001_tipologie_gestibili.php')->unisciSfalci();
    }

    /** Idempotente: salta gli appalti già convertiti (con tipologie o "tutte"). */
    public function converti(): void
    {
        $giaFatti = DB::table('appalti_tipologie')->distinct()->pluck('id_appalto')->all();

        DB::table('appalti')
            ->where('tutte_tipologie', false)
            ->whereNotIn('id_appalto', $giaFatti)
            ->orderBy('id_appalto')
            ->get(['id_appalto', 'id_gruppo'])
            ->each(function ($a) {
                $tipologie = $a->id_gruppo
                    ? DB::table('tipologie_segnalazioni')->where('id_gruppo', $a->id_gruppo)->pluck('id_tipologia_segnalazione')
                    : collect();

                if ($tipologie->isEmpty()) {
                    DB::table('appalti')->where('id_appalto', $a->id_appalto)->update(['tutte_tipologie' => true]);

                    return;
                }

                DB::table('appalti_tipologie')->insertOrIgnore($tipologie->map(fn ($id) => [
                    'id_appalto' => $a->id_appalto,
                    'id_tipologia_segnalazione' => $id,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::table('appalti', fn (Blueprint $table) => $table->dropColumn('tutte_tipologie'));
        Schema::dropIfExists('appalti_tipologie');
    }
};
