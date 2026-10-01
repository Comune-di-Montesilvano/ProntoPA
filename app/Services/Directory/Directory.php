<?php

namespace App\Services\Directory;

interface Directory
{
    /**
     * Verifica username/password contro la directory dei dipendenti.
     *
     * @return DirectoryIdentity|null null = credenziali non valide
     *
     * @throws DirectoryNonDisponibile directory configurata ma non raggiungibile
     */
    public function authenticate(string $username, string $password): ?DirectoryIdentity;

    /**
     * Perché l'ultimo authenticate() ha restituito null, per la diagnosi
     * (ldap:prova, log). Mai mostrato all'utente nel form di login.
     */
    public function motivoUltimoRifiuto(): ?string;
}
