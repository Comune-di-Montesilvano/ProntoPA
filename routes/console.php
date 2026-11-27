<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sla:check')->dailyAt('07:00')->runInBackground();
Schedule::command('segnalazioni:inattivita')->dailyAt('07:15')->runInBackground();
Schedule::command('segnalazioni:richieste')->dailyAt('07:20')->runInBackground();

Schedule::command('backup:crea --tipo=notturno')->dailyAt('02:00')->withoutOverlapping()->runInBackground();

// Prima di sla:check e digest: gli avvisi del mattino vanno a utenti già allineati ad AD.
Schedule::command('ldap:sincronizza')->dailyAt('05:30')->withoutOverlapping()->runInBackground();

// Sedi nuove o con indirizzo cambiato: posizione trovata senza che nessuno la chieda (1.0.2).
Schedule::command('sedi:geocodifica')->dailyAt('06:10')->runInBackground();

// Anagrafe scuole MIUR riscaricata ogni mese (1.0.16): nomi, indirizzi ed email delle sedi importate.
Schedule::command('miur:scarica')->monthlyOn(1, '04:00')->runInBackground();

// Foto caricate a blocchi e mai inviate (1.0.20).
Schedule::command('allegati:pulisci-temporanei')->hourly()->runInBackground();

Schedule::command('deleghe:scadenze')->dailyAt('06:30')->runInBackground();
Schedule::command('deleghe:rinnovi')->monthlyOn(1, '07:00')->runInBackground();

// Riepilogo degli avvisi: ogni ora piena in orario d'ufficio e alla chiusura (il comando decide),
// poi il giro del mattino con il quadro di oggi (sostituisce digest:invia, che resta come comando).
Schedule::command('avvisi:invia --ritmo=ora')->everyFifteenMinutes()->withoutOverlapping()->runInBackground();

Schedule::command('avvisi:invia --ritmo=giorno')
    ->dailyAt((function () {
        try {
            $ora = \App\Models\Impostazione::get('digest_ora', '07:30');
            return preg_match('/^\d{2}:\d{2}$/', (string) $ora) ? $ora : '07:30';
        } catch (\Throwable) {
            return '07:30'; // DB non disponibile (es. primo deploy)
        }
    })())
    ->withoutOverlapping()
    ->runInBackground();

// Difesa in profondità se Sentry non è configurato: senza questo, job
// falliti (webhook irraggiungibile, Ollama giù) restano invisibili in
// failed_jobs a tempo indefinito.
Schedule::command('jobs:check-failed')->weeklyOn(1, '08:00')->runInBackground();
