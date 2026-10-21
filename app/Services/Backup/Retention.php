<?php

namespace App\Services\Backup;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Funzione pura: dato l'elenco e l'ora, dice cosa eliminare.
 * Ultimo verificato sempre; tutto per N giorni; poi uno a settimana ISO
 * (il più recente, preferendo i verificati ai falliti) fino a M giorni.
 */
class Retention
{
    /**
     * @param  Collection<int,BackupInfo>  $backup
     * @return list<string> nomi da eliminare
     */
    public function daEliminare(Collection $backup, CarbonImmutable $ora): array
    {
        $giorniTutti = (int) config('backup.giorni_tutti');
        $giorniMax = (int) config('backup.giorni_settimanali');

        $tieni = [];
        $ultimo = $backup->where('verificato', true)->sortByDesc(fn (BackupInfo $b) => $b->creatoIl->getTimestamp())->first();
        if ($ultimo) {
            $tieni[$ultimo->nome] = true;
        }
        if ($protetto = self::preRipristinoProtetto($backup)) {
            $tieni[$protetto] = true;
        }

        $settimane = [];
        foreach ($backup as $b) {
            $eta = ($ora->getTimestamp() - $b->creatoIl->getTimestamp()) / 86400;
            if ($eta <= $giorniTutti) {
                $tieni[$b->nome] = true;
            } elseif ($eta <= $giorniMax) {
                $settimane[$b->creatoIl->format('o-W')][] = $b;
            }
        }
        foreach ($settimane as $gruppo) {
            usort($gruppo, fn (BackupInfo $a, BackupInfo $b) => [$b->verificato, $b->creatoIl->getTimestamp()] <=> [$a->verificato, $a->creatoIl->getTimestamp()]);
            $tieni[$gruppo[0]->nome] = true;
        }

        return $backup->pluck('nome')->reject(fn (string $n) => isset($tieni[$n]))->values()->all();
    }

    /**
     * L'ultimo pre-ripristino resta finché non esiste un notturno verificato
     * più recente: è l'unica via di ritorno dopo un ripristino.
     *
     * @param  Collection<int,BackupInfo>  $backup
     */
    public static function preRipristinoProtetto(Collection $backup): ?string
    {
        $pre = $backup->where('tipo', 'pre-ripristino')->sortByDesc(fn (BackupInfo $b) => $b->creatoIl->getTimestamp())->first();
        if (! $pre) {
            return null;
        }
        $nuovo = $backup->contains(fn (BackupInfo $b) => $b->tipo === 'notturno' && $b->verificato && $b->creatoIl > $pre->creatoIl);

        return $nuovo ? null : $pre->nome;
    }
}
