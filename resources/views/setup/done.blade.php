<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Setup completato — ProntoPA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Titillium+Web:wght@300;400;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased" style="background: var(--slate-50); font-family: var(--font-ui);">

<div class="min-h-screen flex flex-col sm:justify-center items-center py-10 px-4">
    <div class="w-full max-w-md">
        <div class="bg-white rounded-xl shadow-sm p-8 text-center" style="border: 1px solid var(--slate-200);">

            <div class="inline-flex items-center justify-center w-14 h-14 rounded-full mb-4"
                 style="background: #D1FAE5;">
                <svg class="w-7 h-7" style="color: #059669;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
            </div>

            <h1 class="text-xl font-bold mb-2" style="color: var(--ink);">Setup completato!</h1>

            <p class="text-sm mb-4" style="color: var(--slate-500);">
                L'istanza è configurata. Abbiamo inviato una email all'indirizzo dell'amministratore
                con un link per impostare la password.
            </p>

            <div class="rounded-lg p-4 text-sm text-left" style="background: var(--slate-50); border: 1px solid var(--slate-200);">
                <p class="font-medium mb-1" style="color: var(--ink);">Prossimi passi:</p>
                <ol class="list-decimal list-inside space-y-1" style="color: var(--slate-500);">
                    <li>Controlla la casella email dell'amministratore</li>
                    <li>Clicca il link "Imposta password" (valido 30 giorni)</li>
                    <li>Accedi con le credenziali appena create</li>
                    <li>Completa la configurazione da <strong>Admin → Impostazioni ente</strong></li>
                </ol>
            </div>

            <a href="{{ route('login') }}"
               class="mt-6 inline-block text-sm font-medium"
               style="color: #1D4ED8;">
                Vai alla pagina di login →
            </a>
        </div>
    </div>
</div>

</body>
</html>
