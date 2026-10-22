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

        // Un'unica lettura coerente: scritture concorrenti non devono saltare/duplicare
        // righe né rompere le FK tra tabelle. In REPEATABLE READ la vista si fissa alla prima lettura.
        $conn = DB::connection();
        if (in_array($conn->getDriverName(), ['mysql', 'mariadb'], true)) {
            $conn->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        $conn->beginTransaction();
        try {
            foreach ($this->tabelle() as $tabella) {
                $conteggi[$tabella] = $this->esportaTabella($tabella, "$dir/$tabella.jsonl");
            }
        } finally {
            $conn->rollBack();
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
            // Solo lo schema corrente: altrimenti include i database visibili all'utente DB (es. legacy).
            Schema::getTableListing(Schema::getCurrentSchemaListing(), schemaQualified: false),
            config('backup.tabelle_escluse'),
        ));
    }

    /** @return list<string> nomi in ordine di applicazione */
    public function migrazioniApplicate(): array
    {
        return DB::table('migrations')->orderBy('id')->pluck('migration')->all();
    }

    private function esportaTabella(string $tabella, string $file): int
    {
        $fh = fopen($file, 'wb');
        if ($fh === false) {
            throw new RuntimeException("Impossibile scrivere il dump di {$tabella}.");
        }

        $n = 0;
        try {
            $colonne = $this->colonneOrdine($tabella);
            if ($colonne === ['id']) {
                // Paginazione per chiave: niente scansioni OFFSET O(n²).
                $righe = DB::table($tabella)->lazyById(1000);
            } else {
                $query = DB::table($tabella);
                foreach ($colonne as $col) {
                    $query->orderBy($col);
                }
                $righe = $query->lazy(1000);
            }
            foreach ($righe as $riga) {
                fwrite($fh, json_encode($riga, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."
");
                $n++;
            }
        } finally {
            fclose($fh);
        }

        return $n;
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
