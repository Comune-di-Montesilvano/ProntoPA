<?php

namespace App\Notifications\Concerns;

use App\Models\Segnalazione;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Cosa e dove, uguale in ogni email: oggetto "<motivo> — #id · TIPOLOGIA · titolo"
 * e un riepilogo in testa al messaggio (spec 2026-11-13 §8).
 */
trait DescriveSegnalazione
{
    protected function oggettoSegnalazione(Segnalazione $s, string $motivo): string
    {
        return $motivo.' — '.$this->rigaSegnalazione($s);
    }

    /** "#id · TIPOLOGIA · titolo": riepiloghi, digest e Telegram. */
    protected function rigaSegnalazione(Segnalazione $s): string
    {
        return implode(' · ', array_filter([
            '#'.$s->id_segnalazione,
            $this->tipologiaDi($s),
            $s->titoloLeggibile(60),
        ], 'filled'));
    }

    protected function riepilogoSegnalazione(MailMessage $m, Segnalazione $s, bool $perChiLavora = false, ?string $nota = null): MailMessage
    {
        $m->line('**Titolo:** '.$s->titoloLeggibile());
        if ($tipologia = $this->tipologiaDi($s)) {
            $m->line('**Tipologia:** '.$tipologia);
        }
        $plesso = $s->id_plesso ? $s->plesso : null;
        if ($plesso) {
            $m->line('**Sede:** '.implode(', ', array_filter([$plesso->nome, $plesso->indirizzo], 'filled')));
        }
        $m->line('**Stato:** '.$s->statoEnum()->etichetta());

        if (! $perChiLavora) {
            return $m;
        }

        $m->line('**Cosa è stato segnalato:** '.Str::limit((string) $s->testo_segnalazione, 500));
        if ($plesso && (filled($plesso->referente) || filled($plesso->recapiti))) {
            $m->line('**Referente:** '.implode(' · ', array_filter([$plesso->referente, $plesso->recapiti], 'filled')));
        }
        if ($s->data_scadenza_sla) {
            $m->line('**Scadenza:** '.Carbon::parse($s->data_scadenza_sla)->format('d/m/Y H:i'));
        }
        if (filled($nota)) {
            $m->line('**Nota del comune:** '.Str::limit((string) $nota, 1000));
        }

        return $m;
    }

    private function tipologiaDi(Segnalazione $s): ?string
    {
        if (! $s->id_tipologia_segnalazione) {
            return null;
        }

        $descrizione = $s->tipologia()->value('descrizione');

        return filled($descrizione) ? (string) $descrizione : null;
    }
}
