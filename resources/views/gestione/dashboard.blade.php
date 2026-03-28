<x-app-layout>
    <x-slot name="header">Gestione Segnalazioni</x-slot>
    <x-slot name="actions">
        <a href="{{ route('gestione.stampa', array_filter(['tab' => $tab, 'q' => $q, 'id_tipologia' => $idTipologia, 'id_provenienza' => $idProvenienza])) }}"
           target="_blank"
           class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 transition">
            Stampa lista
        </a>
        <a href="{{ route('segnalazioni.create') }}"
           class="inline-flex items-center px-3 py-1.5 bg-blue-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition">
            + Nuova
        </a>
    </x-slot>

    {{-- KPI cards --}}
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-5">
        @foreach([
            ['tab' => 'aperte',      'label' => 'Aperte',      'color' => 'text-blue-600',   'bg' => 'bg-blue-50'],
            ['tab' => 'in_carico',   'label' => 'In carico',   'color' => 'text-indigo-600', 'bg' => 'bg-indigo-50'],
            ['tab' => 'in_gestione', 'label' => 'In gestione', 'color' => 'text-purple-600', 'bg' => 'bg-purple-50'],
            ['tab' => 'evidenza',    'label' => 'In evidenza', 'color' => 'text-yellow-600', 'bg' => 'bg-yellow-50'],
            ['tab' => 'chiuse',      'label' => 'Chiuse',      'color' => 'text-green-600',  'bg' => 'bg-green-50'],
        ] as $card)
            <a href="{{ route('gestione.dashboard', ['tab' => $card['tab']]) }}"
               class="{{ $tab === $card['tab'] ? 'ring-2 ring-blue-400' : '' }}
                      {{ $card['bg'] }} rounded-xl p-4 text-center hover:shadow-sm transition">
                <div class="text-2xl font-bold {{ $card['color'] }}">{{ $conteggi[$card['tab']] }}</div>
                <div class="text-xs text-gray-500 mt-0.5 uppercase tracking-wide">{{ $card['label'] }}</div>
            </a>
        @endforeach
    </div>

    {{-- Ricerca e filtri --}}
    <form method="GET" action="{{ route('gestione.dashboard') }}"
          class="bg-white shadow-sm rounded-xl px-4 py-3 mb-4 flex flex-wrap gap-2 items-end">
        <input type="hidden" name="tab" value="{{ $tab }}">

        <div class="flex-1 min-w-[180px]">
            <label class="block text-xs text-gray-500 mb-1">Cerca</label>
            <input type="text" name="q" value="{{ $q }}"
                   placeholder="Testo, segnalante, n° segnalazione…"
                   class="block w-full border-gray-300 rounded-md shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500">
        </div>

        <div class="min-w-[160px]">
            <label class="block text-xs text-gray-500 mb-1">Tipologia</label>
            <select name="id_tipologia"
                    class="block w-full border-gray-300 rounded-md shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500">
                <option value="">— Tutte —</option>
                @foreach($tipologie as $t)
                    <option value="{{ $t->id_tipologia_segnalazione }}"
                        {{ $idTipologia == $t->id_tipologia_segnalazione ? 'selected' : '' }}>
                        {{ $t->descrizione }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="min-w-[160px]">
            <label class="block text-xs text-gray-500 mb-1">Provenienza</label>
            <select name="id_provenienza"
                    class="block w-full border-gray-300 rounded-md shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500">
                <option value="">— Tutte —</option>
                @foreach($provenienze as $p)
                    <option value="{{ $p->id_provenienza }}"
                        {{ $idProvenienza == $p->id_provenienza ? 'selected' : '' }}>
                        {{ $p->descrizione }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="flex gap-2">
            <button type="submit"
                    class="px-4 py-2 bg-blue-600 text-white text-xs font-semibold rounded-md hover:bg-blue-700 transition">
                Cerca
            </button>
            @if($q || $idTipologia || $idProvenienza)
                <a href="{{ route('gestione.dashboard', ['tab' => $tab]) }}"
                   class="px-4 py-2 bg-gray-100 text-gray-600 text-xs font-semibold rounded-md hover:bg-gray-200 transition">
                    Reset
                </a>
            @endif
        </div>
    </form>

    {{-- Tab strip --}}
    <div class="border-b border-gray-200 mb-4">
        <nav class="-mb-px flex space-x-5">
            @foreach([
                'aperte'      => 'Aperte',
                'in_carico'   => 'In carico',
                'in_gestione' => 'In gestione',
                'evidenza'    => 'In evidenza',
                'chiuse'      => 'Chiuse',
            ] as $key => $label)
                <a href="{{ route('gestione.dashboard', array_filter(['tab' => $key, 'q' => $q, 'id_tipologia' => $idTipologia, 'id_provenienza' => $idProvenienza])) }}"
                   class="{{ $tab === $key
                       ? 'border-blue-500 text-blue-600'
                       : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}
                       whitespace-nowrap pb-3 px-1 border-b-2 font-medium text-sm">
                    {{ $label }}
                </a>
            @endforeach
        </nav>
    </div>

    {{-- Table --}}
    <div class="bg-white shadow-sm rounded-xl overflow-hidden">
        @if($segnalazioni->isEmpty())
            <div class="p-10 text-center text-gray-400 text-sm">Nessuna segnalazione in questa sezione.</div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-100 text-sm">
                    <thead class="bg-gray-50 text-xs font-medium text-gray-500 uppercase tracking-wider">
                        <tr>
                            <th class="px-3 py-3 text-left w-8"></th>
                            <th class="px-3 py-3 text-left w-10">#</th>
                            <th class="px-3 py-3 text-left">Tipologia</th>
                            <th class="px-3 py-3 text-left">Descrizione</th>
                            <th class="px-3 py-3 text-left">Data</th>
                            <th class="px-3 py-3 text-left">Provenienza</th>
                            <th class="px-3 py-3 text-left">Operatore</th>
                            <th class="px-3 py-3 text-left">Stato</th>
                            <th class="px-3 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach($segnalazioni as $s)
                            <tr class="hover:bg-gray-50 transition-colors {{ $s->flag_evidenza ? 'bg-yellow-50/40' : '' }}">
                                {{-- Toggle evidenza --}}
                                <td class="px-3 py-3 text-center">
                                    @can('update', $s)
                                        <form method="POST"
                                              action="{{ route('segnalazioni.evidenza', $s->id_segnalazione) }}">
                                            @csrf
                                            <button type="submit"
                                                    title="{{ $s->flag_evidenza ? 'Rimuovi da evidenza' : 'Metti in evidenza' }}"
                                                    class="{{ $s->flag_evidenza ? 'text-yellow-400 hover:text-gray-300' : 'text-gray-200 hover:text-yellow-400' }} text-base transition leading-none">
                                                ★
                                            </button>
                                        </form>
                                    @else
                                        @if($s->flag_evidenza)
                                            <span class="text-yellow-400 text-base leading-none">★</span>
                                        @endif
                                    @endcan
                                </td>
                                <td class="px-3 py-3 text-gray-400 font-mono text-xs">{{ $s->id_segnalazione }}</td>
                                <td class="px-3 py-3 text-gray-600 max-w-[120px] truncate">{{ $s->tipologia?->descrizione ?? '—' }}</td>
                                <td class="px-3 py-3 text-gray-800 max-w-xs truncate font-medium">{{ Str::limit($s->testo_segnalazione, 70) }}</td>
                                <td class="px-3 py-3 text-gray-400 whitespace-nowrap text-xs">{{ $s->data_segnalazione?->format('d/m/Y') }}</td>
                                <td class="px-3 py-3 text-gray-500 text-xs">{{ $s->provenienza?->descrizione ?? '—' }}</td>
                                <td class="px-3 py-3 text-gray-500 text-xs">{{ $s->operatore?->name ?? '—' }}</td>
                                <td class="px-3 py-3">
                                    @if($s->stato)
                                        <span class="{{ $s->stato->badgeClass() }} inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium">
                                            {{ $s->stato->descrizione }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-right">
                                    <a href="{{ route('segnalazioni.show', $s->id_segnalazione) }}"
                                       class="text-blue-600 hover:text-blue-800 text-xs font-medium">
                                        Apri &rarr;
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($segnalazioni->hasPages())
                <div class="px-4 py-3 border-t border-gray-100">
                    {{ $segnalazioni->withQueryString()->links() }}
                </div>
            @endif
        @endif
    </div>
</x-app-layout>
