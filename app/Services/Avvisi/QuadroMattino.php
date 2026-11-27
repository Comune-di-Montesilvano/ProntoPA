<?php

namespace App\Services\Avvisi;

use App\Enums\SegnalazioneStato;
use App\Models\Impostazione;
use App\Models\Impresa;
use App\Models\Segnalazione;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Collection;

/**
 * Il quadro con cui si apre il riepilogo del mattino: nuove (da smistare), da fare, in ritardo.
 * «A rischio» (scadenza entro 48 h) non è una sezione: lo marca la riga.
 */
class QuadroMattino
{
    public const LIMITE_NUOVE = 50;

    /** @return array{nuove: Collection<int, Segnalazione>, da_fare: Collection<int, Segnalazione>, in_ritardo: Collection<int, Segnalazione>} */
    public static function per(User|AnonymousNotifiable $chi): array
    {
        $vuoto = ['nuove' => collect(), 'da_fare' => collect(), 'in_ritardo' => collect()];

        if (! Impostazione::get('digest_enabled', false)) {
            return $vuoto;
        }

        if ($chi instanceof AnonymousNotifiable) {
            $idImpresa = self::impresaDaEmail($chi);

            return $idImpresa ? self::quadroDitta($idImpresa) : $vuoto;
        }

        if ($chi->hasRole('impresa')) {
            return $chi->id_impresa ? self::quadroDitta((int) $chi->id_impresa) : $vuoto;
        }

        if ($chi->isSpid() || $chi->hasRole('segnalatore')) {
            return $vuoto;
        }

        $nuove = self::nuove($chi);
        $supervisore = $chi->isSupervisore();

        $daFare = match (true) {
            $chi->hasRole('operaio') => self::lavoriOperaio($chi),
            $chi->isGestore() => self::base()
                ->where('id_stato_segnalazione', SegnalazioneStato::IN_CARICO->value)
                ->where('id_operatore_assegnato', $chi->id),
            default => null,
        };

        $inRitardo = $daFare ? (clone $daFare)->where('sla_violato', true) : null;
        $daFare = $daFare?->where('sla_violato', false);

        if ($supervisore) {
            $inRitardo = self::base()->where('sla_violato', true);
        }

        return [
            'nuove'      => $nuove,
            'da_fare'    => $daFare ? self::ordinaPerPriorita($daFare)->get() : collect(),
            'in_ritardo' => $inRitardo ? $inRitardo->orderBy('data_scadenza_sla')->get() : collect(),
        ];
    }

    /**
     * Aperte non assegnate che il gestore deve smistare (supervisore o «tutte le tipologie» = tutte).
     * Il limite vale dopo il filtro per tipologia: le poche di un gestore non spariscono dietro le
     * 50 più urgenti delle altre.
     *
     * @return Collection<int, Segnalazione>
     */
    public static function nuove(User $gestore): Collection
    {
        if (! $gestore->isSupervisore() && ! $gestore->hasRole('gestore')) {
            return collect();
        }

        $query = Segnalazione::aperte()
            ->with(['tipologia', 'plesso'])
            ->where(fn ($q) => $q->where('id_operatore_assegnato', 0)->orWhereNull('id_operatore_assegnato'));

        if (! $gestore->isSupervisore() && ! $gestore->gestore_tutte_tipologie) {
            $query->whereIn('id_tipologia_segnalazione', $gestore->tipologieGestite()->pluck('tipologie_segnalazioni.id_tipologia_segnalazione')->all());
        }

        return self::ordinaPerPriorita($query)->limit(self::LIMITE_NUOVE)->get();
    }

    /** @return Builder<Segnalazione> */
    private static function base(): Builder
    {
        return Segnalazione::aperte()
            ->with(['tipologia', 'plesso'])
            ->whereNotIn('id_stato_segnalazione', array_map(
                fn (SegnalazioneStato $s) => $s->value,
                array_filter(SegnalazioneStato::cases(), fn (SegnalazioneStato $s) => $s->isTerminale()),
            ));
    }

    /**
     * @param  Builder<Segnalazione>  $query
     * @return Builder<Segnalazione>
     */
    private static function ordinaPerPriorita(Builder $query): Builder
    {
        return $query->orderByDesc('livello_priorita')->orderBy('data_segnalazione');
    }

    /** @return Builder<Segnalazione> */
    private static function lavoriOperaio(User $operaio): Builder
    {
        $squadreGuidate = $operaio->squadreGuidate()->where('attiva', true)->pluck('id_squadra');

        return self::base()->where(function ($q) use ($operaio, $squadreGuidate) {
            $q->where('id_operatore_assegnato', $operaio->id);
            if ($squadreGuidate->isNotEmpty()) {
                $q->orWhereIn('id_squadra_assegnata', $squadreGuidate);
            }
        });
    }

    /** @return array{nuove: Collection<int, Segnalazione>, da_fare: Collection<int, Segnalazione>, in_ritardo: Collection<int, Segnalazione>} */
    private static function quadroDitta(int $idImpresa): array
    {
        $ditta = self::base()->whereHas('appalto', fn ($q) => $q->where('id_impresa', $idImpresa));

        return [
            'nuove'      => collect(),
            'da_fare'    => self::ordinaPerPriorita((clone $ditta)->where('sla_violato', false))->get(),
            'in_ritardo' => (clone $ditta)->where('sla_violato', true)->orderBy('data_scadenza_sla')->get(),
        ];
    }

    /** L'impresa attiva la cui email aziendale è quella della rotta (confronto senza maiuscole, come in DestinatariAssegnatario). */
    private static function impresaDaEmail(AnonymousNotifiable $chi): ?int
    {
        $rotta = $chi->routeNotificationFor('mail');
        if (is_array($rotta)) {
            // Laravel accetta sia ['a@b.it'] sia ['a@b.it' => 'Nome'].
            $rotta = array_is_list($rotta) ? ($rotta[0] ?? '') : array_key_first($rotta);
        }
        $email = mb_strtolower(trim((string) $rotta));

        if ($email === '') {
            return null;
        }

        $impresa = Impresa::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->where('attiva', true)
            ->first();

        return $impresa?->id_impresa;
    }
}
