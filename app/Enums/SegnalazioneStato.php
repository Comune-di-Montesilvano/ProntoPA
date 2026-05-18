<?php

namespace App\Enums;

enum SegnalazioneStato: int
{
    case NUOVA                = 1;
    case IN_CARICO            = 2;
    case ASSEGNATA_OPERATORE  = 3;
    case ASSEGNATA_IMPRESA    = 4;
    case PREVENTIVO_IN_ATTESA = 5;
    case SOSPESA              = 6;
    case COMPLETATA           = 7;
    case DUPLICATA            = 8;
    case ANNULLATA            = 9;
    case ARCHIVIATA           = 10;

    public function isTerminale(): bool
    {
        return match($this) {
            self::COMPLETATA, self::DUPLICATA, self::ANNULLATA, self::ARCHIVIATA => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match($this) {
            self::NUOVA                => 'Nuova',
            self::IN_CARICO            => 'In carico',
            self::ASSEGNATA_OPERATORE  => 'Assegnata a operatore',
            self::ASSEGNATA_IMPRESA    => 'Assegnata a impresa',
            self::PREVENTIVO_IN_ATTESA => 'Preventivo in attesa',
            self::SOSPESA              => 'Sospesa',
            self::COMPLETATA           => 'Completata',
            self::DUPLICATA            => 'Duplicata',
            self::ANNULLATA            => 'Annullata',
            self::ARCHIVIATA           => 'Archiviata',
        };
    }

    public function badgeClass(): string
    {
        return match($this) {
            self::NUOVA                => 'bg-blue-100 text-blue-800',
            self::IN_CARICO            => 'bg-indigo-100 text-indigo-800',
            self::ASSEGNATA_OPERATORE  => 'bg-green-100 text-green-800',
            self::ASSEGNATA_IMPRESA    => 'bg-cyan-100 text-cyan-800',
            self::PREVENTIVO_IN_ATTESA => 'bg-yellow-100 text-yellow-800',
            self::SOSPESA              => 'bg-gray-100 text-gray-700',
            self::COMPLETATA           => 'bg-emerald-100 text-emerald-800',
            self::DUPLICATA            => 'bg-orange-100 text-orange-700',
            self::ANNULLATA            => 'bg-red-100 text-red-700',
            self::ARCHIVIATA           => 'bg-gray-200 text-gray-600',
        };
    }
}
