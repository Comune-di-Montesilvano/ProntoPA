<?php

namespace App\Http\Controllers;

use App\Models\Impostazione;
use App\Models\User;
use App\Notifications\BenvenutoAdminNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SetupController extends Controller
{
    public function show(): View|RedirectResponse
    {
        if (Impostazione::get('setup_completato') === '1') {
            return redirect('/');
        }

        return view('setup.index');
    }

    public function store(Request $request): RedirectResponse
    {
        if (Impostazione::get('setup_completato') === '1') {
            return redirect('/');
        }

        $data = $request->validate([
            'ente_nome'          => ['required', 'string', 'max:100'],
            'ente_sito_url'      => ['nullable', 'url', 'max:200'],
            'ente_colore_primario' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'ente_logo_url'      => ['nullable', 'url', 'max:500'],
            'smtp_host'          => ['nullable', 'string', 'max:200'],
            'smtp_port'          => ['nullable', 'integer', 'min:1', 'max:65535'],
            'smtp_username'      => ['nullable', 'string', 'max:200'],
            'smtp_password'      => ['nullable', 'string', 'max:500'],
            'smtp_encryption'    => ['nullable', 'in:tls,ssl,'],
            'mail_from_address'  => ['required', 'email', 'max:200'],
            'mail_from_name'     => ['required', 'string', 'max:100'],
            'admin_name'         => ['required', 'string', 'max:100'],
            'admin_username'     => ['required', 'string', 'max:50', 'alpha_dash', 'unique:users,username'],
            'admin_email'        => ['required', 'email', 'max:200', 'unique:users,email'],
        ]);

        // Impostazioni brand
        Impostazione::set('ente_nome', $data['ente_nome']);
        Impostazione::set('ente_sito_url', $data['ente_sito_url'] ?? null);
        Impostazione::set('ente_colore_primario', $data['ente_colore_primario'] ?? '#1D4ED8');
        Impostazione::set('ente_logo_url', $data['ente_logo_url'] ?? null);

        // Impostazioni email
        Impostazione::set('mail_from_address', $data['mail_from_address']);
        Impostazione::set('mail_from_name', $data['mail_from_name']);

        if (!empty($data['smtp_host'])) {
            Impostazione::set('smtp_host', $data['smtp_host']);
            Impostazione::set('smtp_port', $data['smtp_port'] ?? 587);
            Impostazione::set('smtp_username', $data['smtp_username'] ?? null);
            if (!empty($data['smtp_password'])) {
                Impostazione::set('smtp_password', $data['smtp_password']);
            }
            Impostazione::set('smtp_encryption', $data['smtp_encryption'] ?? 'tls');
        }

        // Crea utente admin con password casuale
        $user = User::create([
            'name'             => $data['admin_name'],
            'username'         => $data['admin_username'],
            'email'            => $data['admin_email'],
            'password'         => Hash::make(Str::random(32)),
            'attivo'           => true,
            'amministratore'   => true,
            'email_verified_at'=> now(),
        ]);
        $user->syncRoles(['admin']);

        // Token reset password valido 30 giorni
        $rawToken  = Str::random(64);
        $expireMin = config('auth.passwords.users.expire', 60);
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token'      => Hash::make($rawToken),
                'created_at' => now()->addDays(30)->subMinutes($expireMin),
            ]
        );

        $resetUrl = route('password.reset', $rawToken) . '?' . http_build_query(['email' => $user->email]);

        $user->notify(new BenvenutoAdminNotification($resetUrl, $data['ente_nome']));

        Impostazione::set('setup_completato', '1');

        return redirect()->route('setup.done');
    }

    public function done(): View|RedirectResponse
    {
        if (Impostazione::get('setup_completato') !== '1') {
            return redirect()->route('setup.show');
        }

        return view('setup.done');
    }
}
