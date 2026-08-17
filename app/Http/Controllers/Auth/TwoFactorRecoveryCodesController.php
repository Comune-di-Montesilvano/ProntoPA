<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;

class TwoFactorRecoveryCodesController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('auth.two-factor-recovery-codes', ['codes' => $user->recoveryCodes()]);
    }

    public function store(Request $request, GenerateNewRecoveryCodes $generate): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $generate($user);

        return back()->with('status', 'recovery-codes-generated');
    }
}
