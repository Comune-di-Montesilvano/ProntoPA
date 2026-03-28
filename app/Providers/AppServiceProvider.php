<?php

namespace App\Providers;

use App\Models\Segnalazione;
use App\Policies\SegnalazionePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(Segnalazione::class, SegnalazionePolicy::class);
    }
}
