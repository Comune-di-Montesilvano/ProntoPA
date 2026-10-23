{{-- Logo ente (o nome in testo se manca) | marchio ProntoPA --}}
@props(['size' => 'md'])
@php
    $enteNome = \App\Models\Impostazione::get('ente_nome', 'ProntoPA');
    $logoEnte = \App\Support\Brand::logoEnteUrl();
    $altezza = ['sm' => 'h-9', 'md' => 'h-11', 'lg' => 'h-16'][$size] ?? 'h-11';
    $marchio = ['sm' => 'h-8 w-8', 'md' => 'h-10 w-10', 'lg' => 'h-14 w-14'][$size] ?? 'h-10 w-10';
@endphp
<div {{ $attributes->merge(['class' => 'flex items-center gap-3 min-w-0']) }}>
    @if($logoEnte)
        <img src="{{ $logoEnte }}" alt="{{ $enteNome }}" class="{{ $altezza }} w-auto max-w-[9rem] object-contain shrink-0">
    @else
        <span class="font-bold truncate" style="color: var(--ink);">{{ $enteNome }}</span>
    @endif
    <span aria-hidden="true" class="shrink-0" style="width:1px; align-self:stretch; background: var(--slate-200);"></span>
    <x-application-logo class="{{ $marchio }} shrink-0" style="color: #0B3A8C;" />
</div>
