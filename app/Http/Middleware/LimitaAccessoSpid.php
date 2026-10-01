<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Utenti delle scuole (SPID): prima confermano l'email, poi vedono solo
 * ciò che la loro delega consente. In 2a nessuna delega esiste ancora:
 * pagina d'attesa. Il piano 2b sostituisce consentite().
 */
class LimitaAccessoSpid
{
    private const SEMPRE = ['verification.*', 'logout', 'profile.edit', 'profile.update'];

    /** @return list<string> */
    public static function consentite(User $user): array
    {
        return ['spid.attesa'];
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isSpid() || $request->routeIs(...self::SEMPRE)) {
            return $next($request);
        }

        if (! $user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        if ($request->routeIs(...self::consentite($user))) {
            return $next($request);
        }

        return redirect()->route('spid.attesa');
    }
}
