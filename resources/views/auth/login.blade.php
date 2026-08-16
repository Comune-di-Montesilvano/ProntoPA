<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="Area riservata operatori — accedi per gestire le segnalazioni di manutenzione di {{ \App\Models\Impostazione::get('ente_nome', 'ProntoPA') }}.">

    <title>Accedi — {{ \App\Models\Impostazione::get('ente_nome', 'ProntoPA') }}</title>

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
        body { font-family: var(--font-ui); }
    </style>
</head>
<body>
<div style="display:grid; grid-template-columns:1fr 1.1fr; min-height:100vh;">

    {{-- ── Colonna sinistra — brand ────────────────────────────────────────── --}}
    <div style="background:var(--ente-primary); color:#fff; padding:48px 40px;
                display:flex; flex-direction:column; justify-content:space-between;
                position:relative; overflow:hidden;">

        {{-- Ornamento geometrico di sfondo --}}
        <div style="position:absolute; right:-140px; top:-140px; width:380px; height:380px;
                    border-radius:22%; background:rgba(255,255,255,.05);"></div>
        <div style="position:absolute; right:-80px; bottom:-80px; width:260px; height:260px;
                    border-radius:22%; background:rgba(255,255,255,.04);"></div>

        {{-- Lockup ProntoPA --}}
        <div style="display:flex; align-items:center; gap:12px; position:relative; z-index:1;">
            <svg width="36" height="36" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M 50 21 L 76 35 L 76 55 L 60 63 L 50 79 L 40 63 L 24 55 L 24 35 Z" fill="rgba(255,255,255,0.25)" />
                <rect x="45" y="25" width="10" height="26" fill="rgba(255,255,255,0.9)" />
                <rect x="33" y="33" width="34" height="10" fill="rgba(255,255,255,0.9)" />
                <rect x="45" y="25" width="10" height="8" fill="#E07A1F" />
            </svg>
            <span style="font-family:var(--font-ui); font-size:22px; font-weight:800; color:#fff; letter-spacing:-.01em;">ProntoPA</span>
        </div>

        {{-- Claim + contesto --}}
        <div style="position:relative; z-index:1; display:flex; flex-direction:column; gap:16px;">
            <div style="display:inline-flex; align-items:center; gap:6px;
                        background:rgba(255,255,255,.15); border-radius:20px;
                        padding:4px 12px; font-size:12px; font-weight:600; width:fit-content;">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M13 2L4 14h7l-1 8 9-12h-7z"/></svg>
                Area operatori
            </div>
            <h1 style="font-family:var(--font-claim); font-size:36px; font-weight:500;
                       font-style:italic; color:#fff; margin:0; line-height:1.2; letter-spacing:-.005em;">
                Ogni segnalazione<br>è una promessa<br>al cittadino.
            </h1>
            <p style="font-size:14px; color:rgba(255,255,255,.88); margin:8px 0 0; line-height:1.55; max-width:320px;">
                Gestisci flussi, imprese e tempi di risposta di {{ $enteNome }}.
            </p>
        </div>

        <div style="font-family:var(--font-mono); font-size:11px; color:rgba(255,255,255,.8); position:relative; z-index:1;">
            v{{ config('app.version', 'dev') }} · EUPL-1.2
        </div>
    </div>

    {{-- ── Colonna destra — form ───────────────────────────────────────────── --}}
    <main style="background:var(--paper); padding:64px 56px;
                display:flex; flex-direction:column; justify-content:center; gap:24px;">

        <div>
            <h2 style="font-family:var(--font-ui); font-size:28px; font-weight:700;
                       color:var(--ink); margin:0; letter-spacing:-.01em;">Bentornato.</h2>
            <p style="font-size:14px; color:var(--slate-600); margin:6px 0 0;">
                Accedi con le credenziali di {{ $enteNome }}.
            </p>
        </div>

        {{-- Stato sessione (es. reset password) --}}
        @if(session('status'))
            <div style="background:var(--emerald-100); color:var(--emerald); border-radius:var(--radius-sm);
                        padding:10px 14px; font-size:13px; border:1px solid color-mix(in srgb,var(--emerald) 25%,#fff);">
                {{ session('status') }}
            </div>
        @endif

        @if($errors->any())
            <div style="background:var(--rose-100); color:var(--rose); border-radius:var(--radius-sm);
                        padding:10px 14px; font-size:13px; border:1px solid color-mix(in srgb,var(--rose) 25%,#fff);">
                @foreach($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}" style="display:flex; flex-direction:column; gap:20px;">
            @csrf

            <div class="pa-field">
                <label class="pa-field-label" for="username">Username</label>
                <input id="username" type="text" name="username" class="pa-input"
                       value="{{ old('username') }}" required autofocus autocomplete="username">
            </div>

            <div class="pa-field">
                <label class="pa-field-label" for="password">Password</label>
                <input id="password" type="password" name="password" class="pa-input"
                       required autocomplete="current-password">
                @if(Route::has('password.request'))
                    <a href="{{ route('password.request') }}"
                       style="font-size:12px; color:var(--ente-primary); font-weight:600;
                              text-decoration:none; align-self:flex-end; margin-top:2px;">
                        Ho dimenticato la password
                    </a>
                @endif
            </div>

            <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                <input type="checkbox" name="remember"
                       style="width:15px;height:15px;accent-color:var(--ente-primary);">
                <span style="font-size:13px; color:var(--slate-600);">Ricordami</span>
            </label>

            <button type="submit" class="pa-btn pa-btn-primary pa-btn-lg" style="width:100%;">
                Accedi
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
            </button>
        </form>

        @if(Route::has('register'))
            <p style="font-size:12px; color:var(--slate-500); text-align:center; margin:0;">
                Non hai un account?
                <a href="{{ route('register') }}" style="color:var(--ente-primary); font-weight:600; text-decoration:none;">
                    Registrati →
                </a>
            </p>
        @endif
    </main>
</div>
</body>
</html>
