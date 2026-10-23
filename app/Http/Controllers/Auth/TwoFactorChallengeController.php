<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;

class TwoFactorChallengeController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('login.id')) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    public function store(TwoFactorLoginRequest $request): RedirectResponse
    {
        if (! $request->hasChallengedUser()) {
            return redirect()->route('login');
        }

        /** @var User $user */
        $user = $request->challengedUser();

        if (! $request->hasValidCode()) {
            $validCode = $request->validRecoveryCode();

            if (! $validCode) {
                throw ValidationException::withMessages(['code' => 'Codice non valido.']);
            }

            $user->replaceRecoveryCode($validCode);
        }

        Auth::login($user, $request->remember());

        $request->session()->forget(['login.id', 'login.remember', 'login.metodo']);
        $request->session()->regenerate();
        $user->update(['last_login' => now()]);

        return redirect()->intended(route('dashboard'));
    }
}
