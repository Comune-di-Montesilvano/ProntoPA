<?php

namespace App\Services\Backup;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Elenco dei backup su disco: sidecar JSON accanto a ogni zip. */
class CatalogoBackup
{
    public const TIPI = ['notturno', 'manuale', 'pre-ripristino', 'caricato'];

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
            $dati = json_decode((string) file_get_contents($file), true);
            if (! is_array($dati) || ! isset($dati['nome'], $dati['tipo'], $dati['creato_il'])) {
                continue;
            }
            $info = BackupInfo::fromArray($dati);
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

    public function ultimo(): ?BackupInfo
    {
        return $this->elenco()->first();
    }

    public function inAllarme(CarbonImmutable $ora): bool
    {
        $ultimo = $this->ultimo();

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
