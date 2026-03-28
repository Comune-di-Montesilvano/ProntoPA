<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ \App\Models\Impostazione::get('ente_nome', 'ProntoPA') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="font-sans antialiased bg-gray-100">

@php
    $enteNome   = \App\Models\Impostazione::get('ente_nome', 'ProntoPA');
    $u          = auth()->user();
    $isAdmin    = $u && ($u->isAdmin() || $u->hasRole('admin'));
    $isGestore  = $u && ($u->isGestore() || $u->hasRole('gestore'));
    $isSegnalatore = $u && $u->hasRole('segnalatore');
@endphp

<div class="min-h-screen flex" x-data="{ sidebarOpen: false }">

    {{-- ── Sidebar ──────────────────────────────────────────────────────────── --}}
    {{-- Overlay mobile --}}
    <div class="fixed inset-0 z-20 bg-black/50 lg:hidden"
         x-show="sidebarOpen"
         x-cloak
         @click="sidebarOpen = false"></div>

    <aside class="fixed inset-y-0 left-0 z-30 w-64 flex flex-col bg-gray-900 text-gray-100
                  transform transition-transform duration-200 ease-in-out
                  lg:static lg:translate-x-0
                  -translate-x-full"
           :class="{ 'translate-x-0': sidebarOpen, '-translate-x-full': !sidebarOpen }"
           x-cloak>

        {{-- Logo / Ente --}}
        <div class="flex items-center gap-2 px-5 py-5 border-b border-gray-700">
            <div class="flex-1 min-w-0">
                <div class="text-sm font-bold text-white truncate">{{ $enteNome }}</div>
                <div class="text-xs text-gray-400 mt-0.5">ProntoPA</div>
            </div>
        </div>

        {{-- Navigation --}}
        <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1 text-sm">
            @if($isAdmin || $isGestore)
                @php $section = 'gestione'; @endphp
                <p class="px-2 pt-3 pb-1 text-xs font-semibold uppercase tracking-widest text-gray-500">Operatività</p>
                <x-sidebar-link href="{{ route('gestione.dashboard') }}" :active="request()->routeIs('gestione.*')" icon="clipboard">
                    Gestione segnalazioni
                </x-sidebar-link>
                <x-sidebar-link href="{{ route('statistiche.index') }}" :active="request()->routeIs('statistiche.*')" icon="chart-bar">
                    Statistiche
                </x-sidebar-link>

                <p class="px-2 pt-4 pb-1 text-xs font-semibold uppercase tracking-widest text-gray-500">Anagrafica</p>
                <x-sidebar-link href="{{ route('imprese.index') }}" :active="request()->routeIs('imprese.index') || request()->routeIs('imprese.create') || request()->routeIs('imprese.edit')" icon="office-building">
                    Imprese
                </x-sidebar-link>
                <x-sidebar-link href="{{ route('appalti.index') }}" :active="request()->routeIs('appalti.*')" icon="document-text">
                    Appalti
                </x-sidebar-link>
            @endif

            @if($isSegnalatore)
                <p class="px-2 pt-3 pb-1 text-xs font-semibold uppercase tracking-widest text-gray-500">Segnalazioni</p>
                <x-sidebar-link href="{{ route('segnalatore.dashboard') }}" :active="request()->routeIs('segnalatore.*')" icon="inbox">
                    Le mie segnalazioni
                </x-sidebar-link>
                <x-sidebar-link href="{{ route('segnalazioni.create') }}" :active="request()->routeIs('segnalazioni.create')" icon="plus-circle">
                    Nuova segnalazione
                </x-sidebar-link>
            @endif

            @if($isAdmin)
                <p class="px-2 pt-4 pb-1 text-xs font-semibold uppercase tracking-widest text-gray-500">Amministrazione</p>
                <x-sidebar-link href="{{ route('admin.dashboard') }}" :active="request()->is('admin') && !request()->routeIs('admin.impostazioni.*')" icon="cog">
                    Pannello admin
                </x-sidebar-link>
                <x-sidebar-link href="{{ route('admin.impostazioni.index') }}" :active="request()->routeIs('admin.impostazioni.*')" icon="adjustments">
                    Impostazioni ente
                </x-sidebar-link>
            @endif
        </nav>

        {{-- User footer --}}
        <div class="px-4 py-4 border-t border-gray-700 text-xs">
            <div class="font-medium text-gray-200 truncate">{{ auth()->user()?->name }}</div>
            <div class="text-gray-500 truncate mt-0.5">{{ auth()->user()?->email }}</div>
            <div class="mt-3 flex items-center gap-3">
                <a href="{{ route('profile.edit') }}" class="text-gray-400 hover:text-white transition">Profilo</a>
                <form method="POST" action="{{ route('logout') }}" class="inline">
                    @csrf
                    <button type="submit" class="text-gray-400 hover:text-white transition">Esci</button>
                </form>
            </div>
            <div class="mt-2 text-gray-600">v{{ config('app.version', 'dev') }}</div>
        </div>
    </aside>

    {{-- ── Main content ─────────────────────────────────────────────────────── --}}
    <div class="flex-1 flex flex-col min-w-0">

        {{-- Topbar --}}
        <header class="sticky top-0 z-10 bg-white border-b border-gray-200 flex items-center gap-4 px-4 sm:px-6 h-14">
            {{-- Hamburger (mobile) --}}
            <button class="lg:hidden text-gray-500 hover:text-gray-700 focus:outline-none"
                    @click="sidebarOpen = !sidebarOpen"
                    aria-label="Toggle sidebar">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>

            {{-- Page title slot --}}
            <div class="flex-1 min-w-0 flex items-center gap-3">
                @isset($header)
                    <div class="text-base font-semibold text-gray-800 truncate">{{ $header }}</div>
                @endisset
            </div>

            {{-- Quick actions slot --}}
            @isset($actions)
                <div class="flex items-center gap-2 shrink-0">{{ $actions }}</div>
            @endisset
        </header>

        {{-- Flash messages --}}
        @if(session('success'))
            <div class="mx-4 sm:mx-6 mt-4">
                <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-2.5 text-sm flex items-center gap-2">
                    <svg class="w-4 h-4 shrink-0 text-green-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                    {{ session('success') }}
                </div>
            </div>
        @endif
        @if(session('error'))
            <div class="mx-4 sm:mx-6 mt-4">
                <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-2.5 text-sm flex items-center gap-2">
                    <svg class="w-4 h-4 shrink-0 text-red-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm-1-5h2v2h-2v-2zm0-8h2v6h-2V5z" clip-rule="evenodd"/></svg>
                    {{ session('error') }}
                </div>
            </div>
        @endif

        {{-- Page content --}}
        <main class="flex-1 p-4 sm:p-6 text-base">
            {{ $slot }}
        </main>
    </div>
</div>

@stack('scripts')
</body>
</html>
