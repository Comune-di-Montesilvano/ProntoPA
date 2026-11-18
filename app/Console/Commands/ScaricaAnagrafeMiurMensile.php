<?php

namespace App\Console\Commands;

use App\Models\Istituto;
use App\Models\Plesso;
use App\Services\Scuole\AnagrafeMiur;
use Illuminate\Console\Command;

/** Riscarica l'anagrafe scuole MIUR (1.0.16, ogni mese): nomi, indirizzi ed email delle sedi restano allineati. */
class ScaricaAnagrafeMiurMensile extends Command
{
    protected $signature = 'miur:scarica';

    protected $description = "Scarica di nuovo l'anagrafe scuole MIUR e riallinea le sedi importate (se il link è configurato)";

    public function handle(AnagrafeMiur $anagrafe): int
    {
        // Senza sedi importate dal MIUR non c'è niente da riallineare: inutile scaricare ~50 MB.
        if (! Istituto::where('fonte_dati', 'miur')->exists() && ! Plesso::where('fonte_dati', 'miur')->exists()) {
            $this->line('Nessuna sede importata dal MIUR: niente da aggiornare.');

            return self::SUCCESS;
        }

        $errore = $anagrafe->avvia();
        $this->line($errore ?? 'Download avviato.');

        return self::SUCCESS; // link mancante o download già in corso non sono errori del comando
    }
}
