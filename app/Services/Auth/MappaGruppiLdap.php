<?php

namespace App\Services\Auth;

use App\Models\Impostazione;

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

    /** Ordine = precedenza: [chiave impostazione, default, ruolo, supervisore, per-conto, provenienza] */
    private const LIVELLI = [
        ['ldap_gruppo_admin', 'PRONTOPA_ADMIN', 'admin', false, false, self::PROVENIENZA_INTERNA],
        ['ldap_gruppo_supervisori', 'PRONTOPA_SUPERVISORI', 'gestore', true, false, self::PROVENIENZA_INTERNA],
        ['ldap_gruppo_gestori', 'PRONTOPA_GESTORI', 'gestore', false, false, self::PROVENIENZA_INTERNA],
        ['ldap_gruppo_operai', 'PRONTOPA_OPERAI', 'operaio', false, false, self::PROVENIENZA_INTERNA],
        ['ldap_gruppo_urp', 'PRONTOPA_URP', 'segnalatore', false, true, self::PROVENIENZA_URP],
        ['ldap_gruppo_segnalatori', 'PRONTOPA_SEGNALATORI', 'segnalatore', false, false, self::PROVENIENZA_INTERNA],
    ];

    /**
     * @param  list<string>  $gruppi  CN dei gruppi AD dell'utente
     */
    public function risolvi(array $gruppi): ?RuoloLdap
    {
        $utente = array_map(fn (string $g) => mb_strtoupper(trim($g)), $gruppi);

        foreach (self::LIVELLI as [$chiave, $default, $ruolo, $supervisore, $perConto, $provenienza]) {
            $nome = mb_strtoupper(trim((string) Impostazione::get($chiave, $default)));

            if ($nome !== '' && in_array($nome, $utente, true)) {
                return new RuoloLdap($ruolo, $supervisore, $perConto, $provenienza);
            }
        }

        return null;
    }
}
