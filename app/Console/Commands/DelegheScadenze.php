<?php

namespace App\Console\Commands;

use App\Services\Deleghe\DelegaService;
use Illuminate\Console\Command;

class DelegheScadenze extends Command
{
    protected $signature = 'deleghe:scadenze';

    protected $description = 'Chiude deleghe e richieste scadute, invia le richieste in coda, avvisa i delegati';

    public function handle(DelegaService $deleghe): int
    {
        $esito = $deleghe->scadenze();
        $this->info(sprintf(
            'Deleghe scadute: %d · richieste scadute: %d · richieste inviate: %d · avvisi: %d',
            $esito['scadute'], $esito['richieste_scadute'], $esito['inviate'], $esito['avvisi']
        ));

        return self::SUCCESS;
    }
}
