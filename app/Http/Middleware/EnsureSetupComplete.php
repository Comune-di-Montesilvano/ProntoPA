<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSetupComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        // Ambiente di test: gli utenti sono creati via factory, non tramite
        // il wizard — non ha senso applicare il gate.
        if (app()->environment('testing')) {
            return $next($request);
        }

        // Wizard disattivato (nessun SETUP_TOKEN configurato in .env):
        // comportamento invariato, nessun redirect forzato.
        if (blank(config('app.setup_token'))) {
            return $next($request);
        }

        // Escludi sempre: health check
        if ($request->getPathInfo() === '/up') {
            return $next($request);
        }

        if (!$request->route()) {
            return $next($request);
        }

        $routeName = $request->route()->getName() ?? '';

        if (str_starts_with($routeName, 'setup.')
            || in_array($routeName, ['login', 'logout', 'home'])
        ) {
            return $next($request);
        }

        try {
            if (! User::query()->exists()) {
                return redirect()->route('setup.show');
            }
        } catch (\Throwable) {
            // DB non disponibile: lascia passare per evitare loop
        }

        return $next($request);
    }
}
