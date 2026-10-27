<?php

namespace App\Support\Segnalazioni;

use App\Enums\SegnalazioneStato;
use App\Models\Azione;
use App\Models\Impresa;
use App\Models\Segnalazione;
use App\Models\Squadra;
use App\Models\User;
use App\Services\SegnalazioneWorkflowService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Il riquadro in cima alla scheda: cosa serve adesso, detto a chi guarda,
 * con le sole azioni che può eseguire davvero. Calcolato a lettura, nessuno stato salvato.
 *
 * @phpstan-type AzionePasso array{tipo: 'azione'|'pannello'|'post'|'link', etichetta: string, id_azione?: int, pannello?: string, route?: string, primaria: bool}
 * @phpstan-type Passo array{tono: 'rose'|'amber'|'primary'|'slate', titolo: string, sottotitolo: ?string, azioni: list<AzionePasso>}
 */
class ProssimoPasso
{
    /** @var Collection<int, Azione> */
    private Collection $disponibili;

    private function __construct(
        private readonly Segnalazione $s,
        private readonly User $u,
    ) {
        $this->disponibili = Gate::forUser($u)->allows('update', $s)
            ? app(SegnalazioneWorkflowService::class)->getAzioniDisponibili($s, $u)
            : collect();
    }

    /** @return Passo */
    public static function per(Segnalazione $s, User $u): array
    {
        $passo = new self($s, $u);

        return match (LetturaScheda::per($u)) {
            'gestore' => $passo->gestore(),
            'lavoro' => $passo->lavoro(),
            default => $passo->segnalante(),
        };
    }

    /** @return Passo */
    private function gestore(): array
    {
        $stato = $this->s->statoEnum();
        $azioni = [];
        $sottotitolo = null;

        if ($stato->isTerminale()) {
            $titolo = $stato === SegnalazioneStato::DUPLICATA && $this->s->id_segnalazione_madre
                ? "Accorpata alla #{$this->s->id_segnalazione_madre}"
                : 'Chiusa il '.$this->data($this->s->data_chiusura);
            $sottotitolo = $stato === SegnalazioneStato::DUPLICATA ? null : $stato->etichetta();
            $this->azione($azioni, 14, 'Riapri');

            return ['tono' => 'slate', 'titolo' => $titolo, 'sottotitolo' => $sottotitolo, 'azioni' => $azioni];
        }

        $lavorati = [SegnalazioneStato::ASSEGNATA_OPERATORE, SegnalazioneStato::ASSEGNATA_IMPRESA];

        if (in_array($stato, $lavorati, true) && $this->s->haFineLavori()) {
            $titolo = $this->assegnatario()." ha comunicato la fine dei lavori il {$this->dataFineLavori()}: verifica e chiudi";
            $this->pannello($azioni, 'chiudi', 'Chiudi', 9, primaria: true);
        } else {
            switch ($stato) {
                case SegnalazioneStato::NUOVA:
                    $titolo = 'Da smistare: conferma priorità e assegna';
                    $this->azione($azioni, 1, 'Prendi in carico', primaria: true);
                    $this->pannelloAssegna($azioni, 'Assegna…');
                    $this->pannelloPriorita($azioni);
                    if (Gate::forUser($this->u)->allows('accorpa', $this->s)) {
                        $azioni[] = ['tipo' => 'pannello', 'etichetta' => 'Accorpa a…', 'pannello' => 'accorpa', 'primaria' => false];
                    }
                    break;
                case SegnalazioneStato::IN_CARICO:
                    $titolo = 'Da assegnare';
                    $this->pannelloAssegna($azioni, 'Assegna…', primaria: true);
                    $this->pannelloPriorita($azioni);
                    break;
                case SegnalazioneStato::ASSEGNATA_OPERATORE:
                case SegnalazioneStato::ASSEGNATA_IMPRESA:
                    $titolo = $this->assegnatario().' deve chiudere il lavoro'.$this->ritardo();
                    if (Gate::forUser($this->u)->allows('sollecita', $this->s)) {
                        $azioni[] = ['tipo' => 'pannello', 'etichetta' => 'Sollecita', 'pannello' => 'sollecita', 'primaria' => true];
                    }
                    $this->pannello($azioni, 'sospendi', 'Sospendi', 4);
                    $this->pannelloAssegna($azioni, 'Riassegna');
                    break;
                case SegnalazioneStato::PREVENTIVO_IN_ATTESA:
                    $titolo = 'Preventivo di € '.number_format((float) $this->s->importo_preventivo, 2, ',', '.').' da valutare';
                    $this->azione($azioni, 7, 'Accetta', primaria: true);
                    $this->azione($azioni, 8, 'Rifiuta');
                    break;
                case SegnalazioneStato::SOSPESA:
                    $titolo = 'Sospesa dal '.$this->data($this->ultimoPassaggioA(SegnalazioneStato::SOSPESA));
                    $sottotitolo = $this->ultimaNota();
                    $this->azione($azioni, 5, 'Riprendi', primaria: true);
                    break;
                default:
                    $titolo = $stato->etichetta();
            }
        }

        if ($this->s->isSollecitata()) {
            $titolo = 'Segnalata '.$this->s->volteSegnalata().' volte · '.$titolo;
            $this->pannelloPriorita($azioni);
        }

        return ['tono' => $this->tonoScadenza(), 'titolo' => $titolo, 'sottotitolo' => $sottotitolo, 'azioni' => $azioni];
    }

