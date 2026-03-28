<x-app-layout>
    <x-slot name="header">Profili utente</x-slot>
    <x-slot name="actions">
        <a href="{{ route('admin.profili.create') }}"
           class="inline-flex items-center px-3 py-1.5 bg-blue-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition">
            + Nuovo
        </a>
    </x-slot>

    <div class="bg-white shadow-sm rounded-xl overflow-hidden">
        @if($profili->isEmpty())
            <div class="p-10 text-center text-gray-400 text-sm">Nessun profilo. Creane uno con il pulsante in alto a destra.</div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-100 text-sm">
                    <thead class="bg-gray-50 text-xs font-medium text-gray-500 uppercase tracking-wider">
                        <tr>
                            <th class="px-4 py-3 text-left">Descrizione</th>
                            <th class="px-4 py-3 text-left">Organizzazione vincolata</th>
                            <th class="px-4 py-3 text-left">Limite segnalazioni</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach($profili as $profilo)
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-3 font-medium text-gray-800">{{ $profilo->descrizione }}</td>
                                <td class="px-4 py-3 text-gray-500">
                                    @if($profilo->limita_istituto && $profilo->istituto)
                                        <span class="inline-flex items-center gap-1">
                                            <span class="w-2 h-2 rounded-full bg-green-500 shrink-0"></span>
                                            {{ $profilo->istituto->descrizione }}
                                            <span class="text-xs text-gray-400">({{ $profilo->istituto->tipo }})</span>
                                        </span>
                                    @else
                                        <span class="text-gray-300">Nessun vincolo</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-gray-500 text-xs">
                                    @if(is_null($profilo->limita_segnalazioni) || $profilo->limita_segnalazioni == 0)
                                        <span class="text-gray-300">Nessuno</span>
                                    @elseif($profilo->limita_segnalazioni == 1)
                                        Solo edifici organizzazione
                                    @elseif($profilo->limita_segnalazioni == 3)
                                        Solo tipologia specifica
                                    @else
                                        {{ $profilo->limita_segnalazioni }}
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <a href="{{ route('admin.profili.edit', $profilo->id_profilo) }}"
                                       class="text-blue-600 hover:text-blue-800 text-xs font-medium mr-3">Modifica</a>
                                    <form method="POST"
                                          action="{{ route('admin.profili.destroy', $profilo->id_profilo) }}"
                                          class="inline"
                                          onsubmit="return confirm('Eliminare il profilo {{ addslashes($profilo->descrizione) }}?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-red-400 hover:text-red-600 text-xs">Elimina</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($profili->hasPages())
                <div class="px-4 py-3 border-t border-gray-100">
                    {{ $profili->links() }}
                </div>
            @endif
        @endif
    </div>
</x-app-layout>
