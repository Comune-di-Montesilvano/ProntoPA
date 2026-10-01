@props(['titolo', 'descrizione' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @if($descrizione)
        <meta name="description" content="{{ $descrizione }}">
    @endif

    @php
        $brandPrimary = \App\Models\Impostazione::get('ente_colore_primario', '#0B3A8C');
        $enteNome     = \App\Models\Impostazione::get('ente_nome', 'ProntoPA');
    @endphp

    <title>{{ $titolo }} — {{ $enteNome }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Titillium+Web:wght@300;400;600;700;900&family=Lora:ital,wght@0,400;1,400;1,500&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        :root { --brand-primary: {{ $brandPrimary }}; }
        html, body { margin: 0; padding: 0; height: 100%; }
        body { font-family: var(--font-ui); background: var(--paper); }
    </style>
</head>
<body>
<main style="max-width:420px; margin:0 auto; padding:80px 24px; display:flex; flex-direction:column; gap:24px;">
    <div>
        <h1 style="font-family:var(--font-ui); font-size:24px; font-weight:700; color:var(--ink); margin:0; letter-spacing:-.01em;">{{ $titolo }}</h1>
        @isset($sottotitolo)
            <p style="font-size:14px; color:var(--slate-600); margin:6px 0 0;">{{ $sottotitolo }}</p>
        @endisset
    </div>

    @if(session('status'))
        <div role="status" style="background:var(--emerald-100); color:var(--emerald); border-radius:var(--radius-sm); padding:10px 14px; font-size:13px;">
            {{ session('status') }}
        </div>
    @endif

    @if($errors->any())
        <div role="alert" style="background:var(--rose-100); color:var(--rose); border-radius:var(--radius-sm); padding:10px 14px; font-size:13px; border:1px solid color-mix(in srgb,var(--rose) 25%,#fff);">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    {{ $slot }}
</main>
</body>
</html>