    /** @return Passo */
    private function lavoro(): array
    {
        $stato = $this->s->statoEnum();
        $azioni = [];
        $scrivi = ['tipo' => 'link', 'etichetta' => 'Scrivi al comune', 'route' => '#scrivi', 'primaria' => false];

        if ($stato->isTerminale()) {
            return ['tono' => 'slate', 'titolo' => 'Lavoro chiuso il '.$this->data($this->s->data_chiusura), 'sottotitolo' => null, 'azioni' => []];
        }

        if ($stato === SegnalazioneStato::SOSPESA) {
            return ['tono' => 'amber', 'titolo' => 'In pausa: ti avviseremo quando riprendere', 'sottotitolo' => null, 'azioni' => [$scrivi]];
        }

        if ($stato === SegnalazioneStato::PREVENTIVO_IN_ATTESA) {
            return ['tono' => 'amber', 'titolo' => 'Preventivo inviato, in attesa del comune', 'sottotitolo' => null, 'azioni' => [$scrivi]];
        }

        if ($this->s->haFineLavori()) {
            return [
                'tono' => 'primary',
                'titolo' => "Fine lavori comunicata il {$this->dataFineLavori()}: in attesa di verifica del comune",
                'sottotitolo' => null,
                'azioni' => [$scrivi],
            ];
        }

        if (Gate::forUser($this->u)->allows('comunicaFineLavori', $this->s)) {
            $azioni[] = ['tipo' => 'pannello', 'etichetta' => 'Comunica fine lavori', 'pannello' => 'fine-lavori', 'primaria' => true];
            $this->pannello($azioni, 'preventivo', 'Invia preventivo', 6);
            $azioni[] = $scrivi;

            return [
                'tono' => $this->tonoScadenza(),
                'titolo' => 'Quando hai finito, comunica la fine dei lavori con le foto',
                'sottotitolo' => Scadenza::estesa($this->s)['testo'] ?? null,
                'azioni' => $azioni,
            ];
        }

        return ['tono' => 'primary', 'titolo' => 'In attesa di assegnazione', 'sottotitolo' => null, 'azioni' => [$scrivi]];
    }

    /** @return Passo */
    private function segnalante(): array
    {
        $stato = $this->s->statoEnum();
        $frase = $stato->fraseSegnalante();
        $sottotitolo = $stato === SegnalazioneStato::DUPLICATA && $this->s->id_segnalazione_madre
            ? "Segui gli aggiornamenti nella #{$this->s->id_segnalazione_madre}."
            : $frase['dettaglio'];

        return ['tono' => $stato->isTerminale() ? 'slate' : 'primary', 'titolo' => $frase['titolo'], 'sottotitolo' => $sottotitolo, 'azioni' => []];
    }

    // ── Azioni ───────────────────────────────────────────────────────────────

