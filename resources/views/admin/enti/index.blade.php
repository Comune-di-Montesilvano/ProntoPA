<x-app-layout>
    <x-slot name="header">Enti e sedi</x-slot>
    <x-slot name="actions">
        <a href="{{ route('admin.anagrafe-miur.index') }}"
           class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 transition">
            <span class="sm:hidden">Da MIUR</span><span class="hidden sm:inline">Aggiungi da MIUR</span>
        </a>
        <a href="{{ route('admin.enti.create') }}"
           class="inline-flex items-center px-3 py-1.5 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition">
            <span class="sm:hidden">A mano</span><span class="hidden sm:inline">Nuovo ente a mano</span>
        </a>
    </x-slot>

    <div class="space-y-6">
        {{-- Anagrafe MIUR --}}
        <div class="bg-white shadow-sm rounded-xl p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div class="text-sm">
                <h2 class="font-semibold text-gray-700">Anagrafe MIUR</h2>
                @if($inCorsoMiur)
                    <p class="text-blue-700">Download in corso…</p>
                @elseif($statoMiur['stato'] === 'errore')
                    <p class="text-red-700">Ultimo download non riuscito: {{ $statoMiur['messaggio'] }}</p>
                @elseif($statoMiur['scaricato_at'] ?? null)
                    <p class="text-gray-600">Aggiornata il {{ \Illuminate\Support\Carbon::parse($statoMiur['scaricato_at'])->format('d/m/Y H:i') }}, {{ $statoMiur['sedi'] ?? '—' }} scuole.</p>
                @else
                    <p class="text-gray-600">Nessuna anagrafe scaricata.</p>
                @endif
            </div>
            <form method="POST" action="{{ route('admin.anagrafe-miur.scarica') }}">
                @csrf
                <button type="submit" @disabled($inCorsoMiur)
                        class="inline-flex items-center px-3 py-1.5 bg-blue-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 disabled:opacity-50 transition">
                    Scarica / aggiorna anagrafe MIUR
                </button>
            </form>
        </div>

        {{-- Filtri --}}
        <form method="GET" action="{{ route('admin.enti.index') }}" class="bg-white shadow-sm rounded-xl px-4 py-3 flex flex-wrap gap-2 items-end">
            <div>
                <label for="categoria" class="block text-xs text-gray-500 mb-1">Categoria</label>
                <select id="categoria" name="categoria" class="border-gray-300 rounded-md shadow-sm text-sm">
                    <option value="">Tutte</option>
                    @foreach($categorie as $c)
                        <option value="{{ $c->id_categoria }}" @selected($filtroCategoria === $c->id_categoria)>{{ $c->nome }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="q" class="block text-xs text-gray-500 mb-1">Cerca</label>
                <x-text-input id="q" name="q" :value="$q" placeholder="Nome o codice" />
            </div>
            <button class="px-4 py-2 bg-gray-800 text-white text-xs font-semibold rounded-md hover:bg-gray-700 transition">Filtra</button>
            @if($q !== '' || $filtroCategoria)
                <a href="{{ route('admin.enti.index') }}" class="px-4 py-2 bg-gray-100 text-gray-600 text-xs font-semibold rounded-md hover:bg-gray-200 transition">Azzera</a>
            @endif
        </form>

        {{-- Elenco per categoria --}}
        @if($enti->isEmpty())
            <div class="bg-white shadow-sm rounded-xl p-10 text-center text-gray-400 text-sm">Nessun ente trovato.</div>
        @else
            @foreach($enti->groupBy(fn ($e) => $e->categoria->nome) as $nomeCategoria => $gruppo)
                <section class="bg-white shadow-sm rounded-xl overflow-hidden">
                    <h2 class="px-5 py-3 bg-gray-50 text-xs font-semibold text-gray-600 uppercase tracking-wider">{{ $nomeCategoria }}</h2>
                    <ul class="divide-y divide-gray-100">
                        @foreach($gruppo as $ente)
                            <li class="px-5 py-3">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <a href="{{ route('admin.enti.show', $ente) }}" class="font-medium text-blue-700 hover:underline">{{ $ente->descrizione }}</a>
                                        @if($ente->codice_meccanografico)
                                            <span class="text-xs text-gray-500">{{ $ente->codice_meccanografico }}</span>
                                        @endif
                                        @unless($ente->attivo)
                                            <span class="ml-1 text-xs px-1.5 py-0.5 rounded bg-gray-100 text-gray-500">non attivo</span>
                                        @endunless
                                    </div>
                                    <a href="{{ route('admin.enti.show', $ente) }}" class="text-xs text-blue-600 hover:text-blue-800 whitespace-nowrap">Apri scheda</a>
                                </div>
                                @if($ente->plessi->isNotEmpty())
                                    <ul class="mt-1 ml-4 text-sm text-gray-600 list-disc">
                                        @foreach($ente->plessi as $sede)
                                            <li>{{ $sede->nome }}@if($sede->indirizzo) <span class="text-xs text-gray-400">· {{ $sede->indirizzo }}</span>@endif</li>
                                        @endforeach
                                    </ul>
                                @else
                                    <p class="mt-1 ml-4 text-xs text-gray-400">Nessuna sede</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach

            @if($enti->hasPages())
                <div>{{ $enti->links() }}</div>
            @endif
        @endif

        {{-- Categorie --}}
        <details class="bg-white shadow-sm rounded-xl" @if($errors->any() || session('error')) open @endif>
            <summary class="px-5 py-3 cursor-pointer text-sm font-semibold text-gray-700">Categorie degli enti ({{ $categorie->count() }})</summary>
            <div class="px-5 pb-5 space-y-3">
                <p class="text-sm text-gray-600">Le categorie raggruppano gli enti negli elenchi e nel modulo di segnalazione. L'ordine decide la posizione. Gli enti scaricati dal MIUR vanno nella categoria marcata "import MIUR".</p>
                @foreach($categorie as $c)
                    <div class="flex flex-wrap items-center gap-2">
                        <form method="POST" action="{{ route('admin.categorie-enti.update', $c) }}" class="flex flex-wrap items-center gap-2">
                            @csrf @method('PATCH')
                            <input type="text" name="nome" value="{{ $c->nome }}" required maxlength="100" aria-label="Nome categoria"
                                   class="border-gray-300 rounded-md shadow-sm text-sm w-56">
                            <input type="number" name="ordine" value="{{ $c->ordine }}" min="0" aria-label="Ordine"
                                   class="border-gray-300 rounded-md shadow-sm text-sm w-20">
                            <label class="flex items-center gap-1 text-xs text-gray-600">
                                <input type="checkbox" name="import_miur" value="1" @checked($c->import_miur) class="rounded border-gray-300 text-blue-600">
                                Import MIUR
                            </label>
                            <button class="px-3 py-1.5 bg-gray-800 text-white text-xs font-semibold rounded-md hover:bg-gray-700">Salva</button>
                        </form>
                        <form method="POST" action="{{ route('admin.categorie-enti.destroy', $c) }}"
                              onsubmit="return confirm(@js('Eliminare la categoria "'.$c->nome.'"?'))">
                            @csrf @method('DELETE')
                            <button class="text-xs text-red-500 hover:text-red-700">Elimina</button>
                        </form>
                        <span class="text-xs text-gray-400">{{ $c->istituti_count }} enti</span>
                    </div>
                @endforeach

                <form method="POST" action="{{ route('admin.categorie-enti.store') }}" class="flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3">
                    @csrf
                    <input type="text" name="nome" required maxlength="100" placeholder="Nuova categoria" aria-label="Nome nuova categoria"
                           class="border-gray-300 rounded-md shadow-sm text-sm w-56">
                    <button class="px-3 py-1.5 bg-blue-600 text-white text-xs font-semibold rounded-md hover:bg-blue-700">Aggiungi categoria</button>
                </form>
                <x-input-error :messages="$errors->get('nome')" class="mt-1" />
                <x-input-error :messages="$errors->get('ordine')" class="mt-1" />
            </div>
        </details>
    </div>
</x-app-layout>
