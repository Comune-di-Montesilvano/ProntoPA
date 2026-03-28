<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Gestione Segnalazioni
            </h2>
            <a href="{{ route('segnalazioni.create') }}"
               class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition">
                + Nuova segnalazione
            </a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            {{-- Tab navigation --}}
            <div class="border-b border-gray-200 mb-4">
                <nav class="-mb-px flex space-x-6" aria-label="Tabs">
                    @foreach([
                        'aperte'      => 'Aperte',
                        'in_carico'   => 'In carico',
                        'in_gestione' => 'In gestione',
                        'evidenza'    => 'In evidenza',
                        'chiuse'      => 'Chiuse',
                    ] as $key => $label)
                        <a href="{{ request()->fullUrlWithQuery(['tab' => $key]) }}"
                           class="{{ $tab === $key
                               ? 'border-blue-500 text-blue-600'
                               : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}
                               whitespace-nowrap pb-3 px-1 border-b-2 font-medium text-sm inline-flex items-center gap-1.5">
                            {{ $label }}
                            @if($conteggi[$key] > 0)
                                <span class="{{ $tab === $key ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-600' }}
                                    inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full text-xs font-semibold">
                                    {{ $conteggi[$key] }}
                                </span>
                            @endif
                        </a>
                    @endforeach
                </nav>
            </div>

            {{-- Table --}}
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                @if($segnalazioni->isEmpty())
                    <div class="p-8 text-center text-gray-400 text-sm">
                        Nessuna segnalazione in questa sezione.
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-12">#</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tipologia</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Segnalazione</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Data</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Provenienza</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Operatore</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Stato</th>
                                    <th class="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-100">
                                @foreach($segnalazioni as $s)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 text-gray-400 font-mono">
                                            {{ $s->id_segnalazione }}
                                            @if($s->flag_evidenza)
                                                <span class="text-yellow-500" title="In evidenza">&#9733;</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-gray-700">
                                            {{ $s->tipologia?->descrizione ?? '—' }}
                                        </td>
                                        <td class="px-4 py-3 text-gray-900 max-w-xs truncate">
                                            {{ Str::limit($s->testo_segnalazione, 80) }}
                                        </td>
                                        <td class="px-4 py-3 text-gray-500 whitespace-nowrap">
                                            {{ $s->data_segnalazione?->format('d/m/Y') }}
                                        </td>
                                        <td class="px-4 py-3 text-gray-500">
                                            {{ $s->provenienza?->descrizione ?? '—' }}
                                        </td>
                                        <td class="px-4 py-3 text-gray-500">
                                            {{ $s->operatore?->name ?? '—' }}
                                        </td>
                                        <td class="px-4 py-3">
                                            @if($s->stato)
                                                <span class="{{ $s->stato->badgeClass() }} inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium">
                                                    {{ $s->stato->descrizione }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <a href="{{ route('segnalazioni.show', $s->id_segnalazione) }}"
                                               class="text-blue-600 hover:text-blue-800 font-medium text-xs">
                                                Dettaglio &rarr;
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- Pagination --}}
                    @if($segnalazioni->hasPages())
                        <div class="px-4 py-3 border-t border-gray-100">
                            {{ $segnalazioni->withQueryString()->links() }}
                        </div>
                    @endif
                @endif
            </div>

        </div>
    </div>
</x-app-layout>
