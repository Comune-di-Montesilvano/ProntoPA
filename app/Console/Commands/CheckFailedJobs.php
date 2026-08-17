<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\JobFallitiNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Difesa in profondità: la coda 'queue' (docker-compose) può avere job
 * che falliscono ripetutamente (webhook irraggiungibile, Ollama giù) e
 * finiscono in failed_jobs senza che nessuno se ne accorga — Sentry lo
 * cattura se configurato (SENTRY_LARAVEL_DSN), questo comando copre il
 * caso in cui non lo sia o sia temporaneamente giù.
 */
class CheckFailedJobs extends Command
{
    protected $signature   = 'jobs:check-failed';
    protected $description = 'Notifica gli admin se ci sono job falliti in coda non ancora gestiti';

    private const MAX_RIGHE_EMAIL = 10;

    public function handle(): int
    {
        $totale = DB::table('failed_jobs')->count();

        if ($totale === 0) {
            $this->info('Nessun job fallito.');

            return self::SUCCESS;
        }

        $righe = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit(self::MAX_RIGHE_EMAIL)
            ->get(['connection', 'queue', 'exception', 'failed_at'])
            ->map(fn ($r) => [
                'connection' => $r->connection,
                'queue'      => $r->queue,
                'failed_at'  => $r->failed_at,
                'exception'  => Str::of($r->exception)->explode("\n")->first(),
            ])
            ->all();

        $admin = User::where('amministratore', true)->where('attivo', true)->get();

        foreach ($admin as $user) {
            $user->notify(new JobFallitiNotification($totale, $righe));
        }

        $this->warn("{$totale} job falliti — notificati {$admin->count()} admin.");

        return self::SUCCESS;
    }
}
