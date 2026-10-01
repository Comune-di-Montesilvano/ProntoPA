<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Oidc\ClaimsSpid;
use App\Services\Oidc\IdentitaSpid;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Simulatore SPID per sviluppo e Dusk (OIDC_MOCK=true). Vietato in
 * produzione da OidcMockGuard; qui in più 404 se disattivo.
 */
class SpidMockController extends Controller
{
    public function show(): View
    {
        abort_unless(config('oidc.mock'), 404);

        return view('auth.spid-mock');
    }

    public function store(Request $request, SpidController $spid): RedirectResponse
    {
        abort_unless(config('oidc.mock'), 404);

        $request->merge(['codice_fiscale' => ClaimsSpid::normalizzaCodiceFiscale((string) $request->input('codice_fiscale'))]);

        $dati = $request->validate([
            'codice_fiscale' => ['required', 'regex:/^[A-Z0-9]{16}$/'],
            'nome' => ['required', 'string', 'max:100'],
            'cognome' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $identita = new IdentitaSpid(
            $dati['codice_fiscale'],
            $dati['nome'],
            $dati['cognome'],
            isset($dati['email']) ? mb_strtolower($dati['email']) : null,
            'mock-'.$dati['codice_fiscale'],
        );

        return $spid->concludi($request, $identita, null);
    }
}
