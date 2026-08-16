<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\SetupOtpNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class SetupController extends Controller
{
    private const OTP_TTL_MINUTI = 10;

    public function show(): View|RedirectResponse
    {
        if (User::query()->exists()) {
            return redirect()->route('login');
        }

        return view('setup.index');
    }

    /**
     * Step 1: verifica il token di avvio e i dati del nuovo admin,
     * genera un OTP e lo invia via email.
     */
    public function richiediOtp(Request $request): RedirectResponse
    {
        if (User::query()->exists()) {
            return redirect()->route('login');
        }

        $data = $request->validate([
            'token'    => ['required', 'string'],
            'email'    => ['required', 'email', 'max:200'],
            'password' => ['required', 'confirmed', Password::min(10)->mixedCase()->numbers()],
        ]);

        $tokenAtteso = (string) config('app.setup_token');

        if ($tokenAtteso === '' || ! hash_equals($tokenAtteso, $data['token'])) {
            return back()->withErrors(['token' => 'Token di avvio non valido.'])->withInput(
                $request->except('token', 'password', 'password_confirmation')
            );
        }

        $otp = (string) random_int(100000, 999999);

        $request->session()->put('setup_otp', [
            'otp'             => $otp,
            'email'           => $data['email'],
            'password_hash'   => Hash::make($data['password']),
            'scade_alle'      => now()->addMinutes(self::OTP_TTL_MINUTI)->timestamp,
        ]);

        (new AnonymousNotifiable)
            ->route('mail', $data['email'])
            ->notify(new SetupOtpNotification($otp));

        return redirect()->route('setup.verify');
    }

    public function verify(): View|RedirectResponse
    {
        if (User::query()->exists()) {
            return redirect()->route('login');
        }

        if (! session()->has('setup_otp')) {
            return redirect()->route('setup.show');
        }

        return view('setup.verify', [
            'email' => session('setup_otp.email'),
        ]);
    }

    /**
     * Step 2: verifica l'OTP e crea l'account amministratore.
     */
    public function conferma(Request $request): RedirectResponse
    {
        if (User::query()->exists()) {
            return redirect()->route('login');
        }

        $pending = $request->session()->get('setup_otp');

        if (! $pending) {
            return redirect()->route('setup.show');
        }

        $request->validate([
            'otp' => ['required', 'string'],
        ]);

        if (now()->timestamp > $pending['scade_alle']) {
            $request->session()->forget('setup_otp');

            return redirect()->route('setup.show')
                ->withErrors(['otp' => 'Codice scaduto, ricomincia il setup.']);
        }

        if (! hash_equals($pending['otp'], (string) $request->input('otp'))) {
            return back()->withErrors(['otp' => 'Codice non corretto.']);
        }

        $username = Str::before($pending['email'], '@');
        $username = User::where('username', $username)->exists()
            ? $username . '-' . Str::random(4)
            : $username;

        $user = User::create([
            'name'              => 'Amministratore',
            'username'          => $username,
            'email'             => $pending['email'],
            'password'          => $pending['password_hash'],
            'attivo'            => true,
            'amministratore'    => true,
            'email_verified_at' => now(),
        ]);
        $user->syncRoles(['admin']);

        $request->session()->forget('setup_otp');

        return redirect()->route('login')
            ->with('status', 'Account amministratore creato. Accedi con le credenziali appena impostate.');
    }
}
