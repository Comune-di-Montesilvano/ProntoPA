{{-- Compatibilità: priorità sempre visibile (anche Media), stesso aspetto della tavolozza unica. --}}
@props(['livello' => 2])
<x-pa.priorita :livello="$livello" media {{ $attributes }} />
