<?php

namespace App\Console\Commands;

use App\Events\SegnalazionePublishedAutomatically;
use App\Models\Impostazione;
use App\Models\Segnalazione;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class AutoPublishSegnalazioni extends Command
{
    protected $signature = 'app:auto-publish-segnalazioni
                            {--days= : Numero di giorni da processare (0 o omesso = tutte)}';

    protected $description = 'Pubblica automaticamente segnalazioni che raggiungono lo stato trigger configurato';

    public function handle(): int
    {
        // Controlla se la pubblicazione automatica è abilitata
        if (! Impostazione::get('publication_enabled', false)) {
            $this->info('Pubblicazione automatica è DISABILITATA. Abilitarla in Admin → Impostazioni.');
            return 0;
        }

        // Leggi lo stato trigger configurato
        $triggerStateId = Impostazione::get('publication_auto_state_id');

        if ($triggerStateId === null) {
            $this->warn('Nessuno stato trigger configurato. Impossibile procedere.');
            return 1;
        }

        $days = $this->option('days');

        // Query: segnalazioni non ancora pubblicate, che hanno raggiunto lo stato trigger
        $query = Segnalazione::query()
            ->where('flag_pubblicata', false)
            ->where('id_stato_segnalazione', '>=', $triggerStateId);

        if ($days !== null && (int) $days > 0) {
            $cutoffDate = now()->subDays((int) $days)->startOfDay();
            $query->where('data_segnalazione', '>=', $cutoffDate);
            $this->info("Pubblica segnalazioni con stato >= {$triggerStateId} degli ultimi {$days} giorno(i)...");
        } else {
            $this->info("Pubblica tutte le segnalazioni con stato >= {$triggerStateId}...");
        }

        $toPublish = $query->get();

        if ($toPublish->isEmpty()) {
            $this->info('Nessuna segnalazione da pubblicare.');
            return 0;
        }

        $this->withProgressBar($toPublish, function (Segnalazione $segnalazione) use ($triggerStateId) {
            $oldState = $segnalazione->id_stato_segnalazione;

            $segnalazione->update([
                'flag_pubblicata' => true,
                'flag_riservata'  => false,
            ]);

            // Emetti evento audit
            SegnalazionePublishedAutomatically::dispatch(
                $segnalazione->fresh(),
                $oldState,
                $oldState, // Stesso stato, perché non è un cambio
            );
        });

        $count = $toPublish->count();
        Cache::forget('public.home.statistics');
        $this->newLine(2);
        $this->info("✓ {$count} segnalazioni pubblicate automaticamente.");

        return 0;
    }
}
