<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\SpidLoginService;
use App\Services\Oidc\IdentitaSpid;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * Primo accesso SPID: l'utente nasce qui, con l'email (sempre da
 * verificare, anche se arriva dal claim SPID).
 */
class CompletaProfiloController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $dati = $request->session()->get('spid.identita');

        if (! is_array($dati)) {
            return redirect()->route('login');
        }

        return view('auth.spid-completa-profilo', ['identita' => IdentitaSpid::fromArray($dati)]);
    }

    public function store(Request $request, SpidLoginService $servizio): RedirectResponse
    {
        $dati = $request->session()->get('spid.identita');

        if (! is_array($dati)) {
            return redirect()->route('login');
        }

        $validati = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255',
                // Unica tra ditte e scuole attive; può coincidere con un utente AD.
                Rule::unique(User::class, 'email')->where(fn ($q) => $q
                    ->whereIn('auth_source', ['locale', 'spid'])
                    ->where('attivo', true)),
            ],
        ]);

        $user = $servizio->creaDaProfilo(IdentitaSpid::fromArray($dati), $validati['email']);

        $request->session()->forget('spid.identita');
        Auth::login($user);
        $request->session()->regenerate();

        if (! $user->hasVerifiedEmail()) {
            try {
                $user->sendEmailVerificationNotification();
            } catch (Throwable $e) {
                // Account già creato: niente 500, la persona può ritentare
                // l'invio dalla pagina di verifica.
                report($e);

                return redirect()->route('verification.notice')
                    ->with('status', 'Non siamo riusciti a inviare l\'email di conferma. Riprova con "Invia di nuovo il link".');
            }
        }

        return redirect()->route('verification.notice');
    }
}
