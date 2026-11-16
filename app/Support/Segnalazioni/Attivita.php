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
 * @phpstan-type Voce array{quando: Carbon, autore: string, iniziali: string, tipo: 'nota'|'stato'|'allegato', forma: 'passaggio'|'messaggio', stato: ?SegnalazioneStato, testo: ?string, nota: ?string, frase: string, letta_da: ?string, allegato: ?AllegatoSegnalazione}
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
        $storico = StoricoOrdinato::di($this->segnalazione);
        $dal = $this->inizioLavoro($storico);

        $passaggi = [];
        $meta = [];
        $precedente = null;
        foreach ($storico as $evento) {
            if ($dal === null || Carbon::parse($evento->data_registrazione)->gte($dal)) {
                $passaggi[] = $this->daStato($evento, $precedente);
                $meta[] = ['autore' => (int) $evento->id_utente, 'stato' => (int) $evento->id_stato_segnalazione];
            }
            $precedente = (int) $evento->id_stato_segnalazione;
        }

        $altre = [];
        foreach ($this->segnalazione->note()->visibiliA($this->lettore)->get() as $nota) {
            $i = $this->lettura === 'segnalante' ? null : $this->passaggioDellaNota($nota, $passaggi, $meta);
            if ($i !== null) {
                $testo = $nota->origine ? $nota->testoSenzaPrefisso() : (string) $nota->testo;
                $passaggi[$i]['nota'] = trim(($passaggi[$i]['nota'] !== null ? $passaggi[$i]['nota']."\n" : '').$testo) ?: null;
                $passaggi[$i]['letta_da'] ??= $this->lettaDa($nota);

                continue;
            }
            $altre[] = $this->daNota($nota);
        }
        foreach ($this->segnalazione->allegati()->get() as $allegato) {
            $altre[] = $this->daAllegato($allegato);
        }

        return collect($passaggi)->concat($altre)->sortByDesc(fn (array $v) => $v['quando']->getTimestamp())->values();
    }

    /**
     * Nota scritta insieme a un passaggio (stesso autore, stesso minuto; rimando e chiusura senza
     * intervento col loro passaggio): si mostra dentro quel passaggio, non come voce a parte.
     *
     * @param  list<Voce>  $passaggi
     * @param  list<array{autore: int, stato: int}>  $meta
     */
    private function passaggioDellaNota(NotaSegnalazione $nota, array $passaggi, array $meta): ?int
    {
        $quando = Carbon::parse($nota->data);
        $stati = match ($nota->origine) {
            NotaSegnalazione::ORIGINE_RIMANDO => [SegnalazioneStato::NUOVA->value, SegnalazioneStato::IN_CARICO->value],
            NotaSegnalazione::ORIGINE_SENZA_INTERVENTO => [SegnalazioneStato::ANNULLATA->value],
            null => null,
            default => false,
        };
        if ($stati === false) {
            return null;
        }

        $migliore = null;
        $distanza = PHP_INT_MAX;
        foreach ($passaggi as $i => $p) {
            if ($meta[$i]['autore'] !== (int) $nota->id_utente) {
                continue;
            }
            $d = abs($p['quando']->getTimestamp() - $quando->getTimestamp());
            $va = $stati === null
                ? $p['quando']->format('Y-m-d H:i') === $quando->format('Y-m-d H:i')
                : in_array($meta[$i]['stato'], $stati, true) && $d <= 300;
            if ($va && $d < $distanza) {
                [$migliore, $distanza] = [$i, $d];
            }
        }

        return $migliore;
    }

    /**
     * Per la lettura "lavoro" la storia parte dall'assegnazione: alla ditta dal primo passaggio
     * a impresa, all'operaio dal primo a operatore o impresa.
     *
     * @param  Collection<int, StoricoStatoSegnalazione>  $storico
     */
    private function inizioLavoro(Collection $storico): ?Carbon
    {
        return $this->lettura === 'lavoro' ? self::inizioLavoroPer($this->lettore, $storico) : null;
    }

    /**
     * Quando il lavoro è arrivato a chi lo fa (usato anche dal cronogramma).
     *
     * @param  Collection<int, StoricoStatoSegnalazione>  $storico  ordinato crescente
     */
    public static function inizioLavoroPer(User $lettore, Collection $storico): ?Carbon
    {
        $stati = $lettore->hasRole('impresa')
            ? [SegnalazioneStato::ASSEGNATA_IMPRESA->value]
            : [SegnalazioneStato::ASSEGNATA_OPERATORE->value, SegnalazioneStato::ASSEGNATA_IMPRESA->value];

        $primo = $storico->first(fn ($e) => in_array((int) $e->id_stato_segnalazione, $stati, true));

        return $primo ? Carbon::parse($primo->data_registrazione) : null;
    }

    /** @return Voce */
    private function daNota(NotaSegnalazione $nota): array
    {
        $lettaDa = $this->lettaDa($nota);
        if ($this->lettura === 'segnalante') {
            return $this->voce($nota->data, (int) $nota->id_utente, 'nota', $nota->testo, 'ha scritto', lettaDa: null);
        }

        $senza = $nota->testoSenzaPrefisso();

        return match ($nota->origine) {
            NotaSegnalazione::ORIGINE_SCADENZA => $this->scadenzaSpostata($nota, $senza, $lettaDa),
            NotaSegnalazione::ORIGINE_RISEGNALATA => $this->risegnalata($nota, $senza, $lettaDa),
            NotaSegnalazione::ORIGINE_PRIORITA => $this->voce($nota->data, (int) $nota->id_utente, 'nota', null,
                $this->prioritaAumentata($senza), forma: 'passaggio', lettaDa: $lettaDa),
            NotaSegnalazione::ORIGINE_RIMANDO => $this->voce($nota->data, (int) $nota->id_utente, 'nota', null,
                "l'ha rimandata al comune", forma: 'passaggio', nota: $senza, lettaDa: $lettaDa),
            NotaSegnalazione::ORIGINE_SENZA_INTERVENTO => $this->voce($nota->data, (int) $nota->id_utente, 'nota', null,
                "l'ha chiusa senza intervento", forma: 'passaggio', nota: $senza, lettaDa: $lettaDa),
            NotaSegnalazione::ORIGINE_SOLLECITO => $this->voce($nota->data, (int) $nota->id_utente, 'nota',
                $senza === self::SOLLECITO_PREDEFINITO ? null : $senza, 'ha sollecitato', lettaDa: $lettaDa),
            NotaSegnalazione::ORIGINE_INTEGRAZIONE => $this->voce($nota->data, (int) $nota->id_utente, 'nota', $nota->testo,
                'ha aggiunto informazioni', lettaDa: $lettaDa),
            default => $this->voce($nota->data, (int) $nota->id_utente, 'nota', $nota->testo, $this->fraseMessaggio($nota), lettaDa: $lettaDa),
        };
    }

    private const SOLLECITO_PREDEFINITO = "il problema c'è ancora, chiedo un aggiornamento.";

    private function fraseMessaggio(NotaSegnalazione $nota): string
    {
        $autore = $this->utente((int) $nota->id_utente);
        if ($autore !== null && $this->letturaDi($autore) === 'segnalante') {
            return 'ha scritto';
        }

        if (! $nota->visibile_web) {
            return 'ha scritto una nota';
        }

        return User::whereKey((int) $this->segnalazione->id_utente_segnalazione)->first()?->isSpid()
            ? 'ha risposto alla scuola'
            : 'ha risposto a chi ha segnalato';
    }

    /** "Scadenza spostata al 16/11/2026: motivo" → "ha spostato la scadenza a lunedì 16 novembre" + motivo. */
    private function scadenzaSpostata(NotaSegnalazione $nota, string $senza, ?string $lettaDa): array
    {
        $frase = 'ha spostato la scadenza';
        $motivo = null;
        if (preg_match('#^(\d{2}/\d{2}/\d{4})(?::\s*(.*))?#s', $senza, $m)) {
            $frase .= ' a '.Carbon::createFromFormat('d/m/Y', $m[1])->locale('it')->isoFormat('dddd D MMMM');
            $motivo = isset($m[2]) && trim($m[2]) !== '' ? trim($m[2]) : null;
        }

        return $this->voce($nota->data, (int) $nota->id_utente, 'nota', null, $frase, forma: 'passaggio', nota: $motivo, lettaDa: $lettaDa);
    }

    /** Accorpamento: la voce sta all'ora della seconda segnalazione, a nome di chi l'ha fatta. */
    private function risegnalata(NotaSegnalazione $nota, string $senza, ?string $lettaDa): array
    {
        [$intestazione, $testo] = array_pad(explode("\n", $senza, 2), 2, null);
        $testo = $testo !== null ? trim($testo) : null;
        $accorpata = $this->segnalazione->duplicati()->get()
            ->first(fn (Segnalazione $d) => $testo !== null && trim((string) $d->testo_segnalazione) === $testo);

        $chi = $accorpata
            ? $this->nomeAutore((int) $accorpata->id_utente_segnalazione)
            : (preg_replace('/ il \d{2}\/\d{2}\/\d{4}:?$/', '', trim((string) $intestazione)) ?: 'Chi ha segnalato');
        $frase = "l'ha segnalata di nuovo".($accorpata ? ' (#'.$accorpata->id_segnalazione.')' : '')
            .' · accorpata qui da '.$this->nomeAutore((int) $nota->id_utente).' alle '.Carbon::parse($nota->data)->format('H:i');

        return $this->voce($accorpata?->data_segnalazione ?? $nota->data, 0, 'nota', null, $frase,
            forma: 'passaggio', nota: $testo, lettaDa: $lettaDa, nomeAutore: $chi);
    }

    /** "3 dopo 2 adesioni." → "priorità aumentata a Alta dopo 2 adesioni". */
    private function prioritaAumentata(string $senza): string
    {
        if (preg_match('/^(\d)\s+(dopo .+?)\.?$/u', $senza, $m)) {
            $livello = [1 => 'Bassa', 2 => 'Media', 3 => 'Alta', 4 => 'Critica'][(int) $m[1]] ?? $m[1];

            return 'priorità aumentata a '.$livello.' '.$m[2];
        }

        return 'priorità aumentata';
    }

    /** @return Voce */
    private function daStato(StoricoStatoSegnalazione $evento, ?int $precedente = null): array
    {
        $stato = SegnalazioneStato::tryFrom((int) $evento->id_stato_segnalazione);

        $frase = $this->lettura === 'segnalante'
            ? ($stato?->fraseSegnalante()['titolo'] ?? 'Stato aggiornato')
            : $this->fraseInterna($stato, $evento, $precedente);

        return $this->voce($evento->data_registrazione, (int) $evento->id_utente, 'stato', null, $frase, forma: 'passaggio', stato: $stato);
    }

    /** @return Voce */
    private function daAllegato(AllegatoSegnalazione $allegato): array
    {
        $foto = str_starts_with((string) $allegato->tipo, 'image/');
        $frase = $allegato->fase === 'dopo'
            ? 'ha aggiunto una foto del lavoro finito'
            : ($foto ? 'ha aggiunto una foto' : 'ha allegato un file');

        return $this->voce($allegato->created_at, (int) $allegato->id_utente_creazione, 'allegato', null, $frase, forma: 'passaggio', allegato: $allegato);
    }

    /** Frasi dei passaggi (spec A6). */
    private function fraseInterna(?SegnalazioneStato $stato, StoricoStatoSegnalazione $evento, ?int $precedente): string
    {
        $autore = (int) $evento->id_utente;
        $collegato = (int) $evento->id_utente_collegato;

        // Ritorno a "nuova"/"in carico" fatto da chi aveva il lavoro = "Rimanda al comune".
        if (in_array($stato, [SegnalazioneStato::NUOVA, SegnalazioneStato::IN_CARICO], true)) {
            $chi = $this->utente($autore);
            if ($chi !== null && $this->letturaDi($chi) === 'lavoro') {
                return "l'ha rimandata al comune";
            }
        }
        if ($precedente === SegnalazioneStato::SOSPESA->value && $stato !== SegnalazioneStato::SOSPESA) {
            return 'ha ripreso il lavoro';
        }
        $giaAssegnata = in_array($precedente, [SegnalazioneStato::ASSEGNATA_OPERATORE->value, SegnalazioneStato::ASSEGNATA_IMPRESA->value], true);
        $assegnata = $giaAssegnata ? "l'ha riassegnata a " : "l'ha assegnata a ";

        return match ($stato) {
            SegnalazioneStato::NUOVA => 'ha inviato la segnalazione'.$this->avvisati(),
            SegnalazioneStato::IN_CARICO => $collegato > 0 && $collegato !== $autore
                ? "l'ha affidata a ".$this->nome($collegato, 'un collega')
                : "l'ha presa in carico",
            SegnalazioneStato::ASSEGNATA_OPERATORE => $assegnata.$this->nome($collegato, 'una squadra'),
            SegnalazioneStato::ASSEGNATA_IMPRESA => $assegnata.($this->ditta((int) $evento->id_appalto) ?? 'una ditta'),
            SegnalazioneStato::PREVENTIVO_IN_ATTESA => 'ha presentato un preventivo',
            SegnalazioneStato::SOSPESA => 'ha messo in pausa',
            SegnalazioneStato::COMPLETATA => "l'ha chiusa",
            SegnalazioneStato::DUPLICATA => $this->segnalazione->id_segnalazione_madre
                ? "l'ha accorpata alla #".$this->segnalazione->id_segnalazione_madre
                : "l'ha accorpata a una segnalazione precedente",
            SegnalazioneStato::ANNULLATA => "l'ha chiusa senza intervento",
            SegnalazioneStato::ARCHIVIATA => 'archiviata per inattività',
            null => 'ha aggiornato lo stato',
        };
    }

    private function avvisati(): string
    {
        if ($this->lettura !== 'gestore') {
            return '';
        }
        $n = \App\Models\NotificaSegnalazione::where('id_segnalazione', $this->segnalazione->id_segnalazione)
            ->where('tipo', 'NuovaSegnalazioneNotification')->distinct()->count('id_utente');

        return $n > 0 ? ' · avvisati '.$n.' '.($n === 1 ? 'gestore' : 'gestori') : '';
    }

    /** Chi legge la nota, in parole (solo lettura gestore). */
    private function lettaDa(NotaSegnalazione $nota): ?string
    {
        if ($this->lettura !== 'gestore') {
            return null;
        }
        $ditta = (bool) $this->segnalazione->id_appalto;
        $scuola = (bool) User::whereKey((int) $this->segnalazione->id_utente_segnalazione)->first()?->isSpid();

        return match (true) {
            // "Ditta" solo se una ditta c'è davvero: altrimenti la nota la vede solo il comune.
            $nota->visibile_web && $nota->visibile_impresa && $ditta => 'La leggono ditta e '.($scuola ? 'scuola' : 'chi ha segnalato'),
            (bool) $nota->visibile_web => $scuola ? 'La scuola la legge' : 'La legge chi ha segnalato',
            $nota->visibile_impresa && $ditta => 'La legge la ditta',
            default => 'Solo il comune',
        };
    }

    /** @return Voce */
    private function voce(
        mixed $quando, int $idAutore, string $tipo, ?string $testo, string $frase,
        string $forma = 'messaggio', ?SegnalazioneStato $stato = null, ?string $nota = null,
        ?string $lettaDa = null, ?AllegatoSegnalazione $allegato = null, ?string $nomeAutore = null,
    ): array {
        $autore = $nomeAutore ?? $this->nomeAutore($idAutore);

        return [
            'quando' => Carbon::parse($quando),
            'autore' => $autore,
            'iniziali' => self::iniziali($autore),
            'tipo' => $tipo,
            'forma' => $forma,
            'stato' => $stato,
            'testo' => $testo,
            'nota' => $nota,
            'frase' => $frase,
            'letta_da' => $lettaDa,
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
