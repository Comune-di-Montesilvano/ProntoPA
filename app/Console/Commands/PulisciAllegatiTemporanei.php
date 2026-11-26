<?php

namespace App\Console\Commands;

use App\Support\Segnalazioni\AllegatiTemporanei;
use Illuminate\Console\Command;

class PulisciAllegatiTemporanei extends Command
{
    protected $signature = 'allegati:pulisci-temporanei';

    protected $description = 'Cancella le foto caricate a blocchi e mai inviate con un form (più vecchie di '.AllegatiTemporanei::ORE_VITA.' ore)';

    public function handle(): int
    {
        $this->info('Foto temporanee cancellate: '.AllegatiTemporanei::pulisci());

        return self::SUCCESS;
    }
}
