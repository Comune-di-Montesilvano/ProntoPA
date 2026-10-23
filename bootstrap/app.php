<?php

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        then: function () {
            // Esito del ripristino: volutamente fuori dal gruppo web (niente sessione, auth,
            // DB). Durante il ripristino le tabelle vengono sostituite; il throttle usa la cache.
            Route::middleware('throttle:60,1')
                ->get('ripristino/stato/{token}', [\App\Http\Controllers\Admin\BackupController::class, 'stato'])
                ->name('ripristino.stato');
        },
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule) {
    })
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'role'       => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);

        // Durante il ripristino il polling dell'esito deve restare raggiungibile.
        $middleware->preventRequestsDuringMaintenance(except: ['ripristino/stato/*']);

        $middleware->web(append: [
            \App\Http\Middleware\InvalidaSessioniDopoRipristino::class,
            \App\Http\Middleware\EnsureUserIsActive::class,
            \App\Http\Middleware\LimitaAccessoSpid::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // No-op se SENTRY_LARAVEL_DSN non è configurato (vedi config/sentry.php).
        \Sentry\Laravel\Integration::handles($exceptions);

        // Mai nei dati flashati in sessione dopo un errore di validazione.
        $exceptions->dontFlash(['passphrase']);
    })->create();
