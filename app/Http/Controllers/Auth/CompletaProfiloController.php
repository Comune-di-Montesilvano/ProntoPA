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
    public const MINUTI_VALIDITA = 15;

    /**
     * Identità SPID appena verificata dal callback, se ancora fresca.
     *
     * @return array<string, string|null>|null
     */
    private function identitaInSessione(Request $request): ?array
    {
        $dati = $request->session()->get('spid.identita');

        if (! is_array($dati)) {
            return null;
        }

        if ((int) ($dati['creata_il'] ?? 0) < now()->subMinutes(self::MINUTI_VALIDITA)->getTimestamp()) {
            $request->session()->forget('spid.identita');

            return null;
        }

        return $dati;
    }

    public function show(Request $request): View|RedirectResponse
    {
        $dati = $this->identitaInSessione($request);

        if ($dati === null) {
            return redirect()->route('login');
        }

        return view('auth.spid-completa-profilo', ['identita' => IdentitaSpid::fromArray($dati)]);
    }

    public function store(Request $request, SpidLoginService $servizio): RedirectResponse
    {
        $dati = $this->identitaInSessione($request);

        if ($dati === null) {
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
