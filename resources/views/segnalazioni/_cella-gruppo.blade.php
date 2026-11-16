{{-- Cella di gruppo (rowspan) dell'elenco gestione raggruppato: vedi App\Support\Segnalazioni\GruppiElenco.
     Primo livello: casella che sceglie le righe del gruppo in questa pagina + conteggio sull'intero filtro. --}}
<td rowspan="{{ $cella['rowspan'] }}"
    class="pa-cella-gruppo pa-cella-gruppo-{{ $cella['livello'] }} pa-cella-gruppo-{{ $cella['tipo'] }} {{ $cella['tono'] ? 'pa-tono-'.$cella['tono'] : '' }}">
    <div class="pa-cella-gruppo-dentro">
        @if($cella['livello'] === 1)
            <input type="checkbox" class="mb-1" x-data="{ ids: @js(array_map('strval', $cella['ids'])) }"
                   :checked="ids.every(id => scelte.includes(id))"
                   x-on:change="scelte = $event.target.checked ? [...new Set([...scelte, ...ids])] : scelte.filter(id => !ids.includes(id))"
                   aria-label="Seleziona le segnalazioni di {{ $cella['nome'] }} ">
        @endif
        <span class="pa-cella-gruppo-nome">
            <i class="{{ $cella['icona'] }}" aria-hidden="true"></i>{{ $cella['nome'] }}
        </span>
        @if($cella['totale'] !== null)
            <span class="pa-cella-gruppo-conto">{{ $cella['totale'] }} · {{ $cella['in_ritardo'] }} in ritardo</span>
        @endif
    </div>
</td>
