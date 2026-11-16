<?php

namespace App\Support\Segnalazioni;

use App\Enums\SegnalazioneStato;
use App\Models\NotaSegnalazione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Services\Sla\CalendarioUfficio;
use App\Services\SlaService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * I quattro fatti in testa alla scheda gestore (spec A2): stato, scadenza, priorità, da quanto è aperta.
 * Solo parole e toni: la vista decide il resto.
 *
 * @phpstan-type Fatto array{valore: string, dettaglio: ?string, tono: 'slate'|'amber'|'rose'|'emerald', colore: string}
 */
class Fatti
{

    private function __construct(
        private readonly Segnalazione $s,
        private readonly Carbon $ora,
        private readonly CalendarioUfficio $calendario,
    ) {}

    /** @return array{stato: Fatto, scadenza: Fatto, priorita: Fatto, aperta: Fatto} */
    public static function per(Segnalazione $s, ?CarbonInterface $ora = null): array
    {
        $f = new self($s, Carbon::instance($ora ?? now()), CalendarioUfficio::daImpostazioni());

        return ['stato' => $f->stato(), 'scadenza' => $f->scadenza(), 'priorita' => $f->priorita(), 'aperta' => $f->aperta()];
    }

    /** @return Fatto */
    private function stato(): array
    {
        $stato = $this->s->statoEnum();
        $storico = StoricoOrdinato::di($this->s);

        // Ingresso nello stato attuale: inizio dell'ultima serie di righe con questo stato.
        $dal = null;
        foreach ($storico->reverse() as $evento) {
            if ((int) $evento->id_stato_segnalazione !== $stato->value) {
                break;
            }
            $dal = Carbon::parse($evento->data_registrazione);
        }

        if ($stato->isTerminale()) {
            $ultimo = $storico->last();
            $chi = $ultimo && (int) $ultimo->id_utente > 0 ? User::whereKey((int) $ultimo->id_utente)->value('name') : null;
            $quando = $this->s->data_chiusura ? Carbon::parse($this->s->data_chiusura) : $dal;
            $dettaglio = trim(($chi ? 'da '.$chi : '').($quando ? ($chi ? ', ' : '').'il '.$quando->format('d/m') : '')) ?: null;
        } elseif ($stato === SegnalazioneStato::NUOVA) {
            $dettaglio = "nessuno l'ha ancora presa";
        } else {
            $chi = $this->assegnatario($stato);
            $parti = array_filter([$chi ? 'a '.$chi : null, $dal ? $this->daQuando($dal) : null]);
            $dettaglio = $parti ? implode(', ', $parti) : null;
        }

        return $this->fatto($stato->etichetta(), $dettaglio, 'slate', \App\Support\Colori::tono($stato->colore())['punto']);
    }

    private function assegnatario(SegnalazioneStato $stato): ?string
    {
        if (in_array($stato, [SegnalazioneStato::ASSEGNATA_IMPRESA, SegnalazioneStato::PREVENTIVO_IN_ATTESA], true) && $this->s->id_appalto) {
            return $this->s->appalto?->impresa?->ragione_sociale;
        }
        if ($this->s->id_squadra_assegnata) {
            return $this->s->squadraAssegnata?->nome;
        }
        if ($this->s->id_operatore_assegnato) {
            return User::whereKey((int) $this->s->id_operatore_assegnato)->value('name');
        }

        return null;
    }

    /** "da venerdì" entro 6 giorni, altrimenti "dal 3/11". */
    private function daQuando(Carbon $dal): string
    {
        $giorni = $dal->copy()->startOfDay()->diffInDays($this->ora->copy()->startOfDay());
        if ($giorni < 1) {
            return 'da oggi';
        }

        return $giorni <= 6 ? 'da '.$dal->copy()->locale('it')->dayName : 'dal '.$dal->format('j/n');
    }

    /** @return Fatto */
    private function scadenza(): array
    {
        $scadenza = $this->s->data_scadenza_sla ? Carbon::parse($this->s->data_scadenza_sla) : null;
        $aperta = Carbon::parse($this->s->data_segnalazione);

        if ($this->s->data_chiusura) {
            $chiusura = Carbon::parse($this->s->data_chiusura);
            $dettaglio = null;
            if ($scadenza) {
                $dettaglio = $chiusura->lte($scadenza)
                    ? 'entro la scadenza'
                    : $this->quanto($scadenza, $chiusura).' oltre la scadenza';
            }

            return $this->fatto('Chiusa in '.$this->giorni($aperta, $chiusura), $dettaglio, $dettaglio === 'entro la scadenza' ? 'emerald' : 'slate');
        }

        if ($this->s->statoEnum() === SegnalazioneStato::ATTESA_RISPOSTA) {
            return $this->fatto('Ferma', 'aspetta una risposta', 'slate');
        }

        if (! $scadenza) {
            return $this->fatto('—', 'nessuna scadenza', 'slate');
        }

        $manuale = $this->s->scadenza_manuale ? ' · spostata a mano' : '';
        if ($scadenza->lt($this->ora)) {
            return $this->fatto('Scaduta da '.$this->quanto($scadenza, $this->ora),
                'era '.$scadenza->copy()->locale('it')->isoFormat('dddd D/M [alle] HH:mm').$manuale, 'rose');
        }

        $tono = $scadenza->getTimestamp() - $this->ora->getTimestamp() < 24 * 3600 ? 'amber' : 'slate';

        return $this->fatto($this->quandoScade($scadenza), $this->mancano($scadenza).$manuale, $tono);
    }

