{{-- Riquadro "prossimo passo": cosa serve adesso, con le sole azioni eseguibili (ProssimoPasso). --}}
@php $grande = $grande ?? false; @endphp
<section dusk="prossimo-passo" aria-label="Prossimo passo"
         class="pa-card pa-passo pa-passo-{{ $prossimoPasso['tono'] }} p-5 flex flex-col gap-4 {{ $grande ? '' : 'md:flex-row md:items-center md:justify-between' }}">
    <div class="min-w-0">
        <p class="pa-sezione-titolo pa-testo-{{ $prossimoPasso['tono'] === 'slate' ? 'slate' : $prossimoPasso['tono'] }} m-0">Prossimo passo</p>
        <h2 class="m-0 mt-1 text-lg font-bold text-[var(--ink)]">{{ $prossimoPasso['titolo'] }}</h2>
        @if($prossimoPasso['sottotitolo'])
            <p class="m-0 mt-1 text-sm text-[var(--slate-600)] whitespace-pre-line">{{ $prossimoPasso['sottotitolo'] }}</p>
        @endif
    </div>

    @if($prossimoPasso['azioni'] !== [])
        <div class="flex flex-wrap gap-2 {{ $grande ? 'flex-col sm:flex-row' : 'shrink-0' }}">
            @foreach($prossimoPasso['azioni'] as $azione)
                @php
                    $variante = $azione['primaria'] ? 'primary' : 'outline';
                    $taglia = $grande ? 'lg' : null;
                @endphp
                @switch($azione['tipo'])
                    @case('azione')
                        <form method="POST" action="{{ route('segnalazioni.azione', $segnalazione->id_segnalazione) }}" class="{{ $grande ? 'w-full sm:w-auto' : '' }}">
                            @csrf
                            <input type="hidden" name="id_azione" value="{{ $azione['id_azione'] }}">
                            <x-pa.btn type="submit" :variant="$variante" :size="$taglia" class="{{ $grande ? 'w-full sm:w-auto' : '' }}">{{ $azione['etichetta'] }}</x-pa.btn>
                        </form>
                        @break
                    @case('pannello')
                        <x-pa.btn :variant="$variante" :size="$taglia" class="{{ $grande ? 'w-full sm:w-auto' : '' }}"
                                  x-data x-on:click="$dispatch('apri-pannello', '{{ $azione['pannello'] }}')">{{ $azione['etichetta'] }}</x-pa.btn>
                        @break
                    @default
                        <x-pa.btn :href="$azione['route'] ?? '#'" :variant="$variante" :size="$taglia" class="{{ $grande ? 'w-full sm:w-auto' : '' }}">{{ $azione['etichetta'] }}</x-pa.btn>
                @endswitch
            @endforeach
        </div>
    @endif
</section>
