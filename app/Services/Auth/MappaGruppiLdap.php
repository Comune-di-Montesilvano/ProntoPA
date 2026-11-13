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
    /**
     * Ordine = precedenza: [chiave config('ldap.gruppi'), ruolo, supervisore].
     * URP e segnalatori sono la stessa cosa (1.0.0: niente più "per conto").
     */
    private const LIVELLI = [
        ['admin', 'admin', false],
        ['supervisori', 'gestore', true],
        ['gestori', 'gestore', false],
        ['operai', 'operaio', false],
        ['urp', 'segnalatore', false],
        ['segnalatori', 'segnalatore', false],
    ];

    /**
     * @param  list<string>  $gruppi  CN dei gruppi AD dell'utente
     */
    public function risolvi(array $gruppi): ?RuoloLdap
    {
        $utente = array_map(fn (string $g) => mb_strtoupper(trim($g)), $gruppi);

        foreach (self::LIVELLI as [$chiave, $ruolo, $supervisore]) {
            // Nomi in env (LDAP_GRUPPO_*): servono già al primo login, prima che esista un admin.
            $nome = mb_strtoupper(trim((string) config("ldap.gruppi.{$chiave}")));

            if ($nome !== '' && in_array($nome, $utente, true)) {
                return new RuoloLdap($ruolo, $supervisore);
            }
        }

        return null;
    }
}
