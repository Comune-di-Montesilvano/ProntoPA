<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Throwable;

class BackupCrea extends Command
{
    protected $signature = 'backup:crea {--tipo=manuale}';

    protected $description = 'Crea un backup cifrato (database, allegati, segreti)';

    public function handle(BackupService $backup): int
    {
        try {
            $info = $backup->crea((string) $this->option('tipo'));
        } catch (Throwable $e) {
            $this->error('Backup fallito: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info($info->nome);

        return self::SUCCESS;
    }
}
