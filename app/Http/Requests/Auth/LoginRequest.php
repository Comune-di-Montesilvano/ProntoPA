<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
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
     * Tenta l'autenticazione.
     * Prima prova bcrypt standard; se fallisce e l'utente ha una password legacy
     * SHA256 (sistema precedente), verifica e migra automaticamente a bcrypt.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        // Tentativo standard (bcrypt)
        if (Auth::attempt(
            ['username' => $this->string('username'), 'password' => $this->string('password')],
            $this->boolean('remember')
        )) {
            RateLimiter::clear($this->throttleKey());
            return;
        }

        // Fallback: migrazione da SHA256 legacy
        $user = User::where('username', $this->string('username'))->first();

        if ($user?->password_legacy &&
            hash_equals($user->password_legacy, hash('sha256', $this->string('password')))
        ) {
            $user->forceFill([
                'password'        => Hash::make($this->string('password')),
                'password_legacy' => null,
            ])->save();

            Auth::login($user, $this->boolean('remember'));
            RateLimiter::clear($this->throttleKey());
            return;
        }

        RateLimiter::hit($this->throttleKey());

        throw ValidationException::withMessages([
            'username' => trans('auth.failed'),
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
