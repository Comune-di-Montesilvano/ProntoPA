<x-app-layout>
    <x-slot name="header">Gestione Utenti</x-slot>
    <x-slot name="actions">
        <a href="{{ route('admin.utenti.create') }}"
           class="inline-flex items-center px-3 py-1.5 bg-blue-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition">
            + Nuovo utente
        </a>
    </x-slot>

    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nome</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Username</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ruolo</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Profilo</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ultimo accesso</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-100">
                    @forelse($utenti as $u)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium text-gray-900">{{ $u->name }}</td>
                            <td class="px-4 py-3 text-gray-500 font-mono">{{ $u->username }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ $u->email }}</td>
                            <td class="px-4 py-3">
                                @php $ruolo = $u->getRoleNames()->first() @endphp
                                @if($ruolo === 'admin')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">Admin</span>
                                @elseif($ruolo === 'gestore')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                        Gestore{{ $u->supervisore_segnalazioni ? ' ★' : '' }}
                                    </span>
                                @elseif($ruolo === 'impresa')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">Impresa</span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700">Segnalatore</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-500">{{ $u->profilo?->descrizione ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-400 whitespace-nowrap">
                                {{ $u->last_login?->format('d/m/Y H:i') ?? 'Mai' }}
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <a href="{{ route('admin.utenti.edit', $u->id) }}"
                                   class="text-blue-600 hover:text-blue-800 font-medium text-xs mr-3">Modifica</a>
                                @if($u->id !== auth()->id())
                                    <form method="POST" action="{{ route('admin.utenti.destroy', $u->id) }}" class="inline"
                                          onsubmit="return confirm('Eliminare l\'utente {{ addslashes($u->username) }}?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-red-500 hover:text-red-700 font-medium text-xs">Elimina</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-gray-400 text-sm">Nessun utente trovato.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($utenti->hasPages())
            <div class="px-4 py-3 border-t border-gray-100">
                {{ $utenti->links() }}
            </div>
        @endif
    </div>
</x-app-layout>
