{{-- Stato nella tavolozza unica: pallino + nome in grassetto, sotto (facoltativo) chi ci lavora.
     segnalante: frase della lettura segnalante ("Ricevuta", "Presa in carico"…) al posto del nome interno. --}}
@props(['stato', 'chi' => null, 'segnalante' => false])
@php
    $stato = $stato instanceof \App\Enums\SegnalazioneStato ? $stato : \App\Enums\SegnalazioneStato::from((int) $stato);
    $frase = $segnalante ? $stato->fraseSegnalante() : null;
@endphp
<span {{ $attributes->merge(['class' => 'pa-stato pa-tono-'.$stato->colore()]) }} @if($frase) title="{{ $frase['dettaglio'] }}" @endif>
    <span class="pa-stato-nome">{{ $frase['titolo'] ?? $stato->etichetta() }}</span>
    @if(filled($chi))<span class="pa-stato-chi">{{ $chi }}</span>@endif
</span>
