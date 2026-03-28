<x-app-layout>
    <x-slot name="header">Statistiche</x-slot>

    <div class="space-y-6">

            {{-- KPI Cards --}}
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-4">
                @foreach([
                    ['label' => 'Totale',      'value' => $kpi['totale'],      'color' => 'text-gray-800'],
                    ['label' => 'Aperte',       'value' => $kpi['aperte'],      'color' => 'text-blue-600'],
                    ['label' => 'Chiuse',       'value' => $kpi['chiuse'],      'color' => 'text-green-600'],
                    ['label' => 'In evidenza',  'value' => $kpi['evidenza'],    'color' => 'text-yellow-600'],
                    ['label' => 'Questo mese',  'value' => $kpi['questo_mese'], 'color' => 'text-indigo-600'],
                ] as $card)
                    <div class="bg-white shadow-sm rounded-lg p-5 text-center">
                        <div class="text-3xl font-bold {{ $card['color'] }}">{{ $card['value'] }}</div>
                        <div class="text-xs text-gray-500 mt-1 uppercase tracking-wide">{{ $card['label'] }}</div>
                    </div>
                @endforeach
            </div>

            {{-- Grafico per mese --}}
            <div class="bg-white shadow-sm rounded-lg p-6">
                <h3 class="font-medium text-gray-700 mb-4 text-sm uppercase tracking-wide">Segnalazioni per mese (ultimi 12 mesi)</h3>
                @if(count($mesiLabel) > 0)
                    <canvas id="chartMesi" height="80"></canvas>
                @else
                    <p class="text-center text-gray-400 text-sm py-8">Nessun dato disponibile.</p>
                @endif
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                {{-- Grafico per tipologia --}}
                <div class="bg-white shadow-sm rounded-lg p-6">
                    <h3 class="font-medium text-gray-700 mb-4 text-sm uppercase tracking-wide">Per tipologia (top 10)</h3>
                    @if(count($tipologiaLabel) > 0)
                        <canvas id="chartTipologia"></canvas>
                    @else
                        <p class="text-center text-gray-400 text-sm py-8">Nessun dato.</p>
                    @endif
                </div>

                {{-- Grafico per stato --}}
                <div class="bg-white shadow-sm rounded-lg p-6">
                    <h3 class="font-medium text-gray-700 mb-4 text-sm uppercase tracking-wide">Per stato</h3>
                    @if(count($statoLabel) > 0)
                        <canvas id="chartStato"></canvas>
                    @else
                        <p class="text-center text-gray-400 text-sm py-8">Nessun dato.</p>
                    @endif
                </div>
            </div>

    </div>

    @push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
    <script>
        const PALETTE = [
            '#3B82F6','#10B981','#F59E0B','#EF4444','#8B5CF6',
            '#06B6D4','#84CC16','#F97316','#EC4899','#6B7280',
        ];

        @if(count($mesiLabel) > 0)
        new Chart(document.getElementById('chartMesi'), {
            type: 'bar',
            data: {
                labels: @json($mesiLabel),
                datasets: [{
                    label: 'Segnalazioni',
                    data: @json($mesiTotali),
                    backgroundColor: '#3B82F6',
                    borderRadius: 4,
                }],
            },
            options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } },
        });
        @endif

        @if(count($tipologiaLabel) > 0)
        new Chart(document.getElementById('chartTipologia'), {
            type: 'doughnut',
            data: {
                labels: @json($tipologiaLabel),
                datasets: [{ data: @json($tipologiaTotali), backgroundColor: PALETTE }],
            },
            options: { plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } } },
        });
        @endif

        @if(count($statoLabel) > 0)
        new Chart(document.getElementById('chartStato'), {
            type: 'doughnut',
            data: {
                labels: @json($statoLabel),
                datasets: [{ data: @json($statoTotali), backgroundColor: PALETTE }],
            },
            options: { plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } } },
        });
        @endif
    </script>
    @endpush
</x-app-layout>
