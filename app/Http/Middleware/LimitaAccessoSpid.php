<?php

namespace App\Http\Middleware;

use App\Models\Delega;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Utenti delle scuole (SPID): prima confermano l'email, poi senza una delega
 * attiva vedono solo le pagine delle deleghe.
 */
class LimitaAccessoSpid
{
    // deleghe.decidi*/rinnovo*: pagine della segreteria, aperte anche da chi ha
    // una sessione SPID nello stesso browser (es. la DSGA).
    private const SEMPRE = ['verification.*', 'logout', 'profile.edit', 'profile.update', 'deleghe.decidi*', 'deleghe.rinnovo*'];

    private const SENZA_DELEGA = ['spid.attesa', 'scuola.deleghe.*'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isSpid()) {
            return $next($request);
        }

        // Bloccato da una segreteria mentre era collegato: chiude la sessione.
        if ($user->isBloccato()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', "Accesso non consentito. Contatta l'ente.");
        }

        if ($request->routeIs(...self::SEMPRE)) {
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
