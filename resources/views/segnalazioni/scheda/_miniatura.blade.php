{{-- Miniatura di una foto: si apre nel carosello della pagina (resources/js/galleria.js), senza scaricarla. --}}
@php
    $chiavi = [$segnalazione->id_segnalazione, $allegato->id_allegato];
    $fotoUrl = route('segnalazioni.allegati.foto', $chiavi);
@endphp
<a href="{{ $fotoUrl }}" data-galleria="segnalazione-{{ $segnalazione->id_segnalazione }}"
   data-scarica="{{ route('segnalazioni.allegati.download', $chiavi) }}"
   data-didascalia="{{ $allegato->fase === 'dopo' ? 'Dopo l\'intervento' : 'Foto della segnalazione' }} · {{ $allegato->created_at?->timezone('Europe/Rome')->format('d/m/Y H:i') }}"
   class="block overflow-hidden rounded-md border border-[var(--slate-200)]" aria-label="Apri la foto {{ $allegato->nome_originale }}">
    <img src="{{ $fotoUrl }}" alt="{{ $allegato->nome_originale }}" loading="lazy" class="{{ $classe ?? 'h-24 w-32 object-cover sm:h-28 sm:w-36' }}">
</a>
