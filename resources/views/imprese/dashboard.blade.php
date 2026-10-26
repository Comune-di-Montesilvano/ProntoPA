<x-app-layout>
    <x-slot name="header">I miei lavori</x-slot>
    <x-slot name="actions">
        <x-pa.btn :href="route('imprese.reports.riepilogo')" target="_blank">Riepilogo</x-pa.btn>
        <x-pa.btn variant="primary" :href="route('segnalazioni.create')">
            <i class="fas fa-plus" aria-hidden="true"></i> <span class="sm:hidden">Nuova</span><span class="hidden sm:inline">Nuova segnalazione</span>
        </x-pa.btn>
    </x-slot>

    <div class="flex flex-col gap-4">
        {{-- KPI --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach([
                ['valore' => $kpi['appalti_attivi'], 'testo' => 'appalti attivi'],
                ['valore' => $kpi['segnalazioni_aperte'], 'testo' => 'lavori da fare'],
                ['valore' => '€ '.number_format($kpi['importo_affidato'], 0, ',', '.'), 'testo' => 'importo affidato'],
                ['valore' => '€ '.number_format($kpi['importo_liquidato'], 0, ',', '.'), 'testo' => 'importo liquidato'],
            ] as $k)
                <div class="pa-card px-4 py-3">
                    <div class="text-xl font-bold text-[var(--slate-900)]">{{ $k['valore'] }}</div>
                    <div class="text-sm text-[var(--slate-600)]">{{ $k['testo'] }}</div>
                </div>
            @endforeach
        </div>

        {{-- Viste con conteggio --}}
        <nav aria-label="Viste" class="flex gap-1 overflow-x-auto border-b border-[var(--slate-200)] pb-2">
            @foreach(['lavorazione' => 'Da fare', 'preventivo' => 'Preventivi', 'completate' => 'Chiuse'] as $chiave => $etichetta)
                <a href="{{ route('imprese.dashboard', array_filter(['tab' => $chiave, 'data_da' => $dataDa, 'data_a' => $dataA])) }}"
                   class="pa-vista {{ $tab === $chiave ? 'attiva' : '' }}" @if($tab === $chiave) aria-current="page" @endif>
                    {{ $etichetta }} <span class="pa-vista-conteggio">{{ $conteggi[$chiave] }}</span>
                </a>
            @endforeach
        </nav>

        {{-- Periodo --}}
        <form method="GET" action="{{ route('imprese.dashboard') }}" class="flex flex-wrap items-end gap-2">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <div class="pa-field">
                <label for="filtro_data_da" class="pa-field-label">Segnalate dal</label>
                <input type="date" name="data_da" id="filtro_data_da" value="{{ $dataDa }}" class="pa-input">
            </div>
            <div class="pa-field">
                <label for="filtro_data_a" class="pa-field-label">Al</label>
                <input type="date" name="data_a" id="filtro_data_a" value="{{ $dataA }}" class="pa-input">
            </div>
            <x-pa.btn type="submit">Filtra</x-pa.btn>
            @if($dataDa || $dataA)
                <a href="{{ route('imprese.dashboard', ['tab' => $tab]) }}" class="text-sm font-semibold text-[var(--ente-primary)]">Azzera</a>
            @endif
        </form>

        <div class="pa-card overflow-hidden">
            @if($segnalazioni->isEmpty())
                <p class="m-0 p-10 text-center text-sm text-[var(--slate-500)]">Nessun lavoro in questa vista.</p>
            @else
                <table class="pa-elenco">
                    <thead>
                        <tr>
                            <th scope="col">Priorità</th>
                            <th scope="col">Segnalazione</th>
                            <th scope="col">Entro</th>
                            <th scope="col">Stato</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($segnalazioni as $s)
                            @include('segnalazioni._riga', ['colonne' => ['priorita', 'segnalazione', 'scadenza', 'stato'], 'lettura' => 'lavoro'])
                        @endforeach
                    </tbody>
                </table>
                @if($segnalazioni->hasPages())
                    <div class="border-t border-[var(--slate-100)] px-4 py-3">{{ $segnalazioni->withQueryString()->links() }}</div>
                @endif
            @endif
        </div>

    {{-- Appalti attivi (sidebar collassabile) --}}
    @if($appaltiAttivi->isNotEmpty())
    <div class="pa-card p-4" x-data="{ open: false }">
        <button @click="open = !open"
                class="flex items-center justify-between w-full text-sm font-semibold text-gray-700">
            <span>Appalti attivi ({{ $appaltiAttivi->count() }})</span>
            <i class="fas text-xs text-[var(--slate-400)]" :class="open ? 'fa-chevron-up' : 'fa-chevron-down'" aria-hidden="true"></i>
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
    </div>
</x-app-layout>
