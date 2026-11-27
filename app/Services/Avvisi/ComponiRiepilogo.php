<?php

namespace App\Services\Avvisi;

use App\Models\AvvisoInAttesa;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\Concerns\DescriveSegnalazione;
use App\Notifications\Riepilogo\Raggruppabile;
use App\Support\Avvisi\Categorie;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dalle righe in attesa di UN destinatario al contenuto del riepilogo (spec «Cosa si invia» 1-3 e
 * «Il quadro del mattino»): una voce per segnalazione con tutti i motivi, nella sezione della categoria
 * più importante; con il quadro, la segnalazione già presente lì non si ripete negli avvisi.
 */
final class ComponiRiepilogo
{
    use DescriveSegnalazione;

    /** Ordine di visualizzazione delle sezioni del quadro. */
    private const SEZIONI_QUADRO = ['nuove', 'da_fare', 'in_ritardo'];

    /** Chi vince se la stessa segnalazione è in più sezioni del quadro. */
    private const PRECEDENZA_QUADRO = ['in_ritardo', 'da_fare', 'nuove'];

    /** @param  Collection<int, AvvisoInAttesa>  $righe */
    public static function componi(Collection $righe, User|AnonymousNotifiable $chi, bool $conQuadro): Composizione
    {
        return (new self)->comporre($righe, $chi, $conQuadro);
    }

    /** @param  Collection<int, AvvisoInAttesa>  $righe */
    private function comporre(Collection $righe, User|AnonymousNotifiable $chi, bool $conQuadro): Composizione
    {
        $righe = $righe->sortBy([['created_at', 'asc'], ['id', 'asc']])->values();
        $idRigheUsate = $righe->map(fn (AvvisoInAttesa $r) => (int) $r->id)->values()->all();

        // 1. Solo le notifiche ricostruibili e ancora valide; per segnalazione e categoria vince la più recente.
        // Una notifica che lancia (dati vecchi, bug in una regola) è come non più valida: la riga resta tra
        // le usate (si marca, non riprova per sempre) e il resto del riepilogo parte.
        /** @var array<int, array<string, array{notifica: Raggruppabile, motivo: string, riga: string}>> $perSegnalazione */
        $perSegnalazione = [];
        /** @var array<int, list<string>> $tipiPerSegnalazione */
        $tipiPerSegnalazione = [];
        /** @var array<int, array<int, list<string>>> $altrePerSegnalazione */
        $altrePerSegnalazione = [];
        foreach ($righe as $riga) {
            $notifica = $riga->notifica();
            if (! $notifica instanceof Raggruppabile) {
                continue;
            }
            try {
                if (! $notifica->ancoraValida($chi)) {
                    continue;
                }
                $id = $notifica->segnalazioneRiepilogo();
                $voce = ['notifica' => $notifica, 'motivo' => $notifica->motivoRiepilogo(), 'riga' => $notifica->rigaRiepilogo($chi)];
                $categoria = $notifica->categoriaRiepilogo();
                $altre = method_exists($notifica, 'segnalazioniTracciate')
                    ? array_diff(array_map('intval', $notifica->segnalazioniTracciate()), [$id])
                    : [];
            } catch (Throwable $e) {
                Log::warning('Riepilogo avvisi: avviso scartato', [
                    'id'     => (int) $riga->id,
                    'tipo'   => class_basename($notifica),
                    'errore' => $e->getMessage(),
                ]);

                continue;
            }
            $tipo = class_basename($notifica);
            $perSegnalazione[$id][$categoria] = $voce;
            $tipiPerSegnalazione[$id][] = $tipo;
            foreach ($altre as $altra) {
                $altrePerSegnalazione[$id][$altra][] = $tipo;
            }
        }

        $segnalazioni = $perSegnalazione === []
            ? collect()
            : Segnalazione::with(['tipologia', 'plesso'])->whereIn('id_segnalazione', array_keys($perSegnalazione))->get()->keyBy('id_segnalazione');

        // 2. Una voce per segnalazione, nella categoria più importante, con i motivi in ordine di importanza.
        /** @var array<int, Voce> $voci */
        $voci = [];
        foreach ($perSegnalazione as $id => $perCategoria) {
            $segnalazione = $segnalazioni->get($id);
            if (! $segnalazione) {
                continue;
            }
            uksort($perCategoria, fn (string $a, string $b) => self::posizione($a) <=> self::posizione($b));
            $categoria = array_key_first($perCategoria);
            $vincente = $perCategoria[$categoria];

            $voci[$id] = new Voce(
                $segnalazione,
                $categoria,
                array_values(array_unique(array_column(array_values($perCategoria), 'motivo'))),
                $vincente['riga'],
                $vincente['notifica'],
                array_values(array_unique($tipiPerSegnalazione[$id] ?? [])),
                array_map(fn (array $tipi) => array_values(array_unique($tipi)), $altrePerSegnalazione[$id] ?? []),
            );
        }

        // 3. Quadro del mattino: le segnalazioni che vi compaiono assorbono i loro avvisi.
        // Una segnalazione sta in una sola sezione del quadro (in ritardo > da fare > nuove).
        $quadro = array_fill_keys(self::SEZIONI_QUADRO, []);
        $altreQuadro = [];
        if ($conQuadro) {
            $assorbite = [];
            $elenchi = QuadroMattino::per($chi);
            $altreQuadro = array_filter($elenchi['altre']);
            foreach (self::PRECEDENZA_QUADRO as $sezione) {
                foreach ($elenchi[$sezione] as $s) {
                    $id = (int) $s->id_segnalazione;
                    if (isset($assorbite[$id])) {
                        continue;
                    }
                    $avvisi = $voci[$id] ?? null;
                    $quadro[$sezione][] = new Voce($s, $sezione, $avvisi->motivi ?? [], $this->rigaSegnalazione($s), null, $avvisi->tipi ?? [], $avvisi->altreTracciate ?? []);
                    $assorbite[$id] = true;
                }
            }
            $voci = array_diff_key($voci, $assorbite);
        }

        $sezioni = [];
        foreach ($voci as $voce) {
            $sezioni[$voce->categoria][] = $voce;
        }
        uksort($sezioni, fn (string $a, string $b) => self::posizione($a) <=> self::posizione($b));

        // 4. Un solo avviso (una segnalazione, un motivo) e niente quadro: si reinvia l'originale.
        $composizione = new Composizione($quadro, $sezioni, $idRigheUsate, null, $altreQuadro);
        if (! $composizione->quadroVuoto() || count($voci) !== 1) {
            return $composizione;
        }
        $voce = reset($voci);

        return count($voce->motivi) === 1
            ? new Composizione($quadro, $sezioni, $idRigheUsate, $voce->notifica, $altreQuadro)
            : $composizione;
    }

    /** Posizione in `Categorie::ORDINE`; categoria sconosciuta in fondo. */
    private static function posizione(string $categoria): int
    {
        $i = array_search($categoria, Categorie::ORDINE, true);

        return $i === false ? PHP_INT_MAX : $i;
    }
}
