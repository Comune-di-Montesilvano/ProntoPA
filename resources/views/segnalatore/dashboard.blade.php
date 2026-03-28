<x-app-layout>
    <x-slot name="header">Le mie segnalazioni</x-slot>
    <x-slot name="actions">
        <a href="{{ route('segnalazioni.create') }}"
           class="inline-flex items-center px-3 py-1.5 bg-blue-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition">
            + Nuova segnalazione
        </a>
    </x-slot>

    <div class="space-y-4">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                @if($segnalazioni->isEmpty())
                    <div class="p-10 text-center">
                        <p class="text-gray-400 text-sm mb-3">Non hai ancora inviato nessuna segnalazione.</p>
                        <a href="{{ route('segnalazioni.create') }}"
                           class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition">
                            Inserisci la prima segnalazione
                        </a>
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
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Stato</th>
                                    <th class="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-100">
                                @foreach($segnalazioni as $s)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 text-gray-400 font-mono">{{ $s->id_segnalazione }}</td>
                                        <td class="px-4 py-3 text-gray-700">{{ $s->tipologia?->descrizione ?? '—' }}</td>
                                        <td class="px-4 py-3 text-gray-900 max-w-xs truncate">
                                            {{ Str::limit($s->testo_segnalazione, 80) }}
                                        </td>
                                        <td class="px-4 py-3 text-gray-500 whitespace-nowrap">
                                            {{ $s->data_segnalazione?->format('d/m/Y') }}
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

                    @if($segnalazioni->hasPages())
                        <div class="px-4 py-3 border-t border-gray-100">
                            {{ $segnalazioni->links() }}
                        </div>
                    @endif
                @endif
            </div>
    </div>
</x-app-layout>
