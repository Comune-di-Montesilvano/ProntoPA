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

/**
 * Dalle righe in attesa di UN destinatario al contenuto del riepilogo (spec «Cosa si invia» 1-3 e
 * «Il quadro del mattino»): una voce per segnalazione con tutti i motivi, nella sezione della categoria
 * più importante; con il quadro, la segnalazione già presente lì non si ripete negli avvisi.
 */
final class ComponiRiepilogo
{
    use DescriveSegnalazione;

    private const SEZIONI_QUADRO = ['nuove', 'da_fare', 'in_ritardo'];

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
        /** @var array<int, array<string, Raggruppabile>> $perSegnalazione */
        $perSegnalazione = [];
        foreach ($righe as $riga) {
            $notifica = $riga->notifica();
            if (! $notifica instanceof Raggruppabile || ! $notifica->ancoraValida($chi)) {
                continue;
            }
            $perSegnalazione[$notifica->segnalazioneRiepilogo()][$notifica->categoriaRiepilogo()] = $notifica;
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
                array_values(array_unique(array_map(fn (Raggruppabile $n) => $n->motivoRiepilogo(), array_values($perCategoria)))),
                $vincente->rigaRiepilogo($chi),
                $vincente,
            );
        }

        // 3. Quadro del mattino: le segnalazioni che vi compaiono assorbono i loro avvisi.
        $quadro = array_fill_keys(self::SEZIONI_QUADRO, []);
        if ($conQuadro) {
            $assorbite = [];
            foreach (QuadroMattino::per($chi) as $sezione => $elenco) {
                foreach ($elenco as $s) {
                    $id = (int) $s->id_segnalazione;
                    $motivi = isset($voci[$id]) ? $voci[$id]->motivi : [];
                    $quadro[$sezione][] = new Voce($s, $sezione, $motivi, $this->rigaSegnalazione($s), null);
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
        $unica = null;
        $quadroVuoto = array_merge(...array_values($quadro)) === [];
        if ($quadroVuoto && count($voci) === 1) {
            $voce = reset($voci);
            $unica = count($voce->motivi) === 1 ? $voce->notifica : null;
        }

        return new Composizione($quadro, $sezioni, $idRigheUsate, $unica);
    }

    /** Posizione in `Categorie::ORDINE`; categoria sconosciuta in fondo. */
    private static function posizione(string $categoria): int
    {
        $i = array_search($categoria, Categorie::ORDINE, true);

        return $i === false ? PHP_INT_MAX : $i;
    }
}
