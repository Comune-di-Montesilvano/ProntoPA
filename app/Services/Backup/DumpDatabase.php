<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Dump logico (JSONL, una riga per record) indipendente dal motore:
 * lo stesso formato si legge su MariaDB e sqlite. Nessuna colonna binaria
 * nello schema, quindi nessuna codifica speciale dei valori.
 */
class DumpDatabase
{
    private const BLOCCO = 500;

    /** @return array<string,int> conteggio righe per tabella */
    public function esporta(string $dir): array
    {
        $conteggi = [];

        foreach ($this->tabelle() as $tabella) {
            $fh = fopen("$dir/$tabella.jsonl", 'wb');
            if ($fh === false) {
                throw new RuntimeException("Impossibile scrivere il dump di {$tabella}.");
            }

            $n = 0;
            try {
                $query = DB::table($tabella);
                foreach ($this->colonneOrdine($tabella) as $col) {
                    $query->orderBy($col);
                }
                foreach ($query->lazy(1000) as $riga) {
                    fwrite($fh, json_encode($riga, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
                    $n++;
                }
            } finally {
                fclose($fh);
            }
            $conteggi[$tabella] = $n;
        }

        return $conteggi;
    }

    /** @param array<string,int> $conteggi */
    public function importa(string $dir, array $conteggi): void
    {
        // Verifica prima di toccare il DB: un dump troncato non deve produrre un import parziale.
        foreach ($conteggi as $tabella => $atteso) {
            $trovate = $this->contaRighe("$dir/$tabella.jsonl");
            if ($trovate !== $atteso) {
                throw new RuntimeException("Dump di {$tabella} incoerente: attese {$atteso} righe, trovate {$trovate}.");
            }
        }

        Schema::disableForeignKeyConstraints();
        try {
            foreach ($conteggi as $tabella => $atteso) {
                $blocco = [];
                $fh = fopen("$dir/$tabella.jsonl", 'rb');
                try {
                    while (($linea = fgets($fh)) !== false) {
                        if (trim($linea) === '') {
                            continue;
                        }
                        $blocco[] = json_decode($linea, true, 512, JSON_THROW_ON_ERROR);
                        if (count($blocco) >= self::BLOCCO) {
                            DB::table($tabella)->insert($blocco);
                            $blocco = [];
                        }
                    }
                    if ($blocco) {
                        DB::table($tabella)->insert($blocco);
                    }
                } finally {
                    fclose($fh);
                }
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    /** @return list<string> */
    public function tabelle(): array
    {
        return array_values(array_diff(
            Schema::getTableListing(schemaQualified: false),
            config('backup.tabelle_escluse'),
        ));
    }

    /** @return list<string> nomi in ordine di applicazione */
    public function migrazioniApplicate(): array
    {
        return DB::table('migrations')->orderBy('id')->pluck('migration')->all();
    }

    private function contaRighe(string $file): int
    {
        if (! is_file($file)) {
            return 0;
        }
        $n = 0;
        $fh = fopen($file, 'rb');
        try {
            while (($linea = fgets($fh)) !== false) {
                if (trim($linea) !== '') {
                    $n++;
                }
            }
        } finally {
            fclose($fh);
        }

        return $n;
    }

    /** @return list<string> chiave primaria, altrimenti tutte le colonne (pivot) */
    private function colonneOrdine(string $tabella): array
    {
        foreach (Schema::getIndexes($tabella) as $indice) {
            if ($indice['primary']) {
                return $indice['columns'];
            }
        }

        return Schema::getColumnListing($tabella);
    }
}
