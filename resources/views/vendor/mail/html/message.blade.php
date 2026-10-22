@php
    $enteNome = \App\Models\Impostazione::get('ente_nome', config('app.name'));
    $enteLogo = \App\Models\Impostazione::get('ente_logo_url');
    $enteLogo = filled($enteLogo) ? url($enteLogo) : null;
    $enteSito = \App\Models\Impostazione::get('ente_sito_url') ?: config('app.url');
@endphp
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="$enteSito">
@if ($enteLogo)
<img src="{{ $enteLogo }}" class="logo" alt=""><br>
@endif
{{ $enteNome }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
{{ $enteNome }} · servizio segnalazioni [{{ preg_replace('#^https?://#', '', rtrim(config('app.url'), '/')) }}]({{ config('app.url') }})

Email inviata automaticamente: non rispondere a questo messaggio.
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
