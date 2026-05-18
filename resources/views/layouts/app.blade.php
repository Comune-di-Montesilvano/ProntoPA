<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ \App\Models\Impostazione::get('ente_nome', 'ProntoPA') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Titillium+Web:wght@300;400;600;700;900&family=Lora:ital,wght@0,400;0,500;1,400;1,500&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">

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

        /* Legacy Tailwind brand overrides */
        .bg-blue-600  { background-color: var(--brand-primary) !important; }
        .bg-blue-700  { background-color: color-mix(in srgb, var(--brand-primary) 82%, #000) !important; }
        .hover\:bg-blue-700:hover { background-color: color-mix(in srgb, var(--brand-primary) 82%, #000) !important; }
        .text-blue-600 { color: var(--brand-primary) !important; }
        .text-blue-700 { color: color-mix(in srgb, var(--brand-primary) 82%, #000) !important; }
        .hover\:text-blue-600:hover { color: var(--brand-primary) !important; }
        .hover\:text-blue-800:hover { color: color-mix(in srgb, var(--brand-primary) 70%, #000) !important; }
        .border-blue-500, .focus\:border-blue-500:focus { border-color: var(--brand-primary) !important; }
        .ring-blue-400  { --tw-ring-color: var(--brand-primary) !important; }
        .focus\:ring-blue-500:focus { --tw-ring-color: var(--brand-primary) !important; }
        .bg-blue-50 { background-color: color-mix(in srgb, var(--brand-primary) 10%, #fff) !important; }
    </style>
</head>
<body class="antialiased" style="background: var(--slate-50);">

@php
    $enteNome   = \App\Models\Impostazione::get('ente_nome', 'ProntoPA');
    $logoUrl    = \App\Models\Impostazione::get('ente_logo_url');
    $u          = auth()->user();
    $isAdmin    = $u && ($u->isAdmin() || $u->hasRole('admin'));
    $isGestore  = $u && ($u->isGestore() || $u->hasRole('gestore'));
    $isSegnalatore = $u && $u->hasRole('segnalatore');
    $isImpresa  = $u && $u->hasRole('impresa');
@endphp

<div class="min-h-screen flex" x-data="{ sidebarOpen: false }">

    {{-- ── Overlay mobile ────────────────────────────────────────────────────── --}}
    <div class="fixed inset-0 z-20 bg-black/40 lg:hidden"
         x-show="sidebarOpen" x-cloak @click="sidebarOpen = false"></div>

    {{-- ── Sidebar ─────────────────────────────────────────────────────────── --}}
    <aside class="fixed inset-y-0 left-0 z-30 flex flex-col bg-white border-r
                  transform transition-transform duration-200 ease-in-out
                  lg:static lg:translate-x-0 -translate-x-full"
           style="width:220px; border-color: var(--slate-200);"
           :class="{ 'translate-x-0': sidebarOpen, '-translate-x-full': !sidebarOpen }"
           x-cloak>

        {{-- Logo / Co-brand --}}
        <div class="flex items-center gap-3 px-4 py-4" style="border-bottom:1px solid var(--slate-200);">
            @if($logoUrl)
                <img src="{{ $logoUrl }}" alt="{{ $enteNome }}"
                     class="h-9 w-9 object-contain shrink-0 rounded">
            @else
                <x-application-logo class="h-9 w-9 shrink-0" style="color: var(--ente-primary);" />
            @endif
            <div style="width:1px; height:28px; background: var(--slate-200); flex-shrink:0;"></div>
            <div class="flex-1 min-w-0" style="line-height:1.15;">
                <div class="truncate" style="font-size:13px; font-weight:700; color: var(--ink);">{{ $enteNome }}</div>
                <div style="font-size:11px; color: var(--slate-500);">ProntoPA</div>
            </div>
        </div>

        {{-- Navigation --}}
        <nav class="flex-1 overflow-y-auto py-3 px-3" style="display:flex; flex-direction:column; gap:18px;">
            @if($isAdmin || $isGestore)
                <div class="pa-sb-section">
                    <p class="pa-sb-heading">Operatività</p>
                    <x-sidebar-link href="{{ route('gestione.dashboard') }}" :active="request()->routeIs('gestione.*')" icon="clipboard">
                        Segnalazioni
                    </x-sidebar-link>
                    <x-sidebar-link href="{{ route('statistiche.index') }}" :active="request()->routeIs('statistiche.*')" icon="chart-bar">
                        Statistiche
                    </x-sidebar-link>
                </div>
                <div class="pa-sb-section">
                    <p class="pa-sb-heading">Anagrafica</p>
                    <x-sidebar-link href="{{ route('imprese.index') }}" :active="request()->routeIs('imprese.index') || request()->routeIs('imprese.create') || request()->routeIs('imprese.edit')" icon="office-building">
                        Imprese
                    </x-sidebar-link>
                    <x-sidebar-link href="{{ route('appalti.index') }}" :active="request()->routeIs('appalti.*')" icon="document-text">
                        Appalti
                    </x-sidebar-link>
                </div>
            @endif

            @if($isSegnalatore)
                <div class="pa-sb-section">
                    <p class="pa-sb-heading">Segnalazioni</p>
                    <x-sidebar-link href="{{ route('segnalatore.dashboard') }}" :active="request()->routeIs('segnalatore.*')" icon="inbox">
                        Le mie segnalazioni
                    </x-sidebar-link>
                    <x-sidebar-link href="{{ route('segnalazioni.create') }}" :active="request()->routeIs('segnalazioni.create')" icon="plus-circle">
                        Nuova segnalazione
                    </x-sidebar-link>
                </div>
            @endif

            @if($isAdmin)
                <div class="pa-sb-section">
                    <p class="pa-sb-heading">Amministrazione</p>
                    <x-sidebar-link href="{{ route('admin.dashboard') }}" :active="request()->is('admin') && !request()->routeIs('admin.impostazioni.*')" icon="cog">
                        Pannello admin
                    </x-sidebar-link>
                    <x-sidebar-link href="{{ route('admin.impostazioni.index') }}" :active="request()->routeIs('admin.impostazioni.*')" icon="adjustments">
                        Impostazioni ente
                    </x-sidebar-link>
                    <x-sidebar-link href="{{ route('admin.organizzazioni.index') }}" :active="request()->routeIs('admin.organizzazioni.*')" icon="office-building">
                        Organizzazioni
                    </x-sidebar-link>
                    <x-sidebar-link href="{{ route('admin.sedi.index') }}" :active="request()->routeIs('admin.sedi.*')" icon="map-pin">
                        Sedi
                    </x-sidebar-link>
                    <x-sidebar-link href="{{ route('admin.sla.index') }}" :active="request()->routeIs('admin.sla.*')" icon="clock">
                        SLA
                    </x-sidebar-link>
                    <x-sidebar-link href="{{ route('admin.profili.index') }}" :active="request()->routeIs('admin.profili.*') || request()->routeIs('admin.provenienze.*')" icon="user-group">
                        Profili e provenienze
                    </x-sidebar-link>
                </div>
            @endif
        </nav>

        {{-- User footer --}}
        <div class="px-3 py-3" style="border-top:1px solid var(--slate-200);">
            <div class="flex items-center gap-2.5 p-2 rounded-lg" style="background: var(--slate-50);">
                {{-- Avatar --}}
                <div class="flex-shrink-0 flex items-center justify-center rounded-full text-xs font-bold"
                     style="width:32px;height:32px;background:var(--ente-primary-100);color:var(--ente-primary);">
                    {{ strtoupper(substr(auth()->user()?->name ?? 'U', 0, 1)) }}
                </div>
                <div class="flex-1 min-w-0">
                    <div class="truncate" style="font-size:13px;font-weight:600;color:var(--ink);">{{ auth()->user()?->name }}</div>
                    <div class="truncate" style="font-size:11px;color:var(--slate-500);">
                        @if($isAdmin) admin @elseif($isGestore) gestore @elseif($isSegnalatore) segnalatore @elseif($isImpresa) impresa @endif
                    </div>
                </div>
            </div>
            <div class="flex items-center justify-between mt-2 px-1">
                <a href="{{ route('profile.edit') }}" style="font-size:12px;color:var(--slate-500);" class="hover:underline">Profilo</a>
                <form method="POST" action="{{ route('logout') }}" class="inline">
                    @csrf
                    <button type="submit" style="font-size:12px;color:var(--slate-500);background:none;border:none;cursor:pointer;" class="hover:underline">Esci</button>
                </form>
            </div>
            <div class="mt-1 px-1" style="font-size:10px;color:var(--slate-400);font-family:var(--font-mono);">v{{ config('app.version', 'dev') }}</div>
        </div>
    </aside>

    {{-- ── Main content ─────────────────────────────────────────────────────── --}}
    <div class="flex-1 flex flex-col min-w-0">

        {{-- Topbar --}}
        <header class="sticky top-0 z-10 pa-topbar" style="position:sticky;">
            {{-- Hamburger (mobile) --}}
            <button class="lg:hidden flex-shrink-0" style="color:var(--slate-500);background:none;border:none;cursor:pointer;padding:4px;"
                    @click="sidebarOpen = !sidebarOpen" aria-label="Toggle sidebar">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>

            {{-- Page title --}}
            <div class="flex-1 min-w-0 flex items-center gap-3">
                @isset($header)
                    <div style="font-size:16px;font-weight:700;color:var(--ink);" class="truncate">{{ $header }}</div>
                @endisset
            </div>

            {{-- Actions slot --}}
            @isset($actions)
                <div class="flex items-center gap-2 flex-shrink-0">{{ $actions }}</div>
            @endisset
        </header>

        {{-- Flash messages --}}
        @if(session('success'))
            <div class="mx-6 mt-4">
                <div class="flex items-center gap-2.5 px-4 py-2.5 rounded-lg text-sm"
                     style="background:var(--emerald-100);color:var(--emerald);border:1px solid color-mix(in srgb,var(--emerald) 25%,#fff);">
                    <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                    {{ session('success') }}
                </div>
            </div>
        @endif
        @if(session('error'))
            <div class="mx-6 mt-4">
                <div class="flex items-center gap-2.5 px-4 py-2.5 rounded-lg text-sm"
                     style="background:var(--rose-100);color:var(--rose);border:1px solid color-mix(in srgb,var(--rose) 25%,#fff);">
                    <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm-1-5h2v2h-2v-2zm0-8h2v6h-2V5z" clip-rule="evenodd"/></svg>
                    {{ session('error') }}
                </div>
            </div>
        @endif

        {{-- Page content --}}
        <main class="flex-1 p-6" style="font-family:var(--font-ui);color:var(--ink);">
            {{ $slot }}
        </main>
    </div>
</div>

@stack('scripts')
</body>
</html>
