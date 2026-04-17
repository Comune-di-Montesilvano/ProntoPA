<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TelegramAccountController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $request->user()->forceFill([
            'telegram_link_token' => Str::upper(Str::random(24)),
            'telegram_link_expires_at' => now()->addHour(),
        ])->save();

        return back()->with('status', 'telegram-link-generated');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->user()->forceFill([
            'telegram_chat_id' => null,
            'telegram_verified_at' => null,
            'telegram_link_token' => null,
            'telegram_link_expires_at' => null,
        ])->save();

        return back()->with('status', 'telegram-unlinked');
    }
}