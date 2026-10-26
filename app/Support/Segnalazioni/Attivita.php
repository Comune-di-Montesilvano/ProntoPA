<?php

namespace App\Support\Segnalazioni;

use App\Enums\SegnalazioneStato;
use App\Models\AllegatoSegnalazione;
use App\Models\Appalto;
use App\Models\Impostazione;
use App\Models\Impresa;
use App\Models\NotaSegnalazione;
use App\Models\Segnalazione;
use App\Models\StoricoStatoSegnalazione;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Storia unica della segnalazione (note, cambi di stato, allegati) dalla più recente,
 * raccontata secondo chi la legge: il segnalante vede "l'ufficio" e non i nomi,
 * ditta e operaio vedono solo da quando il lavoro è arrivato a loro.
 *
 * @phpstan-type Voce array{quando: Carbon, autore: string, iniziali: string, tipo: 'nota'|'stato'|'allegato', testo: ?string, frase: string, tag: ?string, allegato: ?AllegatoSegnalazione}
 */
class Attivita
{
    /** @var array<int, User|null> */
    private array $utenti = [];

    /** @var array<int, string> */
    private array $letture = [];

    private function __construct(
        private readonly Segnalazione $segnalazione,
        private readonly User $lettore,
        private readonly string $lettura,
    ) {}

    /** @return Collection<int, Voce> */
    public static function per(Segnalazione $segnalazione, User $lettore): Collection
    {
        return (new self($segnalazione, $lettore, LetturaScheda::per($lettore)))->voci();
    }

    /** @return Collection<int, Voce> */
    private function voci(): Collection
    {
        /** @var Collection<int, StoricoStatoSegnalazione> $storico */
        $storico = $this->segnalazione->storicoStati()->orderBy('data_registrazione')->get();
        $dal = $this->inizioLavoro($storico);

        $voci = collect();

        foreach ($this->segnalazione->note()->visibiliA($this->lettore)->get() as $nota) {
            $voci->push($this->daNota($nota));
        }
        foreach ($storico as $evento) {
            if ($dal === null || Carbon::parse($evento->data_registrazione)->gte($dal)) {
                $voci->push($this->daStato($evento));
            }
        }
        foreach ($this->segnalazione->allegati()->get() as $allegato) {
            $voci->push($this->daAllegato($allegato));
        }

        return $voci->sortByDesc(fn (array $v) => $v['quando']->getTimestamp())->values();
    }

    /**
     * Per la lettura "lavoro" la storia parte dall'assegnazione: alla ditta dal primo passaggio
     * a impresa, all'operaio dal primo a operatore o impresa.
     *
     * @param  Collection<int, StoricoStatoSegnalazione>  $storico
     */
    private function inizioLavoro(Collection $storico): ?Carbon
    {
        if ($this->lettura !== 'lavoro') {
            return null;
        }

        $stati = $this->lettore->hasRole('impresa')
            ? [SegnalazioneStato::ASSEGNATA_IMPRESA->value]
            : [SegnalazioneStato::ASSEGNATA_OPERATORE->value, SegnalazioneStato::ASSEGNATA_IMPRESA->value];

        $primo = $storico->first(fn ($e) => in_array((int) $e->id_stato_segnalazione, $stati, true));

        return $primo ? Carbon::parse($primo->data_registrazione) : null;
    }

    /** @return Voce */
    private function daNota(NotaSegnalazione $nota): array
    {
        return $this->voce($nota->data, (int) $nota->id_utente, 'nota', $nota->testo, 'ha scritto', $this->tagNota($nota));
    }

    /** @return Voce */
    private function daStato(StoricoStatoSegnalazione $evento): array
    {
        $stato = SegnalazioneStato::tryFrom((int) $evento->id_stato_segnalazione);

        $frase = $this->lettura === 'segnalante'
            ? ($stato?->fraseSegnalante()['titolo'] ?? 'Stato aggiornato')
            : $this->fraseInterna($stato, $evento);

        return $this->voce($evento->data_registrazione, (int) $evento->id_utente, 'stato', null, $frase);
    }

