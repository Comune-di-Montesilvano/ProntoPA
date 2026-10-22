<?php

namespace App\Services\Backup;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * Archivio ZIP con ogni voce cifrata AES-256: manifest.json, segreti.json,
 * db/*.jsonl e storage/app/**. I file sono aggiunti da percorso (lettura
 * lazy alla chiusura) e riletti a flusso: mai un allegato intero in RAM.
 */
class ArchivioBackup
{
    /**
     * @param  array<string,mixed>  $manifest
     * @param  list<array<string,mixed>>  $segreti
     */
    public function scrivi(
        string $zip,
        #[\SensitiveParameter] string $passphrase,
        array $manifest,
        string $dirDb,
        string $dirStorage,
        array $segreti,
    ): void {
        $segretiJson = json_encode($segreti, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $voci = [];
        $checksum = ['segreti.json' => hash('sha256', $segretiJson)];
        foreach (glob(rtrim($dirDb, '/\\').'/*.jsonl') ?: [] as $file) {
            $nome = 'db/'.basename($file);
            $voci[$nome] = $file;
            $checksum[$nome] = (string) hash_file('sha256', $file);
        }
        foreach ($this->fileStorage($dirStorage) as $nome => $file) {
            $voci['storage/app/'.$nome] = $file;
        }

        $manifest['checksum'] = $checksum;
        $manifestJson = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->scriviVoci($zip, $passphrase, $manifestJson, $segretiJson, $voci);
    }

    /** @return array<string,mixed> */
    public function manifest(string $zip, #[\SensitiveParameter] string $passphrase): array
    {
        $za = $this->apri($zip, $passphrase);
        try {
            if ($za->locateName('manifest.json') === false) {
                throw new BackupNonLeggibile('Il file non è un backup ProntoPA: manifest assente.');
            }
            $json = $za->getFromName('manifest.json');
        } finally {
            $za->close();
        }

        if ($json === false) {
            throw new BackupNonLeggibile("La passphrase è errata oppure l'archivio è danneggiato.");
        }

        $manifest = json_decode($json, true);
        if (! is_array($manifest)) {
            throw new BackupNonLeggibile('La passphrase è errata oppure il manifest è illeggibile.');
        }

        return $manifest;
    }

    public function verifica(string $zip, #[\SensitiveParameter] string $passphrase): void
    {
        $manifest = $this->manifest($zip, $passphrase);
        $conteggi = (array) ($manifest['conteggi'] ?? []);
        $checksum = (array) ($manifest['checksum'] ?? []);

        $za = $this->apri($zip, $passphrase);
        try {
            foreach ($checksum as $nome => $atteso) {
                $stream = $za->getStream((string) $nome);
                if ($stream === false) {
                    throw new BackupNonLeggibile("Voce mancante nell'archivio: $nome.");
                }
                $ctx = hash_init('sha256');
                $righe = 0;
                $ultimo = "\n";
                while (! feof($stream)) {
                    try {
                        $blocco = fread($stream, $this->blocco());
                    } catch (\ErrorException) {
                        $blocco = false;
                    }
                    if ($blocco === false) {
                        throw new BackupNonLeggibile("Voce $nome corrotta o incompleta nell'archivio.");
                    }
                    if ($blocco === '') {
                        break;
                    }
                    hash_update($ctx, $blocco);
                    $righe += substr_count($blocco, "\n");
                    $ultimo = $blocco[-1];
                }
                fclose($stream);

                if (! hash_equals((string) $atteso, hash_final($ctx))) {
                    throw new BackupNonLeggibile("Checksum non valido per $nome: archivio alterato o danneggiato.");
                }

                if (str_starts_with((string) $nome, 'db/') && str_ends_with((string) $nome, '.jsonl')) {
                    if ($ultimo !== "\n") {
                        $righe++;
                    }
                    $tabella = basename((string) $nome, '.jsonl');
                    if (isset($conteggi[$tabella]) && (int) $conteggi[$tabella] !== $righe) {
                        throw new BackupNonLeggibile("Righe di $tabella diverse dal manifest: archivio alterato.");
                    }
                }
            }
        } finally {
            $za->close();
        }
    }

    public function estrai(string $zip, #[\SensitiveParameter] string $passphrase, string $dest): void
    {
        $za = $this->apri($zip, $passphrase);
        try {
            for ($i = 0; $i < $za->numFiles; $i++) {
                $nome = (string) $za->getNameIndex($i);
                $this->controllaNome($nome);
                if (str_ends_with($nome, '/')) {
                    continue;
                }

                $stream = $za->getStream($nome);
                if ($stream === false) {
                    throw new BackupNonLeggibile("La passphrase è errata oppure l'archivio è danneggiato.");
                }

                $percorso = rtrim($dest, '/\\').'/'.$nome;
                if (! is_dir(dirname($percorso))) {
                    mkdir(dirname($percorso), 0775, true);
                }
                $out = fopen($percorso, 'wb');
                if ($out === false) {
                    fclose($stream);
                    throw new BackupNonLeggibile("Impossibile scrivere $nome durante l'estrazione.");
                }
                try {
                    $copiati = stream_copy_to_stream($stream, $out);
                } catch (\ErrorException) {
                    $copiati = false; // il warning di libzip (CRC/AES) è promosso a eccezione dal framework
                }
                fclose($out);
                fclose($stream);

                // Un errore a metà lettura (voce corrotta, autenticazione AES finale) non deve lasciare file troncati.
                if ($copiati === false || $copiati !== ($za->statIndex($i)['size'] ?? -1)) {
                    throw new BackupNonLeggibile("Voce $nome corrotta o incompleta nell'archivio.");
                }
            }
        } finally {
            $za->close();
        }
    }

    public function ricifra(
        string $zip,
        #[\SensitiveParameter] string $da,
        #[\SensitiveParameter] string $a,
    ): void {
        // Accanto allo zip: per gli upload è tmp/ del volume, ripulita da CatalogoBackup::pulisciTemporanei().
        $tmp = dirname($zip).'/.ricifra-'.bin2hex(random_bytes(8));
        mkdir($tmp, 0700, true);
        chmod($tmp, 0700);
        $nuovo = $zip.'.nuovo';

        try {
            // Mai ricifrare un archivio corrotto: si perderebbe l'unica copia buona.
            $this->verifica($zip, $da);
            $this->estrai($zip, $da, $tmp);

            $voci = [];
            foreach ($this->fileStorage($tmp) as $nome => $file) {
                $voci[$nome] = $file;
            }
            if (! isset($voci['manifest.json'], $voci['segreti.json'])) {
                throw new BackupNonLeggibile('Archivio incompleto: manifest o segreti assenti.');
            }
            $manifestJson = (string) file_get_contents($voci['manifest.json']);
            $segretiJson = (string) file_get_contents($voci['segreti.json']);
            unset($voci['manifest.json'], $voci['segreti.json']);

            $this->scriviVoci($nuovo, $a, $manifestJson, $segretiJson, $voci);
            $this->manifest($nuovo, $a);
            rename($nuovo, $zip);
        } finally {
            if (is_file($nuovo)) {
                unlink($nuovo);
            }
            $this->rimuoviDir($tmp);
        }
    }

    /** @param array<string,string> $voci nome nell'archivio => percorso su disco */
    private function scriviVoci(string $zip, #[\SensitiveParameter] string $passphrase, string $manifestJson, string $segretiJson, array $voci): void
    {
        if (is_file($zip)) {
            unlink($zip);
        }
        $za = new ZipArchive;
        if ($za->open($zip, ZipArchive::CREATE) !== true) {
            throw new BackupNonLeggibile("Impossibile creare l'archivio $zip.");
        }

        $cifra = function (string $nome) use ($za, $passphrase): void {
            if (! $za->setEncryptionName($nome, ZipArchive::EM_AES_256, $passphrase)) {
                throw new BackupNonLeggibile("Cifratura non riuscita per $nome.");
            }
        };

        $za->addFromString('manifest.json', $manifestJson);
        $cifra('manifest.json');
        $za->addFromString('segreti.json', $segretiJson);
        $cifra('segreti.json');
        foreach ($voci as $nome => $file) {
            $za->addFile($file, $nome);
            $cifra($nome);
        }

        if (! $za->close()) {
            throw new BackupNonLeggibile("Scrittura dell'archivio non riuscita (spazio su disco?).");
        }
    }

    /** @return \Generator<string,string> nome relativo => percorso (quarantena esclusa) */
    private function fileStorage(string $radice): \Generator
    {
        if (! is_dir($radice)) {
            return;
        }
        $radice = rtrim(str_replace('\\', '/', $radice), '/');
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($radice, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($it as $file) {
            $percorso = str_replace('\\', '/', $file->getPathname());
            $relativo = substr($percorso, strlen($radice) + 1);
            if ($relativo === 'quarantena' || str_starts_with($relativo, 'quarantena/')) {
                continue;
            }
            yield $relativo => $percorso;
        }
    }

    private function apri(string $zip, #[\SensitiveParameter] string $passphrase): ZipArchive
    {
        $za = new ZipArchive;
        if (! is_file($zip) || $za->open($zip, ZipArchive::RDONLY) !== true) {
            throw new BackupNonLeggibile('Il file non è un archivio ZIP valido o è troncato.');
        }
        $za->setPassword($passphrase);

        return $za;
    }

    /** Difesa zip-slip: gli archivi caricati dall'admin non sono fidati. */
    private function controllaNome(string $nome): void
    {
        $segmenti = preg_split('#[/\\\\]#', $nome) ?: [];
        if ($nome === '' || $nome[0] === '/' || $nome[0] === '\\' || preg_match('#^[A-Za-z]:#', $nome)
            || str_contains($nome, "\0") || in_array('..', $segmenti, true)) {
            throw new BackupNonLeggibile("Percorso non ammesso nell'archivio: $nome.");
        }
    }

    private function blocco(): int
    {
        return max(8192, (int) config('backup.dimensione_blocco', 5 * 1024 * 1024));
    }

    private function rimuoviDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
