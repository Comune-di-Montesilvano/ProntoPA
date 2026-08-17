<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
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
     * Tenta l'autenticazione (bcrypt standard). Se l'utente ha il 2FA
     * attivo, la sessione viene subito chiusa e il completamento del
     * login è demandato a TwoFactorChallengeController (session
     * 'login.id' come chiave di stato, stesso pattern di Fortify).
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt(
            ['username' => $this->string('username'), 'password' => $this->string('password')],
            $this->boolean('remember')
        )) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'username' => trans('auth.failed'),
            ]);
        }

        /** @var User $user */
        $user = Auth::user();
        $this->ensureUserIsActive($user);
        RateLimiter::clear($this->throttleKey());

        if ($user->hasEnabledTwoFactorAuthentication()) {
            Auth::logout();

            $this->session()->put([
                'login.id'       => $user->getKey(),
                'login.remember' => $this->boolean('remember'),
            ]);
        }
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
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'username' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    public function throttleKey(): string
    {
        return Str::lower($this->string('username')).'|'.$this->ip();
    }
}
