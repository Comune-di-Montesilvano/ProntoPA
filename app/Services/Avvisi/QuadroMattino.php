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
    /** Massimo di righe per le sezioni senza un tetto naturale (nuove, in ritardo dei supervisori). */
    public const LIMITE_NUOVE = 50;

    private const NESSUNA_ALTRA = ['nuove' => 0, 'da_fare' => 0, 'in_ritardo' => 0];

    /**
     * `altre`: quante non mostrate per sezione (oltre il limite), per il «e altre N».
     *
     * @return array{nuove: Collection<int, Segnalazione>, da_fare: Collection<int, Segnalazione>, in_ritardo: Collection<int, Segnalazione>, altre: array{nuove: int, da_fare: int, in_ritardo: int}}
     */
    public static function per(User|AnonymousNotifiable $chi): array
    {
        $vuoto = ['nuove' => collect(), 'da_fare' => collect(), 'in_ritardo' => collect(), 'altre' => self::NESSUNA_ALTRA];

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

        [$nuove, $altreNuove] = self::nuoveConAltre($chi);
        $supervisore = $chi->isSupervisore();

        $daFare = match (true) {
            $chi->hasRole('operaio') => self::lavoriOperaio($chi),
            $chi->isGestore() => self::base()
                ->whereIn('id_stato_segnalazione', [SegnalazioneStato::IN_CARICO->value, SegnalazioneStato::ATTESA_RISPOSTA->value])
                ->where('id_operatore_assegnato', $chi->id),
            default => null,
        };

        $inRitardo = $daFare ? (clone $daFare)->where('sla_violato', true) : null;
        $daFare = $daFare?->where('sla_violato', false);

        // Supervisori: tutte le scadute dell'ente, quindi con un tetto come le nuove.
        $limiteRitardo = $supervisore ? self::LIMITE_NUOVE : null;
        if ($supervisore) {
            $inRitardo = self::base()->where('sla_violato', true);
        }
        [$elencoRitardo, $altreRitardo] = $inRitardo
            ? self::conLimite($inRitardo->orderBy('data_scadenza_sla'), $limiteRitardo)
            : [collect(), 0];

        return [
            'nuove'      => $nuove,
            'da_fare'    => $daFare ? self::ordinaPerPriorita($daFare)->get() : collect(),
            'in_ritardo' => $elencoRitardo,
            'altre'      => ['nuove' => $altreNuove, 'da_fare' => 0, 'in_ritardo' => $altreRitardo],
        ];
    }

    /**
     * Le prime `$limite` (tutte se null) e quante ne restano fuori; il conteggio solo se il limite è pieno.
     *
     * @param  Builder<Segnalazione>  $query
     * @return array{0: Collection<int, Segnalazione>, 1: int}
     */
    private static function conLimite(Builder $query, ?int $limite): array
    {
        if ($limite === null) {
            return [$query->get(), 0];
        }
        $conteggio = (clone $query)->reorder();
        $elenco = $query->limit($limite)->get();

        return [$elenco, $elenco->count() < $limite ? 0 : max(0, $conteggio->count() - $limite)];
    }

    /**
     * Aperta e da smistare: nessun gestore, operaio, squadra o ditta. Stessa regola di `nuove()`,
     * usata dagli avvisi «Nuova»/«Riclassificata» per sapere se valgono ancora.
     */
    public static function daSmistare(Segnalazione $s): bool
    {
        return ! $s->statoEnum()->isTerminale()
            && $s->data_chiusura === null
            && ! $s->id_operatore_assegnato
            && ! $s->id_squadra_assegnata
            && ! $s->id_appalto;
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
        return self::nuoveConAltre($gestore)[0];
    }

    /** @return array{0: Collection<int, Segnalazione>, 1: int} le nuove e quante oltre il limite */
    private static function nuoveConAltre(User $gestore): array
    {
        if (! $gestore->isSupervisore() && ! $gestore->hasRole('gestore')) {
            return [collect(), 0];
        }

        $query = Segnalazione::aperte()
            ->with(['tipologia', 'plesso'])
            ->where(fn ($q) => $q->where('id_operatore_assegnato', 0)->orWhereNull('id_operatore_assegnato'))
            ->where(fn ($q) => $q->where('id_squadra_assegnata', 0)->orWhereNull('id_squadra_assegnata'))
            ->where(fn ($q) => $q->where('id_appalto', 0)->orWhereNull('id_appalto'));

        if (! $gestore->isSupervisore() && ! $gestore->gestore_tutte_tipologie) {
            $query->whereIn('id_tipologia_segnalazione', $gestore->tipologieGestite()->pluck('tipologie_segnalazioni.id_tipologia_segnalazione')->all());
        }

        return self::conLimite(self::ordinaPerPriorita($query), self::LIMITE_NUOVE);
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

    /** @return array{nuove: Collection<int, Segnalazione>, da_fare: Collection<int, Segnalazione>, in_ritardo: Collection<int, Segnalazione>, altre: array{nuove: int, da_fare: int, in_ritardo: int}} */
    private static function quadroDitta(int $idImpresa): array
    {
        $ditta = self::base()->whereHas('appalto', fn ($q) => $q->where('id_impresa', $idImpresa));

        return [
            'nuove'      => collect(),
            'da_fare'    => self::ordinaPerPriorita((clone $ditta)->where('sla_violato', false))->get(),
            'in_ritardo' => (clone $ditta)->where('sla_violato', true)->orderBy('data_scadenza_sla')->get(),
            'altre'      => self::NESSUNA_ALTRA,
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
