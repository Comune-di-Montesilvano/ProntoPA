<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ \App\Models\Impostazione::get('ente_nome', 'ProntoPA') }}</title>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Titillium+Web:wght@300;400;600;700&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @php $brandPrimary = \App\Models\Impostazione::get('ente_colore_primario', '#0B3A8C'); @endphp
        <style>
            :root { --brand-primary: {{ $brandPrimary }}; }
            body  { font-family: var(--font-ui); }
        </style>
    </head>
    <body class="antialiased" style="background: var(--slate-50);">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0">
            <div>
                <a href="/">
                    <x-application-logo class="w-16 h-16" style="color: var(--ente-primary);" />
                </a>
            </div>

            <div class="w-full sm:max-w-md mt-6 px-6 py-6 overflow-hidden sm:rounded-xl"
                 style="background: var(--paper); box-shadow: var(--shadow-2);
                        border: 1px solid var(--slate-200);">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
