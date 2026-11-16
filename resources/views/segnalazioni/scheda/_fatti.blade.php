{{-- Quattro fatti in testa alla scheda gestore (Support/Segnalazioni/Fatti): stato, scadenza, priorità, da quanto è aperta. --}}
@php
    $puoSpostare = auth()->user()->can('modificaPriorita', $segnalazione);
    $riquadri = [
        'stato' => ['etichetta' => 'Stato', 'modifica' => null],
        'scadenza' => ['etichetta' => 'Scadenza', 'modifica' => $puoSpostare && ! $segnalazione->isChiusa() ? ['scadenza', 'sposta', 'Sposta la scadenza'] : null],
        'priorita' => ['etichetta' => 'Priorità', 'modifica' => $puoSpostare ? ['priorita', 'cambia', 'Cambia priorità'] : null],
        'aperta' => ['etichetta' => 'Aperta da', 'modifica' => null],
    ];
@endphp
@if($fatti ?? null)
    <dl class="pa-fatti m-0">
        @foreach($riquadri as $chiave => $r)
            @php $f = $fatti[$chiave]; @endphp
            <div class="pa-fatto pa-fatto-{{ $f['tono'] }} {{ $chiave === 'stato' ? 'pa-fatto-stato' : '' }}"
                 @if($chiave === 'stato' && $f['colore']) style="border-left-color: {{ $f['colore'] }}" @endif>
                <dt class="flex items-center justify-between gap-2">
                    <span>{{ $r['etichetta'] }}</span>
                    @if($r['modifica'])
                        <button type="button" x-data x-on:click="$dispatch('apri-pannello', '{{ $r['modifica'][0] }}')"
                                class="text-xs font-medium normal-case tracking-normal text-[var(--ente-primary)] hover:underline"
                                aria-label="{{ $r['modifica'][2] }}">{{ $r['modifica'][1] }}</button>
                    @endif
                </dt>
                <dd class="m-0 flex flex-col gap-0.5">
                    <span class="pa-fatto-valore">
                        @if(in_array($chiave, ['stato', 'priorita'], true) && $f['colore'])
                            <span class="pa-fatto-pallino" style="background: {{ $f['colore'] }}" aria-hidden="true"></span>
                        @endif
                        {{ $f['valore'] }}
                    </span>
                    @if($f['dettaglio'])
                        <span class="text-sm text-[var(--slate-600)]">{{ $f['dettaglio'] }}</span>
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>
@endif
