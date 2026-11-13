<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.0.0: via le specializzazioni. Le regole di scadenza con specializzazione spariscono
 * se c'è già quella generica per tipologia + priorità, altrimenti diventano quella generica
 * (la prima per id, se più d'una). Le scadenze delle segnalazioni aperte non si ricalcolano.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sla_configurazioni', 'id_specializzazione')) {
            $this->convertiRegole();

            Schema::table('sla_configurazioni', function (Blueprint $table) {
                // Prima il nuovo indice: su MariaDB la FK della tipologia ha bisogno di un indice.
                $table->index(['id_tipologia_segnalazione', 'livello_priorita'], 'sla_tipologia_priorita_idx');
            });
            Schema::table('sla_configurazioni', function (Blueprint $table) {
                $table->dropForeign(['id_specializzazione']);
            });
            Schema::table('sla_configurazioni', function (Blueprint $table) {
                $table->dropIndex('sla_lookup_idx');
            });
            Schema::table('sla_configurazioni', fn (Blueprint $table) => $table->dropColumn('id_specializzazione'));
        }

        if (Schema::hasColumn('segnalazioni', 'id_specializzazione')) {
            Schema::table('segnalazioni', function (Blueprint $table) {
                $table->dropForeign(['id_specializzazione']);
            });
            Schema::table('segnalazioni', fn (Blueprint $table) => $table->dropColumn('id_specializzazione'));
        }

        Schema::dropIfExists('imprese_specializzazioni');
        Schema::dropIfExists('db_specializzazioni');
    }

    public function convertiRegole(): void
    {
        DB::table('sla_configurazioni')->whereNotNull('id_specializzazione')->orderBy('id_sla')->get()
            ->each(function ($r) {
                $generica = DB::table('sla_configurazioni')
                    ->whereNull('id_specializzazione')
                    ->where('livello_priorita', $r->livello_priorita)
                    ->when($r->id_tipologia_segnalazione === null,
                        fn ($q) => $q->whereNull('id_tipologia_segnalazione'),
                        fn ($q) => $q->where('id_tipologia_segnalazione', $r->id_tipologia_segnalazione))
                    ->exists();

                $riga = DB::table('sla_configurazioni')->where('id_sla', $r->id_sla);
                $generica ? $riga->delete() : $riga->update(['id_specializzazione' => null]);
            });
    }

    public function down(): void
    {
        // Dati eliminati: nessun ritorno (si ripristina dal backup pre-aggiornamento).
    }
};
