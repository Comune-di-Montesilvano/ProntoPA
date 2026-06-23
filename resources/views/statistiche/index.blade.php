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

            {{-- KPI SLA + Tempo medio --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="bg-white shadow-sm rounded-lg p-5 flex items-center gap-4">
                    <div class="text-4xl font-bold text-green-600">{{ $slaCompliance !== null ? $slaCompliance.'%' : '—' }}</div>
                    <div>
                        <div class="text-sm font-semibold text-gray-700">SLA compliance</div>
                        <div class="text-xs text-gray-400 mt-0.5">Interventi chiusi senza violazione SLA</div>
                    </div>
                </div>
                <div class="bg-white shadow-sm rounded-lg p-5 flex items-center gap-4">
                    <div class="text-4xl font-bold text-purple-600">{{ $tempoMedioGg }}<span class="text-lg font-normal text-gray-400 ml-1">gg</span></div>
                    <div>
                        <div class="text-sm font-semibold text-gray-700">Tempo medio risoluzione</div>
                        <div class="text-xs text-gray-400 mt-0.5">Media su tutti gli interventi chiusi</div>
                    </div>
                </div>
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

            {{-- Carico operatori + Trend settimanale --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                <div class="bg-white shadow-sm rounded-lg p-6">
                    <h3 class="font-medium text-gray-700 mb-4 text-sm uppercase tracking-wide">Carico operatori (aperte)</h3>
                    @if(count($caricoLabel) > 0)
                        <canvas id="chartCarico" height="200"></canvas>
                    @else
                        <p class="text-center text-gray-400 text-sm py-8">Nessun operatore con segnalazioni aperte.</p>
                    @endif
                </div>

                <div class="bg-white shadow-sm rounded-lg p-6">
                    <h3 class="font-medium text-gray-700 mb-4 text-sm uppercase tracking-wide">Trend settimanale (8 settimane)</h3>
                    @if(count($trendLabel) > 0)
                        <canvas id="chartTrend" height="200"></canvas>
                    @else
                        <p class="text-center text-gray-400 text-sm py-8">Nessun dato.</p>
                    @endif
                </div>
            </div>

        {{-- KPI Tempi medi per stato --}}
        @if($kpiTransizioni->isNotEmpty())
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 mt-6">
            <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wider mb-4">Tempo medio per stato</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Stato</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Ore medie</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Giorni medi</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Campioni</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-100">
                        @foreach($kpiTransizioni as $transizione)
                        <tr>
                            <td class="px-4 py-2 text-gray-800">{{ $transizione['descrizione'] }}</td>
                            <td class="px-4 py-2 text-right text-gray-600 font-mono">{{ $transizione['ore_medie'] }}h</td>
                            <td class="px-4 py-2 text-right text-gray-600 font-mono">{{ $transizione['gg_medi'] }}gg</td>
                            <td class="px-4 py-2 text-right text-gray-400 text-xs">{{ $transizione['campioni'] }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif

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

        @if(count($caricoLabel) > 0)
        new Chart(document.getElementById('chartCarico'), {
            type: 'bar',
            data: {
                labels: @json($caricoLabel),
                datasets: [{
                    label: 'Aperte',
                    data: @json($caricoTotali),
                    backgroundColor: '#8B5CF6',
                    borderRadius: 4,
                }],
            },
            options: {
                indexAxis: 'y',
                plugins: { legend: { display: false } },
                scales: { x: { beginAtZero: true, ticks: { stepSize: 1 } } },
            },
        });
        @endif

        @if(count($trendLabel) > 0)
        new Chart(document.getElementById('chartTrend'), {
            type: 'line',
            data: {
                labels: @json($trendLabel),
                datasets: [{
                    label: 'Segnalazioni',
                    data: @json($trendTotali),
                    borderColor: '#3B82F6',
                    backgroundColor: 'rgba(59,130,246,0.1)',
                    tension: 0.3,
                    fill: true,
                    pointRadius: 4,
                }],
            },
            options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } },
        });
        @endif
    </script>
    @endpush
</x-app-layout>
