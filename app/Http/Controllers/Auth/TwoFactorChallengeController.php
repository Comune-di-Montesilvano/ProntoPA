<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\CodiceAccessoEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
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

        $metodo = $request->session()->get('login.metodo', 'totp');
        $emailMascherata = null;

        if ($metodo === 'email') {
            $email = (string) User::find($request->session()->get('login.id'))?->email;
            [$locale, $dominio] = array_pad(explode('@', $email, 2), 2, '');
            $emailMascherata = Str::substr($locale, 0, 1).'***@'.$dominio;
        }

        return view('auth.two-factor-challenge', compact('metodo', 'emailMascherata'));
    }

    public function store(TwoFactorLoginRequest $request, CodiceAccessoEmail $codici): RedirectResponse
    {
        if (! $request->hasChallengedUser()) {
            return redirect()->route('login');
        }

        /** @var User $user */
        $user = $request->challengedUser();

        if ($request->session()->get('login.metodo') === 'email') {
            if (! $codici->verifica($user, (string) $request->input('code', ''))) {
                throw ValidationException::withMessages(['code' => 'Codice non valido o scaduto.']);
            }
        } elseif (! $request->hasValidCode()) {
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

    public function reinvia(Request $request, CodiceAccessoEmail $codici): RedirectResponse
    {
        $user = User::find($request->session()->get('login.id'));

        if ($user === null || $request->session()->get('login.metodo') !== 'email') {
            return redirect()->route('login');
        }

        $codici->invia($user);

        return redirect()->route('two-factor.login')->with('status', 'Ti abbiamo inviato un nuovo codice.');
    }
}