    /** "Oggi alle 14:00", "Domani alle 14:00", "Venerdì alle 14:00", "Il 3/12 alle 14:00". */
    private function quandoScade(Carbon $scadenza): string
    {
        $giorni = $this->ora->copy()->startOfDay()->diffInDays($scadenza->copy()->startOfDay());
        $ore = ' alle '.$scadenza->format('H:i');

        return match (true) {
            $giorni < 1 => 'Oggi'.$ore,
            $giorni < 2 => 'Domani'.$ore,
            $giorni <= 6 => ucfirst($scadenza->copy()->locale('it')->dayName).$ore,
            default => 'Il '.$scadenza->format('j/n').$ore,
        };
    }

    /** Tempo rimasto in orario d'ufficio: ore se è questione di una giornata, altrimenti giorni lavorativi. */
    private function mancano(Carbon $scadenza): string
    {
        $ore = $this->calendario->oreLavorativeTra($this->ora, $scadenza);
        if ($scadenza->isSameDay($this->ora) || $ore < 8) {
            $n = (int) floor($ore);

            return match (true) {
                $n < 1 => "manca meno di un'ora",
                $n === 1 => 'manca 1 ora',
                default => 'mancano '.$n.' ore',
            };
        }

        $giorni = 0;
        for ($g = $this->ora->copy()->startOfDay()->addDay(); $g->lte($scadenza); $g = $g->copy()->addDay()) {
            if ($this->calendario->fascia($g) !== null) {
                $giorni++;
            }
        }

        return $giorni === 1 ? 'manca 1 giorno lavorativo' : 'mancano '.$giorni.' giorni lavorativi';
    }

    /** @return Fatto */
    private function priorita(): array
    {
        $livello = (int) ($this->s->livello_priorita ?: 2);
        $regola = app(SlaService::class)->regolaPer($this->s);
        $dettaglio = null;
        if ($regola) {
            $n = (int) $regola->ore_target;
            $dettaglio = 'regola: '.$n.' '.($regola->unita === 'giorni'
                ? ($n === 1 ? 'giorno lavorativo' : 'giorni lavorativi')
                : ($n === 1 ? 'ora lavorativa' : 'ore lavorative'));
        }

        return $this->fatto($this->s->label_priorita, $dettaglio, 'slate', \App\Support\Colori::tono(\App\Support\Colori::priorita($livello))['punto']);
    }

    /** @return Fatto */
    private function aperta(): array
    {
        $aperta = Carbon::parse($this->s->data_segnalazione);
        $fine = $this->s->data_chiusura ? Carbon::parse($this->s->data_chiusura) : $this->ora;
        $giorni = Giorni::giorniDiCalendario($aperta, $fine);

        $parti = [];
        $volte = $this->s->volteSegnalata();
        if ($volte > 1) {
            $parti[] = 'segnalata '.$volte.' volte';
        }
        $risposte = $this->risposte();
        if ($risposte > 0) {
            $parti[] = $risposte.' '.($risposte === 1 ? 'risposta' : 'risposte').' a chi ha segnalato';
        }

        return $this->fatto($giorni < 1 ? 'oggi' : ($giorni === 1 ? '1 giorno' : $giorni.' giorni'), $parti ? implode(' · ', $parti) : null, 'slate');
    }

    /** Messaggi del personale visibili a chi ha segnalato (non le note automatiche, non quelle dei segnalanti). */
    private function risposte(): int
    {
        $autori = NotaSegnalazione::where('id_segnalazione', $this->s->id_segnalazione)
            ->where('visibile_web', true)->whereNull('origine')
            ->where('id_utente', '>', 0)->where('id_utente', '!=', (int) $this->s->id_utente_segnalazione)
            ->pluck('id_utente');

        $personale = User::whereIn('id', $autori->unique())->get()
            ->filter(fn (User $u) => LetturaScheda::per($u) !== 'segnalante')->pluck('id')->all();

        return $autori->filter(fn ($id) => in_array((int) $id, $personale, true))->count();
    }

    private function giorni(CarbonInterface $da, CarbonInterface $a): string
    {
        $n = Giorni::giorniDiCalendario($da, $a);

        return $n < 1 ? 'meno di un giorno' : ($n === 1 ? '1 giorno' : $n.' giorni');
    }

    /** Distanza in parole: giorni, o ore se meno di un giorno. */
    private function quanto(CarbonInterface $da, CarbonInterface $a): string
    {
        $secondi = max(0, $a->getTimestamp() - $da->getTimestamp());
        if ($secondi >= 24 * 3600) {
            $n = intdiv($secondi, 24 * 3600);

            return $n === 1 ? '1 giorno' : $n.' giorni';
        }
        $h = max(1, intdiv($secondi, 3600));

        return $h === 1 ? '1 ora' : $h.' ore';
    }

    /** @return Fatto */
    private function fatto(string $valore, ?string $dettaglio, string $tono, string $colore = ''): array
    {
        return compact('valore', 'dettaglio', 'tono', 'colore');
    }
}
