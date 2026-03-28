<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

class RoleDashboardController extends Controller
{
    /**
     * Redirige l'utente autenticato alla dashboard corretta per il suo ruolo.
     */
    public function index(): RedirectResponse
    {
        $user = auth()->user();

        // Priorità: ruolo Spatie → boolean legacy
        if ($user->hasRole('admin') || $user->isAdmin()) {
            return redirect()->route('admin.dashboard');
        }

        if ($user->hasRole('gestore') || $user->isGestore()) {
            return redirect()->route('gestione.dashboard');
        }

        if ($user->hasRole('impresa')) {
            return redirect()->route('imprese.dashboard');
        }

        // segnalatore o qualsiasi ruolo non riconosciuto
        return redirect()->route('segnalatore.dashboard');
    }
}
