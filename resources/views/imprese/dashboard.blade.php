<x-app-layout>
    <x-slot name="header">I miei lavori</x-slot>
    <x-slot name="actions">
        <a href="{{ route('segnalazioni.create') }}"
           class="inline-flex items-center px-3 py-1.5 bg-blue-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition">
            + Segnalazione
        </a>
    </x-slot>

    {{-- KPI --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
        <div class="bg-blue-50 rounded-xl p-4 text-center">
            <div class="text-2xl font-bold text-blue-600">{{ $kpi['appalti_attivi'] }}</div>
            <div class="text-xs text-gray-500 mt-0.5 uppercase tracking-wide">Appalti attivi</div>
        </div>
        <div class="bg-orange-50 rounded-xl p-4 text-center">
            <div class="text-2xl font-bold text-orange-600">{{ $kpi['segnalazioni_aperte'] }}</div>
            <div class="text-xs text-gray-500 mt-0.5 uppercase tracking-wide">Interventi aperti</div>
        </div>
        <div class="bg-purple-50 rounded-xl p-4 text-center">
            <div class="text-lg font-bold text-purple-600">€ {{ number_format($kpi['importo_affidato'], 0, ',', '.') }}</div>
            <div class="text-xs text-gray-500 mt-0.5 uppercase tracking-wide">Importo affidato</div>
        </div>
        <div class="bg-green-50 rounded-xl p-4 text-center">
            <div class="text-lg font-bold text-green-600">€ {{ number_format($kpi['importo_liquidato'], 0, ',', '.') }}</div>
            <div class="text-xs text-gray-500 mt-0.5 uppercase tracking-wide">Importo liquidato</div>
        </div>
    </div>

    {{-- Filtri --}}
    <form method="GET" action="{{ route('imprese.dashboard') }}"
          class="bg-white shadow-sm rounded-xl px-4 py-3 mb-4 flex flex-wrap gap-2 items-end">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <div class="min-w-[120px]">
            <label class="block text-xs text-gray-500 mb-1">Dal</label>
            <input type="date" name="data_da" value="{{ $dataDa }}"
                   class="block w-full border-gray-300 rounded-md shadow-sm text-sm">
        </div>
        <div class="min-w-[120px]">
            <label class="block text-xs text-gray-500 mb-1">Al</label>
            <input type="date" name="data_a" value="{{ $dataA }}"
                   class="block w-full border-gray-300 rounded-md shadow-sm text-sm">
        </div>
        <button type="submit"
                class="px-4 py-2 bg-blue-600 text-white text-xs font-semibold rounded-md hover:bg-blue-700 transition">
            Filtra
        </button>
        @if($dataDa || $dataA)
            <a href="{{ route('imprese.dashboard', ['tab' => $tab]) }}"
               class="px-4 py-2 bg-gray-100 text-gray-600 text-xs font-semibold rounded-md hover:bg-gray-200 transition">
                Reset
            </a>
        @endif
    </form>

    {{-- Tab strip --}}
    <div class="border-b border-gray-200 mb-4">
        <nav class="-mb-px flex space-x-5">
            @foreach(['lavorazione' => 'In lavorazione', 'preventivo' => 'Da preventivare', 'completate' => 'Completate'] as $key => $label)
                <a href="{{ route('imprese.dashboard', array_filter(['tab' => $key, 'data_da' => $dataDa, 'data_a' => $dataA])) }}"
                   class="{{ $tab === $key ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}
                          whitespace-nowrap pb-3 px-1 border-b-2 font-medium text-sm">
                    {{ $label }}
                </a>
            @endforeach
        </nav>
    </div>

    {{-- Lista segnalazioni --}}
    <div class="space-y-3">
        @forelse($segnalazioni as $s)
            <div class="bg-white shadow-sm rounded-xl p-4 flex flex-col sm:flex-row sm:items-start gap-4">
                {{-- Priorità badge --}}
                <div class="flex-shrink-0 w-16 text-center hidden sm:block">
                    <span class="inline-flex items-center px-2 py-1 rounded-lg text-xs font-bold {{ $s->badge_priorita_class }}">
                        {{ $s->label_priorita }}
                    </span>
                    @if($s->segnalazione_urgente)
                        <div class="mt-1 text-red-500 text-xs font-bold">Urgente</div>
                    @endif
                </div>

                {{-- Info --}}
                <div class="flex-1 min-w-0">
                    <div class="flex items-start gap-2 flex-wrap">
                        <span class="text-xs text-gray-400 font-mono">#{{ $s->id_segnalazione }}</span>
                        <span class="text-sm font-semibold text-gray-800 truncate">{{ $s->tipologia?->descrizione ?? '—' }}</span>
                        @if($s->stato)
                            <span class="{{ $s->stato->badgeClass() }} inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium">
                                {{ $s->stato->descrizione }}
                            </span>
                        @endif
                        @if($s->sla_violato)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold bg-red-100 text-red-700">SLA violato</span>
                        @endif
                    </div>
                    <p class="mt-1 text-sm text-gray-600 line-clamp-2">{{ $s->testo_segnalazione }}</p>
                    <div class="mt-2 flex flex-wrap gap-3 text-xs text-gray-400">
                        @if($s->plesso) <span>📍 {{ $s->plesso->nome }}</span> @endif
                        <span>{{ $s->data_segnalazione?->format('d/m/Y') }}</span>
                        @if($s->appalto) <span>Appalto: {{ $s->appalto->descrizione }}</span> @endif
                    </div>
                </div>

                {{-- Azioni --}}
                <div class="flex flex-col gap-2 flex-shrink-0">
                    <a href="{{ route('segnalazioni.show', $s->id_segnalazione) }}"
                       class="inline-flex items-center justify-center px-3 py-1.5 bg-blue-50 text-blue-700 text-xs font-semibold rounded-md hover:bg-blue-100 transition">
                        Apri →
                    </a>

                    {{-- Upload preventivo --}}
                    @if(!$s->isChiusa())
                    <button type="button"
                            x-data
                            @click="$dispatch('open-upload-{{ $s->id_segnalazione }}')"
                            class="inline-flex items-center justify-center px-3 py-1.5 bg-gray-50 text-gray-700 text-xs font-semibold rounded-md hover:bg-gray-100 transition">
                        Carica doc
                    </button>
                    @endif
                </div>
            </div>

            {{-- Form upload inline (collassato) --}}
            @if(!$s->isChiusa())
            <div x-data="{ open: false }" @open-upload-{{ $s->id_segnalazione }}.window="open = true"
                 x-show="open" x-cloak
                 class="bg-gray-50 border border-gray-200 rounded-xl p-4 -mt-2 ml-20">
                <form method="POST"
                      action="{{ route('segnalazioni.allegati.store', $s->id_segnalazione) }}"
                      enctype="multipart/form-data"
                      class="flex items-end gap-3 flex-wrap">
                    @csrf
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Preventivo / Documento (PDF, JPG, PNG)</label>
                        <input type="file" name="allegati[]"
                               accept="application/pdf,image/jpeg,image/png"
                               class="block text-sm text-gray-500 file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:text-xs file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                    </div>
                    <button type="submit"
                            class="px-4 py-2 bg-blue-600 text-white text-xs font-semibold rounded-md hover:bg-blue-700 transition">
                        Carica
                    </button>
                    <button type="button" @click="open = false"
                            class="px-4 py-2 bg-gray-200 text-gray-600 text-xs font-semibold rounded-md hover:bg-gray-300 transition">
                        Annulla
                    </button>
                </form>
            </div>
            @endif
        @empty
            <div class="bg-white shadow-sm rounded-xl p-10 text-center text-gray-400 text-sm">
                Nessun intervento in questa sezione.
            </div>
        @endforelse
    </div>

    @if($segnalazioni->hasPages())
        <div class="mt-4">{{ $segnalazioni->withQueryString()->links() }}</div>
    @endif

    {{-- Appalti attivi (sidebar collassabile) --}}
    @if($appaltiAttivi->isNotEmpty())
    <div class="mt-6 bg-white shadow-sm rounded-xl p-4" x-data="{ open: false }">
        <button @click="open = !open"
                class="flex items-center justify-between w-full text-sm font-semibold text-gray-700">
            <span>Appalti attivi ({{ $appaltiAttivi->count() }})</span>
            <span x-text="open ? '▲' : '▼'" class="text-gray-400 text-xs"></span>
        </button>
        <div x-show="open" x-cloak class="mt-3 divide-y divide-gray-100">
            @foreach($appaltiAttivi as $a)
                <div class="py-2 flex justify-between items-center text-sm">
                    <div>
                        <span class="font-medium text-gray-800">{{ $a->descrizione }}</span>
                        @if($a->CIG) <span class="ml-2 text-xs text-gray-400">CIG {{ $a->CIG }}</span> @endif
                    </div>
                    <span class="text-gray-500">€ {{ number_format($a->importo_appalto, 0, ',', '.') }}</span>
                </div>
            @endforeach
        </div>
    </div>
    @endif
</x-app-layout>
