<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="Conferma il codice ricevuto via email per completare il setup di ProntoPA.">
    <title>Conferma codice — ProntoPA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Titillium+Web:wght@300;400;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased" style="background: var(--slate-50); font-family: var(--font-ui);">

<main class="min-h-screen flex flex-col sm:justify-center items-center py-10 px-4">
    <div class="w-full max-w-md">

        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-full mb-4 mx-auto"
                 style="background: #DBEAFE;">
                <svg class="w-7 h-7" style="color: #1D4ED8;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
            </div>
            <h1 class="text-2xl font-bold" style="color: var(--ink);">Controlla la tua email</h1>
            <p class="mt-1 text-sm" style="color: var(--slate-600);">
                Codice inviato a <strong>{{ $email }}</strong>
            </p>
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

            <form method="POST" action="{{ route('setup.conferma') }}" class="space-y-4">
                @csrf

                <div>
                    <label for="otp" class="block text-sm font-medium mb-1" style="color: var(--ink);">
                        Codice di conferma <span style="color: #EF4444;">*</span>
                    </label>
                    <input id="otp" type="text" name="otp" required autocomplete="one-time-code" inputmode="numeric"
                           maxlength="6" pattern="[0-9]{6}"
                           class="block w-full rounded-lg text-center text-2xl tracking-[0.5em] font-mono px-3 py-3"
                           style="border: 1px solid var(--slate-300); outline: none;"
                           placeholder="000000" autofocus>
                    <p class="mt-1 text-xs" style="color: var(--slate-500);">Valido per 10 minuti.</p>
                </div>

                <button type="submit"
                        class="w-full inline-flex items-center justify-center gap-2 px-6 py-2.5 rounded-lg font-semibold text-sm text-white transition"
                        style="background: #1D4ED8;">
                    Conferma e crea account
                </button>
            </form>

            <a href="{{ route('setup.show') }}"
               class="mt-4 block text-center text-sm font-medium"
               style="color: var(--slate-500);">
                ← Ricomincia
            </a>
        </div>
    </div>
</main>

</body>
</html>
