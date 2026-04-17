<x-app-layout>
    <x-slot name="header">Gestione Utenti</x-slot>
    <x-slot name="actions">
        <div class="flex items-center gap-3">
            <form method="GET" action="{{ route('admin.utenti.index') }}" class="inline-flex items-center gap-2 text-xs text-gray-600">
                <label for="mostra_disattivati" class="inline-flex items-center gap-2 cursor-pointer whitespace-nowrap">
                    <input id="mostra_disattivati" type="checkbox" name="mostra_disattivati" value="1"
                           class="rounded border-gray-300 text-blue-600 shadow-sm"
                           {{ $mostraDisattivati ? 'checked' : '' }}
                           onchange="this.form.submit()">
                    Mostra disattivati
                </label>
            </form>
            <a href="{{ route('admin.utenti.create') }}"
               class="inline-flex items-center px-3 py-1.5 bg-blue-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition">
                + Nuovo utente
            </a>
        </div>
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
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Stato</th>
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
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if($u->attivo !== false)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Attivo</span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-200 text-gray-700">Disattivato</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <a href="{{ route('admin.utenti.edit', $u->id) }}"
                                   class="text-blue-600 hover:text-blue-800 font-medium text-xs mr-3">Modifica</a>
                                @if($u->id !== auth()->id())
                                    <form method="POST" action="{{ route('admin.utenti.toggle-attivo', $u->id) }}" class="inline"
                                          onsubmit="return confirm('{{ $u->attivo !== false ? 'Disattivare' : 'Riattivare' }} l\'utente {{ addslashes($u->username) }}?')">
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
                            <td colspan="8" class="px-4 py-8 text-center text-gray-400 text-sm">Nessun utente trovato.</td>
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
