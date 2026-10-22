<?php

namespace App\Jobs;

use App\Services\Backup\BackupInCorso;
use App\Services\Backup\BackupService;
use App\Services\Backup\CatalogoBackup;
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

    public function __construct(public readonly string $tipo = 'manuale')
    {
        $this->onConnection((string) config('backup.connessione_coda'))->onQueue('lunghi');
    }

    public function handle(BackupService $backup, CatalogoBackup $catalogo): void
    {
        try {
            $backup->crea($this->tipo);
        } catch (BackupInCorso) {
            // Occupato da un ripristino o da un altro backup: se il marcatore è ancora
            // "in coda" è quello scritto per questo job (un backup avviato lo porta a "in corso").
            if (($catalogo->inCorso()['fase'] ?? null) === 'in coda') {
                $catalogo->fineInCorso();
            }
            Log::info('CreaBackup: un altro backup o ripristino è in corso, salto.');
        }
    }
}
