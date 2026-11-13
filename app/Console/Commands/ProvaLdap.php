<?php

namespace App\Console\Commands;

use App\Services\Auth\MappaGruppiLdap;
use App\Services\Directory\Directory;
use App\Services\Directory\DirectoryNonDisponibile;
use Illuminate\Console\Command;

class ProvaLdap extends Command
{
    protected $signature = 'ldap:prova
        {username? : sAMAccountName da provare (login)}
        {--sync : prova l\'account di servizio e conta i membri dei gruppi}';

    protected $description = 'Verifica login AD e ruolo risolto per un utente, o l\'account di servizio della sync, senza scrivere sul DB';

    public function handle(Directory $directory, MappaGruppiLdap $mappa): int
    {
        $this->table(['Configurazione letta', 'Valore'], [
            ['LDAP_HOST', (string) config('ldap.host') ?: '— (vuoto)'],
            ['LDAP_PORT', (string) config('ldap.port')],
            ['LDAP_BASE_DN', (string) config('ldap.base_dn') ?: '— (vuoto)'],
            ['LDAP_USER_DN_TEMPLATE', (string) config('ldap.user_dn_template')],
            ['LDAP_STARTTLS', config('ldap.starttls') ? 'true' : 'false'],
            ['LDAP_TLS_SKIP_VERIFY', config('ldap.tls_skip_verify') ? 'true' : 'false'],
            ['LDAP_SYNC_USERNAME', (string) config('ldap.sync.username') ?: '— (vuoto: sync spenta)'],
            ['LDAP_SYNC_PASSWORD', filled(config('ldap.sync.password')) ? 'impostata' : '— (vuota)'],
            ['Directory', class_basename($directory)],
            ['Gruppi', implode(', ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys((array) config('ldap.gruppi')), (array) config('ldap.gruppi')))],
        ]);

        if ($this->option('sync')) {
            return $this->provaSync($directory);
        }

        if (blank($this->argument('username'))) {
            $this->error('Indica uno username da provare, oppure --sync per l\'account di servizio.');

            return self::FAILURE;
        }

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
                ? $ruolo->ruolo.($ruolo->supervisore ? ' (supervisore)' : '')
                : '— (nessun gruppo: login rifiutato)'],
        ]);

        return self::SUCCESS;
    }

    private function provaSync(Directory $directory): int
    {
        if (! $directory->syncConfigurata()) {
            $this->error('Sincronizzazione non configurata: imposta LDAP_SYNC_USERNAME e LDAP_SYNC_PASSWORD.');

            return self::FAILURE;
        }

        try {
            $membri = $directory->membriGruppi();
        } catch (DirectoryNonDisponibile $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $righe = [];
        foreach ((array) config('ldap.gruppi') as $gruppo) {
            $gruppo = (string) $gruppo;
            $nomeMaiuscolo = mb_strtoupper($gruppo);
            $righe[] = [$gruppo, count(array_filter($membri, fn ($m) => in_array($nomeMaiuscolo, array_map('mb_strtoupper', $m->groups), true)))];
        }
        $this->table(['Gruppo', 'Membri'], $righe);

        $mancanti = $directory->gruppiMancantiUltimaLettura();
        if ($mancanti !== []) {
            $this->warn('Gruppi non trovati in AD (ignorati dalla sync finché nessun utente ha quel ruolo): '.implode(', ', $mancanti));
        }

        $disabilitati = count(array_filter($membri, fn ($m) => ! $m->abilitato));
        $senzaEmail = count(array_filter($membri, fn ($m) => blank($m->email)));
        $this->info(sprintf('Totale: %d membri (%d disabilitati in AD, %d senza email). Nessuna scrittura.', count($membri), $disabilitati, $senzaEmail));

        return self::SUCCESS;
    }
}
