<?php

namespace App\Console\Commands;

use App\Models\Segnalazione;
use App\Notifications\SlaViolazioneNotification;
use App\Notifications\SlaWarningNotification;
use App\Services\Segnalazioni\DestinatariTipologia;
use App\Services\SlaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class CheckSlaViolazioni extends Command
{
    protected $signature   = 'sla:check';
    protected $description = 'Controlla violazioni e warning SLA, avvisa i gestori della tipologia (scaduto: anche i supervisori)';

    public function handle(SlaService $sla, DestinatariTipologia $destinatari): void
    {
        $segnalazioni = Segnalazione::aperte()
            ->whereNotNull('data_scadenza_sla')
            ->get();

        $violate = 0;
        $warning = 0;

        foreach ($segnalazioni as $s) {
            if ($sla->isViolato($s) && ! $s->sla_violato) {
                $s->update(['sla_violato' => true]);

                // Sempre immediato, anche con il digest attivo (decisione committente 0.9.13).
                $a = $destinatari->perSlaScaduto($s);
                if ($a->isNotEmpty()) {
                    Notification::send($a, new SlaViolazioneNotification($s));
                }
                $violate++;
                continue;
            }

            if ($sla->isArischio($s) && ! $s->sla_warning_inviato) {
                $s->update(['sla_warning_inviato' => true]);
                $a = $destinatari->perSlaArischio($s);
                if ($a->isNotEmpty()) {
                    Notification::send($a, new SlaWarningNotification($s));
                }
                $warning++;
            }
        }

        $this->info("SLA check: {$violate} violate, {$warning} warning.");
    }
}
