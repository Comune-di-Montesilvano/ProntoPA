<x-app-layout>
    <x-slot name="header">Organizzazioni</x-slot>
    <x-slot name="actions">
        <a href="{{ route('admin.organizzazioni.create') }}"
           class="inline-flex items-center px-3 py-1.5 bg-blue-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition">
            + Nuova
        </a>
    </x-slot>

    <div class="bg-white shadow-sm rounded-xl overflow-hidden">
        @if($organizzazioni->isEmpty())
            <div class="p-10 text-center text-gray-400 text-sm">Nessuna organizzazione. Creane una con il pulsante in alto a destra.</div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-100 text-sm">
                    <thead class="bg-gray-50 text-xs font-medium text-gray-500 uppercase tracking-wider">
                        <tr>
                            <th class="px-4 py-3 text-left">Descrizione</th>
                            <th class="px-4 py-3 text-left">Tipo</th>
                            <th class="px-4 py-3 text-left">Dirigente / Ref.</th>
                            <th class="px-4 py-3 text-left">Email</th>
                            <th class="px-4 py-3 text-center w-16">Sedi</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach($organizzazioni as $org)
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-3 font-medium text-gray-800">{{ $org->descrizione }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-50 text-blue-700">
                                        {{ $org->tipo ?? '—' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-gray-500">{{ $org->dirigente ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ $org->email ?? '—' }}</td>
                                <td class="px-4 py-3 text-center">
                                    <a href="{{ route('admin.sedi.index', ['id_istituto' => $org->id_istituto]) }}"
                                       class="text-blue-600 hover:underline font-medium">
                                        {{ $org->plessi_count }}
                                    </a>
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <a href="{{ route('admin.sedi.create', ['id_istituto' => $org->id_istituto]) }}"
                                       class="text-gray-400 hover:text-blue-600 text-xs mr-3">+ Sede</a>
                                    <a href="{{ route('admin.organizzazioni.edit', $org->id_istituto) }}"
                                       class="text-blue-600 hover:text-blue-800 text-xs font-medium mr-3">Modifica</a>
                                    <form method="POST"
                                          action="{{ route('admin.organizzazioni.destroy', $org->id_istituto) }}"
                                          class="inline"
                                          onsubmit="return confirm('Eliminare {{ addslashes($org->descrizione) }}?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-red-400 hover:text-red-600 text-xs">Elimina</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($organizzazioni->hasPages())
                <div class="px-4 py-3 border-t border-gray-100">
                    {{ $organizzazioni->links() }}
                </div>
            @endif
        @endif
    </div>
</x-app-layout>
