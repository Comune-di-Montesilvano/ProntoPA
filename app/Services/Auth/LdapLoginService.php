<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Directory\Directory;

/**
 * Login dipendenti: AD è la fonte di verità. A ogni login ruolo, flag,
 * provenienza e permesso per-conto vengono ricalcolati dai gruppi
 * (scrittura in AllineaUtenteAd, la stessa della sincronizzazione).
 */
final class LdapLoginService
{
    public function __construct(
        private readonly Directory $directory,
        private readonly MappaGruppiLdap $mappa,
        private readonly AllineaUtenteAd $allinea,
    ) {}

    /**
     * @return User|null null = credenziali non valide
     *
     * @throws AccessoLdapNegato
     * @throws \App\Services\Directory\DirectoryNonDisponibile
     */
    public function login(string $username, #[\SensitiveParameter] string $password): ?User
    {
        // Su AD un bind con password vuota è un bind anonimo che "riesce".
        if ($password === '') {
            return null;
        }

        $identita = $this->directory->authenticate($username, $password);
        if ($identita === null) {
            return null;
        }

        $ruolo = $this->mappa->risolvi($identita->groups);
        if ($ruolo === null) {
            throw new AccessoLdapNegato('Non sei abilitato a ProntoPA. Contatta l\'amministratore.');
        }

        if (blank($identita->email)) {
            throw new AccessoLdapNegato('Il tuo account di dominio non ha un indirizzo email: contatta l\'amministratore.');
        }

        try {
            return $this->allinea->applica($identita, $ruolo, login: true);
        } catch (UsernameOccupato) {
            throw new AccessoLdapNegato('Il tuo nome utente è già usato da un altro account ProntoPA: contatta l\'amministratore.');
        }
    }
}
