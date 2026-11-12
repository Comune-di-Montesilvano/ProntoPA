<x-app-layout>
    <x-slot name="header">Persone · Ditte (SPID/CIE)</x-slot>

    @include('admin.utenti._filtri', ['rotta' => 'admin.utenti.ditte'])

    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nome</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Codice fiscale</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ditta</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Stato</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-100">
                    @forelse($utenti as $u)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium text-gray-900">{{ $u->name }}</td>
                            <td class="px-4 py-3 text-gray-500 font-mono">{{ $u->codice_fiscale ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-500">
                                @if($u->impresa)
                                    <a href="{{ route('imprese.edit', $u->impresa) }}" class="text-blue-600 hover:text-blue-800">{{ $u->impresa->ragione_sociale }}</a>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if($u->attivo !== false)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Attivo</span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-200 text-gray-700">Disattivato</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-8 text-center text-gray-400 text-sm">Nessuna persona trovata.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($utenti->hasPages())
            <div class="px-4 py-3 border-t border-gray-100">{{ $utenti->links() }}</div>
        @endif
    </div>
</x-app-layout>
