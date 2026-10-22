<?php

namespace App\Services\Backup;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/** Elenco dei backup su disco: sidecar JSON accanto a ogni zip. */
class CatalogoBackup
{
    public const TIPI = ['notturno', 'manuale', 'pre-ripristino', 'caricato'];

    public const ORE_TEMPORANEI = 3;

    /** Marcatore del backup in corso, in stato/ (vedi inCorso()). */
    public const MARCATORE = 'backup-in-corso.json';

    public function cartella(): string
    {
        return rtrim((string) config('backup.path'), '/\\');
    }

    /** @return Collection<int,BackupInfo> dal più recente */
    public function elenco(): Collection
    {
        $cartella = $this->cartella();
        $per = [];

        foreach (glob($cartella.'/prontopa-*.json') ?: [] as $file) {
            try {
                $dati = json_decode((string) file_get_contents($file), true);
                if (! is_array($dati) || ! isset($dati['nome'], $dati['tipo'], $dati['creato_il'])
                    || $dati['nome'].'.json' !== basename($file)) {
                    throw new \InvalidArgumentException('campi mancanti o nome non corrispondente');
                }
                $this->percorso((string) $dati['nome']);
                $info = BackupInfo::fromArray($dati);
            } catch (\Throwable $e) {
                Log::warning('Sidecar di backup ignorato: '.basename($file).' ('.$e->getMessage().')');

                continue;
            }
            $zip = $cartella.'/'.$info->nome;
            $esiste = is_file($zip);
            // Senza zip il backup non esiste (fallito, o file rimosso a mano).
            $per[$info->nome] = new BackupInfo(
                $info->nome, $info->tipo, $info->creatoIl, $info->versione,
                $esiste ? (int) filesize($zip) : 0,
                $esiste && $info->verificato, $info->errore, $info->durataSecondi,
            );
        }

        foreach (glob($cartella.'/prontopa-*.zip') ?: [] as $zip) {
            $nome = basename($zip);
            if (isset($per[$nome]) || ! ($parsed = $this->daNome($nome))) {
                continue;
            }
            [$tipo, $quando, $versione] = $parsed;
            $per[$nome] = new BackupInfo($nome, $tipo, $quando, $versione, (int) filesize($zip), false, null, null);
        }

        return collect(array_values($per))->sortByDesc(fn (BackupInfo $b) => $b->creatoIl->getTimestamp())->values();
    }

    public function percorso(string $nome): string
    {
        if (! preg_match('/^prontopa-[A-Za-z0-9._-]+\.zip$/', $nome) || str_contains($nome, '..')) {
            throw new \InvalidArgumentException('Nome di backup non valido.');
        }

        return $this->cartella().'/'.$nome;
    }

    public function salvaInfo(BackupInfo $info): void
    {
        $this->percorso($info->nome);
        if (! is_dir($this->cartella())) {
            mkdir($this->cartella(), 0775, true);
        }
        file_put_contents(
            $this->cartella().'/'.$info->nome.'.json',
            json_encode($info->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }

    public function elimina(string $nome): void
    {
        $zip = $this->percorso($nome);
        if (Retention::preRipristinoProtetto($this->elenco()) === $nome) {
            throw new BackupProtetto("Il backup $nome è l'ultimo pre-ripristino e non può essere eliminato.");
        }
        @unlink($zip);
        @unlink($zip.'.json');
    }

    /** Cartella di lavoro sotto tmp/, leggibile solo dal proprietario: può contenere dati in chiaro. */
    public function temporanea(string $nome): string
    {
        $dir = $this->cartella().'/tmp/'.$nome;
        File::ensureDirectoryExists($dir, 0700);
        chmod($dir, 0700);

        return $dir;
    }

    /**
     * Toglie i resti di processi interrotti (dump, estrazioni, ricifrature, upload
     * abbandonati) e gli stati vecchi. Solo per età, senza guardare il lock: prenderlo
     * qui potrebbe far rinunciare un backup notturno concorrente, e oltre ORE_TEMPORANEI
     * (più di ogni timeout dei job e TTL dei lock) nessun processo vivo li usa più.
     */
    public function pulisciTemporanei(): void
    {
        $limite = time() - self::ORE_TEMPORANEI * 3600;
        $tmp = $this->cartella().'/tmp';
        foreach (is_dir($tmp) ? (scandir($tmp) ?: []) : [] as $nome) {
            $voce = $tmp.'/'.$nome;
            if ($nome === '.' || $nome === '..' || (int) @filemtime($voce) >= $limite) {
                continue;
            }
            is_dir($voce) && ! is_link($voce) ? File::deleteDirectory($voce) : @unlink($voce);
        }

        foreach (glob($this->cartella().'/stato/*') ?: [] as $file) {
            $eta = str_ends_with($file, '.json') && basename($file) !== self::MARCATORE ? 30 * 86400 : self::ORE_TEMPORANEI * 3600;
            if ((int) @filemtime($file) < time() - $eta) {
                @unlink($file);
            }
        }
    }

    /** Marcatore per la pagina Backup: scritto all'accodamento e all'avvio, tolto alla fine. */
    public function segnaInCorso(string $tipo, string $fase): void
    {
        $file = $this->cartella().'/stato/'.self::MARCATORE;
        File::ensureDirectoryExists(dirname($file));
        $tmp = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
        file_put_contents($tmp, json_encode(
            ['tipo' => $tipo, 'fase' => $fase, 'dal' => CarbonImmutable::now()->toIso8601String()],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
        rename($tmp, $file);
    }

    /**
     * Un marcatore più vecchio di ORE_TEMPORANEI è di un processo morto (o di un job mai
     * partito): ignorato, così "Backup ora" non resta bloccato.
     *
     * @return array{tipo:string,fase:string,dal:string}|null
     */
    public function inCorso(): ?array
    {
        $file = $this->cartella().'/stato/'.self::MARCATORE;
        clearstatcache(true, $file);
        if (! is_file($file) || (int) @filemtime($file) < time() - self::ORE_TEMPORANEI * 3600) {
            return null;
        }
        $dati = json_decode((string) @file_get_contents($file), true);

        return is_array($dati) && isset($dati['tipo'], $dati['fase'], $dati['dal']) ? $dati : null;
    }

    public function fineInCorso(): void
    {
        @unlink($this->cartella().'/stato/'.self::MARCATORE);
    }

    public function ultimo(): ?BackupInfo
    {
        return $this->elenco()->first();
    }

    /** I caricati non contano: sono copie di altre installazioni, non backup di questa. */
    public function inAllarme(CarbonImmutable $ora): bool
    {
        $ultimo = $this->elenco()->first(fn (BackupInfo $b) => $b->tipo !== 'caricato');

        return $ultimo === null
            || ! $ultimo->verificato
            || $ultimo->creatoIl->addHours((int) config('backup.ore_allarme')) < $ora;
    }

    /** @return array{string,CarbonImmutable,string}|null [tipo, creatoIl, versione] */
    private function daNome(string $nome): ?array
    {
        $tipi = implode('|', array_map('preg_quote', self::TIPI));
        if (! preg_match('/^prontopa-(\d{8}-\d{6})-(.+)-('.$tipi.')\.zip$/', $nome, $m)) {
            return null;
        }
        $quando = CarbonImmutable::createFromFormat('Ymd-His', $m[1]);

        return $quando ? [$m[3], $quando, $m[2]] : null;
    }
}
