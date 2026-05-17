<?php

namespace App\Console\Commands;

use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\SlaViolazioneNotification;
use App\Notifications\SlaWarningNotification;
use App\Services\SlaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class CheckSlaViolazioni extends Command
{
    protected $signature   = 'sla:check';
    protected $description = 'Controlla violazioni e warning SLA, notifica i supervisori';

    public function handle(SlaService $sla): void
    {
        $segnalazioni = Segnalazione::aperte()
            ->whereNotNull('data_scadenza_sla')
            ->get();

        $supervisori = User::where('supervisore_segnalazioni', true)
            ->orWhere('amministratore', true)
            ->where('attivo', true)
            ->get();

        $violate = 0;
        $warning = 0;

        foreach ($segnalazioni as $s) {
            if ($sla->isViolato($s) && ! $s->sla_violato) {
                $s->update(['sla_violato' => true]);
                if ($supervisori->isNotEmpty()) {
                    Notification::send($supervisori, new SlaViolazioneNotification($s));
                }
                $violate++;
                continue;
            }

            if ($sla->isArischio($s) && ! $s->sla_warning_inviato) {
                $s->update(['sla_warning_inviato' => true]);
                if ($supervisori->isNotEmpty()) {
                    Notification::send($supervisori, new SlaWarningNotification($s));
                }
                $warning++;
            }
        }

        $this->info("SLA check: {$violate} violate, {$warning} warning.");
    }
}
