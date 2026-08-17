@php
    /** @var \App\Models\User $user */
    $user = auth()->user();
    $pending = $user->two_factor_secret && ! $user->two_factor_confirmed_at;
@endphp

<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Autenticazione a due fattori') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Aggiungi un livello di sicurezza in più al tuo account: oltre alla password, ti verrà chiesto un codice generato da un\'app di autenticazione (es. Google Authenticator, Aegis).') }}
        </p>
    </header>

    <div class="mt-6 space-y-6">
        @if (session('status') === 'two-factor-authentication-enabled')
            <div class="text-sm text-emerald-700 bg-emerald-50 rounded p-3">
                Inquadra il codice QR con la tua app di autenticazione, poi inserisci il codice generato per confermare.
            </div>
        @endif

        @if (session('status') === 'two-factor-authentication-confirmed')
            <div class="text-sm text-emerald-700 bg-emerald-50 rounded p-3">
                2FA attivo. Salva i codici di recupero prima di uscire da questa pagina.
            </div>
        @endif

        @if ($user->two_factor_confirmed_at)
            <div class="text-sm text-emerald-700 font-medium">✓ Autenticazione a due fattori attiva.</div>

            <div class="flex items-center gap-4">
                <a href="{{ route('two-factor.recovery-codes') }}" class="text-sm text-gray-600 underline">
                    Mostra codici di recupero
                </a>

                <form method="POST" action="{{ route('two-factor.recovery-codes.regenerate') }}">
                    @csrf
                    <button type="submit" class="text-sm text-gray-600 underline">
                        Rigenera codici di recupero
                    </button>
                </form>
            </div>

            <form method="POST" action="{{ route('two-factor.disable') }}">
                @csrf
                @method('DELETE')
                <x-danger-button>{{ __('Disattiva 2FA') }}</x-danger-button>
            </form>
        @elseif ($pending)
            <div class="bg-gray-50 rounded p-4">
                {!! $user->twoFactorQrCodeSvg() !!}
            </div>

            <form method="POST" action="{{ route('two-factor.confirm') }}" class="space-y-4 max-w-xs">
                @csrf
                <div>
                    <x-input-label for="two_factor_code" :value="__('Codice di conferma')" />
                    <x-text-input id="two_factor_code" name="code" type="text" inputmode="numeric"
                                  autocomplete="one-time-code" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('code')" class="mt-2" />
                </div>
                <x-primary-button>{{ __('Conferma') }}</x-primary-button>
            </form>

            <form method="POST" action="{{ route('two-factor.disable') }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="text-sm text-gray-500 underline">Annulla</button>
            </form>
        @else
            <form method="POST" action="{{ route('two-factor.enable') }}">
                @csrf
                <x-primary-button>{{ __('Abilita 2FA') }}</x-primary-button>
            </form>
        @endif
    </div>
</section>
