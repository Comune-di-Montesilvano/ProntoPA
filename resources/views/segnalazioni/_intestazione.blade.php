{{-- Intestazione di un elenco .pa-elenco: stesse chiavi di colonna di segnalazioni._riga.
     Parametri: colonne, gruppo (celle di gruppo iniziali: lista di 'sede'|'tipologia'). --}}
@php
    $nomi = [
        'sede' => 'Sede', 'tipologia' => 'Tipologia', 'stato' => 'Stato', 'priorita' => 'Priorità',
        'scadenza' => 'Scadenza', 'segnalazione' => 'Segnalazione', 'aggiornata' => 'Aggiornata', 'affida' => 'Operaio',
    ];
@endphp
<thead>
    <tr>
        @foreach(array_merge($gruppo ?? [], $colonne) as $colonna)
            @switch($colonna)
                @case('seleziona')
                    <th scope="col" class="w-8">
                        <input type="checkbox" aria-label="Seleziona tutte le righe della pagina"
                               :checked="pagina.length && pagina.every(id => scelte.includes(id))"
                               x-on:change="scelte = $event.target.checked ? [...new Set([...scelte, ...pagina])] : scelte.filter(id => !pagina.includes(id))">
                    </th>
                    @break
                @case('azioni')
                    <th scope="col"><span class="sr-only">Azioni</span></th>
                    @break
                @default
                    <th scope="col">{{ $nomi[$colonna] ?? '' }}</th>
            @endswitch
        @endforeach
    </tr>
</thead>
