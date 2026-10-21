<?php

namespace App\Services\Backup;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Le colonne cifrate con APP_KEY non sopravvivono a un cambio di chiave:
 * nel backup viaggiano in chiaro (dentro lo ZIP cifrato) e al ripristino
 * vengono ricifrate con la APP_KEY del nuovo ambiente.
 */
class SegretiCifrati
{
    /**
     * @return list<array{tabella:string,colonna:string,id?:int|string,chiave?:string,valore:string}>
     */
    public function estrai(): array
    {
        $segreti = [];

        foreach ((array) config('backup.colonne_cifrate') as $def) {
            $righe = DB::table($def['tabella'])
                ->when(isset($def['chiave']), fn ($q) => $q->where($def['chiave'], $def['valore']))
                ->whereNotNull($def['colonna'])
                ->get();

            foreach ($righe as $riga) {
                $grezzo = (string) $riga->{$def['colonna']};
                if ($grezzo === '') {
                    continue;
                }

                $chiaveRiga = isset($def['chiave']) ? $def['chiave'] : 'id';
                $idRiga = isset($def['chiave']) ? $def['valore'] : $riga->id;

                try {
                    // Fortify cifra con encrypt() (serializzato): il valore in chiaro è la stringa serializzata,
                    // che applica() ricifra identica.
                    $valore = Crypt::decryptString($grezzo);
                } catch (DecryptException) {
                    Log::warning('Backup: valore cifrato non decifrabile, saltato', [
                        'tabella' => $def['tabella'],
                        'colonna' => $def['colonna'],
                        $chiaveRiga => $idRiga,
                    ]);

                    continue;
                }

                $segreti[] = [
                    'tabella' => $def['tabella'],
                    'colonna' => $def['colonna'],
                    $chiaveRiga => $idRiga,
                    'valore' => $valore,
                ];
            }
        }

        return $segreti;
    }

    /** @param list<array<string,mixed>> $segreti */
    public function applica(array $segreti): void
    {
        foreach ($segreti as $s) {
            $cifrato = Crypt::encryptString((string) $s['valore']);

            if (isset($s['chiave'])) {
                DB::table($s['tabella'])->where('chiave', $s['chiave'])->update([$s['colonna'] => $cifrato]);
            } else {
                DB::table($s['tabella'])->where('id', $s['id'])->update([$s['colonna'] => $cifrato]);
            }
        }
    }
}
