<?php

namespace App\Support\Segnalazioni;

use App\Models\Segnalazione;
use Illuminate\Support\Carbon;

/**
 * Scadenza SLA di una segnalazione aperta, in parole: breve per gli elenchi, estesa per la scheda.
 * Rosa se superata, ambra entro 24 ore, neutra altrimenti.
 */
class Scadenza
{
    /** @return array{testo: string, tono: 'rose'|'amber'|'slate'}|null */
    public static function per(Segnalazione $s, ?Carbon $ora = null): ?array
    {
        $secondi = self::secondiRimasti($s, $ora);
        if ($secondi === null) {
            return null;
        }

        $ore = intdiv(abs($secondi), 3600);
        if ($secondi < 0) {
            $testo = $ore >= 24 ? 'Superata da '.intdiv($ore, 24).' g' : 'Superata da '.max(1, $ore).' h';

            return ['testo' => $testo, 'tono' => 'rose'];
        }

        if ($ore < 24) {
            return ['testo' => 'Entro '.max(1, $ore).' h', 'tono' => 'amber'];
        }

        return ['testo' => 'Entro '.intdiv($ore, 24).' g', 'tono' => 'slate'];
    }

    /** @return array{testo: string, tono: 'rose'|'amber'|'slate'}|null */
    public static function estesa(Segnalazione $s, ?Carbon $ora = null): ?array
    {
        $secondi = self::secondiRimasti($s, $ora);
        if ($secondi === null) {
            return null;
        }

        $ore = intdiv(abs($secondi), 3600);
        if ($secondi < 0) {
            $quanto = $ore >= 24
                ? self::plurale(intdiv($ore, 24), 'giorno', 'giorni')
                : self::plurale(max(1, $ore), 'ora', 'ore');

            return ['testo' => 'Tempo massimo superato da '.$quanto, 'tono' => 'rose'];
        }

        if ($ore < 24) {
            return ['testo' => 'Scade tra '.self::plurale(max(1, $ore), 'ora', 'ore'), 'tono' => 'amber'];
        }

        return ['testo' => 'Scade il '.$s->data_scadenza_sla->format('d/m'), 'tono' => 'slate'];
    }

    private static function secondiRimasti(Segnalazione $s, ?Carbon $ora): ?int
    {
        if ($s->data_chiusura !== null || $s->data_scadenza_sla === null) {
            return null;
        }

        return $s->data_scadenza_sla->getTimestamp() - ($ora ?? now())->getTimestamp();
    }

    private static function plurale(int $n, string $uno, string $molti): string
    {
        return $n.' '.($n === 1 ? $uno : $molti);
    }
}
