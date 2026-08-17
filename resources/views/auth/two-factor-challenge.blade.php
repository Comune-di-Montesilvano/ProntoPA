<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="Verifica in due passaggi — inserisci il codice dell'app di autenticazione per completare l'accesso.">

    <title>Verifica in due passaggi — {{ \App\Models\Impostazione::get('ente_nome', 'ProntoPA') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Titillium+Web:wght@300;400;600;700;900&family=Lora:ital,wght@0,400;1,400;1,500&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @php
        $brandPrimary = \App\Models\Impostazione::get('ente_colore_primario', '#0B3A8C');
        $enteNome     = \App\Models\Impostazione::get('ente_nome', 'ProntoPA');
    @endphp
    <style>
        :root { --brand-primary: {{ $brandPrimary }}; }
        html, body { margin: 0; padding: 0; height: 100%; }
        body { font-family: var(--font-ui); background: var(--paper); }
    </style>
</head>
<body>
<main style="max-width:420px; margin:0 auto; padding:80px 24px; display:flex; flex-direction:column; gap:24px;">

    <div>
        <h2 style="font-family:var(--font-ui); font-size:24px; font-weight:700;
                   color:var(--ink); margin:0; letter-spacing:-.01em;">Verifica in due passaggi</h2>
        <p style="font-size:14px; color:var(--slate-600); margin:6px 0 0;">
            Inserisci il codice generato dall'app di autenticazione, oppure uno dei tuoi codici di recupero.
        </p>
    </div>

    @if($errors->any())
        <div style="background:var(--rose-100); color:var(--rose); border-radius:var(--radius-sm);
                    padding:10px 14px; font-size:13px; border:1px solid color-mix(in srgb,var(--rose) 25%,#fff);">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('two-factor.login') }}" style="display:flex; flex-direction:column; gap:20px;">
        @csrf

        <div class="pa-field">
            <label class="pa-field-label" for="code">Codice a 6 cifre</label>
            <input id="code" type="text" name="code" class="pa-input" inputmode="numeric"
                   autocomplete="one-time-code" autofocus placeholder="000000">
        </div>

        <div class="pa-field">
            <label class="pa-field-label" for="recovery_code">Oppure codice di recupero</label>
            <input id="recovery_code" type="text" name="recovery_code" class="pa-input" autocomplete="off">
        </div>

        <button type="submit" class="pa-btn pa-btn-primary pa-btn-lg" style="width:100%;">
            Verifica e accedi
        </button>
    </form>

    <p style="font-size:12px; color:var(--slate-500); text-align:center; margin:0;">
        <a href="{{ route('login') }}" style="color:var(--ente-primary); font-weight:600; text-decoration:none;">
            ← Torna al login
        </a>
    </p>
</main>
</body>
</html>
