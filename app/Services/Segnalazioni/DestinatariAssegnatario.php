<?php

namespace App\Services\Segnalazioni;

use App\Enums\SegnalazioneStato;
use App\Models\Impresa;
use App\Models\Segnalazione;
use App\Models\Squadra;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Chi ha in mano il lavoro, secondo lo stato attuale: la ditta (persone delegate attive
 * più l'email aziendale se non coincide con quella di un delegato), il caposquadra o l'operaio.
 * Ditta disattivata = nessuno.
 */
class DestinatariAssegnatario
{
    /** @return array{utenti: Collection<int, User>, email: list<string>} */
    public static function per(Segnalazione $segnalazione, ?User $escludi = null): array
    {
        $vuoto = ['utenti' => collect(), 'email' => []];
        $stato = $segnalazione->statoEnum();

        $risultato = match ($stato) {
            SegnalazioneStato::ASSEGNATA_IMPRESA, SegnalazioneStato::PREVENTIVO_IN_ATTESA => self::ditta($segnalazione) ?? $vuoto,
            SegnalazioneStato::ASSEGNATA_OPERATORE => ['utenti' => self::operativi($segnalazione), 'email' => []],
            default => $vuoto,
        };

        if ($escludi) {
            $risultato['utenti'] = $risultato['utenti']->reject(fn (User $u) => $u->id === $escludi->id)->values();
        }

        return $risultato;
    }

    /** @return array{utenti: Collection<int, User>, email: list<string>}|null */
    private static function ditta(Segnalazione $segnalazione): ?array
    {
        if (! $segnalazione->id_appalto) {
            return null;
        }

        $idImpresa = $segnalazione->appalto()->value('id_impresa');
        $impresa = $idImpresa ? Impresa::find($idImpresa) : null;

        if ($impresa === null || $impresa->attiva === false) {
            return null;
        }

        $utenti = User::role('impresa')
            ->where('id_impresa', $impresa->id_impresa)
            ->get()
            ->filter(fn (User $u) => $u->attivo !== false)
            ->values();

        $emailDitta = mb_strtolower(trim((string) $impresa->email));
        $giaAvvisata = $utenti->contains(fn (User $u) => mb_strtolower(trim((string) $u->email)) === $emailDitta);

        return ['utenti' => $utenti, 'email' => ($emailDitta !== '' && ! $giaAvvisata) ? [$emailDitta] : []];
    }

    /** @return Collection<int, User> */
    private static function operativi(Segnalazione $segnalazione): Collection
    {
        if ($segnalazione->id_squadra_assegnata) {
            $capo = Squadra::find($segnalazione->id_squadra_assegnata)?->caposquadra;

            return $capo instanceof User && $capo->attivo !== false ? collect([$capo]) : collect();
        }

        $operatore = $segnalazione->id_operatore_assegnato ? User::find($segnalazione->id_operatore_assegnato) : null;

        return $operatore && $operatore->attivo !== false ? collect([$operatore]) : collect();
    }
}
