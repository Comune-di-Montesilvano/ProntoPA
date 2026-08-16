<?php

namespace App\Http\Middleware;

use App\Models\Impostazione;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSetupComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        // Escludi sempre: health check e le route di setup stesso
        if ($request->getPathInfo() === '/up') {
            return $next($request);
        }

        if (!$request->route()) {
            return $next($request);
        }

        $routeName = $request->route()->getName() ?? '';

        if (str_starts_with($routeName, 'setup.')
            || str_starts_with($routeName, 'password.')
            || str_starts_with($routeName, 'verification.')
            || str_starts_with($routeName, 'magic-link.')
            || str_starts_with($routeName, 'account.verification.')
            || in_array($routeName, ['login', 'logout', 'register', 'home'])
        ) {
            return $next($request);
        }

        try {
            if (Impostazione::get('setup_completato') !== '1') {
                return redirect()->route('setup.show');
            }
        } catch (\Throwable) {
            // DB non disponibile: lascia passare per evitare loop
        }

        return $next($request);
    }
}
