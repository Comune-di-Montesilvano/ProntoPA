<x-app-layout>
    <x-slot name="header">Ripristina backup</x-slot>

    <div class="space-y-6">
        <a href="{{ route('admin.backup.index') }}" class="text-sm text-blue-700 hover:underline">&larr; Torna ai backup</a>

        <div class="bg-white shadow-sm rounded-xl p-6 space-y-3">
            <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wider">{{ $nome }}</h2>

            @if($errore)
                <div class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-800">{{ $errore }}</div>
            @endif

            @if($chiediPassphrase)
                <form method="POST" action="{{ route('admin.backup.anteprima-passphrase', $nome) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <div>
                        <label class="block text-xs text-gray-500 mb-1" for="passphrase">Passphrase di questo backup</label>
                        <input id="passphrase" name="passphrase" type="password" required autocomplete="off" class="rounded-md border-gray-300 text-sm">
                    </div>
                    <button type="submit" class="inline-flex items-center px-3 py-1.5 bg-blue-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition">
                        Apri
                    </button>
                </form>
            @endif
        </div>

        @if($anteprima)
            @php $m = $anteprima->manifest; @endphp

            <div class="bg-white shadow-sm rounded-xl p-6 space-y-3">
                <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wider">Contenuto</h2>
                <dl class="grid grid-cols-1 sm:grid-cols-4 gap-3 text-sm">
                    <div><dt class="text-gray-500">Origine</dt><dd class="break-all">{{ $m['app_url'] ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Creato il</dt><dd>{{ isset($m['creato_il']) ? \Illuminate\Support\Carbon::parse($m['creato_il'])->format('d/m/Y H:i') : '—' }}</dd></div>
                    <div><dt class="text-gray-500">Versione</dt><dd>{{ $m['versione'] ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Record</dt><dd>{{ number_format($totaleRighe, 0, ',', '.') }} in {{ count($m['conteggi'] ?? []) }} tabelle · {{ $m['file_storage']['numero'] ?? 0 }} file</dd></div>
                </dl>
            </div>

            @if($anteprima->bloccante)
                <div class="rounded-lg border-2 border-red-500 bg-red-50 p-4 text-sm font-semibold text-red-800" role="alert">
                    {{ $anteprima->bloccante }}
                </div>
            @endif

            <div class="bg-white shadow-sm rounded-xl p-6 space-y-3">
                <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wider">Cosa succede</h2>
                <ul class="list-disc pl-5 text-sm text-gray-700 space-y-1">
                    <li>Il database e gli allegati attuali vengono <strong>sostituiti</strong> da quelli del backup.</li>
                    @if($anteprima->preRipristino)
                        <li>Prima di procedere viene salvato un backup "pre-ripristino" dello stato attuale; se il ripristino fallisce si torna indietro in automatico.</li>
                    @endif
                    <li>L'applicazione resta in manutenzione fino al termine.</li>
                    <li>Tutti gli utenti vengono disconnessi e devono accedere di nuovo.</li>
                </ul>
                @unless($anteprima->stessaAppKey)
                    <p class="text-sm text-amber-800">Il backup proviene da un'installazione con APP_KEY diversa: i segreti cifrati vengono ricifrati con quella locale, ma i link firmati già inviati (magic-link ditte, decisione e rinnovo deleghe, verifica annuale) smetteranno di funzionare.</p>
                @endunless
                @unless($anteprima->stessoAppUrl)
                    <p class="text-sm text-amber-800">Il backup proviene da un APP_URL diverso: i link firmati già inviati smetteranno di funzionare e le impostazioni legate all'host (es. logo dell'ente) vanno ricontrollate.</p>
                @endunless
            </div>

            <form method="POST" action="{{ route('admin.backup.ripristina', $nome) }}" class="bg-white shadow-sm rounded-xl p-6 space-y-3">
                @csrf
                @if($passphrase)
                    <input type="hidden" name="passphrase" value="{{ $passphrase }}">
                @endif
                <label class="block text-sm text-gray-700" for="conferma">Per confermare scrivi <strong>RIPRISTINA</strong></label>
                <input id="conferma" name="conferma" type="text" autocomplete="off" @disabled($anteprima->bloccante !== null)
                       class="rounded-md border-gray-300 text-sm">
                @error('conferma')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                <div>
                    <button type="submit" @disabled($anteprima->bloccante !== null)
                            class="inline-flex items-center px-3 py-1.5 bg-red-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-700 disabled:opacity-50 transition">
                        Ripristina
                    </button>
                </div>
            </form>
        @endif
    </div>
</x-app-layout>
