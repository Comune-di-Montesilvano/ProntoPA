<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scheda Segnalazione #{{ $segnalazione->id_segnalazione }}</title>
    <style>
        @include('segnalazioni._print-style')
    </style>
</head>
<body>
    @include('segnalazioni._scheda-print')

    <script>window.onload = function() { window.print(); }</script>
</body>
</html>
