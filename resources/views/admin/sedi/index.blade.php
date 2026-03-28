<x-app-layout>
    <x-slot name="header">Sedi / Strutture</x-slot>
    <x-slot name="actions">
        <a href="{{ route('admin.sedi.create', $filtroIstituto ? ['id_istituto' => $filtroIstituto] : []) }}"
           class="inline-flex items-center px-3 py-1.5 bg-blue-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition">
            + Nuova
        </a>
    </x-slot>

    {{-- Filtro per organizzazione --}}
    <form method="GET" action="{{ route('admin.sedi.index') }}" class="bg-white shadow-sm rounded-xl px-4 py-3 mb-4 flex flex-wrap gap-2 items-end">
        <div class="flex-1 min-w-[200px]">
            <label class="block text-xs text-gray-500 mb-1">Filtra per organizzazione</label>
            <select name="id_istituto"
                    class="block w-full border-gray-300 rounded-md shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500">
                <option value="">— Tutte —</option>
                @foreach($organizzazioni->groupBy('tipo') as $tipo => $items)
                    <optgroup label="{{ $tipo }}">
                        @foreach($items as $org)
                            <option value="{{ $org->id_istituto }}" {{ $filtroIstituto == $org->id_istituto ? 'selected' : '' }}>
                                {{ $org->descrizione }}
                            </option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </div>
        <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-xs font-semibold rounded-md hover:bg-blue-700 transition">
            Filtra
        </button>
        @if($filtroIstituto)
            <a href="{{ route('admin.sedi.index') }}" class="px-4 py-2 bg-gray-100 text-gray-600 text-xs font-semibold rounded-md hover:bg-gray-200 transition">
                Reset
            </a>
        @endif
    </form>

    <div class="bg-white shadow-sm rounded-xl overflow-hidden">
        @if($sedi->isEmpty())
            <div class="p-10 text-center text-gray-400 text-sm">Nessuna sede trovata.</div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-100 text-sm">
                    <thead class="bg-gray-50 text-xs font-medium text-gray-500 uppercase tracking-wider">
                        <tr>
                            <th class="px-4 py-3 text-left">Organizzazione</th>
                            <th class="px-4 py-3 text-left">Nome sede</th>
                            <th class="px-4 py-3 text-left">Indirizzo</th>
                            <th class="px-4 py-3 text-left">Referente</th>
                            <th class="px-4 py-3 text-left">Contatti</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach($sedi as $sede)
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-3 text-gray-500 text-xs">
                                    {{ $sede->istituto?->descrizione ?? '—' }}
                                    @if($sede->istituto?->tipo)
                                        <span class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded text-xs bg-gray-100 text-gray-500">{{ $sede->istituto->tipo }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 font-medium text-gray-800">{{ $sede->nome }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ $sede->indirizzo ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ $sede->referente ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-400 text-xs">
                                    @if($sede->email) <div>{{ $sede->email }}</div> @endif
                                    @if($sede->recapiti) <div>{{ $sede->recapiti }}</div> @endif
                                    @if(!$sede->email && !$sede->recapiti) — @endif
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <a href="{{ route('admin.sedi.edit', $sede->id_plesso) }}"
                                       class="text-blue-600 hover:text-blue-800 text-xs font-medium mr-3">Modifica</a>
                                    <form method="POST"
                                          action="{{ route('admin.sedi.destroy', $sede->id_plesso) }}"
                                          class="inline"
                                          onsubmit="return confirm('Eliminare {{ addslashes($sede->nome) }}?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-red-400 hover:text-red-600 text-xs">Elimina</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($sedi->hasPages())
                <div class="px-4 py-3 border-t border-gray-100">
                    {{ $sedi->appends(['id_istituto' => $filtroIstituto])->links() }}
                </div>
            @endif
        @endif
    </div>
</x-app-layout>
