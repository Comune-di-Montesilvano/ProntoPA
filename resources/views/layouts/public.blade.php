<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? \App\Models\Impostazione::get('ente_nome', 'ProntoPA') }} — Portale trasparenza</title>
    <meta name="description" content="Portale pubblico per la consultazione delle segnalazioni aggregate e l'accesso al sistema ProntoPA.">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')

    @php
        $brandPrimary   = \App\Models\Impostazione::get('ente_colore_primario',   '#2563EB');
        $brandSecondary = \App\Models\Impostazione::get('ente_colore_secondario', '#16A34A');
    @endphp
    <style>
        :root {
            --brand-primary:   {{ $brandPrimary }};
            --brand-secondary: {{ $brandSecondary }};
        }
        .btn-brand {
            background-color: var(--brand-primary);
            color: #fff;
        }
        .btn-brand:hover {
            background-color: color-mix(in srgb, var(--brand-primary) 82%, #000);
        }
        .text-brand { color: var(--brand-primary); }
        .border-brand { border-color: var(--brand-primary); }
        .bg-brand-light { background-color: color-mix(in srgb, var(--brand-primary) 8%, #fff); }
        .kpi-icon { background-color: color-mix(in srgb, var(--brand-primary) 12%, #fff); color: var(--brand-primary); }
    </style>
</head>
<body class="font-sans antialiased bg-gray-50 text-gray-900">

    {{-- Navbar top --}}
    @php
        $enteNome = \App\Models\Impostazione::get('ente_nome', 'ProntoPA');
        $logoUrl  = \App\Models\Impostazione::get('ente_logo_url');
        $enteSito = \App\Models\Impostazione::get('ente_sito_url');
    @endphp
    <header class="bg-white border-b border-gray-200 shadow-sm sticky top-0 z-30">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between gap-4">
            <div class="flex items-center gap-3 min-w-0">
                @if($logoUrl)
                    <img src="{{ $logoUrl }}" alt="{{ $enteNome }}" class="h-9 w-9 object-contain shrink-0 rounded">
                @else
                    <div class="h-9 w-9 rounded flex items-center justify-center text-xs font-bold text-white shrink-0"
                         style="background-color: var(--brand-primary);">PA</div>
                @endif
                <div class="min-w-0">
                    <div class="font-bold text-gray-900 truncate leading-tight">{{ $enteNome }}</div>
                    <div class="text-xs text-gray-400 leading-tight">Portale trasparenza</div>
                </div>
            </div>
            <nav class="flex items-center gap-2 shrink-0">
                @if($enteSito)
                    <a href="{{ $enteSito }}" target="_blank" rel="noreferrer"
                       class="hidden sm:inline-flex items-center px-3 py-1.5 rounded-md text-sm font-medium text-gray-600 hover:text-gray-900 hover:bg-gray-100 transition">
                        Sito ente
                    </a>
                @endif
                @auth
                    <a href="{{ route('dashboard') }}"
                       class="btn-brand inline-flex items-center px-4 py-1.5 rounded-md text-sm font-semibold transition">
                        Dashboard
                    </a>
                @else
                    <a href="{{ route('login') }}"
                       class="btn-brand inline-flex items-center px-4 py-1.5 rounded-md text-sm font-semibold transition">
                        Accedi
                    </a>
                @endauth
            </nav>
        </div>
    </header>

    <main>
        {{ $slot }}
    </main>

    <footer class="mt-16 border-t border-gray-200 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-gray-400">
            <span>{{ $enteNome }} — Dati aggregati e anonimizzati. Nessun dato personale esposto.</span>
            <span>Powered by <strong class="text-gray-500">ProntoPA</strong></span>
        </div>
    </footer>

    @stack('scripts')
</body>
</html>