<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="Configurazione guidata del primo account amministratore di ProntoPA.">
    <title>Setup iniziale — ProntoPA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Titillium+Web:wght@300;400;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased" style="background: var(--slate-50); font-family: var(--font-ui);">

<main class="min-h-screen flex flex-col sm:justify-center items-center py-10 px-4">
    <div class="w-full max-w-md">

        {{-- Header --}}
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl mb-4 mx-auto"
                 style="background: #1D4ED8;">
                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
            </div>
            <h1 class="text-2xl font-bold" style="color: var(--ink);">Setup iniziale ProntoPA</h1>
            <p class="mt-1 text-sm" style="color: var(--slate-600);">Crea l'account amministratore per iniziare</p>
        </div>

        <div class="bg-white rounded-xl shadow-sm p-8" style="border: 1px solid var(--slate-200);">

            @if($errors->any())
                <div class="mb-6 rounded-lg p-4 text-sm" style="background: #FEF2F2; border: 1px solid #FECACA; color: #991B1B;">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('setup.richiedi-otp') }}" class="space-y-4">
                @csrf

                <div>
                    <label for="token" class="block text-sm font-medium mb-1" style="color: var(--ink);">
                        Token di avvio <span style="color: #EF4444;">*</span>
                    </label>
                    <input id="token" type="password" name="token" required autocomplete="off"
                           class="block w-full rounded-lg text-sm px-3 py-2"
                           style="border: 1px solid var(--slate-300); outline: none;"
                           placeholder="SETUP_TOKEN da .env">
                    <p class="mt-1 text-xs" style="color: var(--slate-600);">
                        Valore di <code>SETUP_TOKEN</code> configurato sul server.
                    </p>
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium mb-1" style="color: var(--ink);">
                        Email amministratore <span style="color: #EF4444;">*</span>
                    </label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}"
                           required maxlength="200"
                           class="block w-full rounded-lg text-sm px-3 py-2"
                           style="border: 1px solid var(--slate-300); outline: none;"
                           placeholder="admin@comune.example.it">
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium mb-1" style="color: var(--ink);">
                        Password <span style="color: #EF4444;">*</span>
                    </label>
                    <input id="password" type="password" name="password" required autocomplete="new-password"
                           class="block w-full rounded-lg text-sm px-3 py-2"
                           style="border: 1px solid var(--slate-300); outline: none;">
                    <p class="mt-1 text-xs" style="color: var(--slate-600);">Almeno 10 caratteri, maiuscole/minuscole e numeri.</p>
                </div>

                <div>
                    <label for="password_confirmation" class="block text-sm font-medium mb-1" style="color: var(--ink);">
                        Conferma password <span style="color: #EF4444;">*</span>
                    </label>
                    <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"
                           class="block w-full rounded-lg text-sm px-3 py-2"
                           style="border: 1px solid var(--slate-300); outline: none;">
                </div>

                <p class="text-xs" style="color: var(--slate-500);">
                    Riceverai un codice di conferma via email da inserire nel passaggio successivo.
                </p>

                <button type="submit"
                        class="w-full inline-flex items-center justify-center gap-2 px-6 py-2.5 rounded-lg font-semibold text-sm text-white transition"
                        style="background: #1D4ED8;">
                    Invia codice di conferma
                </button>
            </form>
        </div>
    </div>
</main>

</body>
</html>
