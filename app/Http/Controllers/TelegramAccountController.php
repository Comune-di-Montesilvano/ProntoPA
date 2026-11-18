<?php

namespace App\Http\Controllers;

use App\Models\Impostazione;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TelegramAccountController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->usaTelegram(), 403);

        $token = Str::upper(Str::random(24));
        $request->user()->forceFill([
            'telegram_link_token' => $token,
            'telegram_link_expires_at' => now()->addHour(),
        ])->save();

        // "Collega Telegram" (1.0.9): apre subito il bot con il codice, basta premere Avvia.
        $bot = ltrim((string) Impostazione::get('telegram_bot_username'), '@');
        if ($request->boolean('apri') && $bot !== '') {
            return redirect()->away('https://t.me/'.$bot.'?start='.$token);
        }

        return back()->with('status', 'telegram-link-generated');
    }

    /** "Ignora" sull'invito in cima agli elenchi: non riproporlo per 30 giorni. */
    public function ignora(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['telegram_invito_nascosto_fino' => now()->addDays(30)])->save();

        return back();
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