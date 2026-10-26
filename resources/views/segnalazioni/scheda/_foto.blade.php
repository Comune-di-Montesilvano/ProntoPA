{{-- Miniature delle foto (escluse quelle in quarantena). --}}
@php $immagini = $foto->filter(fn ($a) => str_starts_with((string) $a->tipo, 'image/') && ! $a->isInfetto()); @endphp
@if($immagini->isNotEmpty())
    <div class="flex flex-wrap gap-2">
        @foreach($immagini as $allegato)
            @php $url = route('segnalazioni.allegati.download', [$segnalazione->id_segnalazione, $allegato->id_allegato]); @endphp
            <a href="{{ $url }}" class="block overflow-hidden rounded-md border border-[var(--slate-200)]">
                <img src="{{ $url }}" alt="{{ $allegato->nome_originale }}" loading="lazy" class="h-24 w-32 object-cover sm:h-28 sm:w-36">
            </a>
        @endforeach
    </div>
@endif
