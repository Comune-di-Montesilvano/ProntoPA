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
            @if($syncConfigurata && ! $syncInCorso)
                <form method="POST" action="{{ route('admin.utenti.sincronizza') }}" class="inline">
                    @csrf
                    <button type="submit" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-md bg-blue-600 text-white text-xs font-medium hover:bg-blue-700">
                        <span><i class="fas fa-rotate" aria-hidden="true"></i></span> Sincronizza ora
                    </button>
                </form>
            @endif
        </div>
    </x-slot>

    @if($syncConfigurata)
        @php
            $coloreSync = match ($esitoSync?->stato) {
                'ok' => 'border-green-200 bg-green-50 text-green-900',
                'bloccata' => 'border-amber-300 bg-amber-50 text-amber-900',
                'fallita' => 'border-red-200 bg-red-50 text-red-900',
                default => 'border-gray-200 bg-gray-50 text-gray-700',
            };
        @endphp
        <div class="mb-4 px-4 py-3 border rounded-lg text-sm {{ $coloreSync }}">
            <p class="m-0">
                <strong>Sincronizzazione con Active Directory</strong>
                (ogni notte alle 05:30; gli utenti di dominio sono attivati e disattivati solo da AD).
            </p>
            @if($syncInCorso)
                <p class="m-0 mt-1"><strong>Sincronizzazione in corso</strong>: ricarica la pagina tra poco per vedere l'esito.</p>
            @endif
            @if($esitoSync === null)
                <p class="m-0 mt-1">Nessuna sincronizzazione eseguita finora.</p>
            @else
                <p class="m-0 mt-1">
                    Ultimo giro {{ $esitoSync->quando->timezone(config('app.timezone'))->format('d/m/Y H:i') }}:
                    @if($esitoSync->ok())
                        {{ $esitoSync->creati }} creati · {{ $esitoSync->aggiornati }} aggiornati ·
                        {{ $esitoSync->riattivati }} riattivati · {{ $esitoSync->disattivati }} disattivati
                        @if($esitoSync->senzaEmail || $esitoSync->usernameOccupati)
                            · saltati: {{ $esitoSync->senzaEmail }} senza email, {{ $esitoSync->usernameOccupati }} con username già usato
                        @endif
                        @if($esitoSync->errori)
                            · <strong>{{ $esitoSync->errori }} non allineati per errore</strong> (dettagli nel log degli errori; riprova il giro)
                        @endif
                    @else
                        {{ $esitoSync->stato === 'bloccata' ? 'bloccato' : 'non riuscito' }}.
                        {{ $esitoSync->motivo }}
                    @endif
                </p>
                @if($esitoSync->stato === 'bloccata' && ! $syncInCorso)
                    <form method="POST" action="{{ route('admin.utenti.sincronizza') }}" class="mt-2"
                          onsubmit="return confirm('Verranno disattivati {{ $esitoSync->daDisattivare }} utenti di dominio. Continuare?')">
                        @csrf
                        <input type="hidden" name="forza" value="1">
                        <input type="hidden" name="confermati" value="{{ $esitoSync->daDisattivare }}">
                        <button type="submit" class="px-3 py-1.5 rounded-md bg-amber-600 text-white text-xs font-medium hover:bg-amber-700">Sincronizza comunque</button>
                    </form>
                @endif
            @endif
        </div>
    @endif

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
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ente</th>
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
                            <td class="px-4 py-3 text-gray-500">{{ $u->istituto?->descrizione ?? '—' }}</td>
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
                                @if($u->auth_source === 'ldap')
                                    <span class="text-gray-400 text-xs" title="Attivazione decisa dai gruppi di Active Directory">Gestito da AD</span>
                                @elseif($u->id !== auth()->id())
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
                            <td colspan="9" class="px-4 py-8 text-center text-gray-400 text-sm">Nessun utente trovato.</td>
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
