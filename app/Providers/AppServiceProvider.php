<?php

namespace App\Providers;

use App\Models\Segnalazione;
use App\Policies\SegnalazionePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(Segnalazione::class, SegnalazionePolicy::class);

        // Unica fonte di verità per la policy password: prima d'ora solo il
        // wizard di setup imponeva min 10 + complessità, registrazione e
        // cambio password usavano il default Laravel puro (8 caratteri,
        // zero complessità) — incoerente per un sistema con account
        // gestori/imprese su dati di minori.
        Password::defaults(fn () => Password::min(10)->mixedCase()->numbers());
    }
}
