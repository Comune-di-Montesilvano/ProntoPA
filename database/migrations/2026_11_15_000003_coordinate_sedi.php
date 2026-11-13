<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.0.0: la posizione sta sulla sede. Prima stima: mediana delle coordinate delle sue
 * segnalazioni (robusta ai punti sbagliati); le altre si trovano da Admin → Enti e sedi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plessi', function (Blueprint $table) {
            $table->decimal('latitudine', 10, 7)->nullable();
            $table->decimal('longitudine', 10, 7)->nullable();
        });

        $this->mediane();
    }

    public function mediane(): void
    {
        if (! Schema::hasColumn('segnalazioni', 'latitudine')) {
            return;
        }

        DB::table('plessi')->whereNull('latitudine')->orderBy('id_plesso')->pluck('id_plesso')->each(function ($id) {
            $punti = DB::table('segnalazioni')->where('id_plesso', $id)
                ->whereNotNull('latitudine')->whereNotNull('longitudine')
                ->where('latitudine', '!=', 0)->where('longitudine', '!=', 0)
                ->get(['latitudine', 'longitudine']);

            if ($punti->isEmpty()) {
                return;
            }

            DB::table('plessi')->where('id_plesso', $id)->update([
                'latitudine' => $this->mediana($punti->pluck('latitudine')->map(fn ($v) => (float) $v)->all()),
                'longitudine' => $this->mediana($punti->pluck('longitudine')->map(fn ($v) => (float) $v)->all()),
            ]);
        });
    }

    /** @param list<float> $valori */
    private function mediana(array $valori): float
    {
        sort($valori);
        $n = count($valori);
        $m = intdiv($n, 2);

        return $n % 2 ? $valori[$m] : ($valori[$m - 1] + $valori[$m]) / 2;
    }

    public function down(): void
    {
        Schema::table('plessi', fn (Blueprint $table) => $table->dropColumn(['latitudine', 'longitudine']));
    }
};
