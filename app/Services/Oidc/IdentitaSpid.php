<?php

namespace App\Services\Oidc;

final class IdentitaSpid
{
    public function __construct(
        public readonly string $codiceFiscale,
        public readonly string $nome,
        public readonly string $cognome,
        public readonly ?string $email,
        public readonly string $subject,
    ) {}

    public function nomeCompleto(): string
    {
        return trim($this->nome.' '.$this->cognome);
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'codice_fiscale' => $this->codiceFiscale,
            'nome' => $this->nome,
            'cognome' => $this->cognome,
            'email' => $this->email,
            'subject' => $this->subject,
        ];
    }

    /** @param array<string, string|null> $dati */
    public static function fromArray(array $dati): self
    {
        return new self(
            (string) $dati['codice_fiscale'],
            (string) $dati['nome'],
            (string) $dati['cognome'],
            $dati['email'] ?? null,
            (string) $dati['subject'],
        );
    }
}
