<?php

namespace App\Console\Commands;

use App\Services\PubblicazioneAutomatica;
use Illuminate\Console\Command;

class AutoPublishSegnalazioni extends Command
{
    protected $signature = 'app:auto-publish-segnalazioni
                            {--days= : Numero di giorni da processare (0 o omesso = tutte)}';

    protected $description = 'Pubblica automaticamente segnalazioni che raggiungono lo stato trigger configurato';

    public function handle(PubblicazioneAutomatica $pubblicazione): int
    {
        $giorni = (int) $this->option('days');
        $count = $pubblicazione->applicaAEsistenti($giorni > 0 ? $giorni : null);

        if ($count === null) {
            $this->warn('Pubblicazione automatica disattivata o senza stato trigger: configurala in Admin → Impostazioni.');

            return 0;
        }

        $this->info("✓ {$count} segnalazioni pubblicate automaticamente.");

        return 0;
    }
}
