<x-app-layout>
    <x-slot name="header">Le mie segnalazioni</x-slot>
    <x-slot name="actions">
        <a href="{{ route('operaio.statistiche') }}"
           class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 transition">
            Statistiche
        </a>
    </x-slot>

    {{-- KPI cards (scrollabili orizzontalmente su mobile) --}}
    <div class="flex gap-3 overflow-x-auto pb-1 mb-5 -mx-4 px-4 sm:mx-0 sm:px-0 sm:grid sm:grid-cols-3">
        <div class="flex-shrink-0 w-36 sm:w-auto bg-blue-50 rounded-xl p-4 text-center">
            <div class="text-2xl font-bold text-blue-600">{{ $kpi['aperte'] }}</div>
            <div class="text-xs text-gray-500 mt-0.5 uppercase tracking-wide">Assegnate</div>
        </div>
        <div class="flex-shrink-0 w-36 sm:w-auto bg-green-50 rounded-xl p-4 text-center">
            <div class="text-2xl font-bold text-green-600">{{ $kpi['chiuse_mese'] }}</div>
            <div class="text-xs text-gray-500 mt-0.5 uppercase tracking-wide">Chiuse mese</div>
        </div>
        <div class="flex-shrink-0 w-36 sm:w-auto bg-purple-50 rounded-xl p-4 text-center">
            <div class="text-2xl font-bold text-purple-600">{{ $kpi['tempo_medio_gg'] }}</div>
            <div class="text-xs text-gray-500 mt-0.5 uppercase tracking-wide">Giorni medi</div>
        </div>
    </div>

    {{-- Tab strip --}}
    <div class="border-b border-gray-200 mb-4">
        <nav class="-mb-px flex space-x-5">
            @foreach(['assegnate' => 'Assegnate', 'chiuse' => 'Chiuse recenti'] as $key => $label)
                <a href="{{ route('operaio.dashboard', ['tab' => $key]) }}"
                   class="{{ $tab === $key ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' }}
                          whitespace-nowrap pb-3 px-1 border-b-2 font-medium text-sm">
                    {{ $label }}
                </a>
            @endforeach
        </nav>
    </div>

    {{-- Mappa (solo tab assegnate, se ci sono coordinate) --}}
    @if($tab === 'assegnate')
    <div id="mappa-operaio" class="h-48 w-full rounded-xl border border-gray-200 mb-4 overflow-hidden"></div>
    @endif

    {{-- Lista card verticali --}}
    <div class="space-y-3">
        @forelse($segnalazioni as $s)
            <div class="bg-white shadow-sm rounded-xl p-4 active:bg-gray-50 transition">
                {{-- Header card --}}
                <div class="flex items-start gap-3">
                    <span class="inline-flex items-center px-2 py-1 rounded-lg text-xs font-bold {{ $s->badge_priorita_class }} mt-0.5 flex-shrink-0">
                        {{ $s->label_priorita }}
                    </span>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-xs text-gray-400 font-mono">#{{ $s->id_segnalazione }}</span>
                            <span class="text-sm font-semibold text-gray-800">{{ $s->tipologia?->descrizione ?? '—' }}</span>
                            @if($s->segnalazione_urgente)
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-bold bg-red-100 text-red-700">Urgente</span>
                            @endif
                        </div>
                        @if($s->plesso)
                            <p class="text-xs text-gray-400 mt-0.5">📍 {{ $s->plesso->nome }}</p>
                        @endif
                    </div>
                    @if($s->stato)
                        <span class="{{ $s->stato->badgeClass() }} inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium flex-shrink-0">
                            {{ $s->stato->descrizione }}
                        </span>
                    @endif
                </div>

                {{-- Testo --}}
                <p class="mt-2 text-sm text-gray-600 line-clamp-2">{{ $s->testo_segnalazione }}</p>

                {{-- Footer --}}
                <div class="mt-3 flex items-center justify-between">
                    <span class="text-xs text-gray-400">{{ $s->data_segnalazione?->format('d/m/Y') }}</span>
                    <a href="{{ route('segnalazioni.show', $s->id_segnalazione) }}"
                       class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-xs font-semibold rounded-lg hover:bg-blue-700 active:bg-blue-800 transition">
                        Apri →
                    </a>
                </div>

                {{-- SLA scadenza se presente --}}
                @if($s->data_scadenza_sla && !$s->isChiusa())
                    <div class="mt-2 text-xs {{ $s->sla_violato ? 'text-red-600 font-bold' : 'text-yellow-600' }}">
                        {{ $s->sla_violato ? '🚨 SLA violato — scaduto il ' : '⏰ Scade: ' }}{{ $s->data_scadenza_sla->format('d/m/Y H:i') }}
                    </div>
                @endif
            </div>
        @empty
            <div class="bg-white shadow-sm rounded-xl p-10 text-center text-gray-400 text-sm">
                @if($tab === 'assegnate')
                    Nessuna segnalazione assegnata.
                @else
                    Nessuna segnalazione chiusa nell'ultimo mese.
                @endif
            </div>
        @endforelse
    </div>

    @if($segnalazioni->hasPages())
        <div class="mt-4">{{ $segnalazioni->withQueryString()->links() }}</div>
    @endif

    @push('head')
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    @endpush
    @if($tab === 'assegnate')
    @push('scripts')
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
        <script>
            const mapEl = document.getElementById('mappa-operaio');
            if (mapEl) {
                const map = L.map('mappa-operaio').setView([
                    {{ \App\Models\Impostazione::get('osm_lat', 42.5098) }},
                    {{ \App\Models\Impostazione::get('osm_lng', 14.1443) }}
                ], {{ \App\Models\Impostazione::get('osm_zoom', 13) }});

                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '© OpenStreetMap', maxZoom: 19
                }).addTo(map);

                fetch('{{ route('operaio.mappa') }}')
                    .then(r => r.json())
                    .then(punti => {
                        punti.forEach(p => {
                            L.marker([p.lat, p.lng])
                                .bindPopup(`<strong>${p.titolo}</strong><br>${p.stato}<br><a href="${p.url}">Apri →</a>`)
                                .addTo(map);
                        });
                        if (punti.length > 0) {
                            const bounds = L.latLngBounds(punti.map(p => [p.lat, p.lng]));
                            map.fitBounds(bounds, { padding: [20, 20] });
                        }
                    });
            }
        </script>
    @endpush
    @endif
</x-app-layout>
