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
    public function authenticate(string $username, #[\SensitiveParameter] string $password): ?DirectoryIdentity;

    /**
     * Perché l'ultimo authenticate() ha restituito null, per la diagnosi
     * (ldap:prova, log). Mai mostrato all'utente nel form di login.
     */
    public function motivoUltimoRifiuto(): ?string;

    /** true = l'elenco membri è leggibile (account di servizio configurato, o mock). */
    public function syncConfigurata(): bool;

    /**
     * Tutti i membri (anche annidati) dei gruppi in config('ldap.gruppi'),
     * con i soli gruppi ProntoPA di appartenenza. Elenco completo o eccezione:
     * mai un elenco parziale (la sync disattiverebbe chi manca).
     *
     * @return list<DirectoryIdentity>
     *
     * @throws DirectoryNonDisponibile sync non configurata, bind di servizio fallito, AD non raggiungibile
     */
    public function membriGruppi(): array;

    /**
     * Gruppi configurati (in maiuscolo) che l'ultimo membriGruppi() non ha
     * trovato in AD. Chi chiama decide se è grave (ci sono utenti di quel ruolo).
     *
     * @return list<string>
     */
    public function gruppiMancantiUltimaLettura(): array;
}
