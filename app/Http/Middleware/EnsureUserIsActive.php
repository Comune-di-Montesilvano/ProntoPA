<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if (($user->approval_status ?? 'approved') !== 'approved') {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $messaggio = $user->approval_status === 'rejected'
                ? 'La richiesta di registrazione non è stata approvata. Contatta il supporto.'
                : 'La tua registrazione è in attesa di approvazione da parte dell\'operatore.';

            return redirect()
                ->route('login')
                ->withErrors(['username' => $messaggio]);
        }

        if ($user->attivo !== false) {
            return $next($request);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('login')
            ->withErrors(['username' => 'Questo account è stato disattivato.']);
    }
}