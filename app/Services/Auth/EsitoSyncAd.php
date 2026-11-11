<?php

namespace App\Services\Auth;

use Illuminate\Support\Carbon;

/** Risultato di un giro di sincronizzazione AD (mostrato in Admin → Utenti). */
final class EsitoSyncAd
{
    public const OK = 'ok';

    public const FALLITA = 'fallita';

    public const BLOCCATA = 'bloccata';

    public function __construct(
        public readonly string $stato,
        public readonly Carbon $quando,
        public readonly int $creati = 0,
        public readonly int $aggiornati = 0,
        public readonly int $riattivati = 0,
        public readonly int $disattivati = 0,
        public readonly int $daDisattivare = 0,
        public readonly int $senzaEmail = 0,
        public readonly int $usernameOccupati = 0,
        public readonly ?string $motivo = null,
    ) {}

    public static function fallita(string $motivo): self
    {
        return new self(self::FALLITA, now(), motivo: $motivo);
    }

    public function ok(): bool
    {
        return $this->stato === self::OK;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'stato' => $this->stato,
            'quando' => $this->quando->toIso8601String(),
            'creati' => $this->creati,
            'aggiornati' => $this->aggiornati,
            'riattivati' => $this->riattivati,
            'disattivati' => $this->disattivati,
            'daDisattivare' => $this->daDisattivare,
            'senzaEmail' => $this->senzaEmail,
            'usernameOccupati' => $this->usernameOccupati,
            'motivo' => $this->motivo,
        ];
    }

    /** @param array<string, mixed> $dati */
    public static function fromArray(array $dati): self
    {
        return new self(
            stato: (string) ($dati['stato'] ?? self::FALLITA),
            quando: Carbon::parse((string) ($dati['quando'] ?? 'now')),
            creati: (int) ($dati['creati'] ?? 0),
            aggiornati: (int) ($dati['aggiornati'] ?? 0),
            riattivati: (int) ($dati['riattivati'] ?? 0),
            disattivati: (int) ($dati['disattivati'] ?? 0),
            daDisattivare: (int) ($dati['daDisattivare'] ?? 0),
            senzaEmail: (int) ($dati['senzaEmail'] ?? 0),
            usernameOccupati: (int) ($dati['usernameOccupati'] ?? 0),
            motivo: isset($dati['motivo']) ? (string) $dati['motivo'] : null,
        );
    }
}
