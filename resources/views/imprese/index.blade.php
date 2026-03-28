<x-app-layout>
    <x-slot name="header">Imprese</x-slot>
    <x-slot name="actions">
        <a href="{{ route('imprese.create') }}"
           class="inline-flex items-center px-3 py-1.5 bg-blue-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition">
            + Nuova impresa
        </a>
    </x-slot>

    <div class="space-y-4">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                @if($imprese->isEmpty())
                    <div class="p-8 text-center text-gray-400 text-sm">Nessuna impresa registrata.</div>
                @else
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ragione sociale</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">P.IVA</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Referente</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            @foreach($imprese as $impresa)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 font-medium text-gray-900">{{ $impresa->ragione_sociale }}</td>
                                    <td class="px-4 py-3 text-gray-500 font-mono text-xs">{{ $impresa->partita_iva ?? '—' }}</td>
                                    <td class="px-4 py-3 text-gray-500">{{ $impresa->referente ?? '—' }}</td>
                                    <td class="px-4 py-3 text-gray-500">{{ $impresa->email ?? '—' }}</td>
                                    <td class="px-4 py-3 text-right space-x-3">
                                        <a href="{{ route('imprese.edit', $impresa->id_impresa) }}"
                                           class="text-blue-600 hover:text-blue-800 text-xs font-medium">Modifica</a>
                                        <form method="POST" action="{{ route('imprese.destroy', $impresa->id_impresa) }}" class="inline"
                                              onsubmit="return confirm('Eliminare questa impresa?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="text-red-500 hover:text-red-700 text-xs font-medium">Elimina</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if($imprese->hasPages())
                        <div class="px-4 py-3 border-t border-gray-100">{{ $imprese->links() }}</div>
                    @endif
                @endif
            </div>
    </div>
</x-app-layout>
