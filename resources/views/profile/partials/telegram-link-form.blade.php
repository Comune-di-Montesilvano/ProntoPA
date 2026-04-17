@php
    $botUsername = \App\Models\Impostazione::get('telegram_bot_username');
    $telegramStartCommand = $user->telegram_link_token
        ? '/start ' . $user->telegram_link_token
        : null;
@endphp

<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">Telegram</h2>
        <p class="mt-1 text-sm text-gray-600">
            Collega il tuo account al bot per ricevere notifiche push e usare i comandi rapidi.
        </p>
    </header>

    <div class="mt-6 space-y-4">
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">
            @if($user->telegram_chat_id)
                <p class="font-medium text-green-700">Account collegato</p>
                <p class="mt-1">Chat ID: {{ $user->telegram_chat_id }}</p>
                <p>Verificato il {{ optional($user->telegram_verified_at)->format('d/m/Y H:i') ?? 'N/D' }}</p>
            @else
                <p class="font-medium text-gray-900">Account non collegato</p>
                <p class="mt-1">Genera un token e invialo al bot con il comando <strong>/start &lt;token&gt;</strong>.</p>
            @endif
        </div>

        @if($telegramStartCommand)
            <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
                <p class="font-medium">Token attivo fino a {{ optional($user->telegram_link_expires_at)->format('d/m/Y H:i') }}</p>
                @if($botUsername)
                    <p class="mt-1">Apri Telegram e cerca <strong>@{{ ltrim($botUsername, '@') }}</strong>.</p>
                @endif
                <p class="mt-2 font-mono text-xs break-all">{{ $telegramStartCommand }}</p>
            </div>
        @endif

        <div class="flex flex-wrap items-center gap-3">
            <form method="POST" action="{{ route('profile.telegram.store') }}">
                @csrf
                <x-primary-button>Genera token Telegram</x-primary-button>
            </form>

            @if($user->telegram_chat_id || $user->telegram_link_token)
                <form method="POST" action="{{ route('profile.telegram.destroy') }}">
                    @csrf
                    @method('DELETE')
                    <x-danger-button>Scollega Telegram</x-danger-button>
                </form>
            @endif
        </div>

        @if (session('status') === 'telegram-link-generated')
            <p class="text-sm text-gray-600">Token Telegram generato.</p>
        @endif

        @if (session('status') === 'telegram-unlinked')
            <p class="text-sm text-gray-600">Collegamento Telegram rimosso.</p>
        @endif
    </div>
</section>