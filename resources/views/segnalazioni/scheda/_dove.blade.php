{{-- Dove: sede, indirizzo, ubicazione, referente. La lettura lavoro aggiunge Naviga e Chiama. --}}
@php
    $conAzioni = $conAzioni ?? false;
    $conMappa = $conMappa ?? false;
    $plesso = $segnalazione->id_plesso ? $segnalazione->plesso : null;
    $haCoordinate = $segnalazione->latitudine && $segnalazione->longitudine && (float) $segnalazione->latitudine != 0;
    $naviga = $haCoordinate
        ? 'https://www.google.com/maps/search/?api=1&query='.(float) $segnalazione->latitudine.','.(float) $segnalazione->longitudine
        : ($plesso?->indirizzo ? 'https://www.google.com/maps/search/?api=1&query='.urlencode($plesso->indirizzo) : null);
    $primoNumero = $plesso?->recapiti ? trim(preg_split('/[,;\/]| - /', $plesso->recapiti)[0]) : '';
    $telefono = preg_replace('/[^\d+]/', '', $primoNumero);
@endphp
<div class="flex flex-col gap-2 text-sm text-[var(--slate-700)]">
    @if($plesso)
        <p class="m-0 font-semibold text-[var(--ink)]">{{ $plesso->nome }}</p>
        @if($plesso->indirizzo)<p class="m-0">{{ $plesso->indirizzo }}</p>@endif
    @endif
    @if($segnalazione->ubicazione_tipo)
        <p class="m-0">{{ $segnalazione->label_ubicazione }}</p>
    @endif
    @if($plesso?->referente)
        <p class="m-0">Referente: {{ $plesso->referente }}@if($plesso->recapiti) · {{ $plesso->recapiti }}@endif</p>
    @endif
    @if(! $plesso && ! $haCoordinate && ! $segnalazione->ubicazione_tipo)
        <p class="m-0 text-[var(--slate-500)]">Luogo non indicato.</p>
    @endif

    @if($conAzioni && ($naviga || $telefono))
        <div class="mt-1 flex flex-col gap-2 sm:flex-row">
            @if($naviga)
                <x-pa.btn :href="$naviga" variant="primary" size="lg" target="_blank" rel="noopener" class="w-full sm:w-auto">Naviga</x-pa.btn>
            @endif
            @if($telefono)
                <x-pa.btn href="tel:{{ $telefono }}" variant="outline" size="lg" class="w-full sm:w-auto">Chiama il referente</x-pa.btn>
            @endif
        </div>
    @endif

    @if($conMappa && $haCoordinate)
        <div id="mappa-segnalazione" class="mt-2 h-48 w-full overflow-hidden rounded-md border border-[var(--slate-200)]"></div>
    @endif
</div>
