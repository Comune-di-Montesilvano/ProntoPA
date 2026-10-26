@props(['livello' => 2])

@php
    $livello = (int) ($livello ?: 2);
    $etichetta = [1 => 'Bassa', 2 => 'Media', 3 => 'Alta', 4 => 'Critica'][$livello] ?? 'Media';
    $tono = [3 => 'pa-testo-amber', 4 => 'pa-testo-rose'][$livello] ?? 'pa-testo-slate';
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2 text-sm font-semibold '.$tono]) }}>
    <span class="pa-dot pa-dot-{{ $livello }}" aria-hidden="true"></span>{{ $etichetta }}
</span>
