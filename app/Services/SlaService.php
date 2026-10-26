<?php

namespace App\Services;

use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\SlaConfigurazione;
use App\Services\Sla\CalendarioUfficio;
use Carbon\Carbon;

class SlaService
{
    /**
     * Calcola la data di scadenza SLA per una segnalazione, contando solo l'orario d'ufficio.
     * Restituisce null se nessuna regola è configurata.
     */
    public function calcolaScadenza(Segnalazione $segnalazione): ?Carbon
    {
        $priorita = $segnalazione->livello_priorita ?? 2;

        $regola = SlaConfigurazione::applicabileA(
            $segnalazione->id_tipologia_segnalazione,
            $segnalazione->id_specializzazione,
            $priorita
        );

        if (! $regola) {
            return null;
        }

        $calendario = CalendarioUfficio::daImpostazioni();

        return $regola->unita === 'giorni'
            ? $calendario->aggiungiGiorniLavorativi($segnalazione->data_segnalazione, $regola->ore_target)
            : $calendario->aggiungiOreLavorative($segnalazione->data_segnalazione, $regola->ore_target);
    }

    /**
     * Ricalcola la priorità in base all'anzianità della segnalazione.
     * Scala di un livello se superata la soglia di giorni.
     */
    public function calcolaPriorita(Segnalazione $segnalazione): int
    {
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
     * Segnalazione a rischio = mancano al massimo ore_warning ore lavorative alla scadenza.
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

        return ! $this->isViolato($segnalazione)
            && CalendarioUfficio::daImpostazioni()->oreLavorativeTra(now(), $segnalazione->data_scadenza_sla) <= $oreWarning;
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
