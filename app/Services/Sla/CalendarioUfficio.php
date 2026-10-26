<?php

namespace App\Services\Sla;

use App\Models\Impostazione;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Orario d'ufficio del comune: una fascia per giorno della settimana, chiuso nei
 * festivi nazionali e nel giorno del patrono. Base del calcolo delle scadenze SLA.
 */
class CalendarioUfficio
{
    public const GIORNI = ['lun', 'mar', 'mer', 'gio', 'ven', 'sab', 'dom'];

    public const ORARIO_DEFAULT = [
        'lun' => '08:00-14:00', 'mar' => '08:00-17:00', 'mer' => '08:00-14:00',
        'gio' => '08:00-17:00', 'ven' => '08:00-14:00', 'sab' => '', 'dom' => '',
    ];

    /** Oltre questo orizzonte senza fasce aperte il calcolo si arrende (orario tutto chiuso). */
    private const MAX_GIORNI = 400;

    private const FESTIVI_FISSI = ['01-01', '01-06', '04-25', '05-01', '06-02', '08-15', '11-01', '12-08', '12-25', '12-26'];

    private string $tz;

    /** @var array<int, list<string>> date Y-m-d dei festivi per anno */
    private array $festiviPerAnno = [];

    /**
     * @param  array<string, string|null>  $orario  es. ['lun' => '08:00-14:00', 'sab' => '']
     */
    public function __construct(private array $orario, private ?string $patrono, ?string $tz = null)
    {
        $this->tz = $tz ?? config('app.timezone');
    }

    public static function daImpostazioni(): self
    {
        $orario = [];
        foreach (self::GIORNI as $g) {
            $orario[$g] = (string) Impostazione::get("orario_$g", '');
        }
        // Tutto vuoto = impostazioni mai seminate (il salvataggio da admin lo vieta).
        if (implode('', $orario) === '') {
            $orario = self::ORARIO_DEFAULT;
        }

        return new self($orario, Impostazione::get('patrono') ?: null);
    }

    public function isFestivo(CarbonInterface $giorno): bool
    {
        $g = $this->locale($giorno);

        return in_array($g->format('Y-m-d'), $this->dateFestive($g->year), true);
    }

    /** @return list<Carbon> */
    public function festivi(int $anno): array
    {
        return array_map(fn ($d) => Carbon::parse($d, $this->tz), $this->dateFestive($anno));
    }

    /** @return array{0: Carbon, 1: Carbon}|null apertura e chiusura, null se chiuso o festivo */
    public function fascia(CarbonInterface $giorno): ?array
    {
        $g = $this->locale($giorno);
        $fascia = trim((string) ($this->orario[self::GIORNI[$g->dayOfWeekIso - 1]] ?? ''));
        if (! self::valida($fascia) || $this->isFestivo($g)) {
            return null;
        }
        [$da, $a] = explode('-', $fascia);

        return [$this->alle($g, $da), $this->alle($g, $a)];
    }

    public function aggiungiOreLavorative(CarbonInterface $da, float $ore): ?Carbon
    {
        $resto = (int) round($ore * 3600);
        $cursore = $this->locale($da);

        for ($i = 0; $i < self::MAX_GIORNI; $i++) {
            $fascia = $this->fascia($cursore);
            if ($fascia) {
                $inizio = max($cursore->getTimestamp(), $fascia[0]->getTimestamp());
                $disponibili = $fascia[1]->getTimestamp() - $inizio;
                if ($disponibili > 0) {
                    if ($resto <= $disponibili) {
                        return Carbon::createFromTimestamp($inizio + $resto, $this->tz);
                    }
                    $resto -= $disponibili;
                }
            }
            $cursore = $cursore->copy()->addDay()->startOfDay();
        }

        return null;
    }

    /** Chiusura dell'N-esimo giorno lavorativo successivo al giorno di $da. */
    public function aggiungiGiorniLavorativi(CarbonInterface $da, int $giorni): ?Carbon
    {
        $cursore = $this->locale($da)->startOfDay();
        $contati = 0;

        for ($i = 0; $i < self::MAX_GIORNI; $i++) {
            $cursore = $cursore->copy()->addDay();
            $fascia = $this->fascia($cursore);
            if ($fascia && ++$contati >= max(1, $giorni)) {
                return $fascia[1];
            }
        }

        return null;
    }

    public function oreLavorativeTra(CarbonInterface $da, CarbonInterface $a): float
    {
        if ($a->getTimestamp() <= $da->getTimestamp()) {
            return 0.0;
        }
        $secondi = 0;
        $cursore = $this->locale($da)->startOfDay();
        $fine = $this->locale($a);

        while ($cursore->lte($fine)) {
            if ($fascia = $this->fascia($cursore)) {
                $inizio = max($da->getTimestamp(), $fascia[0]->getTimestamp());
                $termine = min($a->getTimestamp(), $fascia[1]->getTimestamp());
                $secondi += max(0, $termine - $inizio);
            }
            $cursore = $cursore->copy()->addDay();
        }

        return $secondi / 3600;
    }

    public static function valida(string $fascia): bool
    {
        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)-([01]\d|2[0-3]):([0-5]\d)$/', $fascia, $m)) {
            return false;
        }

        return $m[1] * 60 + $m[2] < $m[3] * 60 + $m[4];
    }

    public static function validaPatrono(string $ggMm): bool
    {
        if (! preg_match('/^(\d{2})\/(\d{2})$/', $ggMm, $m)) {
            return false;
        }

        // 2024 è bisestile: 29/02 ammesso.
        return checkdate((int) $m[2], (int) $m[1], 2024);
    }

    /** @return list<string> */
    private function dateFestive(int $anno): array
    {
        if (isset($this->festiviPerAnno[$anno])) {
            return $this->festiviPerAnno[$anno];
        }
        $date = array_map(fn ($md) => "$anno-$md", self::FESTIVI_FISSI);
        $pasqua = self::pasqua($anno);
        $date[] = $pasqua->format('Y-m-d');
        $date[] = $pasqua->copy()->addDay()->format('Y-m-d');
        if ($this->patrono && self::validaPatrono($this->patrono)) {
            [$gg, $mm] = explode('/', $this->patrono);
            if (checkdate((int) $mm, (int) $gg, $anno)) {
                $date[] = "$anno-$mm-$gg";
            }
        }
        $date = array_values(array_unique($date));
        sort($date);

        return $this->festiviPerAnno[$anno] = $date;
    }

    /** Domenica di Pasqua (calendario gregoriano, algoritmo di Meeus/Jones/Butcher). */
    private static function pasqua(int $anno): Carbon
    {
        $a = $anno % 19;
        $b = intdiv($anno, 100);
        $c = $anno % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mese = intdiv($h + $l - 7 * $m + 114, 31);
        $giorno = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Carbon::create($anno, $mese, $giorno, 0, 0, 0);
    }

    private function locale(CarbonInterface $momento): Carbon
    {
        return Carbon::instance($momento)->copy()->setTimezone($this->tz);
    }

    private function alle(Carbon $giorno, string $hhmm): Carbon
    {
        [$h, $i] = explode(':', $hhmm);

        return Carbon::create($giorno->year, $giorno->month, $giorno->day, (int) $h, (int) $i, 0, $this->tz);
    }
}
