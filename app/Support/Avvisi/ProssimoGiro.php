<?php

namespace App\Support\Avvisi;

use App\Models\Impostazione;
use App\Services\Sla\CalendarioUfficio;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Quando parte il riepilogo degli avvisi: regole condivise tra `avvisi:invia` (che decide se è il momento)
 * e la scheda (che dice quando partirà un avviso in attesa).
 * Giro orario: ogni ora piena in fascia (estremi inclusi) e alla chiusura portata al quarto d'ora.
 * Giro del mattino: a `digest_ora` nei giorni lavorativi.
 */
final class ProssimoGiro
{
    private const GIORNI_ORIZZONTE = 14;

    public function __construct(private readonly CalendarioUfficio $calendario) {}

    public static function daImpostazioni(): self
    {
        return new self(CalendarioUfficio::daImpostazioni());
    }

    /** Chiusura portata al quarto d'ora successivo (lo schedule gira ogni 15 minuti). */
    public static function aiQuartiDOra(CarbonInterface $chiusura): Carbon
    {
        $c = Carbon::instance($chiusura)->startOfMinute();
        $resto = $c->minute % 15;

        return $resto === 0 ? $c : $c->addMinutes(15 - $resto);
    }

    /** Orari del giro orario di un giorno (vuoto se chiuso). @return list<Carbon> */
    public function orariDelGiorno(CarbonInterface $giorno): array
    {
        $fascia = $this->calendario->fascia($giorno);
        if ($fascia === null) {
            return [];
        }
        [$apertura, $chiusura] = $fascia;

        $orari = [];
        for ($o = $apertura->copy()->startOfHour(); $o->lte($chiusura); $o->addHour()) {
            if ($o->gte($apertura)) {
                $orari[] = $o->copy();
            }
        }
        $orari[] = self::aiQuartiDOra($chiusura);

        usort($orari, fn (Carbon $a, Carbon $b) => $a->getTimestamp() <=> $b->getTimestamp());

        return $orari;
    }

    public function eOrarioDelGiro(CarbonInterface $momento): bool
    {
        foreach ($this->orariDelGiorno($momento) as $orario) {
            if ($orario->equalTo($momento)) {
                return true;
            }
        }

        return false;
    }

    /** Prossimo giro orario strettamente dopo `$da`. */
    public function orario(CarbonInterface $da): ?Carbon
    {
        $giorno = Carbon::instance($da)->setTimezone((string) config('app.timezone'))->startOfDay();
        for ($i = 0; $i <= self::GIORNI_ORIZZONTE; $i++, $giorno = $giorno->copy()->addDay()) {
            foreach ($this->orariDelGiorno($giorno) as $orario) {
                if ($orario->gt($da)) {
                    return $orario;
                }
            }
        }

        return null;
    }

    /** Prossimo giro del mattino strettamente dopo `$da`. */
    public function mattino(CarbonInterface $da): ?Carbon
    {
        $ora = (string) Impostazione::get('digest_ora', '07:30');
        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $ora, $m)) {
            $m = [null, '07', '30'];
        }

        $giorno = Carbon::instance($da)->setTimezone((string) config('app.timezone'))->startOfDay();
        for ($i = 0; $i <= self::GIORNI_ORIZZONTE; $i++, $giorno = $giorno->copy()->addDay()) {
            if ($this->calendario->fascia($giorno) === null) {
                continue;
            }
            $giro = $giorno->copy()->setTime((int) $m[1], (int) $m[2]);
            if ($giro->gt($da)) {
                return $giro;
            }
        }

        return null;
    }

    /** Quando parte un avviso in attesa: `ora` col primo giro utile (orario o del mattino), `giorno` col mattino. */
    public function perRitmo(string $ritmo, CarbonInterface $da): ?Carbon
    {
        if ($ritmo !== Categorie::RITMO_ORA) {
            return $this->mattino($da);
        }

        $candidati = array_filter([$this->orario($da), $this->mattino($da)]);

        return $candidati === [] ? null : collect($candidati)->sortBy(fn (Carbon $c) => $c->getTimestamp())->first();
    }
}
