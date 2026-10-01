<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\AccessoSpidNegato;
use App\Services\Auth\SpidLoginService;
use App\Services\Oidc\ClaimsSpid;
use App\Services\Oidc\IdentitaSpid;
use App\Services\Oidc\OidcAccessoFallito;
use App\Services\Oidc\OidcClient;
use App\Services\Oidc\OidcNonDisponibile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Login scuole via pa-sso-proxy. state/nonce/verifier nella sessione
 * Laravel: legata al browser dal cookie, quindi il callback completato da
 * un altro browser (login CSRF) non trova lo state e viene rifiutato.
 */
class SpidController extends Controller
{
    private const NON_DISPONIBILE = 'Accesso SPID/CIE temporaneamente non disponibile. Riprova più tardi.';

    private const NON_RIUSCITO = 'Accesso SPID/CIE non riuscito, riprova.';

    private const SCADUTA = 'Sessione di accesso scaduta, riprova.';

    public function start(Request $request, OidcClient $client): RedirectResponse
    {
        if (config('oidc.mock')) {
            return redirect()->route('spid.mock');
        }

        $state = Str::random(40);
        $nonce = Str::random(40);
        $verifier = OidcClient::nuovoVerifier();

        try {
            $url = $client->urlAutorizzazione($state, $nonce, $verifier);
        } catch (OidcNonDisponibile $e) {
            report($e);

            return $this->errore(self::NON_DISPONIBILE);
        }

        $request->session()->put(['spid.state' => $state, 'spid.nonce' => $nonce, 'spid.verifier' => $verifier]);

        return redirect()->away($url);
    }

    public function callback(Request $request, OidcClient $client): RedirectResponse
    {
        $stateAtteso = (string) $request->session()->pull('spid.state', '');
        $nonce = (string) $request->session()->pull('spid.nonce', '');
        $verifier = (string) $request->session()->pull('spid.verifier', '');

        if ($stateAtteso === '' || ! hash_equals($stateAtteso, (string) $request->query('state', ''))) {
            return $this->errore(self::SCADUTA);
        }

        if ($request->filled('error')) {
            return $this->errore('Accesso SPID/CIE annullato.');
        }

        try {
            $esito = $client->completaAccesso((string) $request->query('code', ''), $verifier, $nonce);
        } catch (OidcNonDisponibile $e) {
            report($e);

            return $this->errore(self::NON_DISPONIBILE);
        } catch (OidcAccessoFallito $e) {
            report($e);

            return $this->errore(self::NON_RIUSCITO);
        }

        $identita = ClaimsSpid::estrai($esito['claims']);

        if ($identita === null) {
            report(new OidcAccessoFallito('Claim SPID senza codice fiscale valido'));

            return $this->errore(self::NON_RIUSCITO);
        }

        return $this->concludi($request, $identita, $esito['id_token']);
    }

    /** Condiviso con il simulatore dev (mock). */
    public function concludi(Request $request, IdentitaSpid $identita, ?string $idToken): RedirectResponse
    {
        try {
            $user = app(SpidLoginService::class)->accedi($identita);
        } catch (AccessoSpidNegato $e) {
            return $this->errore($e->getMessage());
        }

        if ($user === null) {
            // Scade dopo CompletaProfiloController::MINUTI_VALIDITA: su un PC
            // condiviso non deve restare reclamabile da chi viene dopo.
            $request->session()->put('spid.identita', $identita->toArray() + ['creata_il' => now()->getTimestamp()]);

            return redirect()->route('spid.completa-profilo');
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('spid.id_token', $idToken);

        return redirect()->intended(route('dashboard'));
    }

    private function errore(string $messaggio): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['username' => $messaggio]);
    }
}
