<?php

namespace App\Jobs;

use App\Services\Backup\BackupInCorso;
use App\Services\Backup\BackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class CreaBackup implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly string $tipo = 'manuale') {}

    public function handle(BackupService $backup): void
    {
        try {
            $backup->crea($this->tipo);
        } catch (BackupInCorso) {
            // La UI già impedisce il doppio avvio: nessun errore da mostrare.
            Log::info('CreaBackup: un altro backup è in corso, salto.');
        }
    }
}
