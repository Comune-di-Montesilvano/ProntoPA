<?php

namespace App\Console\Commands;

use App\Services\Segnalazioni\Inattivita;
use Illuminate\Console\Command;

class ControllaInattivita extends Command
{
    protected $signature   = 'segnalazioni:inattivita';
    protected $description = 'Avvisa sulle segnalazioni ferme e archivia quelle rimaste senza aggiornamenti dopo l\'avviso';

    public function handle(): void
    {
        $esito = app(Inattivita::class)->esegui();

        $this->info('Inattività: '.count($esito['avvisate']).' avvisate, '.count($esito['archiviate']).' archiviate.');
    }
}
