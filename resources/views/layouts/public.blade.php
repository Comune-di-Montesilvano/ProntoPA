<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? \App\Models\Impostazione::get('ente_nome', 'ProntoPA') }}</title>
    <meta name="description" content="Portale pubblico per la consultazione delle segnalazioni aggregate e l'accesso al sistema ProntoPA.">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')

    @php
        $brandPrimary = \App\Models\Impostazione::get('ente_colore_primario', '#0f766e');
        $brandSecondary = \App\Models\Impostazione::get('ente_colore_secondario', '#f59e0b');
    @endphp
    <style>
        :root {
            --brand-primary: {{ $brandPrimary }};
            --brand-secondary: {{ $brandSecondary }};
            --brand-ink: color-mix(in srgb, var(--brand-primary) 40%, #0f172a);
            --brand-soft: color-mix(in srgb, var(--brand-primary) 12%, white);
            --brand-warm: color-mix(in srgb, var(--brand-secondary) 20%, white);
        }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 antialiased">
    <div class="relative isolate overflow-hidden">
        <div class="absolute inset-0 -z-20 bg-[radial-gradient(circle_at_top_left,_rgba(255,255,255,0.14),_transparent_32%),linear-gradient(140deg,#020617_0%,color-mix(in_srgb,var(--brand-primary)_18%,#020617)_45%,#111827_100%)]"></div>
        <div class="absolute -left-24 top-20 -z-10 h-72 w-72 rounded-full blur-3xl opacity-40" style="background: color-mix(in srgb, var(--brand-primary) 75%, transparent);"></div>
        <div class="absolute right-0 top-0 -z-10 h-80 w-80 translate-x-1/4 -translate-y-1/4 rounded-full blur-3xl opacity-30" style="background: color-mix(in srgb, var(--brand-secondary) 70%, transparent);"></div>

        {{ $slot }}
    </div>

    @stack('scripts')
</body>
</html>