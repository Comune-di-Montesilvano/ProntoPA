{{-- Miniature delle foto (escluse quelle in quarantena). --}}
@php $immagini = $foto->filter(fn ($a) => $a->isFotoVisibile()); @endphp
@if($immagini->isNotEmpty())
    <div class="flex flex-wrap gap-2">
        @foreach($immagini as $allegato)
            @include('segnalazioni.scheda._miniatura')
        @endforeach
    </div>
@endif
