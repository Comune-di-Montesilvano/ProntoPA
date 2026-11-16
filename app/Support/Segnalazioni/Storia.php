<?php

namespace App\Support\Segnalazioni;

use App\Services\Sla\CalendarioUfficio;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * La storia (voci di Attivita, dalla più recente) divisa per giorno, con una riga per i giorni
 * senza attività in mezzo: "Mercoledì e giovedì · nessuna attività · 2 giorni lavorativi fermi".
 */
class Storia
{
    /**
     * @param  Collection<int, array<string, mixed>>  $voci  dalla più recente, ognuna con 'quando'
     * @return list<array{intestazione: string, voci: list<array<string, mixed>>}|array{vuoto: string}>
     */
    public static function giorni(Collection $voci, ?CarbonInterface $ora = null): array
    {
        $ora = Carbon::instance($ora ?? now());
        $calendario = CalendarioUfficio::daImpostazioni();
        $gruppi = $voci->groupBy(fn (array $v) => Carbon::instance($v['quando'])->format('Y-m-d'));

        $risultato = [];
        $giornoPrecedente = null;
        foreach ($gruppi as $data => $delGiorno) {
            $giorno = Carbon::parse($data);
            if ($giornoPrecedente !== null && ($vuoto = self::vuoto($giorno, $giornoPrecedente, $calendario)) !== null) {
                $risultato[] = ['vuoto' => $vuoto];
            }
            $risultato[] = ['intestazione' => self::intestazione($giorno, $ora), 'voci' => $delGiorno->values()->all()];
            $giornoPrecedente = $giorno;
        }

        return $risultato;
    }

    private static function intestazione(Carbon $giorno, Carbon $ora): string
    {
        $formato = $giorno->year === $ora->year ? 'dddd D MMMM' : 'dddd D MMMM YYYY';
        $testo = $giorno->copy()->locale('it')->isoFormat($formato);

        return match (true) {
            $giorno->isSameDay($ora) => 'Oggi · '.$testo,
            $giorno->isSameDay($ora->copy()->subDay()) => 'Ieri · '.$testo,
            default => mb_strtoupper(mb_substr($testo, 0, 1)).mb_substr($testo, 1),
        };
    }

    /** Giorni senza attività tra $piuVecchio e $piuRecente (esclusi). */
    private static function vuoto(Carbon $piuVecchio, Carbon $piuRecente, CalendarioUfficio $calendario): ?string
    {
        $giorni = [];
        for ($g = $piuVecchio->copy()->addDay(); $g->lt($piuRecente); $g = $g->copy()->addDay()) {
            $giorni[] = $g->copy();
        }
        if ($giorni === []) {
            return null;
        }

        $lavorativi = count(array_filter($giorni, fn (Carbon $g) => $calendario->fascia($g) !== null));
        $quali = self::quali($giorni);

        if ($lavorativi === 0) {
            return $quali.' · nessuna attività, ufficio chiuso';
        }

        return $quali.' · nessuna attività · '.$lavorativi.' '.($lavorativi === 1 ? 'giorno lavorativo fermo' : 'giorni lavorativi fermi');
    }

    /** @param  list<Carbon>  $giorni  "Mercoledì e giovedì", oltre 3 "Dal 3 al 9 novembre". */
    private static function quali(array $giorni): string
    {
        if (count($giorni) > 3) {
            $primo = $giorni[0]->copy()->locale('it');
            $ultimo = end($giorni)->copy()->locale('it');

            return 'Dal '.($primo->month === $ultimo->month ? $primo->day : $primo->isoFormat('D MMMM'))
                .' al '.$ultimo->isoFormat('D MMMM');
        }

        $nomi = array_map(fn (Carbon $g) => $g->copy()->locale('it')->dayName, $giorni);
        $ultimo = array_pop($nomi);
        $testo = $nomi === [] ? $ultimo : implode(', ', $nomi).' e '.$ultimo;

        return mb_strtoupper(mb_substr($testo, 0, 1)).mb_substr($testo, 1);
    }
}
