{{-- Testata della lettura gestore: dove/cosa, stato, priorità (cliccabile), scadenza, etichette, menu. --}}
@php
    $stato = $segnalazione->statoEnum();
    $titolo = $segnalazione->titolo_generato ?: \Illuminate\Support\Str::limit((string) $segnalazione->testo_segnalazione, 90);
    $puoAggiornare = auth()->user()->can('update', $segnalazione);
@endphp
<header class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
    <div class="min-w-0 flex flex-col gap-2">
        <p class="m-0 text-sm text-[var(--slate-600)]">
            <span class="pa-mono font-semibold">#{{ $segnalazione->id_segnalazione }}</span>
            · {{ $segnalazione->tipologia?->descrizione ?? '—' }}
            @if($segnalazione->plesso) · {{ $segnalazione->plesso->nome }} @endif
        </p>
        <h1 class="m-0 text-2xl font-bold text-[var(--ink)] break-words">{{ $titolo }}</h1>
        <div class="flex flex-wrap items-center gap-2">
            <x-pa.pill :tono="$stato->tono()">{{ $stato->etichetta() }}</x-pa.pill>
            @can('modificaPriorita', $segnalazione)
                <button type="button" x-data x-on:click="$dispatch('apri-pannello', 'priorita')"
                        class="rounded-md px-1.5 py-0.5 hover:bg-[var(--slate-100)]" title="Cambia priorità">
                    <x-pa.prio :livello="$segnalazione->livello_priorita" />
                </button>
            @else
                <x-pa.prio :livello="$segnalazione->livello_priorita" />
            @endcan
            <x-pa.scadenza :segnalazione="$segnalazione" estesa />
            @if($segnalazione->flag_riservata)
                <x-pa.pill tono="slate"><i class="fas fa-lock" aria-hidden="true"></i> Riservata</x-pa.pill>
            @endif
            @if($segnalazione->isSollecitata())
                <x-pa.pill tono="amber">Segnalata {{ $segnalazione->volteSegnalata() }} volte</x-pa.pill>
            @endif
            @if($segnalazione->isRicorrente())
                <x-pa.pill tono="amber">Ricorrente</x-pa.pill>
            @endif
        </div>
    </div>

    <div class="flex shrink-0 items-center gap-2">
        @if($puoAggiornare)
            <form method="POST" action="{{ route('segnalazioni.evidenza', $segnalazione->id_segnalazione) }}">
                @csrf
                <button type="submit" class="pa-btn pa-btn-outline pa-btn-sm" aria-pressed="{{ $segnalazione->flag_evidenza ? 'true' : 'false' }}"
                        title="{{ $segnalazione->flag_evidenza ? 'Togli da in evidenza' : 'Metti in evidenza' }}">
                    <span class="{{ $segnalazione->flag_evidenza ? 'pa-testo-amber' : '' }}" aria-hidden="true">{{ $segnalazione->flag_evidenza ? '★' : '☆' }}</span>
                    <span class="sr-only">In evidenza</span>
                </button>
            </form>
        @endif

        @if($altreAzioni !== [])
            <div class="relative" x-data="{ aperto: false }" x-on:click.outside="aperto = false" x-on:keydown.escape="aperto = false">
                <button type="button" class="pa-btn pa-btn-outline pa-btn-sm" x-on:click="aperto = !aperto" :aria-expanded="aperto">Altre azioni ▾</button>
                <div x-show="aperto" x-cloak class="absolute right-0 z-30 mt-1 w-60 rounded-md border border-[var(--slate-200)] bg-white py-1 shadow-lg">
                    @foreach($altreAzioni as $voce)
                        <button type="button" class="block w-full px-4 py-2 text-left text-sm hover:bg-[var(--slate-50)]"
                                x-on:click="aperto = false; $dispatch('apri-pannello', '{{ $voce['pannello'] }}')">{{ $voce['etichetta'] }}</button>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="relative" x-data="{ aperto: false }" x-on:click.outside="aperto = false" x-on:keydown.escape="aperto = false">
            <button type="button" class="pa-btn pa-btn-outline pa-btn-sm" x-on:click="aperto = !aperto" :aria-expanded="aperto" aria-label="Altro">⋯</button>
            <div x-show="aperto" x-cloak class="absolute right-0 z-30 mt-1 w-64 rounded-md border border-[var(--slate-200)] bg-white py-1 shadow-lg">
                <a href="{{ route('segnalazioni.stampa', $segnalazione->id_segnalazione) }}" target="_blank" class="block px-4 py-2 text-sm hover:bg-[var(--slate-50)]">Stampa</a>
                <a href="{{ route('segnalazioni.fascicolo-pdf', $segnalazione) }}" class="block px-4 py-2 text-sm hover:bg-[var(--slate-50)]">PDF fascicolo</a>
                @if($puoAggiornare)
                    <form method="POST" action="{{ route('segnalazioni.toggle-riservata', $segnalazione->id_segnalazione) }}">
                        @csrf @method('PATCH')
                        <button type="submit" class="block w-full px-4 py-2 text-left text-sm hover:bg-[var(--slate-50)]">
                            {{ $segnalazione->flag_riservata ? 'Rendi pubblica' : 'Rendi riservata' }}
                        </button>
                    </form>
                @endif
                @can('accorpa', $segnalazione)
                    <button type="button" class="block w-full px-4 py-2 text-left text-sm hover:bg-[var(--slate-50)]"
                            x-on:click="aperto = false; $dispatch('apri-pannello', 'accorpa')">Accorpa a…</button>
                @endcan
                @if($azioniDisponibili->contains('codice', 'collega'))
                    <button type="button" class="block w-full px-4 py-2 text-left text-sm hover:bg-[var(--slate-50)]"
                            x-on:click="aperto = false; $dispatch('apri-pannello', 'collega')">Collega a precedente…</button>
                @endif
            </div>
        </div>
    </div>
</header>
