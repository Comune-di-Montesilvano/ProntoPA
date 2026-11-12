<x-app-layout>
    <x-slot name="header">{{ $ente->descrizione }}</x-slot>
    <x-slot name="actions">
        <a href="{{ route('admin.enti.index') }}"
           class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 transition">
            Torna a Enti e sedi
        </a>
    </x-slot>

    <div class="space-y-6">
        <div class="bg-white shadow-sm rounded-xl">
            <form method="POST" action="{{ route('admin.enti.update', $ente) }}" class="p-6 space-y-5">
                @csrf @method('PATCH')
                @include('admin.enti._form')

                <div class="flex justify-end gap-3 pt-2 border-t border-gray-100">
                    <button type="submit"
                            class="inline-flex items-center px-5 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-sm text-white uppercase tracking-widest hover:bg-blue-700 transition">
                        Salva modifiche
                    </button>
                </div>
            </form>
        </div>

        <div class="bg-white shadow-sm rounded-xl p-6 space-y-4">
            <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wider">Sedi ({{ $ente->plessi->count() }})</h2>

            @if($ente->plessi->isEmpty())
                <p class="text-sm text-gray-500">Nessuna sede. Aggiungine una qui sotto.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100 text-sm">
                        <thead class="text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <tr>
                                <th class="py-2 text-left">Nome</th>
                                <th class="py-2 text-left">Indirizzo</th>
                                <th class="py-2 text-left">Referente</th>
                                <th class="py-2 text-left">Contatti</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @foreach($ente->plessi as $sede)
                                <tr>
                                    <td class="py-2 font-medium text-gray-800">{{ $sede->nome }}
                                        @if($sede->isMiur())<span class="ml-1 text-xs px-1.5 py-0.5 rounded bg-blue-50 text-blue-700 font-normal">MIUR</span>@endif
                                    </td>
                                    <td class="py-2 text-gray-500">{{ $sede->indirizzo ?? '—' }}</td>
                                    <td class="py-2 text-gray-500">{{ $sede->referente ?? '—' }}</td>
                                    <td class="py-2 text-gray-400 text-xs">
                                        @if($sede->email)<div>{{ $sede->email }}</div>@endif
                                        @if($sede->recapiti)<div>{{ $sede->recapiti }}</div>@endif
                                        @if(! $sede->email && ! $sede->recapiti) — @endif
                                    </td>
                                    <td class="py-2 text-right whitespace-nowrap">
                                        <a href="{{ route('admin.sedi.edit', $sede->id_plesso) }}"
                                           class="text-blue-600 hover:text-blue-800 text-xs font-medium mr-3">Modifica</a>
                                        <form method="POST" action="{{ route('admin.sedi.destroy', $sede->id_plesso) }}" class="inline"
                                              onsubmit="return confirm(@js('Eliminare "'.$sede->nome.'"?'))">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="text-red-400 hover:text-red-600 text-xs">Elimina</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.enti.sedi.store', $ente) }}" class="border-t border-gray-100 pt-4 space-y-3">
                @csrf
                <h3 class="text-sm font-semibold text-gray-700">Aggiungi sede</h3>
                <p class="text-xs text-gray-500">Referente, email e recapiti si completano poi da "Modifica".</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <x-input-label for="sede_nome" value="Nome sede *" />
                        <x-text-input id="sede_nome" name="nome" type="text" class="mt-1 block w-full" :value="old('nome')" required maxlength="255" />
                        <x-input-error :messages="$errors->sede->get('nome')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="sede_indirizzo" value="Indirizzo" />
                        <x-text-input id="sede_indirizzo" name="indirizzo" type="text" class="mt-1 block w-full" :value="old('indirizzo')" maxlength="255" />
                        <x-input-error :messages="$errors->sede->get('indirizzo')" class="mt-1" />
                    </div>
                </div>
                <button type="submit"
                        class="inline-flex items-center px-4 py-2 bg-blue-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition">
                    Aggiungi sede
                </button>
            </form>
        </div>

        @if($ente->plessi->isEmpty())
            <div class="bg-white shadow-sm rounded-xl p-6">
                <form method="POST" action="{{ route('admin.enti.destroy', $ente) }}"
                      onsubmit="return confirm(@js('Eliminare "'.$ente->descrizione.'"?'))">
                    @csrf @method('DELETE')
                    <button type="submit" class="text-sm text-red-600 hover:text-red-800">Elimina questo ente</button>
                </form>
            </div>
        @endif
    </div>
</x-app-layout>
