<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? \App\Models\Impostazione::get('ente_nome', 'ProntoPA') }} — Portale trasparenza</title>
    <meta name="description" content="Portale pubblico per la consultazione delle segnalazioni aggregate e l'accesso al sistema ProntoPA.">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Titillium+Web:wght@300;400;600;700;900&family=Lora:ital,wght@0,400;1,400;1,500&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')

    @php
        $brandPrimary   = \App\Models\Impostazione::get('ente_colore_primario',   '#0B3A8C');
        $brandSecondary = \App\Models\Impostazione::get('ente_colore_secondario', '#1F7A5A');
    @endphp
    <style>
        :root {
            --brand-primary:   {{ $brandPrimary }};
            --brand-secondary: {{ $brandSecondary }};
        }
        body { font-family: var(--font-ui); }
    </style>
</head>
<body class="antialiased" style="background: var(--slate-50); color: var(--ink);">

    @php
        $enteNome = \App\Models\Impostazione::get('ente_nome', 'ProntoPA');
        $logoUrl  = \App\Models\Impostazione::get('ente_logo_url');
        $enteSito = \App\Models\Impostazione::get('ente_sito_url');
    @endphp

    {{-- Fascia istituzionale fina --}}
    <div style="background:var(--ente-primary); color:#fff; font-size:12px; padding:5px 32px;
                display:flex; justify-content:space-between; align-items:center;">
        <span style="opacity:.85;">Sito istituzionale di {{ $enteNome }}</span>
        @if($enteSito)
            <a href="{{ $enteSito }}" target="_blank" rel="noreferrer"
               style="opacity:.75; color:#fff; text-decoration:none; font-family:var(--font-mono); font-size:11px;">
                {{ parse_url($enteSito, PHP_URL_HOST) ?? $enteSito }}
            </a>
        @endif
    </div>

    {{-- Navbar principale --}}
    <header style="background:#fff; border-bottom:1px solid var(--slate-200); box-shadow:var(--shadow-1);
                   position:sticky; top:0; z-index:30;">
        <div class="max-w-7xl mx-auto px-6" style="height:60px; display:flex; align-items:center; justify-content:space-between; gap:16px;">
            {{-- Co-brand --}}
            <div style="display:flex; align-items:center; gap:12px; min-width:0;">
                @if($logoUrl)
                    <img src="{{ $logoUrl }}" alt="{{ $enteNome }}" class="h-9 w-9 object-contain shrink-0 rounded">
                @else
                    <svg width="32" height="32" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" style="flex-shrink:0;">
                        <path d="M 50 21 L 76 35 L 76 55 L 60 63 L 50 79 L 40 63 L 24 55 L 24 35 Z" fill="var(--ente-primary)" />
                        <rect x="45" y="25" width="10" height="26" fill="white" />
                        <rect x="33" y="33" width="34" height="10" fill="white" />
                        <rect x="45" y="25" width="10" height="8" fill="#E07A1F" />
                    </svg>
                @endif
                <div style="width:1px; height:26px; background:var(--slate-200); flex-shrink:0;"></div>
                <div style="min-width:0; line-height:1.15;">
                    <div class="truncate" style="font-size:14px; font-weight:700; color:var(--ink);">{{ $enteNome }}</div>
                    <div style="font-size:11px; color:var(--slate-500);">Portale trasparenza</div>
                </div>
            </div>

            {{-- Nav destra --}}
            <nav style="display:flex; align-items:center; gap:8px; flex-shrink:0;">
                @if($enteSito)
                    <a href="{{ $enteSito }}" target="_blank" rel="noreferrer"
                       class="pa-btn pa-btn-ghost pa-btn-sm hidden sm:inline-flex">
                        Sito ente
                    </a>
                @endif
                @auth
                    <a href="{{ route('dashboard') }}" class="pa-btn pa-btn-primary pa-btn-sm">Dashboard</a>
                @else
                    <a href="{{ route('login') }}" class="pa-btn pa-btn-primary pa-btn-sm">Accedi</a>
                @endauth
            </nav>
        </div>
    </header>

    <main>{{ $slot }}</main>

    <footer style="margin-top:64px; border-top:1px solid var(--slate-200); background:#fff;">
        <div class="max-w-7xl mx-auto px-6 py-5"
             style="display:flex; flex-direction:column; gap:4px; align-items:center; text-align:center;">
            <div style="display:flex; align-items:center; gap:8px;">
                <svg width="18" height="18" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M 50 21 L 76 35 L 76 55 L 60 63 L 50 79 L 40 63 L 24 55 L 24 35 Z" fill="var(--ente-primary)" />
                    <rect x="45" y="25" width="10" height="26" fill="white" />
                    <rect x="33" y="33" width="34" height="10" fill="white" />
                    <rect x="45" y="25" width="10" height="8" fill="#E07A1F" />
                </svg>
                <span style="font-size:13px; font-weight:700; color:var(--ink);">ProntoPA</span>
            </div>
            <p style="font-size:12px; color:var(--slate-500); margin:0;">
                {{ $enteNome }} — Dati aggregati e anonimizzati. Nessun dato personale esposto.
            </p>
        </div>
    </footer>

    @stack('scripts')
</body>
</html>
