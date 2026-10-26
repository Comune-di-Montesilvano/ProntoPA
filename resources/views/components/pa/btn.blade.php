@props(['variant' => 'outline', 'href' => null, 'type' => 'button', 'size' => null])

@php $classi = 'pa-btn pa-btn-'.$variant.($size ? ' pa-btn-'.$size : ''); @endphp

@if($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classi]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classi]) }}>{{ $slot }}</button>
@endif
