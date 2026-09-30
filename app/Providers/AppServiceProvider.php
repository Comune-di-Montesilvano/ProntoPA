<?php

namespace App\Providers;

use App\Models\Segnalazione;
use App\Policies\SegnalazionePolicy;
use App\Services\Directory\Directory;
use App\Services\Directory\LdapConfigGuard;
use App\Services\Directory\LdapRecordDirectory;
use App\Services\Directory\MockDirectory;
use App\Services\Directory\NullDirectory;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Directory::class, function () {
            $host = config('ldap.host');

            return match (true) {
                $host === 'mock' => new MockDirectory(),
                blank($host) => new NullDirectory(),
                default => new LdapRecordDirectory(config('ldap')),
            };
        });
    }

    public function boot(): void
    {
        LdapConfigGuard::verifica((string) $this->app->environment(), config('ldap.host'));

        if ($avviso = LdapConfigGuard::avviso((string) $this->app->environment(), (bool) config('ldap.tls_skip_verify'))) {
            Log::warning($avviso);
        }

        Gate::policy(Segnalazione::class, SegnalazionePolicy::class);

        // Unica fonte di verità per la policy password: prima d'ora solo il
        // wizard di setup imponeva min 10 + complessità, registrazione e
        // cambio password usavano il default Laravel puro (8 caratteri,
        // zero complessità) — incoerente per un sistema con account
        // gestori/imprese su dati di minori.
        Password::defaults(fn () => Password::min(10)->mixedCase()->numbers());
    }
}
