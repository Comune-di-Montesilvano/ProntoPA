{{-- Priorità nella tavolozza unica: etichetta. Media non si mostra (salvo media=true: pannelli, scheda). --}}
@props(['livello' => 2, 'media' => false])
@php
    $livello = (int) ($livello ?: 2);
    $voce = [
        1 => ['Bassa', 'fa-arrow-down'], 2 => ['Media', null], 3 => ['Alta', 'fa-arrow-up'], 4 => ['Critica', 'fa-angles-up'],
    ][$livello] ?? ['Media', null];
    $tono = \App\Support\Colori::priorita($livello);
@endphp
@if($livello !== 2 || $media)
    <span {{ $attributes->merge(['class' => 'pa-priorita pa-priorita-'.$livello.($tono ? ' pa-tono-'.$tono : '')]) }}>
        @if($voce[1])<i class="fas {{ $voce[1] }}" aria-hidden="true"></i>@endif{{ $voce[0] }}
    </span>
@endif
