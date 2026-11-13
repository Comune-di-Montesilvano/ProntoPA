<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * 1.0.0: via provenienza (scuola = utente SPID con delega), permesso "per conto"
 * e dati del chiamante (nome, email, telefono) su segnalazioni e adesioni.
 * Le adesioni diventano una per persona. Senza ritorno.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->deduplicaAdesioni();

        if (Schema::hasColumn('segnalazioni', 'id_provenienza')) {
            Schema::table('segnalazioni', function (Blueprint $table) {
                $table->dropForeign(['id_provenienza']);
            });
        }

        $this->togliColonne('segnalazioni', ['id_provenienza', 'segnalante', 'email', 'telefono']);
        $this->togliColonne('users', ['id_provenienza']);
        $this->togliColonne('adesioni_segnalazioni', ['segnalante', 'telefono', 'email']);

        Schema::table('adesioni_segnalazioni', function (Blueprint $table) {
            $table->unique(['id_segnalazione', 'id_utente'], 'adesioni_segnalazione_utente_unique');
        });

        $this->togliPermessoPerConto();

        Schema::dropIfExists('provenienze_segnalazioni');
    }

    /** Stessa persona più volte sulla stessa segnalazione (adesioni URP "per conto"): resta la più vecchia. */
    public function deduplicaAdesioni(): void
    {
        DB::table('adesioni_segnalazioni')
            ->select('id_segnalazione', 'id_utente', DB::raw('MIN(id_adesione) as resta'))
            ->groupBy('id_segnalazione', 'id_utente')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->each(fn ($g) => DB::table('adesioni_segnalazioni')
                ->where('id_segnalazione', $g->id_segnalazione)
                ->where('id_utente', $g->id_utente)
                ->where('id_adesione', '!=', $g->resta)
                ->delete());
    }

    /** @param list<string> $colonne */
    private function togliColonne(string $tabella, array $colonne): void
    {
        $presenti = array_values(array_filter($colonne, fn ($c) => Schema::hasColumn($tabella, $c)));
        if ($presenti !== []) {
            Schema::table($tabella, fn (Blueprint $table) => $table->dropColumn($presenti));
        }
    }

    private function togliPermessoPerConto(): void
    {
        $ids = DB::table('permissions')->where('name', 'segnalazioni.per-conto')->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Dati eliminati: nessun ritorno (si ripristina dal backup pre-aggiornamento).
    }
};
