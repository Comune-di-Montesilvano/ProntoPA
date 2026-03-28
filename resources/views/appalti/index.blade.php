<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Appalti</h2>
            <a href="{{ route('appalti.create') }}"
               class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition">
                + Nuovo appalto
            </a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                @if($appalti->isEmpty())
                    <div class="p-8 text-center text-gray-400 text-sm">Nessun appalto registrato.</div>
                @else
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-12">#</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Descrizione</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Gruppo</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Impresa</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">CIG</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Importo</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Stato</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            @foreach($appalti as $appalto)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 text-gray-400 font-mono">{{ $appalto->id_appalto }}</td>
                                    <td class="px-4 py-3 font-medium text-gray-900">{{ $appalto->descrizione }}</td>
                                    <td class="px-4 py-3 text-gray-500">{{ $appalto->gruppo?->descrizione ?? '—' }}</td>
                                    <td class="px-4 py-3 text-gray-500">{{ $appalto->impresa?->ragione_sociale ?? '—' }}</td>
                                    <td class="px-4 py-3 text-gray-500 font-mono text-xs">{{ $appalto->CIG ?? '—' }}</td>
                                    <td class="px-4 py-3 text-gray-700">
                                        {{ $appalto->importo_appalto ? '€ ' . number_format($appalto->importo_appalto, 2, ',', '.') : '—' }}
                                    </td>
                                    <td class="px-4 py-3">
                                        @if($appalto->valido)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">Attivo</span>
                                        @else
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-500">Scaduto</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right space-x-3">
                                        <a href="{{ route('appalti.edit', $appalto->id_appalto) }}"
                                           class="text-blue-600 hover:text-blue-800 text-xs font-medium">Modifica</a>
                                        <form method="POST" action="{{ route('appalti.destroy', $appalto->id_appalto) }}" class="inline"
                                              onsubmit="return confirm('Eliminare questo appalto?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="text-red-500 hover:text-red-700 text-xs font-medium">Elimina</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if($appalti->hasPages())
                        <div class="px-4 py-3 border-t border-gray-100">{{ $appalti->links() }}</div>
                    @endif
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
