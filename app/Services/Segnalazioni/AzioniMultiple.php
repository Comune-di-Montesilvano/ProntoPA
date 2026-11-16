<?php

namespace App\Services\Segnalazioni;

use App\Models\Segnalazione;
use App\Models\User;
use App\Services\SegnalazioneWorkflowService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * La stessa azione su più segnalazioni dall'elenco (spec 2026-11-13 §4).
 * Ognuna passa dal workflow come dalla scheda: quelle che non si possono fare
 * vengono saltate col motivo, le altre vanno avanti.
 */
class AzioniMultiple
{
    public const OPERAZIONI = ['assegna', 'chiudi', 'chiudi_senza_intervento', 'accorpa'];

    private const PRENDI = 1;
    private const OPERATORE = 2;
    private const IMPRESA = 3;
    private const CHIUDI = 9;
    private const SENZA_INTERVENTO = 12;

    public function __construct(
        private readonly SegnalazioneWorkflowService $workflow,
        private readonly AccorpamentoService $accorpamento,
    ) {}

    /**
     * @param  Collection<int, Segnalazione>  $segnalazioni
     * @param  array<string, mixed>  $dati
     * @return array{fatte: int, saltate: array<int, string>, destinazione?: int}
     */
    public function esegui(Collection $segnalazioni, User $u, string $operazione, array $dati): array
    {
        if ($operazione === 'accorpa') {
            return $this->accorpa($segnalazioni, $u);
        }

        [$idAzione, $params] = $this->azione($operazione, $dati);
        $fatte = 0;
        $saltate = [];

        foreach ($segnalazioni as $s) {
            if (Gate::forUser($u)->denies('update', $s)) {
                $saltate[$s->id_segnalazione] = 'non di tua competenza';

                continue;
            }

            try {
                // Niente transazione esterna: le email e il webhook partirebbero prima del commit.
                $this->workflow->eseguiAzione($s, $idAzione, $u, $params);
                $fatte++;
            } catch (ValidationException $e) {
                $saltate[$s->id_segnalazione] = (string) collect($e->errors())->flatten()->first();
            } catch (\Throwable $e) {
                // Un errore su una riga non deve lasciare le altre a metà senza dirlo.
                report($e);
                $saltate[$s->id_segnalazione] = 'errore imprevisto, riprova dalla scheda';
            }
        }

        return ['fatte' => $fatte, 'saltate' => $saltate];
    }

    /**
     * Doppioni visti raggruppando l'elenco (1.0.4): solo stessa sede e stessa tipologia,
     * la più vecchia ancora aperta riceve le altre (allegati, adesioni, nota, avvisi: AccorpamentoService).
     *
     * @param  Collection<int, Segnalazione>  $segnalazioni
     * @return array{fatte: int, saltate: array<int, string>, destinazione: int}
     */
    private function accorpa(Collection $segnalazioni, User $u): array
    {
        $chiavi = $segnalazioni->map(fn (Segnalazione $s) => (int) $s->id_plesso.'|'.(int) $s->id_tipologia_segnalazione)->unique();
        if ($segnalazioni->count() < 2) {
            throw ValidationException::withMessages(['ids' => 'Per accorpare scegli almeno due segnalazioni.']);
        }
        if ($chiavi->count() > 1 || $segnalazioni->contains(fn (Segnalazione $s) => ! (int) $s->id_plesso || ! (int) $s->id_tipologia_segnalazione)) {
            throw ValidationException::withMessages(['ids' => 'Si accorpano solo segnalazioni della stessa sede e della stessa tipologia.']);
        }

        $aperte = $segnalazioni->reject(fn (Segnalazione $s) => $s->isChiusa())
            ->sortBy(fn (Segnalazione $s) => [$s->data_segnalazione?->getTimestamp() ?? PHP_INT_MAX, (int) $s->id_segnalazione])
            ->values();
        $destinazione = $aperte->first();
        if ($destinazione === null || $aperte->count() < 2) {
            throw ValidationException::withMessages(['ids' => 'Servono almeno due segnalazioni ancora aperte.']);
        }
        if (Gate::forUser($u)->denies('modificaPriorita', $destinazione)) {
            throw ValidationException::withMessages(['ids' => "La #{$destinazione->id_segnalazione}, la più vecchia, non è di tua competenza."]);
        }

        $fatte = 0;
        $saltate = [];
        foreach ($segnalazioni as $s) {
            if ($s->id_segnalazione === $destinazione->id_segnalazione) {
                continue;
            }
            if ($s->isChiusa()) {
                $saltate[$s->id_segnalazione] = 'già chiusa';

                continue;
            }
            if (Gate::forUser($u)->denies('accorpa', $s)) {
                $saltate[$s->id_segnalazione] = 'non di tua competenza';

                continue;
            }

            try {
                $this->accorpamento->accorpa($s, $destinazione, $u);
                $fatte++;
            } catch (ValidationException $e) {
                $saltate[$s->id_segnalazione] = (string) collect($e->errors())->flatten()->first();
            } catch (\Throwable $e) {
                report($e);
                $saltate[$s->id_segnalazione] = 'errore imprevisto, riprova dalla scheda';
            }
        }

        return ['fatte' => $fatte, 'saltate' => $saltate, 'destinazione' => (int) $destinazione->id_segnalazione];
    }

    /**
     * @param  array<string, mixed>  $dati
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function azione(string $operazione, array $dati): array
    {
        $comuni = array_filter([
            'nota' => $dati['nota'] ?? null,
            'data_scadenza' => $dati['data_scadenza'] ?? null,
        ], 'filled');

        return match ($operazione) {
            'assegna' => match ($dati['tipo'] ?? null) {
                'me' => [self::PRENDI, $comuni],
                'operaio' => [self::OPERATORE, $comuni + ['id_operatore' => (int) $dati['id_operatore']]],
                'squadra' => [self::OPERATORE, $comuni + ['id_squadra' => (int) $dati['id_squadra']]],
                'ditta' => [self::IMPRESA, $comuni + ['id_appalto' => (int) $dati['id_appalto']]],
                default => throw new \InvalidArgumentException('Tipo di assegnazione sconosciuto.'),
            },
            'chiudi' => [self::CHIUDI, array_filter(['nota' => $dati['nota'] ?? null], 'filled')],
            'chiudi_senza_intervento' => [self::SENZA_INTERVENTO, array_filter([
                'motivo' => $dati['motivo'] ?? null,
                'nota' => $dati['nota'] ?? null,
            ], 'filled')],
            default => throw new \InvalidArgumentException("Operazione sconosciuta: {$operazione}"),
        };
    }
}