    /** @param  list<AzionePasso>  $azioni */
    private function azione(array &$azioni, int $id, string $etichetta, bool $primaria = false): void
    {
        if ($this->disponibile($id)) {
            $azioni[] = ['tipo' => 'azione', 'etichetta' => $etichetta, 'id_azione' => $id, 'primaria' => $primaria];
        }
    }

    /** @param  list<AzionePasso>  $azioni */
    private function pannello(array &$azioni, string $pannello, string $etichetta, int $id, bool $primaria = false): void
    {
        if ($this->disponibile($id)) {
            $azioni[] = ['tipo' => 'pannello', 'etichetta' => $etichetta, 'id_azione' => $id, 'pannello' => $pannello, 'primaria' => $primaria];
        }
    }

    /** @param  list<AzionePasso>  $azioni */
    private function pannelloAssegna(array &$azioni, string $etichetta, bool $primaria = false): void
    {
        if ($this->disponibile(2) || $this->disponibile(3)) {
            $azioni[] = ['tipo' => 'pannello', 'etichetta' => $etichetta, 'pannello' => 'assegna', 'primaria' => $primaria];
        }
    }

    /** @param  list<AzionePasso>  $azioni */
    private function pannelloPriorita(array &$azioni): void
    {
        $etichetta = $this->s->isSollecitata() ? 'Alza priorità' : 'Priorità…';
        $presente = collect($azioni)->contains(fn (array $a) => ($a['pannello'] ?? null) === 'priorita');
        if (! $presente && Gate::forUser($this->u)->allows('modificaPriorita', $this->s)) {
            $azioni[] = ['tipo' => 'pannello', 'etichetta' => $etichetta, 'pannello' => 'priorita', 'primaria' => false];
        }
    }

    private function disponibile(int $id): bool
    {
        return $this->disponibili->contains(fn (Azione $a) => (int) $a->id_azione === $id);
    }

    // ── Dati ─────────────────────────────────────────────────────────────────

    private function assegnatario(): string
    {
        if ($this->s->statoEnum() !== SegnalazioneStato::ASSEGNATA_OPERATORE && $this->s->id_appalto) {
            $idImpresa = $this->s->appalto()->value('id_impresa');
            $nome = $idImpresa ? Impresa::whereKey($idImpresa)->value('ragione_sociale') : null;

            return $nome ?: 'La ditta';
        }

        if ($this->s->id_squadra_assegnata) {
            $nome = Squadra::whereKey($this->s->id_squadra_assegnata)->value('nome');

            return $nome ? 'La squadra '.$nome : 'La squadra';
        }

        $nome = $this->s->id_operatore_assegnato ? User::whereKey($this->s->id_operatore_assegnato)->value('name') : null;

        return $nome ?: "L'operatore";
    }

    private function ritardo(): string
    {
        if ($this->s->data_scadenza_sla === null || $this->s->isChiusa()) {
            return '';
        }

        $secondi = now()->getTimestamp() - Carbon::parse($this->s->data_scadenza_sla)->getTimestamp();
        if ($secondi <= 0) {
            return '';
        }

        $ore = intdiv($secondi, 3600);

        return $ore >= 24
            ? ': è in ritardo di '.intdiv($ore, 24).' '.(intdiv($ore, 24) === 1 ? 'giorno' : 'giorni')
            : ': è in ritardo di '.max(1, $ore).' '.(max(1, $ore) === 1 ? 'ora' : 'ore');
    }

    /** @return 'rose'|'amber'|'primary' */
    private function tonoScadenza(): string
    {
        $tono = Scadenza::per($this->s)['tono'] ?? 'slate';

        return $tono === 'slate' ? 'primary' : $tono;
    }

    private function dataFineLavori(): string
    {
        return $this->data($this->s->allegati()->where('fase', 'dopo')->max('created_at'));
    }

    private function ultimoPassaggioA(SegnalazioneStato $stato): mixed
    {
        return $this->s->storicoStati()->where('id_stato_segnalazione', $stato->value)->max('data_registrazione');
    }

    private function ultimaNota(): ?string
    {
        $nota = $this->s->note()->visibiliA($this->u)->latest('data')->value('testo');

        return $nota ? Str::limit((string) $nota, 140) : null;
    }

    private function data(mixed $valore): string
    {
        return $valore ? Carbon::parse($valore)->format('d/m') : '—';
    }
}
