<?php

namespace App\Support\Scuole;

/** Calcola la categoria di partenza degli enti esistenti (usata dalla migration delle categorie). */
class CategoriaIniziale
{
    public const MIUR = 'Scuole statali';

    private const DA_TIPO_ENTE = [
        'scuola' => 'Scuole',
        'comune' => 'Comune',
        'asl' => 'ASL',
        'provincia' => 'Provincia',
        'regione' => 'Regione',
    ];

    public static function per(?string $fonteDati, ?string $tipo, ?string $tipoEnte): string
    {
        if ($fonteDati === 'miur') {
            return self::MIUR;
        }

        $tipo = self::pulisci($tipo);
        // 'Scuola' era il valore di default della colonna: non dice nulla, conta tipo_ente.
        if ($tipo !== '' && mb_strtolower($tipo) !== 'scuola') {
            return mb_substr($tipo, 0, 80);
        }

        return self::DA_TIPO_ENTE[mb_strtolower(trim((string) $tipoEnte))] ?? 'Altro';
    }

    /**
     * @param  iterable<int|string, array{0: ?string, 1: ?string, 2: ?string}>  $righe  id => [fonte_dati, tipo, tipo_ente]
     * @return array<int|string, string> id => nome categoria (chi differisce solo per maiuscole/spazi condivide il nome della prima occorrenza)
     */
    public static function assegna(iterable $righe): array
    {
        $visti = [];
        $esito = [];
        foreach ($righe as $id => [$fonte, $tipo, $tipoEnte]) {
            $nome = self::per($fonte, $tipo, $tipoEnte);
            $chiave = mb_strtolower($nome);
            $visti[$chiave] ??= $nome;
            $esito[$id] = $visti[$chiave];
        }

        return $esito;
    }

    private static function pulisci(?string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $s));
    }
}
