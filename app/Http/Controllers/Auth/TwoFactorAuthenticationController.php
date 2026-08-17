<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;

class TwoFactorAuthenticationController extends Controller
{
    public function store(Request $request, EnableTwoFactorAuthentication $enable): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $enable($user);

        return back()->with('status', 'two-factor-authentication-enabled');
    }

    public function confirm(Request $request, ConfirmTwoFactorAuthentication $confirm): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        /** @var User $user */
        $user = $request->user();

        $confirm($user, $request->string('code'));

        return back()->with('status', 'two-factor-authentication-confirmed');
    }

    public function destroy(Request $request, DisableTwoFactorAuthentication $disable): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $disable($user);

        return back()->with('status', 'two-factor-authentication-disabled');
    }
}
