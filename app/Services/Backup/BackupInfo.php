<?php

namespace App\Services\Backup;

use Carbon\CarbonImmutable;

final readonly class BackupInfo
{
    public function __construct(
        public string $nome,
        public string $tipo,
        public CarbonImmutable $creatoIl,
        public string $versione,
        public int $dimensione,
        public bool $verificato,
        public ?string $errore,
        public ?int $durataSecondi,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'nome' => $this->nome,
            'tipo' => $this->tipo,
            'creato_il' => $this->creatoIl->toIso8601String(),
            'versione' => $this->versione,
            'dimensione' => $this->dimensione,
            'verificato' => $this->verificato,
            'errore' => $this->errore,
            'durata_secondi' => $this->durataSecondi,
        ];
    }

    /** @param array<string,mixed> $d */
    public static function fromArray(array $d): self
    {
        return new self(
            nome: (string) $d['nome'],
            tipo: (string) $d['tipo'],
            creatoIl: CarbonImmutable::parse((string) $d['creato_il']),
            versione: (string) ($d['versione'] ?? ''),
            dimensione: (int) ($d['dimensione'] ?? 0),
            verificato: (bool) ($d['verificato'] ?? false),
            errore: isset($d['errore']) ? (string) $d['errore'] : null,
            durataSecondi: isset($d['durata_secondi']) ? (int) $d['durata_secondi'] : null,
        );
    }
}
