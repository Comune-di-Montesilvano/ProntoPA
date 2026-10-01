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
        $this->table(['Configurazione letta', 'Valore'], [
            ['LDAP_HOST', (string) config('ldap.host') ?: '— (vuoto)'],
            ['LDAP_PORT', (string) config('ldap.port')],
            ['LDAP_BASE_DN', (string) config('ldap.base_dn') ?: '— (vuoto)'],
            ['LDAP_USER_DN_TEMPLATE', (string) config('ldap.user_dn_template')],
            ['LDAP_STARTTLS', config('ldap.starttls') ? 'true' : 'false'],
            ['LDAP_TLS_SKIP_VERIFY', config('ldap.tls_skip_verify') ? 'true' : 'false'],
            ['Directory', class_basename($directory)],
            ['Gruppi', implode(', ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys((array) config('ldap.gruppi')), (array) config('ldap.gruppi')))],
        ]);

        $password = (string) $this->secret('Password');

        try {
            $identita = $directory->authenticate((string) $this->argument('username'), $password);
        } catch (DirectoryNonDisponibile $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($identita === null) {
            $this->error('Accesso rifiutato: '.($directory->motivoUltimoRifiuto() ?? 'credenziali non valide.'));

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
