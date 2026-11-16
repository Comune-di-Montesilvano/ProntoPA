<?php

namespace App\Support\Segnalazioni;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/** Date dette come le direbbe una persona: "oggi", "ieri", "venerdì", "3/11". */
class Giorni
{
    /** "oggi" · "ieri" · nome del giorno entro 6 giorni · altrimenti "3/11" (anno se diverso). */
    public static function breve(CarbonInterface $quando, ?CarbonInterface $ora = null): string
    {
        $ora = Carbon::instance($ora ?? now());
        $giorni = self::giorniDiCalendario($quando, $ora);

        return match (true) {
            $giorni === 0 => 'oggi',
            $giorni === 1 => 'ieri',
            $giorni > 1 && $giorni <= 6 => Carbon::instance($quando)->locale('it')->dayName,
            default => Carbon::instance($quando)->format($quando->year === $ora->year ? 'j/n' : 'j/n/Y'),
        };
    }

    /** "oggi" · "ieri" · "6 giorni fa". */
    public static function fa(CarbonInterface $quando, ?CarbonInterface $ora = null): string
    {
        $giorni = self::giorniDiCalendario($quando, Carbon::instance($ora ?? now()));

        return match (true) {
            $giorni <= 0 => 'oggi',
            $giorni === 1 => 'ieri',
            default => "{$giorni} giorni fa",
        };
    }

    public static function giorniDiCalendario(CarbonInterface $da, CarbonInterface $a): int
    {
        return (int) Carbon::instance($da)->startOfDay()->diffInDays(Carbon::instance($a)->startOfDay(), false);
    }
}
