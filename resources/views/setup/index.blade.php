<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Setup iniziale — ProntoPA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Titillium+Web:wght@300;400;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased" style="background: var(--slate-50); font-family: var(--font-ui);">

<div class="min-h-screen py-10 px-4">
    <div class="max-w-2xl mx-auto">

        {{-- Header --}}
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl mb-4"
                 style="background: #1D4ED8;">
                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
            </div>
            <h1 class="text-2xl font-bold" style="color: var(--ink);">Setup iniziale ProntoPA</h1>
            <p class="mt-1 text-sm" style="color: var(--slate-500);">Configura l'istanza prima di iniziare a usarla</p>
        </div>

        @if($errors->any())
            <div class="mb-6 rounded-lg p-4 text-sm" style="background: #FEF2F2; border: 1px solid #FECACA; color: #991B1B;">
                <ul class="list-disc list-inside space-y-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('setup.store') }}" class="space-y-6">
            @csrf

            {{-- Sezione 1: Ente --}}
            <div class="bg-white rounded-xl shadow-sm overflow-hidden" style="border: 1px solid var(--slate-200);">
                <div class="px-6 py-4" style="background: var(--slate-50); border-bottom: 1px solid var(--slate-200);">
                    <h2 class="font-semibold text-sm uppercase tracking-wider" style="color: var(--slate-500);">
                        1 · Dati ente
                    </h2>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color: var(--ink);">
                            Nome ente <span style="color: #EF4444;">*</span>
                        </label>
                        <input type="text" name="ente_nome" value="{{ old('ente_nome', 'ProntoPA') }}"
                               required maxlength="100"
                               class="block w-full rounded-lg text-sm px-3 py-2"
                               style="border: 1px solid var(--slate-300); outline: none;"
                               placeholder="es. Comune di Montesilvano">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color: var(--ink);">
                            Sito web ente
                        </label>
                        <input type="url" name="ente_sito_url" value="{{ old('ente_sito_url') }}"
                               maxlength="200"
                               class="block w-full rounded-lg text-sm px-3 py-2"
                               style="border: 1px solid var(--slate-300); outline: none;"
                               placeholder="https://www.comune.example.it">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1" style="color: var(--ink);">
                                Colore primario
                            </label>
                            <div class="flex items-center gap-2" x-data="{ hex: '{{ old('ente_colore_primario', '#1D4ED8') }}' }">
                                <input type="color" name="ente_colore_primario" x-model="hex"
                                       class="h-9 w-14 rounded cursor-pointer p-0.5"
                                       style="border: 1px solid var(--slate-300);">
                                <span class="text-xs font-mono" style="color: var(--slate-400);" x-text="hex"></span>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1" style="color: var(--ink);">
                                URL logo (opzionale)
                            </label>
                            <input type="url" name="ente_logo_url" value="{{ old('ente_logo_url') }}"
                                   maxlength="500"
                                   class="block w-full rounded-lg text-sm px-3 py-2"
                                   style="border: 1px solid var(--slate-300); outline: none;"
                                   placeholder="https://…/logo.png">
                        </div>
                    </div>
                </div>
            </div>

            {{-- Sezione 2: Email --}}
            <div class="bg-white rounded-xl shadow-sm overflow-hidden" style="border: 1px solid var(--slate-200);">
                <div class="px-6 py-4" style="background: var(--slate-50); border-bottom: 1px solid var(--slate-200);">
                    <h2 class="font-semibold text-sm uppercase tracking-wider" style="color: var(--slate-500);">
                        2 · Server email (SMTP)
                    </h2>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <p class="text-xs" style="color: var(--slate-400);">
                        Lascia vuoto per usare i valori da <code>.env</code> (utile in sviluppo con Mailpit).
                        Se configurato, sovrascrive MAIL_HOST/PORT/USERNAME/PASSWORD.
                    </p>
                    <div class="grid grid-cols-3 gap-4">
                        <div class="col-span-2">
                            <label class="block text-sm font-medium mb-1" style="color: var(--ink);">Host SMTP</label>
                            <input type="text" name="smtp_host" value="{{ old('smtp_host') }}"
                                   maxlength="200"
                                   class="block w-full rounded-lg text-sm px-3 py-2"
                                   style="border: 1px solid var(--slate-300); outline: none;"
                                   placeholder="smtp.gmail.com">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1" style="color: var(--ink);">Porta</label>
                            <input type="number" name="smtp_port" value="{{ old('smtp_port', 587) }}"
                                   min="1" max="65535"
                                   class="block w-full rounded-lg text-sm px-3 py-2"
                                   style="border: 1px solid var(--slate-300); outline: none;">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1" style="color: var(--ink);">Username SMTP</label>
                            <input type="text" name="smtp_username" value="{{ old('smtp_username') }}"
                                   autocomplete="off"
                                   class="block w-full rounded-lg text-sm px-3 py-2"
                                   style="border: 1px solid var(--slate-300); outline: none;"
                                   placeholder="user@example.com">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1" style="color: var(--ink);">Password SMTP</label>
                            <input type="password" name="smtp_password" autocomplete="new-password"
                                   class="block w-full rounded-lg text-sm px-3 py-2"
                                   style="border: 1px solid var(--slate-300); outline: none;">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color: var(--ink);">Cifratura</label>
                        <select name="smtp_encryption"
                                class="block w-full rounded-lg text-sm px-3 py-2"
                                style="border: 1px solid var(--slate-300); outline: none;">
                            <option value="tls" {{ old('smtp_encryption', 'tls') === 'tls' ? 'selected' : '' }}>TLS (STARTTLS — porta 587)</option>
                            <option value="ssl" {{ old('smtp_encryption') === 'ssl' ? 'selected' : '' }}>SSL (porta 465)</option>
                            <option value=""   {{ old('smtp_encryption') === '' ? 'selected' : '' }}>Nessuna (porta 25)</option>
                        </select>
                    </div>
                    <hr style="border-color: var(--slate-200);">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1" style="color: var(--ink);">
                                Email mittente <span style="color: #EF4444;">*</span>
                            </label>
                            <input type="email" name="mail_from_address" value="{{ old('mail_from_address', 'noreply@comune.example.it') }}"
                                   required maxlength="200"
                                   class="block w-full rounded-lg text-sm px-3 py-2"
                                   style="border: 1px solid var(--slate-300); outline: none;">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1" style="color: var(--ink);">
                                Nome mittente <span style="color: #EF4444;">*</span>
                            </label>
                            <input type="text" name="mail_from_name" value="{{ old('mail_from_name', 'ProntoPA') }}"
                                   required maxlength="100"
                                   class="block w-full rounded-lg text-sm px-3 py-2"
                                   style="border: 1px solid var(--slate-300); outline: none;">
                        </div>
                    </div>
                </div>
            </div>

            {{-- Sezione 3: Admin --}}
            <div class="bg-white rounded-xl shadow-sm overflow-hidden" style="border: 1px solid var(--slate-200);">
                <div class="px-6 py-4" style="background: var(--slate-50); border-bottom: 1px solid var(--slate-200);">
                    <h2 class="font-semibold text-sm uppercase tracking-wider" style="color: var(--slate-500);">
                        3 · Account amministratore
                    </h2>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <p class="text-xs" style="color: var(--slate-400);">
                        Verrà inviata una email all'indirizzo indicato con un link per impostare la password. Link valido 30 giorni.
                    </p>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1" style="color: var(--ink);">
                                Nome completo <span style="color: #EF4444;">*</span>
                            </label>
                            <input type="text" name="admin_name" value="{{ old('admin_name') }}"
                                   required maxlength="100"
                                   class="block w-full rounded-lg text-sm px-3 py-2"
                                   style="border: 1px solid var(--slate-300); outline: none;"
                                   placeholder="Mario Rossi">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1" style="color: var(--ink);">
                                Username <span style="color: #EF4444;">*</span>
                            </label>
                            <input type="text" name="admin_username" value="{{ old('admin_username') }}"
                                   required maxlength="50"
                                   class="block w-full rounded-lg text-sm px-3 py-2"
                                   style="border: 1px solid var(--slate-300); outline: none;"
                                   placeholder="admin">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color: var(--ink);">
                            Email <span style="color: #EF4444;">*</span>
                        </label>
                        <input type="email" name="admin_email" value="{{ old('admin_email') }}"
                               required maxlength="200"
                               class="block w-full rounded-lg text-sm px-3 py-2"
                               style="border: 1px solid var(--slate-300); outline: none;"
                               placeholder="admin@comune.example.it">
                    </div>
                </div>
            </div>

            <div class="flex justify-end">
                <button type="submit"
                        class="inline-flex items-center gap-2 px-6 py-2.5 rounded-lg font-semibold text-sm text-white transition"
                        style="background: #1D4ED8;">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M5 13l4 4L19 7"/>
                    </svg>
                    Configura e avvia
                </button>
            </div>

        </form>
    </div>
</div>

</body>
</html>