    /** @return Voce */
    private function daAllegato(AllegatoSegnalazione $allegato): array
    {
        $foto = str_starts_with((string) $allegato->tipo, 'image/');
        $frase = $allegato->fase === 'dopo'
            ? 'ha aggiunto una foto del lavoro finito'
            : ($foto ? 'ha aggiunto una foto' : 'ha allegato un file');

        return $this->voce($allegato->created_at, (int) $allegato->id_utente_creazione, 'allegato', null, $frase, null, $allegato);
    }

    private function fraseInterna(?SegnalazioneStato $stato, StoricoStatoSegnalazione $evento): string
    {
        return match ($stato) {
            SegnalazioneStato::NUOVA => 'ha inviato la segnalazione',
            SegnalazioneStato::IN_CARICO => 'ha preso in carico',
            SegnalazioneStato::ASSEGNATA_OPERATORE => 'ha assegnato a '.$this->nome((int) $evento->id_utente_collegato, 'un operatore'),
            SegnalazioneStato::ASSEGNATA_IMPRESA => 'ha assegnato a '.($this->ditta((int) $evento->id_appalto) ?? 'una ditta'),
            SegnalazioneStato::PREVENTIVO_IN_ATTESA => 'ha presentato un preventivo',
            SegnalazioneStato::SOSPESA => 'ha sospeso',
            SegnalazioneStato::COMPLETATA => 'ha chiuso',
            SegnalazioneStato::DUPLICATA => 'ha accorpato a una segnalazione precedente',
            SegnalazioneStato::ANNULLATA => 'ha annullato',
            SegnalazioneStato::ARCHIVIATA => 'ha archiviato',
            null => 'ha aggiornato lo stato',
        };
    }

    private function tagNota(NotaSegnalazione $nota): ?string
    {
        if ($this->lettura !== 'gestore') {
            return null;
        }

        return match (true) {
            $nota->visibile_web && $nota->visibile_impresa => 'Ditta e segnalante',
            $nota->visibile_web => 'Segnalante',
            $nota->visibile_impresa => 'Ditta',
            default => 'Interna',
        };
    }

    /** @return Voce */
    private function voce(mixed $quando, int $idAutore, string $tipo, ?string $testo, string $frase, ?string $tag = null, ?AllegatoSegnalazione $allegato = null): array
    {
        $autore = $this->nomeAutore($idAutore);

        return [
            'quando' => Carbon::parse($quando),
            'autore' => $autore,
            'iniziali' => self::iniziali($autore),
            'tipo' => $tipo,
            'testo' => $testo,
            'frase' => $frase,
            'tag' => $tag,
            'allegato' => $allegato,
        ];
    }

    private function nomeAutore(int $id): string
    {
        if ($id === $this->lettore->id) {
            return $this->lettura === 'segnalante' ? 'Tu' : ($this->lettore->name ?: 'Tu');
        }

        $utente = $this->utente($id);

        if ($this->lettura === 'segnalante') {
            return $utente !== null && $this->letturaDi($utente) === 'segnalante'
                ? 'Un altro segnalante'
                : Impostazione::get('etichetta_ufficio_segnalante', 'Ufficio manutenzioni');
        }

        return $utente?->name ?: 'Sistema';
    }

    private function nome(int $id, string $altrimenti): string
    {
        $utente = $this->utente($id);

        return $utente !== null && $utente->name ? $utente->name : $altrimenti;
    }

    private function letturaDi(User $utente): string
    {
        return $this->letture[$utente->id] ??= LetturaScheda::per($utente);
    }

    private function utente(int $id): ?User
    {
        if ($id <= 0) {
            return null;
        }

        return array_key_exists($id, $this->utenti) ? $this->utenti[$id] : ($this->utenti[$id] = User::find($id));
    }

    private function ditta(int $idAppalto): ?string
    {
        $idImpresa = $idAppalto > 0 ? Appalto::whereKey($idAppalto)->value('id_impresa') : null;

        return $idImpresa ? Impresa::whereKey($idImpresa)->value('ragione_sociale') : null;
    }

    public static function iniziali(string $nome): string
    {
        $parole = preg_split('/\s+/', trim($nome), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];

        return mb_strtoupper(mb_substr($parole[0], 0, 1).(isset($parole[1]) ? mb_substr($parole[1], 0, 1) : ''));
    }
}
