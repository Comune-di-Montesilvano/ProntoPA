<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\Auth\AccessoLdapNegato;
use App\Services\Auth\CodiceAccessoEmail;
use App\Services\Auth\LdapLoginService;
use App\Services\Auth\NormalizzaUsernameAd;
use App\Services\Directory\DirectoryNonDisponibile;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    private const MAX_FALLIMENTI_PER_IP = 20;

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

        $user = $canale === 'email'
            ? $this->tentaLocale('email', $identita, $password)
            : $this->tentaDipendente($identita, $password);

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

            if ($metodo === 'email') {
                app(CodiceAccessoEmail::class)->invia($user);
            }

            return;
        }

        Auth::login($user, $this->boolean('remember'));
    }

    /**
     * @throws ValidationException
     */
    private function tentaDipendente(string $username, string $password): ?User
    {
        try {
            $user = app(LdapLoginService::class)->login($username, $password);
        } catch (AccessoLdapNegato $e) {
            $this->fallito($e->getMessage());
        } catch (DirectoryNonDisponibile $e) {
            report($e);

            return $this->tentaLocale('username', $username, $password)
                ?? $this->fallito('Autenticazione dei dipendenti temporaneamente non disponibile. Riprova più tardi.');
        }

        // TRANSITORIO fino al cutover (fase 3): account locali legacy che
        // entrano ancora con username. Da rimuovere in utenze:cutover.
        return $user ?? $this->tentaLocale('username', $username, $password);
    }

    /**
     * Solo account locali: un utente ldap/spid non entra mai con password,
     * anche se ha la stessa email di una ditta.
     */
    private function tentaLocale(string $campo, string $valore, string $password): ?User
    {
        $user = User::where('auth_source', 'locale')
            ->when(
                $campo === 'email',
                fn ($q) => $q->whereRaw('LOWER(email) = ?', [$valore]),
                fn ($q) => $q->where('username', $valore),
            )
            ->orderByDesc('attivo')
            ->first();

        if ($user === null || $user->password === null || ! Hash::check($password, $user->password)) {
            return null;
        }

        return $user;
    }

    private function ensureUserIsActive(?User $user): void
    {
        $approvalStatus = $user->approval_status ?? 'approved';

        if ($approvalStatus !== 'approved') {
            if (Auth::check()) {
                Auth::logout();
            }

            throw ValidationException::withMessages([
                'username' => $approvalStatus === 'rejected'
                    ? 'La richiesta di registrazione non è stata approvata. Contatta il supporto.'
                    : 'La tua registrazione è in attesa di approvazione da parte dell\'operatore.',
            ]);
        }

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
        $chiave = match (true) {
            RateLimiter::tooManyAttempts($this->throttleKey(), 5) => $this->throttleKey(),
            RateLimiter::tooManyAttempts($this->throttleKeyIp(), self::MAX_FALLIMENTI_PER_IP) => $this->throttleKeyIp(),
            default => null,
        };

        if ($chiave === null) {
            return;
        }

        event(new Lockout($this));

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
     * devono condividere lo stesso budget di tentativi.
     */
    public function throttleKey(): string
    {
        [$canale, $identita] = $this->identitaLogin();

        return $canale.':'.mb_strtolower($identita).'|'.$this->ip();
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
        RateLimiter::hit($this->throttleKey());
        RateLimiter::hit($this->throttleKeyIp());

        throw ValidationException::withMessages(['username' => $messaggio]);
    }
}
