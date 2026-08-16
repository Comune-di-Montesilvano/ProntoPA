<x-public-layout>
    @php
        $hasCharts = count($mesiLabel) > 0 || count($tipologiaLabel) > 0 || count($statoLabel) > 0;
    @endphp

    <div class="max-w-7xl mx-auto px-6 py-12">

        {{-- Hero --}}
        <div class="mb-10">
            <p style="font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.08em;
                      color:var(--ente-primary); margin:0 0 10px;">Portale trasparenza</p>
            <h2 class="pa-claim" style="font-size:36px; font-weight:500; font-style:italic;
                                        color:var(--ink); margin:0 0 10px; line-height:1.2;">
                La tua segnalazione conta.<br>Ti diciamo cosa succede.
            </h2>
            <p style="font-size:15px; color:var(--slate-600); margin:0; max-width:560px; line-height:1.55;">
                Dati aggregati e anonimizzati aggiornati in tempo reale. Nessun dato personale esposto.
            </p>
        </div>

        {{-- KPI Cards --}}
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-4 mb-8">
            @foreach([
                ['label' => 'Totale pubblicate', 'value' => $kpi['totale'],       'tone' => 'primary'],
                ['label' => 'Ancora aperte',     'value' => $kpi['aperte'],       'tone' => 'amber'],
                ['label' => 'Chiuse',            'value' => $kpi['chiuse'],       'tone' => 'emerald'],
                ['label' => 'Questo mese',       'value' => $kpi['questo_mese'], 'tone' => 'violet'],
            ] as $card)
                @php
                    $toneColors = [
                        'primary' => ['bg' => 'var(--ente-primary-100)', 'fg' => 'var(--ente-primary)'],
                        'amber'   => ['bg' => 'var(--amber-100)',        'fg' => 'var(--amber-600)'],
                        'emerald' => ['bg' => 'var(--emerald-100)',      'fg' => 'var(--emerald)'],
                        'violet'  => ['bg' => 'var(--violet-100)',       'fg' => 'var(--violet)'],
                    ];
                    $tc = $toneColors[$card['tone']];
                @endphp
                <div class="pa-card" style="padding:20px;">
                    <div style="width:36px;height:36px;border-radius:8px;margin-bottom:12px;
                                display:flex;align-items:center;justify-content:center;
                                background:{{ $tc['bg'] }};color:{{ $tc['fg'] }};">
                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                            @if($card['tone'] === 'primary')
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                            @elseif($card['tone'] === 'amber')
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            @elseif($card['tone'] === 'emerald')
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                            @else
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            @endif
                        </svg>
                    </div>
                    <div style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em;
                                color:var(--slate-500); margin-bottom:6px;">{{ $card['label'] }}</div>
                    <div style="font-size:32px; font-weight:900; color:{{ $tc['fg'] }}; font-feature-settings:'tnum';
                                font-family:var(--font-ui); letter-spacing:-.02em; line-height:1;">
                        {{ number_format($card['value'], 0, ',', '.') }}
                    </div>
                </div>
            @endforeach
            @if(isset($kpi['tempo_medio_gg']) && $kpi['tempo_medio_gg'] !== null)
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="kpi-icon h-9 w-9 rounded-lg flex items-center justify-center text-base shrink-0">
                            ⏱
                        </div>
                        <span class="text-xs font-semibold text-gray-500 uppercase tracking-wide leading-tight">Tempo medio risoluzione</span>
                    </div>
                    <div class="text-3xl font-black text-brand">{{ $kpi['tempo_medio_gg'] }} <span class="text-base font-normal text-gray-400">gg</span></div>
                </div>
            @endif
        </div>

        {{-- Grafici principali --}}
        <div class="grid gap-6 lg:grid-cols-3 mb-6">

            {{-- Andamento 12 mesi --}}
            <div class="lg:col-span-2 pa-card" style="padding:24px;">
                <div style="display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:20px;">
                    <div>
                        <h3 style="font-size:15px; font-weight:700; color:var(--ink); margin:0;">Andamento mensile</h3>
                        <p style="font-size:12px; color:var(--slate-500); margin:3px 0 0;">Ultimi 12 mesi</p>
                    </div>
                    <span class="pa-pill pa-pill-emerald">Dati pubblicati</span>
                </div>
                @if(count($mesiLabel) > 0)
                    <canvas id="chartMesi" height="100"></canvas>
                @else
                    <div style="display:flex; align-items:center; justify-content:center; height:120px;
                                font-size:13px; color:var(--slate-500);">
                        Nessun dato disponibile.
                    </div>
                @endif
            </div>

            {{-- Info trasparenza --}}
            <div class="pa-card" style="padding:24px; display:flex; flex-direction:column;">
                <h3 style="font-size:15px; font-weight:700; color:var(--ink); margin:0 0 4px;">Trasparenza</h3>
                <p style="font-size:12px; color:var(--slate-500); margin:0 0 18px;">Cosa contiene questa pagina</p>
                <ul style="display:flex; flex-direction:column; gap:12px; flex:1; margin:0; padding:0; list-style:none;">
                    @foreach([
                        [true,  'Totali aggregati per stato e tipologia.'],
                        [true,  'Trend mensile del carico di lavoro.'],
                        [true,  'Solo dati anonimi: nessun nominativo né indirizzo operativo.'],
                        [false, 'Nessun dettaglio su singole segnalazioni.'],
                    ] as [$ok, $txt])
                        <li style="display:flex; align-items:flex-start; gap:8px; font-size:13px; color:var(--slate-600);">
                            <span style="margin-top:1px; flex-shrink:0; font-weight:700;
                                         color:{{ $ok ? 'var(--emerald)' : 'var(--rose)' }};">
                                {{ $ok ? '✓' : '✗' }}
                            </span>
                            {{ $txt }}
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        {{-- Distribuzione per tipologia e stato --}}
        <div class="grid gap-6 sm:grid-cols-2 mb-6">
            <div class="pa-card" style="padding:24px;">
                <h3 style="font-size:15px; font-weight:700; color:var(--ink); margin:0 0 4px;">Per tipologia</h3>
                <p style="font-size:12px; color:var(--slate-500); margin:0 0 20px;">Distribuzione delle segnalazioni pubblicate</p>
                @if(count($tipologiaLabel) > 0)
                    <canvas id="chartTipologia"></canvas>
                @else
                    <div style="display:flex;align-items:center;justify-content:center;height:120px;font-size:13px;color:var(--slate-500);">
                        Nessuna tipologia disponibile.
                    </div>
                @endif
            </div>

            <div class="pa-card" style="padding:24px;">
                <h3 style="font-size:15px; font-weight:700; color:var(--ink); margin:0 0 4px;">Per stato</h3>
                <p style="font-size:12px; color:var(--slate-500); margin:0 0 20px;">Distribuzione delle segnalazioni pubblicate</p>
                @if(count($statoLabel) > 0)
                    <canvas id="chartStato"></canvas>
                @else
                    <div style="display:flex;align-items:center;justify-content:center;height:120px;font-size:13px;color:var(--slate-500);">
                        Nessuno stato disponibile.
                    </div>
                @endif
            </div>
        </div>

        {{-- Stato vuoto --}}
        @if(! $hasCharts && $kpi['totale'] === 0)
            <div class="pa-card" style="padding:48px 24px; text-align:center; border-style:dashed;">
                <svg width="40" height="40" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg"
                     style="margin:0 auto 16px; opacity:.25; display:block;">
                    <path d="M 50 21 L 76 35 L 76 55 L 60 63 L 50 79 L 40 63 L 24 55 L 24 35 Z" fill="var(--ente-primary)" />
                    <rect x="45" y="25" width="10" height="26" fill="white" />
                    <rect x="33" y="33" width="34" height="10" fill="white" />
                    <rect x="45" y="25" width="10" height="8" fill="#E07A1F" />
                </svg>
                <p style="font-size:14px; color:var(--slate-500); margin:0;">
                    La sezione pubblica è attiva, ma non risultano ancora segnalazioni pubblicate.
                </p>
            </div>
        @endif

    </div>

    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
        <script>
            const palette = [
                '#0B3A8C','#1B5BD8','#E07A1F','#1F7A5A','#6B4FB0',
                '#B23A3A','#C3640D','#0E3FA3','#E8F0FC','#ECE6F8',
            ];

            const axisStyle = {
                ticks: { color: '#6B7589', font: { size: 11, family: "'IBM Plex Mono', monospace" } },
                grid:  { color: '#E1E5ED' },
            };

            @if(count($mesiLabel) > 0)
            new Chart(document.getElementById('chartMesi'), {
                type: 'bar',
                data: {
                    labels: @json($mesiLabel),
                    datasets: [{
                        label: 'Segnalazioni',
                        data: @json($mesiTotali),
                        backgroundColor: 'rgba(11,58,140,0.12)',
                        borderColor: 'rgba(11,58,140,0.85)',
                        borderWidth: 2,
                        borderRadius: 5,
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

            const donutOpts = {
                plugins: {
                    legend: { position: 'bottom', labels: {
                        color: '#2D3340', boxWidth: 12,
                        font: { size: 11, family: "'Titillium Web', sans-serif" },
                        padding: 12,
                    } },
                },
            };

            @if(count($tipologiaLabel) > 0)
            new Chart(document.getElementById('chartTipologia'), {
                type: 'doughnut',
                data: {
                    labels: @json($tipologiaLabel),
                    datasets: [{ data: @json($tipologiaTotali), backgroundColor: palette, borderWidth: 2, borderColor: '#fff' }],
                },
                options: donutOpts,
            });
            @endif

            @if(count($statoLabel) > 0)
            new Chart(document.getElementById('chartStato'), {
                type: 'doughnut',
                data: {
                    labels: @json($statoLabel),
                    datasets: [{ data: @json($statoTotali), backgroundColor: [...palette].reverse(), borderWidth: 2, borderColor: '#fff' }],
                },
                options: donutOpts,
            });
            @endif
        </script>
    @endpush
</x-public-layout>
