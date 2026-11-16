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
    /** Regola SLA che vale per la segnalazione (tipologia + priorità, poi la generica). */
    public function regolaPer(Segnalazione $segnalazione): ?SlaConfigurazione
    {
        return SlaConfigurazione::applicabileA(
            $segnalazione->id_tipologia_segnalazione,
            $segnalazione->livello_priorita ?? 2
        );
    }

    public function calcolaScadenza(Segnalazione $segnalazione): ?Carbon
    {
        $regola = $this->regolaPer($segnalazione);

        if (! $regola) {
            return null;
        }

        $calendario = CalendarioUfficio::daImpostazioni();

        $base = $regola->unita === 'giorni'
            ? $calendario->aggiungiGiorniLavorativi($segnalazione->data_segnalazione, $regola->ore_target)
            : $calendario->aggiungiOreLavorative($segnalazione->data_segnalazione, $regola->ore_target);

        // Il tempo passato ad aspettare la risposta di chi ha segnalato non conta (1.0.2).
        $attesa = $base ? \App\Models\RichiestaInformazioni::oreDiAttesa($segnalazione, $calendario) : 0.0;

        return $attesa > 0 ? $calendario->aggiungiOreLavorative($base, $attesa) : $base;
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
