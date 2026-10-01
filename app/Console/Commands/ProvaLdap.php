<?php

namespace App\Console\Commands;

use App\Services\Auth\MappaGruppiLdap;
use App\Services\Directory\Directory;
use App\Services\Directory\DirectoryNonDisponibile;
use Illuminate\Console\Command;

class ProvaLdap extends Command
{
    protected $signature = 'ldap:prova {username : sAMAccountName da provare}';

    protected $description = 'Verifica login AD e ruolo risolto per un utente, senza scrivere sul DB';

    public function handle(Directory $directory, MappaGruppiLdap $mappa): int
    {
        $password = (string) $this->secret('Password');

        try {
            $identita = $directory->authenticate((string) $this->argument('username'), $password);
        } catch (DirectoryNonDisponibile $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($identita === null) {
            $this->error('Credenziali non valide (o LDAP_HOST vuoto).');

            return self::FAILURE;
        }

        $ruolo = $mappa->risolvi($identita->groups);

        $this->table(['Campo', 'Valore'], [
            ['GUID', $identita->guid],
            ['Username', $identita->username],
            ['Nome', $identita->name],
            ['Email', $identita->email ?? '— (manca: login rifiutato)'],
            ['Gruppi', implode(', ', $identita->groups) ?: '—'],
            ['Ruolo ProntoPA', $ruolo
                ? $ruolo->ruolo.($ruolo->supervisore ? ' (supervisore)' : '').($ruolo->perConto ? ' + per-conto' : '')
                : '— (nessun gruppo: login rifiutato)'],
        ]);

        return self::SUCCESS;
    }
}
