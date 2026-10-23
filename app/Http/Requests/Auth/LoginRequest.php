<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\Auth\AccessoLdapNegato;
use App\Services\Auth\LdapLoginService;
use App\Services\Auth\NormalizzaUsernameAd;
use App\Services\Directory\DirectoryNonDisponibile;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Instrada per contenuto del campo: con "@" (che non sia l'UPN del
     * dominio AD) → account locale per email (ditte); altrimenti → Active
     * Directory. Le credenziali vengono solo verificate: il login vero
     * avviene qui se non serve secondo fattore, altrimenti dopo il
     * challenge (sessione 'login.*', stesso pattern di Fortify).
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $password = (string) $this->input('password');
        [$canale, $identita] = $this->identitaLogin();

        // Solo personale AD: un'email non di dominio (vecchie ditte) non va
        // mai ad AD, dove verrebbe ridotta a un sAMAccountName qualsiasi.
        $user = $canale === 'email' ? null : $this->tentaDipendente($identita, $password);

        if ($user === null) {
            $this->fallito(trans('auth.failed'));
        }

        $this->ensureUserIsActive($user);
        RateLimiter::clear($this->throttleKey());

        $metodo = $user->metodoSecondoFattore();

        if ($metodo !== null) {
            $this->session()->put([
                'login.id'       => $user->getKey(),
                'login.remember' => $this->boolean('remember'),
                'login.metodo'   => $metodo,
            ]);

            return;
        }

        Auth::login($user, $this->boolean('remember'));
    }

    /**
     * @throws ValidationException
     */
    private function tentaDipendente(string $username, #[\SensitiveParameter] string $password): ?User
    {
        try {
            $user = app(LdapLoginService::class)->login($username, $password);
        } catch (AccessoLdapNegato $e) {
            $this->fallito($e->getMessage());
        } catch (DirectoryNonDisponibile $e) {
            report($e);

            $this->fallito('Autenticazione dei dipendenti temporaneamente non disponibile. Riprova più tardi.');
        }

        return $user;
    }

    private function ensureUserIsActive(?User $user): void
    {
        if ($user?->attivo ?? true) {
            return;
        }

        if (Auth::check()) {
            Auth::logout();
        }

        throw ValidationException::withMessages([
            'username' => 'Questo account è stato disattivato.',
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $limiti = config('auth.login');

        $chiave = match (true) {
            RateLimiter::tooManyAttempts($this->throttleKey(), $limiti['max_tentativi_account']) => $this->throttleKey(),
            RateLimiter::tooManyAttempts($this->throttleKeyIp(), $limiti['max_tentativi_ip']) => $this->throttleKeyIp(),
            default => null,
        };

        if ($chiave === null) {
            return;
        }

        event(new Lockout($this));
        Log::warning('Login bloccato per troppi tentativi falliti', [
            'chiave' => $chiave === $this->throttleKeyIp() ? 'ip' : 'account',
            'username' => $this->identitaLogin()[1],
            'ip' => $this->ip(),
        ]);

        $seconds = RateLimiter::availableIn($chiave);

        throw ValidationException::withMessages([
            'username' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Chiave sull'identità reale, non sulla stringa digitata: "a\m.rossi",
     * "b\m.rossi" e "m.rossi@ente.local" sono la stessa persona per AD e
     * devono condividere lo stesso budget di tentativi. Senza IP: un attacco
     * distribuito su tanti IP non deve arrivare a bloccare l'account su AD.
     */
    public function throttleKey(): string
    {
        [$canale, $identita] = $this->identitaLogin();

        return 'login-account|'.$canale.':'.mb_strtolower($identita);
    }

    /** Limite trasversale agli username, contro tentativi su tanti account dallo stesso IP. */
    private function throttleKeyIp(): string
    {
        return 'login-ip|'.$this->ip();
    }

    /**
     * @return array{0: 'email'|'ad', 1: string}
     */
    private function identitaLogin(): array
    {
        $login = trim((string) $this->input('username'));
        $template = (string) config('ldap.user_dn_template', '%s');

        return str_contains($login, '@') && ! NormalizzaUsernameAd::èUpn($login, $template)
            ? ['email', mb_strtolower($login)]
            : ['ad', NormalizzaUsernameAd::normalizza($login, $template)];
    }

    /**
     * Ogni tentativo fallito conta, qualunque sia il motivo.
     *
     * @throws ValidationException
     */
    private function fallito(string $messaggio): never
    {
        $limiti = config('auth.login');
        RateLimiter::hit($this->throttleKey(), $limiti['blocco_account_minuti'] * 60);
        RateLimiter::hit($this->throttleKeyIp(), $limiti['blocco_ip_minuti'] * 60);

        throw ValidationException::withMessages(['username' => $messaggio]);
    }
}
