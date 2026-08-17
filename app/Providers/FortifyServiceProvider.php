<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

/**
 * Fortify è usato solo come libreria per il 2FA (TOTP + recovery codes):
 * login/registrazione/reset password restano gestiti dai controller custom
 * dell'app (routes/auth.php). Fortify::ignoreRoutes() evita che registri
 * le sue rotte (es. login/logout) in conflitto con le nostre.
 */
class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Fortify::ignoreRoutes();
    }

    public function boot(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });
    }
}
