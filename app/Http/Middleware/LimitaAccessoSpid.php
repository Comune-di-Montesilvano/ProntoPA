<?php

namespace App\Http\Middleware;

use App\Models\Delega;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Utenti delle scuole (SPID): prima confermano l'email, poi senza una delega
 * attiva vedono solo le pagine delle deleghe.
 */
class LimitaAccessoSpid
{
    private const SEMPRE = ['verification.*', 'logout', 'profile.edit', 'profile.update'];

    private const SENZA_DELEGA = ['spid.attesa', 'scuola.deleghe.*'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isSpid() || $request->routeIs(...self::SEMPRE)) {
            return $next($request);
        }

        if (! $user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        if ($request->routeIs(...self::SENZA_DELEGA)
            || Delega::attive()->where('user_id', $user->id)->exists()) {
            return $next($request);
        }

        return redirect()->route('scuola.deleghe.index');
    }
}
