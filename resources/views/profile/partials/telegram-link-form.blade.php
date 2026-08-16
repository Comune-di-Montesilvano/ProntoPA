@php
    $botUsername = \App\Models\Impostazione::get('telegram_bot_username');
    $telegramStartCommand = $user->telegram_link_token
        ? '/start ' . $user->telegram_link_token
        : null;
@endphp

<section class="space-y-6">
    <header>
        <h2 class="text-lg font-medium text-gray-900">Telegram</h2>
        <p class="mt-1 text-sm text-gray-600">
            Collega il tuo account al bot per ricevere notifiche push e usare i comandi rapidi direttamente da Telegram.
        </p>
    </header>

    <div class="space-y-4">
        {{-- Caso 1: Telegram Bot non ancora configurato nel sistema --}}
        @if(!$botUsername)
            <div class="rounded-lg border border-yellow-200 bg-yellow-50 p-4 text-sm text-yellow-800">
                <div class="flex items-start gap-2">
                    <span class="text-base">⚠️</span>
                    <div>
                        <p class="font-medium">Integrazione Telegram non configurata</p>
                        <p class="mt-1">Il bot Telegram non è ancora stato configurato dall'amministratore del sistema.</p>
                        @if($user->isAdmin())
                            <p class="mt-2 text-xs text-yellow-700">
                                Come amministratore, puoi configurarlo andando in <strong>Admin ➔ Impostazioni ➔ Telegram</strong> e impostando le chiavi <code>telegram_bot_token</code> e <code>telegram_bot_username</code>.
                            </p>
                        @else
                            <p class="mt-2 text-xs text-yellow-700">Contatta l'amministratore del sistema per abilitare il servizio.</p>
                        @endif
                    </div>
                </div>
            </div>
        @else
            {{-- Caso 2: Visualizzazione stato di collegamento --}}
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">
                @if($user->telegram_chat_id)
                    <div class="flex items-start gap-2 text-green-700">
                        <svg class="h-5 w-5 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <div>
                            <p class="font-semibold text-green-800">Account collegato con successo</p>
                            <p class="mt-1 text-xs text-gray-600">ID Chat Telegram: <code class="bg-gray-200 px-1 py-0.5 rounded text-gray-800 font-mono">{{ $user->telegram_chat_id }}</code></p>
                            <p class="text-xs text-gray-500">Verificato il {{ optional($user->telegram_verified_at)->format('d/m/Y H:i') ?? 'N/D' }}</p>
                        </div>
                    </div>
                @else
                    <div class="flex items-start gap-2 text-gray-700">
                        <svg class="h-5 w-5 mt-0.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                        </svg>
                        <div>
                            <p class="font-medium text-gray-900">Account non ancora collegato</p>
                            <p class="mt-2 text-xs text-gray-600 leading-relaxed">
                                Collega il tuo account in pochi secondi per iniziare a utilizzare i comandi operativi.
                            </p>
                            <div class="mt-3 space-y-1 text-xs text-gray-500 list-decimal list-inside">
                                <p>1. Clicca su <strong>"Genera token Telegram"</strong> in basso.</p>
                                <p>2. Cerca il bot su Telegram: <a href="https://t.me/{{ ltrim($botUsername, '@') }}" target="_blank" class="text-blue-600 hover:underline font-semibold inline-flex items-center gap-0.5">@{{ ltrim($botUsername, '@') }} <svg class="h-3 w-3 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg></a></p>
                                <p>3. Invia il codice che verrà generato per attivare il collegamento.</p>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        @endif

        {{-- Caso 3: Token attivo generato e pronto per essere inserito --}}
        @if($telegramStartCommand && $botUsername)
            <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
                <p class="font-medium flex items-center gap-1.5">
                    <svg class="h-4 w-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    Token generato con successo!
                </p>
                
                <div class="mt-3 space-y-3">
                    <div>
                        <p class="font-semibold text-xs text-blue-800 uppercase tracking-wider">Metodo Rapido (Consigliato)</p>
                        <p class="mt-1 text-xs leading-relaxed">
                            Clicca sul pulsante qui sotto: aprirà la chat di Telegram inserendo e inviando automaticamente il tuo token di attivazione.
                        </p>
                        <a href="https://t.me/{{ ltrim($botUsername, '@') }}?start={{ $user->telegram_link_token }}" target="_blank" 
                           class="inline-flex items-center gap-1.5 bg-blue-600 text-white text-xs font-semibold px-3 py-1.5 rounded-md hover:bg-blue-700 transition mt-2 shadow-sm">
                            Apri ed avvia @{{ ltrim($botUsername, '@') }}
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                            </svg>
                        </a>
                    </div>

                    <hr class="border-blue-100" />

                    <div>
                        <p class="font-semibold text-xs text-blue-800 uppercase tracking-wider">Metodo Manuale</p>
                        <p class="mt-1 text-xs leading-relaxed">
                            Se preferisci, apri Telegram, cerca <strong>@{{ ltrim($botUsername, '@') }}</strong> e invia manualmente questo testo in chat entro il <strong>{{ optional($user->telegram_link_expires_at)->format('d/m/Y H:i') }}</strong>:
                        </p>
                        <div class="mt-1.5 bg-white border border-blue-200 rounded-md px-3 py-2 shadow-inner">
                            <a href="https://t.me/{{ ltrim($botUsername, '@') }}?start={{ $user->telegram_link_token }}" target="_blank" 
                               class="font-mono text-xs text-blue-800 hover:underline hover:text-blue-600 block break-all select-all">
                                {{ $telegramStartCommand }}
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- Pulsanti d'azione --}}
        @if($botUsername)
            <div class="flex flex-wrap items-center gap-3 pt-2">
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
        @endif

        @if (session('status') === 'telegram-link-generated')
            <p class="text-sm text-green-600 flex items-center gap-1 font-medium">
                <span class="text-base">✓</span> Token Telegram generato.
            </p>
        @endif

        @if (session('status') === 'telegram-unlinked')
            <p class="text-sm text-gray-600 flex items-center gap-1 font-medium">
                <span class="text-base">✓</span> Collegamento Telegram rimosso con successo.
            </p>
        @endif
    </div>
</section>