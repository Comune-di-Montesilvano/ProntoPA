<x-app-layout>
    <x-slot name="header">Persone · Scuole (SPID/CIE)</x-slot>

    @include('admin.utenti._filtri', ['rotta' => 'admin.utenti.scuole'])

    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nome</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Codice fiscale</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email verificata</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Scuole delegate</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Stato</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-100">
                    @forelse($utenti as $u)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium text-gray-900">{{ $u->name }}</td>
                            <td class="px-4 py-3 text-gray-500 font-mono">{{ $u->codice_fiscale ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ $u->email_verified_at ? 'Sì' : 'No' }}</td>
                            <td class="px-4 py-3 text-gray-500">
                                @php $scuole = $u->deleghe->map(fn ($d) => $d->istituto?->descrizione)->filter()->unique()->values(); @endphp
                                @forelse($scuole as $scuola)
                                    <div>{{ $scuola }}</div>
                                @empty
                                    —
                                @endforelse
                                @if($u->codice_fiscale)
                                    <a href="{{ route('admin.deleghe.index', ['q' => $u->codice_fiscale]) }}" class="text-blue-600 hover:text-blue-800 text-xs">Vedi deleghe</a>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if($u->attivo !== false)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Attivo</span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-200 text-gray-700">Disattivato</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                @if($u->id !== auth()->id())
                                    <form method="POST" action="{{ route('admin.utenti.toggle-attivo', $u->id) }}" class="inline"
                                          onsubmit="return confirm('{{ $u->attivo !== false ? 'Disattivare' : 'Riattivare' }} {{ addslashes($u->name) }}?')">
                                        @csrf @method('PATCH')
                                        <button type="submit" class="{{ $u->attivo !== false ? 'text-amber-600 hover:text-amber-800' : 'text-green-600 hover:text-green-800' }} font-medium text-xs">
                                            {{ $u->attivo !== false ? 'Disattiva' : 'Riattiva' }}
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-gray-400 text-sm">Nessuna persona trovata.</td>
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
