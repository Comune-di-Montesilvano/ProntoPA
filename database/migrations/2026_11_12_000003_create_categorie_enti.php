<?php

use App\Support\Scuole\CategoriaIniziale;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Ripartibile: su MariaDB il DDL è in autocommit, un fallimento a metà lascia lo schema in parte cambiato. */
    public function up(): void
    {
        if (! Schema::hasTable('categorie_enti')) {
            Schema::create('categorie_enti', function (Blueprint $table) {
                $table->id('id_categoria');
                $table->string('nome', 80)->unique();
                $table->unsignedInteger('ordine')->default(0);
                $table->boolean('import_miur')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('istituti', 'id_categoria')) {
            Schema::table('istituti', function (Blueprint $table) {
                $table->unsignedBigInteger('id_categoria')->nullable()->after('descrizione');
            });
        }

        // Le colonne legacy esistono solo se non sono già state eliminate da un giro precedente.
        if (Schema::hasColumn('istituti', 'tipo') && Schema::hasColumn('istituti', 'tipo_ente')) {
            $righe = [];
            foreach (DB::table('istituti')->whereNull('id_categoria')->orderBy('id_istituto')->get(['id_istituto', 'fonte_dati', 'tipo', 'tipo_ente']) as $r) {
                $righe[$r->id_istituto] = [$r->fonte_dati, $r->tipo, $r->tipo_ente];
            }
            $nomi = CategoriaIniziale::assegna($righe);

            // "Scuole statali" esiste sempre: l'import MIUR ha cosi una destinazione.
            $elenco = array_values(array_unique([CategoriaIniziale::MIUR, ...array_values($nomi)]));
            usort($elenco, fn (string $a, string $b) => strcasecmp($a, $b));

            $ids = [];
            foreach ($elenco as $i => $nome) {
                $ids[mb_strtolower($nome)] = CategoriaIniziale::trovaOCrea($nome, $i + 1, $nome === CategoriaIniziale::MIUR);
            }

            foreach ($nomi as $idIstituto => $nome) {
                DB::table('istituti')->where('id_istituto', $idIstituto)->update(['id_categoria' => $ids[mb_strtolower($nome)]]);
            }
        }

        Schema::table('istituti', function (Blueprint $table) {
            $table->unsignedBigInteger('id_categoria')->nullable(false)->change();
        });

        $haForeign = collect(Schema::getForeignKeys('istituti'))
            ->contains(fn (array $fk) => $fk['columns'] === ['id_categoria']);
        if (! $haForeign) {
            Schema::table('istituti', function (Blueprint $table) {
                $table->foreign('id_categoria')
                    ->references('id_categoria')->on('categorie_enti')->restrictOnDelete();
            });
        }

        if (Schema::hasIndex('istituti', 'istituti_tipo_ente_idx')) {
            Schema::table('istituti', function (Blueprint $table) {
                $table->dropIndex('istituti_tipo_ente_idx');
            });
        }
        $daEliminare = array_values(array_filter(['tipo', 'tipo_ente'], fn (string $c) => Schema::hasColumn('istituti', $c)));
        if ($daEliminare !== []) {
            Schema::table('istituti', function (Blueprint $table) use ($daEliminare) {
                $table->dropColumn($daEliminare);
            });
        }
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
