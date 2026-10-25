@props(['segnalazione', 'estesa' => false])

@php
    $info = $estesa
        ? \App\Support\Segnalazioni\Scadenza::estesa($segnalazione)
        : \App\Support\Segnalazioni\Scadenza::per($segnalazione);
@endphp

@if($info)
    @if($estesa)
        <x-pa.pill :tono="$info['tono']" {{ $attributes }}>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
            {{ $info['testo'] }}
        </x-pa.pill>
    @else
        <span {{ $attributes->merge(['class' => 'text-sm pa-testo-'.$info['tono'].($info['tono'] === 'slate' ? '' : ' font-bold')]) }}>{{ $info['testo'] }}</span>
    @endif
@elseif(! $estesa)
    <span {{ $attributes->merge(['class' => 'text-sm text-[var(--slate-400)]']) }}>—</span>
@endif
