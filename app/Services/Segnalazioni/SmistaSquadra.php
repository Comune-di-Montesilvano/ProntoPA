<?php

namespace App\Services\Segnalazioni;

use App\Models\Segnalazione;
use App\Models\Squadra;
use App\Models\StoricoStatoSegnalazione;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Il caposquadra passa il lavoro della squadra a un membro (scheda web e bot Telegram, 1.0.15). */
class SmistaSquadra
{
    /** Squadra attiva assegnata di cui l'utente è caposquadra, altrimenti null. */
    public function squadraDiCapo(Segnalazione $s, User $utente): ?Squadra
    {
        if (! $s->id_squadra_assegnata || $s->isChiusa()) {
            return null;
        }
        $squadra = Squadra::where('attiva', true)->find($s->id_squadra_assegnata);

        return $squadra && (int) $squadra->id_caposquadra === (int) $utente->id ? $squadra : null;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, User> membri attivi della squadra */
    public function membri(Squadra $squadra): \Illuminate\Database\Eloquent\Collection
    {
        return User::whereIn('id', $squadra->membri()->select('users.id'))
            ->where(fn ($q) => $q->whereNull('attivo')->orWhere('attivo', true))
            ->orderBy('name')
            ->get();
    }

    /** @throws ValidationException se l'utente non è membro della squadra */
    public function smista(Segnalazione $s, Squadra $squadra, User $capo, int $idMembro): void
    {
        if (! $squadra->membri()->where('users.id', $idMembro)->exists()) {
            throw ValidationException::withMessages(['id_membro' => 'L\'utente non è membro della squadra.']);
        }

        $s->update(['id_operatore_assegnato' => $idMembro]);

        StoricoStatoSegnalazione::create([
            'id_segnalazione' => $s->id_segnalazione,
            'id_stato_segnalazione' => $s->id_stato_segnalazione,
            'id_utente' => $capo->id,
            'id_utente_collegato' => $idMembro,
            'id_appalto' => 0,
        ]);
    }
}
