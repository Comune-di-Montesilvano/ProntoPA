<x-app-layout>
    <x-slot name="header">Le mie statistiche</x-slot>
    <x-slot name="actions">
        <a href="{{ route('operaio.dashboard') }}"
           class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 transition">
            ← Dashboard
        </a>
    </x-slot>

    {{-- KPI --}}
    <div class="grid grid-cols-3 gap-3 mb-5">
        <div class="bg-blue-50 rounded-xl p-4 text-center">
            <div class="text-2xl font-bold text-blue-600">{{ $kpi['aperte'] }}</div>
            <div class="text-xs text-gray-500 mt-0.5 uppercase tracking-wide">Assegnate</div>
        </div>
        <div class="bg-green-50 rounded-xl p-4 text-center">
            <div class="text-2xl font-bold text-green-600">{{ $kpi['chiuse_mese'] }}</div>
            <div class="text-xs text-gray-500 mt-0.5 uppercase tracking-wide">Chiuse mese</div>
        </div>
        <div class="bg-purple-50 rounded-xl p-4 text-center">
            <div class="text-2xl font-bold text-purple-600">{{ $kpi['tempo_medio_gg'] }}<span class="text-sm font-normal text-gray-500 ml-1">gg</span></div>
            <div class="text-xs text-gray-500 mt-0.5 uppercase tracking-wide">Tempo medio</div>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
        {{-- Chiuse per mese --}}
        <div class="bg-white shadow-sm rounded-xl p-5">
            <h3 class="text-sm font-semibold text-gray-700 mb-4">Chiuse per mese (ultimi 6)</h3>
            <canvas id="grafico-mesi" height="200"></canvas>
        </div>

        {{-- Per tipologia --}}
        <div class="bg-white shadow-sm rounded-xl p-5">
            <h3 class="text-sm font-semibold text-gray-700 mb-4">Per tipologia</h3>
            <canvas id="grafico-tipologie" height="200"></canvas>
        </div>
    </div>

    @push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
    <script>
        new Chart(document.getElementById('grafico-mesi'), {
            type: 'bar',
            data: {
                labels: @json($perMese->pluck('mese')),
                datasets: [{
                    label: 'Chiuse',
                    data: @json($perMese->pluck('totale')),
                    backgroundColor: 'rgba(59,130,246,0.6)',
                    borderRadius: 4,
                }]
            },
            options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
        });

        new Chart(document.getElementById('grafico-tipologie'), {
            type: 'doughnut',
            data: {
                labels: @json($perTipologia->map(fn($r) => $r->tipologia?->descrizione ?? 'N/D')),
                datasets: [{
                    data: @json($perTipologia->pluck('totale')),
                    backgroundColor: ['#3b82f6','#8b5cf6','#f59e0b','#10b981','#ef4444','#06b6d4','#f97316','#84cc16'],
                }]
            },
            options: { plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } } }
        });
    </script>
    @endpush
</x-app-layout>
