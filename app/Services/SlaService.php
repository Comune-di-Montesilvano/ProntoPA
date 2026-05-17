<?php

namespace App\Services;

use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\SlaConfigurazione;
use Carbon\Carbon;

class SlaService
{
    /**
     * Calcola la data di scadenza SLA per una segnalazione.
     * Restituisce null se nessuna regola è configurata.
     */
    public function calcolaScadenza(Segnalazione $segnalazione): ?Carbon
    {
        $priorita = $segnalazione->livello_priorita ?? 2;

        // Segnalazione urgente → priorità 4 per lookup SLA
        if ($segnalazione->segnalazione_urgente) {
            $priorita = 4;
        }

        $regola = SlaConfigurazione::applicabileA(
            $segnalazione->id_tipologia_segnalazione,
            $segnalazione->id_specializzazione,
            $priorita
        );

        if (! $regola) {
            return null;
        }

        return $segnalazione->data_segnalazione->addHours($regola->ore_target);
    }

    /**
     * Ricalcola la priorità in base all'anzianità della segnalazione.
     * L'urgente forza priorità 4; altrimenti scala se superata soglia giorni.
     */
    public function calcolaPriorita(Segnalazione $segnalazione): int
    {
        if ($segnalazione->segnalazione_urgente) {
            return 4;
        }

        $prioritaBase = $segnalazione->livello_priorita ?? 2;
        $sogliaNumerica = (int) Impostazione::get('sla_anzianita_scala_giorni', 30);

        if ($sogliaNumerica > 0) {
            $giorniAperti = $segnalazione->data_segnalazione?->diffInDays(now()) ?? 0;
            if ($giorniAperti >= $sogliaNumerica && $prioritaBase < 4) {
                return min($prioritaBase + 1, 4);
            }
        }

        return $prioritaBase;
    }

    /**
     * Segnalazione a rischio = scadenza entro ore_warning dal momento attuale.
     */
    public function isArischio(Segnalazione $segnalazione): bool
    {
        if (! $segnalazione->data_scadenza_sla || $segnalazione->isChiusa()) {
            return false;
        }

        if ($segnalazione->sla_violato) {
            return false;
        }

        $priorita = $segnalazione->livello_priorita ?? 2;
        $regola = SlaConfigurazione::applicabileA(
            $segnalazione->id_tipologia_segnalazione,
            $segnalazione->id_specializzazione,
            $priorita
        );

        $oreWarning = $regola?->ore_warning ?? 24;

        return $segnalazione->data_scadenza_sla->diffInHours(now(), false) >= -$oreWarning
            && ! $this->isViolato($segnalazione);
    }

    /**
     * Segnalazione violata = scadenza superata e non ancora chiusa.
     */
    public function isViolato(Segnalazione $segnalazione): bool
    {
        if (! $segnalazione->data_scadenza_sla || $segnalazione->isChiusa()) {
            return false;
        }

        return $segnalazione->data_scadenza_sla->isPast();
    }
}
