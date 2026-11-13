<?php

namespace App\Services\Segnalazioni;

use App\Models\Segnalazione;
use App\Models\User;
use App\Services\SegnalazioneWorkflowService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * La stessa azione su più segnalazioni dall'elenco (spec 2026-11-13 §4).
 * Ognuna passa dal workflow in una propria transazione: quelle che non si possono fare
 * vengono saltate col motivo, le altre vanno avanti.
 */
class AzioniMultiple
{
    public const OPERAZIONI = ['assegna', 'chiudi', 'chiudi_senza_intervento'];

    private const PRENDI = 1;
    private const OPERATORE = 2;
    private const IMPRESA = 3;
    private const CHIUDI = 9;
    private const SENZA_INTERVENTO = 12;

    public function __construct(private readonly SegnalazioneWorkflowService $workflow) {}

    /**
     * @param  Collection<int, Segnalazione>  $segnalazioni
     * @param  array<string, mixed>  $dati
     * @return array{fatte: int, saltate: array<int, string>}
     */
    public function esegui(Collection $segnalazioni, User $u, string $operazione, array $dati): array
    {
        [$idAzione, $params] = $this->azione($operazione, $dati);
        $fatte = 0;
        $saltate = [];

        foreach ($segnalazioni as $s) {
            if (Gate::forUser($u)->denies('update', $s)) {
                $saltate[$s->id_segnalazione] = 'non di tua competenza';

                continue;
            }

            try {
                DB::transaction(fn () => $this->workflow->eseguiAzione($s, $idAzione, $u, $params));
                $fatte++;
            } catch (ValidationException $e) {
                $saltate[$s->id_segnalazione] = (string) collect($e->errors())->flatten()->first();
            }
        }

        return ['fatte' => $fatte, 'saltate' => $saltate];
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
