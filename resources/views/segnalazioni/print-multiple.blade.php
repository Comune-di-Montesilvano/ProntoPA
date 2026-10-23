<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schede segnalazioni — {{ $tabLabels[$tab] ?? $tab }} ({{ $segnalazioni->count() }})</title>
    <style>
        @include('segnalazioni._print-style')
        .scheda { page-break-after: always; break-after: page; }
        .scheda:last-child { page-break-after: auto; break-after: auto; }
    </style>
</head>
<body>
    @forelse($segnalazioni as $segnalazione)
        <div class="scheda">
            @include('segnalazioni._scheda-print')
        </div>
    @empty
        <p>Nessuna segnalazione da stampare con i filtri scelti.</p>
    @endforelse

    @if($segnalazioni->isNotEmpty())
        <script>window.onload = function() { window.print(); }</script>
    @endif
</body>
</html>
