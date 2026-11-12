<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('profili') && Schema::hasColumn('users', 'id_profilo')) {
            $perdono = DB::table('users as u')
                ->join('profili as p', 'p.id_profilo', '=', 'u.id_profilo')
                ->where('u.attivo', 1)
                ->where('p.limita_istituto', 1)
                ->pluck('u.username')
                ->all();

            if ($perdono !== []) {
                Log::info('Profili rimossi: utenti attivi che perdono la limitazione a un istituto', ['username' => $perdono]);
            }
        }

        if (Schema::hasColumn('users', 'id_profilo')) {
            try {
                Schema::table('users', fn (Blueprint $t) => $t->dropForeign(['id_profilo']));
            } catch (\Throwable) {
                // nessuna FK: la colonna nasceva senza vincolo
            }
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('id_profilo'));
        }

        Schema::dropIfExists('profili');
    }

    public function down(): void
    {
        if (! Schema::hasTable('profili')) {
            Schema::create('profili', function (Blueprint $table) {
                $table->id('id_profilo');
                $table->string('descrizione', 50)->nullable();
                $table->boolean('limita_istituto')->default(false);
                $table->unsignedBigInteger('id_istituto')->nullable();
                $table->tinyInteger('limita_segnalazioni')->nullable();
                $table->unsignedBigInteger('id_tipologia_segnalazione')->nullable();
            });
        }

        if (! Schema::hasColumn('users', 'id_profilo')) {
            Schema::table('users', fn (Blueprint $t) => $t->unsignedBigInteger('id_profilo')->nullable());
        }
    }
};
