<x-mail::message :loghi="\App\Support\Brand::loghiEmail($message ?? null)">
{{-- Greeting --}}
@if (! empty($greeting))
# {{ $greeting }}
@else
@if ($level === 'error')
# Attenzione
@else
# Buongiorno,
@endif
@endif

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Action Button --}}
@isset($actionText)
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Salutation --}}
@if (! empty($salutation))
{{ $salutation }}
@else
Cordiali saluti,<br>
{{ \App\Models\Impostazione::get('ente_nome', config('app.name')) }}
@endif

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
Se il pulsante "{{ $actionText }}" non funziona, copia e incolla questo indirizzo nel browser: <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
