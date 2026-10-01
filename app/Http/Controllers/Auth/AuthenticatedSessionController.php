<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\Oidc\OidcClient;
use App\Services\Oidc\OidcNonDisponibile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        if ($request->session()->has('login.id')) {
            return redirect()->route('two-factor.login');
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = $request->user();
        $user->update(['last_login' => now()]);

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $spid = $request->user()?->isSpid() ?? false;
        $idToken = $request->session()->get('spid.id_token');

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($spid) {
            try {
                $endpoint = app(OidcClient::class)->endSessionEndpoint();
            } catch (OidcNonDisponibile) {
                $endpoint = null;
            }

            if ($endpoint !== null) {
                return redirect()->away($endpoint.'?'.http_build_query(array_filter([
                    'id_token_hint' => is_string($idToken) ? $idToken : null,
                    'post_logout_redirect_uri' => rtrim((string) config('app.url'), '/').'/',
                ]), '', '&', PHP_QUERY_RFC3986));
            }
        }

        return redirect('/');
    }
}
