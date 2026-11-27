<?php

namespace App\Services\Avvisi;

use App\Notifications\Riepilogo\Raggruppabile;

/** Il contenuto di un riepilogo per un destinatario, pronto da impaginare (email o Telegram). */
final class Composizione
{
    /**
     * @param  array{nuove: list<Voce>, da_fare: list<Voce>, in_ritardo: list<Voce>}  $quadro
     * @param  array<string, list<Voce>>  $sezioni  categoria => voci, solo le non vuote, in `Categorie::ORDINE`
     * @param  list<int>  $idRigheUsate  tutte le righe lette (valide, non più valide, assorbite dal quadro)
     * @param  Raggruppabile|null  $unica  resta un solo avviso (quadro vuoto): si reinvia l'originale
     * @param  array<string, int>  $altreQuadro  sezione del quadro => quante non mostrate (oltre il limite)
     */
    public function __construct(
        public readonly array $quadro,
        public readonly array $sezioni,
        public readonly array $idRigheUsate,
        public readonly ?Raggruppabile $unica,
        public readonly array $altreQuadro = [],
    ) {}

    public function vuota(): bool
    {
        return $this->quadroVuoto() && $this->sezioni === [];
    }

    public function quadroVuoto(): bool
    {
        foreach ($this->quadro as $voci) {
            if ($voci !== []) {
                return false;
            }
        }

        return true;
    }

    /** Segnalazioni distinte tra quadro e sezioni. */
    public function totaleSegnalazioni(): int
    {
        $id = [];
        foreach ([...array_values($this->quadro), ...array_values($this->sezioni)] as $voci) {
            foreach ($voci as $voce) {
                $id[(int) $voce->segnalazione->id_segnalazione] = true;
            }
        }

        return count($id);
    }
}
