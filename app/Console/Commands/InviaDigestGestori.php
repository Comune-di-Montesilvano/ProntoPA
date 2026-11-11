<?php

namespace App\Console\Commands;

use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\DigestGestoreNotification;
use Illuminate\Console\Command;

class InviaDigestGestori extends Command
{
    protected $signature   = 'digest:invia';
    protected $description = 'Invia il digest mattutino ai gestori attivi';

    public function handle(): void
    {
        if (! Impostazione::get('digest_enabled', false)) {
            $this->info('Digest disabilitato.');
            return;
        }

        if (Impostazione::get('digest_skip_weekend', true) && now()->isWeekend()) {
            $this->info('Weekend: digest saltato.');
            return;
        }

        $gestori = User::where('gestore_segnalazioni', true)
            ->where('attivo', true)
            ->get();

        // Limite applicato dopo il filtro per tipologia: le poche di un gestore
        // non devono sparire dietro le 50 più urgenti delle altre tipologie.
        $nonAssegnate = fn (?array $tipologie = null) => Segnalazione::aperte()
            ->where(fn ($q) => $q->where('id_operatore_assegnato', 0)->orWhereNull('id_operatore_assegnato'))
            ->when($tipologie !== null, fn ($q) => $q->whereIn('id_tipologia_segnalazione', $tipologie))
            ->orderByDesc('livello_priorita')
            ->orderBy('data_segnalazione')
            ->limit(50)
            ->get();
        $nonAssegnateGlobali = $nonAssegnate();

        $inviati = 0;

        foreach ($gestori as $gestore) {
            $inRitardo = Segnalazione::aperte()
                ->where('id_operatore_assegnato', $gestore->id)
                ->where('sla_violato', true)
                ->orderBy('data_scadenza_sla')
                ->get();

            $inScadenza = Segnalazione::aperte()
                ->where('id_operatore_assegnato', $gestore->id)
                ->where('sla_violato', false)
                ->whereNotNull('data_scadenza_sla')
                ->whereBetween('data_scadenza_sla', [now(), now()->addHours(48)])
                ->orderBy('data_scadenza_sla')
                ->get();

            $daSmistare = match (true) {
                $gestore->supervisore_segnalazioni => $nonAssegnateGlobali,
                ! $gestore->hasRole('gestore') => collect(),
                $gestore->gestore_tutte_tipologie => $nonAssegnateGlobali,
                default => $nonAssegnate($gestore->tipologieGestite()->pluck('tipologie_segnalazioni.id_tipologia_segnalazione')->all()),
            };

            if ($inRitardo->isEmpty() && $inScadenza->isEmpty() && $daSmistare->isEmpty()) {
                continue;
            }

            $gestore->notify(new DigestGestoreNotification(
                $inRitardo->pluck('id_segnalazione')->all(),
                $inScadenza->pluck('id_segnalazione')->all(),
                $daSmistare->pluck('id_segnalazione')->all(),
            ));
            $inviati++;
        }

        $this->info("Digest inviato a {$inviati} gestori.");
    }
}
