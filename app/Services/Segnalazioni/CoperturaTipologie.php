<?php

namespace App\Services\Segnalazioni;

use App\Models\TipologiaSegnalazione;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Chi si occupa di quale tipologia. Un gestore (non supervisore) copre una
 * tipologia se la ha tra le sue o ha "tutte le tipologie". Conta come
 * copertura solo se attivo e raggiungibile via email: altrimenti l'avviso
 * non arriverebbe a nessuno e la tipologia va trattata come orfana
 * (segnalazioni ai supervisori).
 *
 * Un admin conta come gestore solo delle tipologie assegnategli esplicitamente
 * (mai "tutte"): es. l'admin che segue anche la rete telefonica/dati.
 */
class CoperturaTipologie
{
    /** @return Collection<int, User> */
    public function gestoriDi(int $idTipologia): Collection
    {
        return $this->gestoriCheCoprono($idTipologia)
            ->where(self::conEmail(...))
            ->get();
    }

    /**
     * Coprono ma senza email (pre-registrati "sulla fiducia", mai entrati).
     *
     * @return Collection<int, User>
     */
    public function nonVerificati(int $idTipologia): Collection
    {
        return $this->gestoriCheCoprono($idTipologia)
            ->where(fn (Builder $q) => $q->whereNull('email')->orWhere('email', ''))
            ->get();
    }

    /** @return Collection<int, TipologiaSegnalazione> */
    public function orfane(): Collection
    {
        if ($this->gestoriAttivi()->where('gestore_tutte_tipologie', true)->where(self::conEmail(...))->exists()) {
            return new Collection();
        }

        $coperte = DB::table('gestori_tipologie')
            ->whereIn('id_utente', $this->coprenti()->where(self::conEmail(...))->select('users.id'))
            ->distinct()
            ->pluck('id_tipologia_segnalazione');

        return TipologiaSegnalazione::with('gruppo')
            ->whereNotIn('id_tipologia_segnalazione', $coperte)
            ->orderBy('id_gruppo')
            ->orderBy('descrizione')
            ->get();
    }

    public function isOrfana(int $idTipologia): bool
    {
        return $this->gestoriDi($idTipologia)->isEmpty();
    }

    /** @return Collection<int, User> */
    public function supervisori(): Collection
    {
        return User::query()->where(self::conRuolo('gestore'))
            ->where('supervisore_segnalazioni', true)
            ->where(fn (Builder $q) => $q->where('attivo', true)->orWhereNull('attivo'))
            ->where(self::conEmail(...))
            ->get();
    }

    /** Backfill del rilascio: i gestori già in servizio partono con "tutte le tipologie". */
    public static function abilitaTutteAiGestoriEsistenti(): int
    {
        $ruolo = DB::table('roles')->where('name', 'gestore')->value('id');
        if ($ruolo === null) {
            return 0;
        }

        $ids = DB::table('model_has_roles')
            ->where('role_id', $ruolo)
            ->where('model_type', User::class)
            ->pluck('model_id');

        return DB::table('users')
            ->whereIn('id', $ids)
            ->where('supervisore_segnalazioni', false)
            ->update(['gestore_tutte_tipologie' => true]);
    }

    /**
     * Filtro per ruolo che non esplode se il ruolo non esiste ancora
     * (lo scope role() di Spatie lancia RoleDoesNotExist).
     *
     * @return \Closure(Builder<User>): void
     */
    private static function conRuolo(string $ruolo): \Closure
    {
        return fn (Builder $q) => $q->whereHas('roles', fn (Builder $r) => $r->where('name', $ruolo));
    }

    /** @param Builder<User> $q */
    private static function conEmail(Builder $q): void
    {
        $q->whereNotNull('email')->where('email', '!=', '');
    }

    /** @return Builder<User> */
    private function gestoriAttivi(): Builder
    {
        return User::query()->where(self::conRuolo('gestore'))
            ->where('supervisore_segnalazioni', false)
            ->where(fn (Builder $q) => $q->where('attivo', true)->orWhereNull('attivo'));
    }

    /**
     * Gestori non supervisori e admin, attivi: chi può coprire tipologie.
     *
     * @return Builder<User>
     */
    private function coprenti(): Builder
    {
        return User::query()
            ->where(fn (Builder $q) => $q->where('attivo', true)->orWhereNull('attivo'))
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $g) => $g->where(self::conRuolo('gestore'))->where('supervisore_segnalazioni', false))
                ->orWhere(fn (Builder $a) => $a->where(self::conRuolo('admin'))));
    }

    /** @return Builder<User> */
    private function gestoriCheCoprono(int $idTipologia): Builder
    {
        return $this->coprenti()->where(fn (Builder $q) => $q
            ->where(fn (Builder $g) => $g->where(self::conRuolo('gestore'))->where('gestore_tutte_tipologie', true))
            ->orWhereHas('tipologieGestite', fn (Builder $t) => $t->whereKey($idTipologia)));
    }
}
