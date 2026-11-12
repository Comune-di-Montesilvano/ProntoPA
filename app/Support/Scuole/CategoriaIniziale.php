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
        // La categoria MIUR esiste sempre: vale la sua grafia anche se un tipo scritto a mano differisce solo per maiuscole.
        $visti = [mb_strtolower(self::MIUR) => self::MIUR];
        $esito = [];
        foreach ($righe as $id => [$fonte, $tipo, $tipoEnte]) {
            $nome = self::per($fonte, $tipo, $tipoEnte);
            $chiave = mb_strtolower($nome);
            $visti[$chiave] ??= $nome;
            $esito[$id] = $visti[$chiave];
        }

        return $esito;
    }

    /**
     * Id della categoria con quel nome, creandola se manca. Il confronto lo fa il DB (con la sua collation:
     * accenti e maiuscole inclusi), cosi due nomi uguali per il DB non violano l'unique su `nome`.
     */
    public static function trovaOCrea(string $nome, int $ordine, bool $importMiur): int
    {
        $db = \Illuminate\Support\Facades\DB::table('categorie_enti');
        $id = (clone $db)->where('nome', $nome)->value('id_categoria');
        if ($id !== null) {
            return (int) $id;
        }

        return (int) $db->insertGetId([
            'nome' => $nome,
            'ordine' => $ordine,
            'import_miur' => $importMiur,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id_categoria');
    }

    private static function pulisci(?string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $s));
    }
}
