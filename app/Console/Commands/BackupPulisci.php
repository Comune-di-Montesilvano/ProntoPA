<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;

class BackupPulisci extends Command
{
    protected $signature = 'backup:pulisci';

    protected $description = 'Applica la retention ai backup (tutti 7 giorni, poi uno a settimana fino a 30)';

    public function handle(BackupService $backup): int
    {
        $eliminati = $backup->applicaRetention();
        foreach ($eliminati as $nome) {
            $this->line($nome);
        }
        $this->info(count($eliminati).' backup eliminati.');

        return self::SUCCESS;
    }
}
