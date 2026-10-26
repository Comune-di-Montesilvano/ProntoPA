@props(['tono' => 'slate'])

<span {{ $attributes->merge(['class' => 'pa-pill pa-pill-'.$tono]) }}>{{ $slot }}</span>
