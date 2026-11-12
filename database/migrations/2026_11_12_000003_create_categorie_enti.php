<?php

use App\Support\Scuole\CategoriaIniziale;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorie_enti', function (Blueprint $table) {
            $table->id('id_categoria');
            $table->string('nome', 80)->unique();
            $table->unsignedInteger('ordine')->default(0);
            $table->boolean('import_miur')->default(false);
            $table->timestamps();
        });

        Schema::table('istituti', function (Blueprint $table) {
            $table->unsignedBigInteger('id_categoria')->nullable()->after('descrizione');
        });

        $righe = [];
        foreach (DB::table('istituti')->orderBy('id_istituto')->get(['id_istituto', 'fonte_dati', 'tipo', 'tipo_ente']) as $r) {
            $righe[$r->id_istituto] = [$r->fonte_dati, $r->tipo, $r->tipo_ente];
        }
        $nomi = CategoriaIniziale::assegna($righe);

        // "Scuole statali" esiste sempre: l'import MIUR ha cosi una destinazione.
        $elenco = array_values(array_unique([...array_values($nomi), CategoriaIniziale::MIUR]));
        usort($elenco, fn (string $a, string $b) => strcasecmp($a, $b));

        $ids = [];
        $now = now();
        foreach ($elenco as $i => $nome) {
            $ids[mb_strtolower($nome)] = DB::table('categorie_enti')->insertGetId([
                'nome' => $nome,
                'ordine' => $i + 1,
                'import_miur' => $nome === CategoriaIniziale::MIUR,
                'created_at' => $now,
                'updated_at' => $now,
            ], 'id_categoria');
        }

        foreach ($nomi as $idIstituto => $nome) {
            DB::table('istituti')->where('id_istituto', $idIstituto)->update(['id_categoria' => $ids[mb_strtolower($nome)]]);
        }

        Schema::table('istituti', function (Blueprint $table) {
            $table->unsignedBigInteger('id_categoria')->nullable(false)->change();
            $table->foreign('id_categoria')
                ->references('id_categoria')->on('categorie_enti')->restrictOnDelete();
        });

        Schema::table('istituti', function (Blueprint $table) {
            $table->dropIndex('istituti_tipo_ente_idx');
        });
        Schema::table('istituti', function (Blueprint $table) {
            $table->dropColumn(['tipo', 'tipo_ente']);
        });
    }

    public function down(): void
    {
        Schema::table('istituti', function (Blueprint $table) {
            $table->string('tipo', 50)->nullable()->default('Scuola')->after('descrizione');
            $table->string('tipo_ente', 30)->default('scuola')->after('tipo');
            $table->index('tipo_ente', 'istituti_tipo_ente_idx');
        });

        // Un UPDATE per categoria (niente update con join: non portabile tra MariaDB e SQLite).
        foreach (DB::table('categorie_enti')->get() as $categoria) {
            DB::table('istituti')->where('id_categoria', $categoria->id_categoria)->update([
                'tipo' => $categoria->nome,
                'tipo_ente' => $categoria->import_miur ? 'scuola' : 'altro',
            ]);
        }

        Schema::table('istituti', function (Blueprint $table) {
            $table->dropForeign(['id_categoria']);
        });
        Schema::table('istituti', function (Blueprint $table) {
            $table->dropColumn('id_categoria');
        });
        Schema::dropIfExists('categorie_enti');
    }
};
