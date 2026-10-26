<?php

use App\Models\Segnalazione;
use App\Services\SlaService;
use Illuminate\Database\Migrations\Migration;

/**
 * Il flag "urgente" non esiste più (0.9.2): le segnalazioni aperte che lo avevano
 * diventano Critica e ricalcolano la scadenza. La colonna resta a DB per lo storico.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sla = app(SlaService::class);

        Segnalazione::whereNull('data_chiusura')
            ->where('segnalazione_urgente', true)
            ->chunkById(200, function ($segnalazioni) use ($sla) {
                foreach ($segnalazioni as $s) {
                    $s->livello_priorita = 4;
                    $scadenza = $sla->calcolaScadenza($s);
                    if ($scadenza) {
                        $s->data_scadenza_sla = $scadenza;
                        $s->sla_violato = $scadenza->isPast();
                    }
                    $s->saveQuietly();
                }
            }, 'id_segnalazione');
    }

    public function down(): void
    {
        // Irreversibile: la priorità precedente non è conservata.
    }
};
