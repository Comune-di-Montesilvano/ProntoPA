<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\SyncAdFallitaNotification;
use App\Services\Auth\SincronizzaUtentiAd;
use App\Services\Directory\Directory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class SincronizzaLdap extends Command
{
    protected $signature = 'ldap:sincronizza
        {--forza : disattiva anche oltre la soglia del 50% degli utenti di dominio}
        {--prova : calcola e stampa senza scrivere}';

    protected $description = 'Allinea gli utenti di dominio ai membri dei gruppi AD PRONTOPA_* (account di servizio)';

    public function handle(SincronizzaUtentiAd $sync, Directory $directory): int
    {
        if (! $directory->syncConfigurata()) {
            $this->info('Sincronizzazione AD spenta: LDAP_SYNC_USERNAME / LDAP_SYNC_PASSWORD non impostate.');

            return self::SUCCESS;
        }

        $prova = (bool) $this->option('prova');
        $esito = $sync->esegui((bool) $this->option('forza'), $prova);

        $this->table(['Esito', 'Creati', 'Aggiornati', 'Riattivati', 'Disattivati', 'Da disattivare', 'Senza email', 'Username occupati'], [[
            $esito->stato, $esito->creati, $esito->aggiornati, $esito->riattivati, $esito->disattivati,
            $esito->daDisattivare, $esito->senzaEmail, $esito->usernameOccupati,
        ]]);
        if ($esito->motivo) {
            $this->line($esito->motivo);
        }

        if ($esito->ok()) {
            return self::SUCCESS;
        }

        if (! $prova) {
            $admin = User::where('attivo', true)
                ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
                ->get();
            Notification::send($admin, SyncAdFallitaNotification::da($esito));
        }

        return self::FAILURE;
    }
}
