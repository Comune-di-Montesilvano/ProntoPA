<x-public-layout>
    @php
        $enteNome = \App\Models\Impostazione::get('ente_nome', 'ProntoPA');
        $enteSito = \App\Models\Impostazione::get('ente_sito_url');
        $logoUrl = \App\Models\Impostazione::get('ente_logo_url');
        $hasCharts = count($mesiLabel) > 0 || count($tipologiaLabel) > 0 || count($statoLabel) > 0;
    @endphp

    <div class="mx-auto flex min-h-screen w-full max-w-7xl flex-col px-6 py-6 lg:px-10 lg:py-10">
        <header class="flex flex-col gap-6 border-b border-white/10 pb-8 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex items-center gap-4">
                @if($logoUrl)
                    <img src="{{ $logoUrl }}" alt="{{ $enteNome }}" class="h-14 w-14 rounded-2xl bg-white/90 object-contain p-2 shadow-lg shadow-black/20">
                @else
                    <div class="flex h-14 w-14 items-center justify-center rounded-2xl text-lg font-bold text-slate-950 shadow-lg shadow-black/20"
                         style="background: linear-gradient(135deg, var(--brand-secondary), white);">
                        PA
                    </div>
                @endif
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-300/80">ProntoPA</p>
                    <h1 class="mt-1 text-2xl font-bold tracking-tight text-white sm:text-3xl">{{ $enteNome }}</h1>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                @if($enteSito)
                    <a href="{{ $enteSito }}" target="_blank" rel="noreferrer"
                       class="inline-flex items-center rounded-full border border-white/15 px-4 py-2 text-sm font-medium text-slate-100 transition hover:border-white/30 hover:bg-white/5">
                        Sito ente
                    </a>
                @endif

                @auth
                    <a href="{{ route('dashboard') }}"
                       class="inline-flex items-center rounded-full px-5 py-2 text-sm font-semibold text-slate-950 transition"
                       style="background: linear-gradient(135deg, var(--brand-secondary), white);">
                        Vai alla dashboard
                    </a>
                @else
                    <a href="{{ route('login') }}"
                       class="inline-flex items-center rounded-full px-5 py-2 text-sm font-semibold text-slate-950 transition"
                       style="background: linear-gradient(135deg, var(--brand-secondary), white);">
                        Accedi al sistema
                    </a>
                @endauth
            </div>
        </header>

        <main class="flex-1 py-10 lg:py-14">
            <section class="grid gap-8 lg:grid-cols-[1.1fr_0.9fr] lg:items-end">
                <div class="space-y-6">
                    <span class="inline-flex items-center rounded-full border border-white/15 bg-white/5 px-4 py-1 text-xs font-semibold uppercase tracking-[0.25em] text-slate-200/85">
                        Monitoraggio pubblico aggregato
                    </span>
                    <div class="max-w-3xl space-y-4">
                        <h2 class="text-4xl font-black leading-tight text-white sm:text-5xl lg:text-6xl">
                            Segnalazioni pubblicate, stato dei flussi e trend recenti.
                        </h2>
                        <p class="max-w-2xl text-base leading-7 text-slate-300 sm:text-lg">
                            Questa pagina espone solo dati aggregati e anonimizzati dell'ente corrente. Nessun riferimento operativo, nessun dettaglio su utenti o singole segnalazioni.
                        </p>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach([
                        ['label' => 'Totale pubblicate', 'value' => $kpi['totale']],
                        ['label' => 'Ancora aperte', 'value' => $kpi['aperte']],
                        ['label' => 'Chiuse', 'value' => $kpi['chiuse']],
                        ['label' => 'Questo mese', 'value' => $kpi['questo_mese']],
                    ] as $card)
                        <article class="rounded-3xl border border-white/10 bg-white/8 p-5 shadow-2xl shadow-black/10 backdrop-blur">
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-300/80">{{ $card['label'] }}</p>
                            <p class="mt-3 text-4xl font-black text-white">{{ $card['value'] }}</p>
                        </article>
                    @endforeach
                </div>
            </section>

            <section class="mt-10 grid gap-6 lg:grid-cols-[1.25fr_0.75fr]">
                <article class="rounded-[2rem] border border-white/10 bg-slate-950/40 p-6 shadow-2xl shadow-black/10 backdrop-blur lg:p-8">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-300/70">Andamento</p>
                            <h3 class="mt-2 text-xl font-bold text-white">Ultimi 12 mesi</h3>
                        </div>
                        <div class="rounded-full px-3 py-1 text-xs font-semibold text-slate-900"
                             style="background: color-mix(in srgb, var(--brand-secondary) 85%, white);">
                            Dati pubblicati
                        </div>
                    </div>

                    <div class="mt-6 rounded-[1.5rem] border border-white/8 bg-black/10 p-4">
                        @if(count($mesiLabel) > 0)
                            <canvas id="chartMesi" height="120"></canvas>
                        @else
                            <p class="py-16 text-center text-sm text-slate-400">Nessun dato pubblicato disponibile per il grafico temporale.</p>
                        @endif
                    </div>
                </article>

                <article class="rounded-[2rem] border border-white/10 bg-white/8 p-6 shadow-2xl shadow-black/10 backdrop-blur lg:p-8">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-300/70">Trasparenza</p>
                    <h3 class="mt-2 text-xl font-bold text-white">Cosa mostra questa pagina</h3>
                    <ul class="mt-6 space-y-4 text-sm leading-6 text-slate-200">
                        <li class="rounded-2xl border border-white/8 bg-black/10 px-4 py-3">Totali aggregati per stato e tipologia delle segnalazioni pubblicate.</li>
                        <li class="rounded-2xl border border-white/8 bg-black/10 px-4 py-3">Trend mensile utile per capire il carico recente del servizio.</li>
                        <li class="rounded-2xl border border-white/8 bg-black/10 px-4 py-3">Solo dati anonimizzati: nessun nominativo, indirizzo operativo o dettaglio interno.</li>
                    </ul>
                </article>
            </section>

            <section class="mt-6 grid gap-6 lg:grid-cols-2">
                <article class="rounded-[2rem] border border-white/10 bg-white/8 p-6 shadow-2xl shadow-black/10 backdrop-blur lg:p-8">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-300/70">Distribuzione</p>
                    <h3 class="mt-2 text-xl font-bold text-white">Per tipologia</h3>
                    <div class="mt-6 rounded-[1.5rem] border border-white/8 bg-black/10 p-4">
                        @if(count($tipologiaLabel) > 0)
                            <canvas id="chartTipologia"></canvas>
                        @else
                            <p class="py-12 text-center text-sm text-slate-400">Nessuna tipologia pubblicata al momento.</p>
                        @endif
                    </div>
                </article>

                <article class="rounded-[2rem] border border-white/10 bg-white/8 p-6 shadow-2xl shadow-black/10 backdrop-blur lg:p-8">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-300/70">Distribuzione</p>
                    <h3 class="mt-2 text-xl font-bold text-white">Per stato</h3>
                    <div class="mt-6 rounded-[1.5rem] border border-white/8 bg-black/10 p-4">
                        @if(count($statoLabel) > 0)
                            <canvas id="chartStato"></canvas>
                        @else
                            <p class="py-12 text-center text-sm text-slate-400">Nessuno stato disponibile nei dati pubblicati.</p>
                        @endif
                    </div>
                </article>
            </section>

            @if(! $hasCharts && $kpi['totale'] === 0)
                <section class="mt-6 rounded-[2rem] border border-dashed border-white/15 bg-black/10 px-6 py-8 text-center text-sm text-slate-300">
                    La sezione pubblica è attiva, ma non risultano ancora segnalazioni marcate come pubblicabili.
                </section>
            @endif
        </main>
    </div>

    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
        <script>
            const palette = [
                'rgba(255,255,255,0.92)',
                'rgba(245,158,11,0.92)',
                'rgba(45,212,191,0.92)',
                'rgba(56,189,248,0.92)',
                'rgba(248,113,113,0.92)',
                'rgba(196,181,253,0.92)',
                'rgba(163,230,53,0.92)',
                'rgba(251,191,36,0.72)',
            ];

            const axisStyle = {
                ticks: { color: 'rgba(226,232,240,0.78)' },
                grid: { color: 'rgba(255,255,255,0.08)' },
            };

            @if(count($mesiLabel) > 0)
            new Chart(document.getElementById('chartMesi'), {
                type: 'bar',
                data: {
                    labels: @json($mesiLabel),
                    datasets: [{
                        label: 'Segnalazioni',
                        data: @json($mesiTotali),
                        backgroundColor: 'rgba(255,255,255,0.88)',
                        borderColor: 'rgba(255,255,255,1)',
                        borderWidth: 1,
                        borderRadius: 999,
                    }],
                },
                options: {
                    plugins: {
                        legend: { display: false },
                    },
                    scales: {
                        x: axisStyle,
                        y: { ...axisStyle, beginAtZero: true, ticks: { color: 'rgba(226,232,240,0.78)', precision: 0 } },
                    },
                },
            });
            @endif

            @if(count($tipologiaLabel) > 0)
            new Chart(document.getElementById('chartTipologia'), {
                type: 'doughnut',
                data: {
                    labels: @json($tipologiaLabel),
                    datasets: [{ data: @json($tipologiaTotali), backgroundColor: palette }],
                },
                options: {
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { color: 'rgba(226,232,240,0.82)', boxWidth: 12, font: { size: 11 } },
                        },
                    },
                },
            });
            @endif

            @if(count($statoLabel) > 0)
            new Chart(document.getElementById('chartStato'), {
                type: 'doughnut',
                data: {
                    labels: @json($statoLabel),
                    datasets: [{ data: @json($statoTotali), backgroundColor: palette.slice().reverse() }],
                },
                options: {
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { color: 'rgba(226,232,240,0.82)', boxWidth: 12, font: { size: 11 } },
                        },
                    },
                },
            });
            @endif
        </script>
    @endpush
</x-public-layout>