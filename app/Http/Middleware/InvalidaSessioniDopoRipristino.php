<?php

namespace App\Http\Middleware;

use App\Models\Impostazione;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dopo un ripristino gli id utente del backup non corrispondono a quelli di
 * prima: ogni sessione aperta prima dell'evento va chiusa. Il ripristino cambia
 * l'impostazione `ripristino_id`; al login la sessione ne memorizza il valore
 * corrente (listener di Login in AppServiceProvider).
 */
class InvalidaSessioniDopoRipristino
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            $corrente = Impostazione::get('ripristino_id');
            $sessione = $request->session()->get('ripristino_id');

            // Installazioni mai ripristinate: né impostazione né sessione, nessun effetto.
            if ($corrente !== $sessione) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->with('status', 'Sistema ripristinato da backup, accedi di nuovo.');
            }
        }

        return $next($request);
    }
}
