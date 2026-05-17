<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Impostazione;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class AnnualAccountVerificationController extends Controller
{
    public function verify(User $user, string $token): RedirectResponse
    {
        if (($user->approval_status ?? 'approved') !== 'approved') {
            return redirect()->route('login')->withErrors([
                'username' => 'Account non approvato. Contatta un operatore.',
            ]);
        }

        if (! $user->annual_verification_token_hash || ! hash_equals($user->annual_verification_token_hash, hash('sha256', $token))) {
            return redirect()->route('login')->withErrors([
                'username' => 'Link di verifica non valido.',
            ]);
        }

        if (! $user->annual_verification_due_at || $user->annual_verification_due_at->isPast()) {
            return redirect()->route('login')->withErrors([
                'username' => 'Link di verifica scaduto. Contatta un operatore.',
            ]);
        }

        $annualDays = (int) Impostazione::get('account_verification_annual_days', 365);

        $user->update([
            'attivo' => true,
            'email_confermata_annualmente_at' => now(),
            'prossima_verifica_annuale_at' => now()->addDays(max($annualDays, 1)),
            'annual_verification_token_hash' => null,
            'annual_verification_sent_at' => null,
            'annual_verification_due_at' => null,
            'annual_verification_reminder_at' => null,
        ]);

        return redirect()->route('login')->with('status', 'Verifica annuale completata con successo.');
    }
}
