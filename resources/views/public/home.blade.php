<x-public-layout>
    @php
        $hasCharts = count($mesiLabel) > 0 || count($tipologiaLabel) > 0 || count($statoLabel) > 0;
    @endphp

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">

        {{-- Hero --}}
        <div class="mb-8">
            <h2 class="text-2xl font-bold text-gray-900">Monitoraggio segnalazioni</h2>
            <p class="mt-1 text-sm text-gray-500">Dati aggregati e anonimizzati aggiornati in tempo reale. Nessun dato personale esposto.</p>
        </div>

        {{-- KPI Cards --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
            @foreach([
                ['label' => 'Totale pubblicate', 'value' => $kpi['totale'],       'icon' => '📋'],
                ['label' => 'Ancora aperte',     'value' => $kpi['aperte'],       'icon' => '🔄'],
                ['label' => 'Chiuse',            'value' => $kpi['chiuse'],       'icon' => '✅'],
                ['label' => 'Questo mese',       'value' => $kpi['questo_mese'], 'icon' => '📅'],
            ] as $card)
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="kpi-icon h-9 w-9 rounded-lg flex items-center justify-center text-base shrink-0">
                            {{ $card['icon'] }}
                        </div>
                        <span class="text-xs font-semibold text-gray-500 uppercase tracking-wide leading-tight">{{ $card['label'] }}</span>
                    </div>
                    <div class="text-3xl font-black text-brand">{{ number_format($card['value'], 0, ',', '.') }}</div>
                </div>
            @endforeach
        </div>

        {{-- Grafici principali --}}
        <div class="grid gap-6 lg:grid-cols-3 mb-6">

            {{-- Andamento 12 mesi --}}
            <div class="lg:col-span-2 bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                <div class="flex items-center justify-between mb-5">
                    <div>
                        <h3 class="font-semibold text-gray-900">Andamento mensile</h3>
                        <p class="text-xs text-gray-400 mt-0.5">Ultimi 12 mesi</p>
                    </div>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-50 text-green-700">
                        Dati pubblicati
                    </span>
                </div>
                @if(count($mesiLabel) > 0)
                    <canvas id="chartMesi" height="100"></canvas>
                @else
                    <div class="flex items-center justify-center h-32 text-sm text-gray-400">
                        Nessun dato disponibile per il grafico temporale.
                    </div>
                @endif
            </div>

            {{-- Info trasparenza --}}
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 flex flex-col">
                <h3 class="font-semibold text-gray-900 mb-1">Trasparenza</h3>
                <p class="text-xs text-gray-400 mb-4">Cosa contiene questa pagina</p>
                <ul class="space-y-3 text-sm text-gray-600 flex-1">
                    <li class="flex items-start gap-2">
                        <span class="text-green-500 mt-0.5 shrink-0">✓</span>
                        Totali aggregati per stato e tipologia.
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="text-green-500 mt-0.5 shrink-0">✓</span>
                        Trend mensile del carico di lavoro.
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="text-green-500 mt-0.5 shrink-0">✓</span>
                        Solo dati anonimi: nessun nominativo ne indirizzo operativo.
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="text-red-400 mt-0.5 shrink-0">✗</span>
                        Nessun dettaglio su singole segnalazioni.
                    </li>
                </ul>
            </div>
        </div>

        {{-- Distribuzione per tipologia e stato --}}
        <div class="grid gap-6 sm:grid-cols-2 mb-6">
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                <h3 class="font-semibold text-gray-900 mb-1">Per tipologia</h3>
                <p class="text-xs text-gray-400 mb-5">Distribuzione delle segnalazioni pubblicate</p>
                @if(count($tipologiaLabel) > 0)
                    <canvas id="chartTipologia"></canvas>
                @else
                    <div class="flex items-center justify-center h-32 text-sm text-gray-400">
                        Nessuna tipologia disponibile.
                    </div>
                @endif
            </div>

            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                <h3 class="font-semibold text-gray-900 mb-1">Per stato</h3>
                <p class="text-xs text-gray-400 mb-5">Distribuzione delle segnalazioni pubblicate</p>
                @if(count($statoLabel) > 0)
                    <canvas id="chartStato"></canvas>
                @else
                    <div class="flex items-center justify-center h-32 text-sm text-gray-400">
                        Nessuno stato disponibile.
                    </div>
                @endif
            </div>
        </div>

        {{-- Stato vuoto --}}
        @if(! $hasCharts && $kpi['totale'] === 0)
            <div class="bg-white rounded-xl border border-dashed border-gray-300 px-6 py-12 text-center">
                <div class="text-4xl mb-3">📭</div>
                <p class="text-gray-500 text-sm">La sezione pubblica e attiva, ma non risultano ancora segnalazioni pubblicate.</p>
            </div>
        @endif

    </div>

    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
        <script>
            const palette = [
                '#2563eb','#16a34a','#f59e0b','#ef4444','#8b5cf6',
                '#06b6d4','#f97316','#ec4899','#10b981','#6366f1',
            ];

            const axisStyle = {
                ticks: { color: '#6b7280', font: { size: 11 } },
                grid:  { color: '#f3f4f6' },
            };

            @if(count($mesiLabel) > 0)
            new Chart(document.getElementById('chartMesi'), {
                type: 'bar',
                data: {
                    labels: @json($mesiLabel),
                    datasets: [{
                        label: 'Segnalazioni',
                        data: @json($mesiTotali),
                        backgroundColor: 'rgba(37,99,235,0.15)',
                        borderColor: 'rgba(37,99,235,0.9)',
                        borderWidth: 2,
                        borderRadius: 6,
                    }],
                },
                options: {
                    plugins: { legend: { display: false } },
                    scales: {
                        x: axisStyle,
                        y: { ...axisStyle, beginAtZero: true, ticks: { ...axisStyle.ticks, precision: 0 } },
                    },
                },
            });
            @endif

            @if(count($tipologiaLabel) > 0)
            new Chart(document.getElementById('chartTipologia'), {
                type: 'doughnut',
                data: {
                    labels: @json($tipologiaLabel),
                    datasets: [{ data: @json($tipologiaTotali), backgroundColor: palette, borderWidth: 2, borderColor: '#fff' }],
                },
                options: {
                    plugins: {
                        legend: { position: 'bottom', labels: { color: '#374151', boxWidth: 12, font: { size: 11 }, padding: 12 } },
                    },
                },
            });
            @endif

            @if(count($statoLabel) > 0)
            new Chart(document.getElementById('chartStato'), {
                type: 'doughnut',
                data: {
                    labels: @json($statoLabel),
                    datasets: [{ data: @json($statoTotali), backgroundColor: [...palette].reverse(), borderWidth: 2, borderColor: '#fff' }],
                },
                options: {
                    plugins: {
                        legend: { position: 'bottom', labels: { color: '#374151', boxWidth: 12, font: { size: 11 }, padding: 12 } },
                    },
                },
            });
            @endif
        </script>
    @endpush
</x-public-layout>