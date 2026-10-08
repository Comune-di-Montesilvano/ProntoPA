<?php

namespace App\Console\Commands;

use App\Services\Deleghe\DelegaService;
use Illuminate\Console\Command;

class DelegheRinnovi extends Command
{
    protected $signature = 'deleghe:rinnovi';

    protected $description = 'Scrive a ogni scuola l\'elenco delle deleghe in scadenza da confermare';

    public function handle(DelegaService $deleghe): int
    {
        $this->info('Email di rinnovo inviate: '.$deleghe->inviaRinnovi());

        return self::SUCCESS;
    }
}
