{{-- "Com'è andata finora": barra del tempo da Support/Segnalazioni/Cronogramma (posizioni già in %). --}}
@php
    $c = $cronogramma ?? null;
    $pct = fn (float $v) => rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.').'%';
    $etichettaAria = fn (string $f) => str_replace("\n", '; ', $f);
    $righe = fn (string $f) => preg_split('/ · |\n/', $f);
    // Fumetto ancorato al bordo vicino, così non esce dalla scheda.
    $lato = fn (float $centro) => $centro > 70 ? 'pa-crono-fumetto-destra' : ($centro < 30 ? 'pa-crono-fumetto-sinistra' : '');
@endphp
@if($c)
    <section class="pa-card p-4 sm:p-5 flex flex-col gap-3" aria-labelledby="crono-titolo">
        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <h2 id="crono-titolo" class="m-0 text-sm font-semibold uppercase tracking-wider text-[var(--slate-600)]">Com'è andata finora</h2>
            @if($c['riassunto'])
                <p class="m-0 text-sm text-[var(--slate-700)]">{{ $c['riassunto'] }}</p>
            @endif
        </div>

        <div class="pa-crono">
            <div class="pa-crono-barra">
                @foreach($c['chiusi'] as $chiuso)
                    <div class="pa-crono-chiuso" style="left: {{ $pct($chiuso['sinistra']) }}; width: {{ $pct($chiuso['larghezza']) }}" aria-hidden="true">
                        @if($chiuso['etichetta'])<span>{{ $chiuso['etichetta'] }}</span>@endif
                    </div>
                @endforeach

                @foreach($c['tratti'] as $tratto)
                    <div class="pa-crono-tratto pa-crono-{{ $tratto['classe'] }}" tabindex="0"
                         style="left: {{ $pct($tratto['sinistra']) }}; width: {{ $pct($tratto['larghezza']) }}"
                         aria-label="{{ $etichettaAria($tratto['fumetto']) }}">
                        <span class="pa-crono-fumetto {{ $lato($tratto['sinistra'] + $tratto['larghezza'] / 2) }}" aria-hidden="true">
                            @foreach($righe($tratto['fumetto']) as $i => $riga)
                                <span class="{{ $i === 0 ? 'font-semibold text-[var(--ink)]' : '' }}">{{ $riga }}</span>
                            @endforeach
                        </span>
                    </div>
                @endforeach

                @if($c['superata_da'] !== null)
                    <div class="pa-crono-superata" style="left: {{ $pct($c['superata_da']) }}; width: {{ $pct(max(0, $c['fine_reale'] - $c['superata_da'])) }}" aria-hidden="true"></div>
                @endif
                @php $vicine = $c['scadenza'] !== null && $c['ora'] !== null && abs($c['scadenza'] - $c['ora']) < 8; @endphp
                @if($c['scadenza'] !== null)
                    <div class="pa-crono-linea pa-crono-linea-scadenza {{ $vicine ? 'pa-crono-etichetta-sotto' : '' }} {{ $c['scadenza'] > 90 ? 'pa-crono-etichetta-sinistra' : '' }}" style="left: {{ $pct($c['scadenza']) }}" aria-hidden="true"><span>scadenza</span></div>
                @endif
                @if($c['ora'] !== null)
                    <div class="pa-crono-linea pa-crono-linea-ora {{ $c['ora'] > 90 ? 'pa-crono-etichetta-sinistra' : '' }}" style="left: {{ $pct($c['ora']) }}" aria-hidden="true"><span>adesso</span></div>
                @endif

                @foreach($c['marcatori'] as $m)
                    <div class="pa-crono-marcatore pa-crono-m-{{ $m['tipo'] }} {{ ($m['riga'] ?? 0) ? 'pa-crono-riga-2' : '' }}" tabindex="0" style="left: {{ $pct($m['posizione']) }}"
                         aria-label="{{ $etichettaAria($m['fumetto']) }}">
                        @if($m['quanti'] > 1)<span class="pa-crono-quanti" aria-hidden="true">{{ $m['quanti'] }}</span>@endif
                        <span class="pa-crono-fumetto {{ $lato($m['posizione']) }}" aria-hidden="true">
                            @foreach(explode("\n", $m['fumetto']) as $riga)
                                <span>{{ $riga }}</span>
                            @endforeach
                        </span>
                    </div>
                @endforeach
            </div>

            <div class="pa-crono-tacche" aria-hidden="true">
                @foreach($c['tacche'] as $tacca)
                    <span style="left: {{ $pct($tacca['posizione']) }}">{{ $tacca['testo'] }}</span>
                @endforeach
            </div>
        </div>

        @if($c['legenda'])
            <ul class="pa-crono-legenda" aria-label="Legenda">
                @foreach($c['legenda'] as $classe => $voce)
                    <li><span class="pa-crono-campione pa-crono-c-{{ $classe }}" aria-hidden="true"></span>{{ $voce }}</li>
                @endforeach
            </ul>
        @endif
    </section>
@endif
