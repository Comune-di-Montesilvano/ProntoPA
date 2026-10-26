<x-app-layout>
    <x-slot name="header">I miei lavori</x-slot>
    <x-slot name="actions">
        <x-pa.btn :href="route('operaio.statistiche')">Statistiche</x-pa.btn>
    </x-slot>

    @php
        $caposquadra = auth()->user()->isCaposquadra();
        $viste = ['assegnate' => 'Assegnate'] + ($caposquadra ? ['squadra' => 'Squadra'] : []) + ['chiuse' => 'Chiuse ultimo mese'];
        $membri = $tab === 'squadra'
            ? auth()->user()->squadreGuidate()->where('attiva', true)->with('membri')->get()->flatMap->membri->unique('id')
            : collect();
        $colonne = $tab === 'squadra'
            ? ['priorita', 'segnalazione', 'scadenza', 'affida']
            : ['priorita', 'segnalazione', 'scadenza', 'stato'];
    @endphp

    <div class="flex flex-col gap-4">
        <div class="grid grid-cols-3 gap-3">
            @foreach([
                ['valore' => $kpi['aperte'], 'testo' => 'assegnate'],
                ['valore' => $kpi['chiuse_mese'], 'testo' => 'chiuse nel mese'],
                ['valore' => $kpi['tempo_medio_gg'], 'testo' => 'giorni medi'],
            ] as $k)
                <div class="pa-card px-4 py-3">
                    <div class="text-xl font-bold text-[var(--slate-900)]">{{ $k['valore'] }}</div>
                    <div class="text-sm text-[var(--slate-600)]">{{ $k['testo'] }}</div>
                </div>
            @endforeach
        </div>

        <nav aria-label="Viste" class="flex gap-1 overflow-x-auto border-b border-[var(--slate-200)] pb-2">
            @foreach($viste as $chiave => $etichetta)
                <a href="{{ route('operaio.dashboard', ['tab' => $chiave]) }}"
                   class="pa-vista {{ $tab === $chiave ? 'attiva' : '' }}" @if($tab === $chiave) aria-current="page" @endif>{{ $etichetta }}</a>
            @endforeach
        </nav>

        @if($tab === 'assegnate')
            <div id="mappa-operaio" class="h-48 w-full overflow-hidden rounded-[var(--radius-md)] border border-[var(--slate-200)]"></div>
        @endif

        <div class="pa-card overflow-hidden">
            @if($segnalazioni->isEmpty())
                <p class="m-0 p-10 text-center text-sm text-[var(--slate-500)]">
                    {{ match ($tab) { 'chiuse' => "Nessun lavoro chiuso nell'ultimo mese.", 'squadra' => 'Nessun lavoro della squadra.', default => 'Nessun lavoro assegnato.' } }}
                </p>
            @else
                <table class="pa-elenco">
                    <thead>
                        <tr>
                            <th scope="col">Priorità</th>
                            <th scope="col">Segnalazione</th>
                            <th scope="col">Entro</th>
                            <th scope="col">{{ $tab === 'squadra' ? 'Affidata a' : 'Stato' }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($segnalazioni as $s)
                            @include('segnalazioni._riga', ['colonne' => $colonne, 'lettura' => 'lavoro', 'membri' => $membri])
                        @endforeach
                    </tbody>
                </table>
                @if($segnalazioni->hasPages())
                    <div class="border-t border-[var(--slate-100)] px-4 py-3">{{ $segnalazioni->withQueryString()->links() }}</div>
                @endif
            @endif
        </div>
    </div>

    @push('head')
        <link rel="stylesheet" href="{{ asset('build/vendor/leaflet/leaflet.css') }}?v={{ config('app.version') }}" />
    @endpush
    @if($tab === 'assegnate')
    @push('scripts')
        <script src="{{ asset('build/vendor/leaflet/leaflet.js') }}?v={{ config('app.version') }}"></script>
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
