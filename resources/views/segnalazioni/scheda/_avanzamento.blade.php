{{-- Barra a 4 tappe: Ricevuta → Presa in carico → In lavorazione → Chiusa (date dal primo ingresso in ciascuna). --}}
@use('App\Enums\SegnalazioneStato')
@php
    $stato = $segnalazione->statoEnum();
    $corrente = $stato->tappa();
    $date = [1 => $segnalazione->data_segnalazione];
    foreach ($segnalazione->storicoStati->sortBy('data_registrazione') as $evento) {
        $tappa = SegnalazioneStato::tryFrom((int) $evento->id_stato_segnalazione)?->tappa();
        if ($tappa && $tappa > 1 && ! isset($date[$tappa])) {
            $date[$tappa] = $evento->data_registrazione;
        }
    }
    if ($corrente === 4 && ! isset($date[4])) {
        $date[4] = $segnalazione->data_chiusura;
    }
    $tappe = [
        1 => 'Ricevuta',
        2 => 'Presa in carico',
        3 => $stato === SegnalazioneStato::SOSPESA ? 'Sospesa' : 'In lavorazione',
        4 => $corrente === 4 && $stato !== SegnalazioneStato::COMPLETATA ? $stato->etichetta() : 'Chiusa',
    ];
@endphp
<ol class="grid grid-cols-4 gap-2 m-0 p-0 list-none" aria-label="Avanzamento">
    @foreach($tappe as $n => $etichetta)
        @php
            $fatta = $n <= $corrente;
            $sospesa = $n === 3 && $stato === SegnalazioneStato::SOSPESA;
        @endphp
        <li class="flex flex-col gap-1.5 min-w-0" @if($n === $corrente) aria-current="step" @endif>
            <span class="pa-tappa-bar {{ $sospesa ? 'sospesa' : ($fatta ? 'fatta' : '') }}"></span>
            <span class="text-xs sm:text-sm font-semibold truncate {{ $sospesa ? 'pa-testo-amber' : ($fatta ? 'text-[var(--ink)]' : 'text-[var(--slate-400)]') }}">{{ $etichetta }}</span>
            <span class="text-xs text-[var(--slate-500)]">{{ $fatta && ! empty($date[$n]) ? \Illuminate\Support\Carbon::parse($date[$n])->format('d/m') : ' ' }}</span>
        </li>
    @endforeach
</ol>
