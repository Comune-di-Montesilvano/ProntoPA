@props(['titolo' => null])

<section {{ $attributes->merge(['class' => 'pa-card p-5 flex flex-col gap-3']) }}>
    @if($titolo || isset($azione))
        <div class="flex items-baseline justify-between gap-3">
            @if($titolo)<h2 class="pa-sezione-titolo">{{ $titolo }}</h2>@endif
            {{ $azione ?? '' }}
        </div>
    @endif
    {{ $slot }}
</section>
