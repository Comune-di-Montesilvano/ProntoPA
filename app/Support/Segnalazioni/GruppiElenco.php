<?php

namespace App\Support\Segnalazioni;

use App\Models\Segnalazione;
use Illuminate\Support\Collection;

/**
 * Elenco gestione "Raggruppa per" (1.0.4): celle di gruppo a sinistra con rowspan, come in un foglio.
 * Il primo livello porta il conteggio sull'intero filtro; la paginazione non spezza mai un gruppo
 * di primo livello (PaginaPerGruppi), quindi ogni gruppo comincia e finisce nella sua pagina.
 *
 * @phpstan-type Cella array{livello: int, tipo: string, chiave: int, nome: string, dettagli: ?string, tono: ?string, icona: ?string,
 *     rowspan: int, totale: ?int, in_ritardo: ?int, ids: list<int>}
 */
final class GruppiElenco
{
    public const GRUPPI = [
        'sede' => ['sede'],
        'sede_tipologia' => ['sede', 'tipologia'],
        'tipologia' => ['tipologia'],
        'tipologia_sede' => ['tipologia', 'sede'],
    ];

    /** @return list<string> */
    public static function livelli(?string $gruppo): array
    {
        return self::GRUPPI[(string) $gruppo] ?? [];
    }

    public static function chiave(Segnalazione $s, string $tipo): int
    {
        return (int) ($tipo === 'sede' ? $s->id_plesso : $s->id_tipologia_segnalazione);
    }

    /**
     * @param  Collection<int, Segnalazione>  $pagina  righe della pagina, già ordinate per gruppo
     * @param  array<int, array{totale: int, in_ritardo: int}>  $conteggi  per chiave del primo livello
     * @return list<array{s: Segnalazione, celle: list<Cella>}>
     */
    public static function costruisci(Collection $pagina, ?string $gruppo, array $conteggi): array
    {
        $righe = $pagina->values();
        $livelli = self::livelli($gruppo);
        $out = $righe->map(fn (Segnalazione $s) => ['s' => $s, 'celle' => []])->all();

        foreach ($livelli as $l => $tipo) {
            $tipiFinoA = array_slice($livelli, 0, $l + 1);
            $prefisso = fn (Segnalazione $s) => implode('|', array_map(fn ($t) => self::chiave($s, $t), $tipiFinoA));

            $i = 0;
            while ($i < $righe->count()) {
                $s = $righe[$i];
                $k = $prefisso($s);
                $j = $i;
                while ($j + 1 < $righe->count() && $prefisso($righe[$j + 1]) === $k) {
                    $j++;
                }
                $chiave = self::chiave($s, $tipo);
                $out[$i]['celle'][] = [
                    'livello' => $l + 1,
                    'tipo' => $tipo,
                    'chiave' => $chiave,
                    'nome' => $tipo === 'sede'
                        ? ($s->plesso?->nomeLeggibile() ?: 'Senza sede')
                        : ($s->tipologia?->nomeLeggibile() ?: 'Senza tipologia'),
                    'dettagli' => $tipo === 'sede' ? $s->plesso?->dettagli() : null,
                    'tono' => $tipo === 'tipologia' ? ($s->tipologia?->tono() ?? 'grigio') : null,
                    'icona' => $tipo === 'tipologia' ? ($s->tipologia?->icona ?: 'fas fa-tag') : 'fas fa-school',
                    'rowspan' => $j - $i + 1,
                    'totale' => $l === 0 ? (int) ($conteggi[$chiave]['totale'] ?? 0) : null,
                    'in_ritardo' => $l === 0 ? (int) ($conteggi[$chiave]['in_ritardo'] ?? 0) : null,
                    'ids' => $righe->slice($i, $j - $i + 1)->map(fn ($r) => (int) $r->id_segnalazione)->values()->all(),
                ];
                $i = $j + 1;
            }
        }

        return $out;
    }
}
