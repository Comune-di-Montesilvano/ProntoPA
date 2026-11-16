{{-- Tipologia nel colore della tavolozza unica: sfondo pieno tenue, icona e nome. --}}
@props(['tipologia'])
@if($tipologia)
    <span {{ $attributes->merge(['class' => 'pa-tipologia pa-tono-'.$tipologia->tono()]) }}>
        <i class="{{ $tipologia->icona ?: 'fas fa-tag' }}" aria-hidden="true"></i>{{ $tipologia->nomeLeggibile() }}
    </span>
@else
    <span {{ $attributes->merge(['class' => 'pa-tipologia pa-tono-grigio']) }}>—</span>
@endif
