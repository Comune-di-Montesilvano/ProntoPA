<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sla:check')->dailyAt('07:00')->runInBackground();

Schedule::command('digest:invia')
    ->dailyAt((function () {
        try {
            $ora = \App\Models\Impostazione::get('digest_ora', '07:30');
            return preg_match('/^\d{2}:\d{2}$/', (string) $ora) ? $ora : '07:30';
        } catch (\Throwable) {
            return '07:30'; // DB non disponibile (es. primo deploy)
        }
    })())
    ->runInBackground();
