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

    /** Tono del linguaggio visivo ProntoPA (token in resources/css/app.css). */
    public function tono(): string
    {
        return match($this) {
            self::IN_CARICO                                   => 'primary',
            self::ASSEGNATA_OPERATORE, self::ASSEGNATA_IMPRESA => 'violet',
            self::PREVENTIVO_IN_ATTESA, self::SOSPESA         => 'amber',
            self::COMPLETATA                                  => 'emerald',
            default                                           => 'slate',
        };
    }

    /** Etichetta mostrata: come label(), ma la duplicata è "Accorpata". */
    public function etichetta(): string
    {
        return $this === self::DUPLICATA ? 'Accorpata' : $this->label();
    }

    /**
     * Frase in linguaggio semplice per chi ha segnalato.
     *
     * @return array{titolo: string, dettaglio: string}
     */
    public function fraseSegnalante(): array
    {
        return match($this) {
            self::NUOVA     => ['titolo' => 'Ricevuta', 'dettaglio' => 'Il comune la esaminerà a breve.'],
            self::IN_CARICO => ['titolo' => 'Presa in carico', 'dettaglio' => "Il comune sta organizzando l'intervento."],
            self::ASSEGNATA_OPERATORE, self::ASSEGNATA_IMPRESA, self::PREVENTIVO_IN_ATTESA
                            => ['titolo' => 'Ci stiamo lavorando', 'dettaglio' => 'Ti avviseremo quando il lavoro sarà chiuso.'],
            self::SOSPESA    => ['titolo' => 'In attesa', 'dettaglio' => 'Il lavoro è momentaneamente sospeso.'],
            self::COMPLETATA => ['titolo' => 'Risolta', 'dettaglio' => 'Se il problema persiste, scrivici qui sotto.'],
            self::DUPLICATA  => ['titolo' => 'Già in lavorazione', 'dettaglio' => 'Era già stata segnalata: segui gli aggiornamenti nella segnalazione collegata.'],
            self::ANNULLATA  => ['titolo' => 'Chiusa senza intervento', 'dettaglio' => 'La segnalazione è stata chiusa senza intervento.'],
            self::ARCHIVIATA => ['titolo' => 'Archiviata', 'dettaglio' => 'La segnalazione è stata archiviata.'],
        };
    }

    /** Tappa della barra di avanzamento: 1 ricevuta, 2 presa in carico, 3 in lavorazione, 4 chiusa. */
    public function tappa(): int
    {
        return match($this) {
            self::NUOVA     => 1,
            self::IN_CARICO => 2,
            self::ASSEGNATA_OPERATORE, self::ASSEGNATA_IMPRESA, self::PREVENTIVO_IN_ATTESA, self::SOSPESA => 3,
            default         => 4,
        };
    }
}
