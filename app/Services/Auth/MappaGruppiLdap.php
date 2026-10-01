<?php

namespace App\Services\Auth;

/**
 * Gruppi AD → ruolo ProntoPA. Un solo ruolo per persona (vince il più
 * alto): Segnalazione::scopeVisibileA valuta i ruoli in cascata e un
 * doppio ruolo produrrebbe una visibilità sbagliata. Caposquadra non è un
 * gruppo: si decide sulla squadra in ProntoPA.
 */
final class MappaGruppiLdap
{
    public const PROVENIENZA_INTERNA = 1;

    public const PROVENIENZA_URP = 3;

    /** Ordine = precedenza: [chiave config('ldap.gruppi'), ruolo, supervisore, per-conto, provenienza] */
    private const LIVELLI = [
        ['admin', 'admin', false, false, self::PROVENIENZA_INTERNA],
        ['supervisori', 'gestore', true, false, self::PROVENIENZA_INTERNA],
        ['gestori', 'gestore', false, false, self::PROVENIENZA_INTERNA],
        ['operai', 'operaio', false, false, self::PROVENIENZA_INTERNA],
        ['urp', 'segnalatore', false, true, self::PROVENIENZA_URP],
        ['segnalatori', 'segnalatore', false, false, self::PROVENIENZA_INTERNA],
    ];

    /**
     * @param  list<string>  $gruppi  CN dei gruppi AD dell'utente
     */
    public function risolvi(array $gruppi): ?RuoloLdap
    {
        $utente = array_map(fn (string $g) => mb_strtoupper(trim($g)), $gruppi);

        foreach (self::LIVELLI as [$chiave, $ruolo, $supervisore, $perConto, $provenienza]) {
            // Nomi in env (LDAP_GRUPPO_*): servono già al primo login, prima che esista un admin.
            $nome = mb_strtoupper(trim((string) config("ldap.gruppi.{$chiave}")));

            if ($nome !== '' && in_array($nome, $utente, true)) {
                return new RuoloLdap($ruolo, $supervisore, $perConto, $provenienza);
            }
        }

        return null;
    }
}
