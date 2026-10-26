{{-- Riga di un elenco di segnalazioni (tabella .pa-elenco, a scheda sotto 768px).
     Parametri: s, colonne (priorita|segnalazione|stato|assegnata|scadenza|aggiornata|azioni),
     lettura ('gestore'|'lavoro'|'segnalante', default gestore), rapide (azioni rapide, opzionale),
     segui (true = visibile solo per adesione, lettura segnalante). --}}
@php
    $lettura = $lettura ?? 'gestore';
    $stato = $s->statoEnum();
    $url = route('segnalazioni.show', $s->id_segnalazione);
    $titolo = $s->titolo_generato ?: \Illuminate\Support\Str::limit((string) $s->testo_segnalazione, 80);
    $sede = $s->plesso?->nome ?? ($lettura === 'gestore' ? $s->provenienza?->descrizione : null);
@endphp
<tr>
    @foreach($colonne as $colonna)
        @switch($colonna)
            @case('priorita')
                <td class="pa-col-priorita whitespace-nowrap">
                    <x-pa.prio :livello="$s->livello_priorita" :urgente="(bool) $s->segnalazione_urgente" />
                </td>
                @break

            @case('segnalazione')
                <td class="pa-col-segnalazione min-w-0">
                    <a href="{{ $url }}" class="pa-elenco-titolo"
                       title="Segnalata il {{ $s->data_segnalazione?->format('d/m/Y') }}">{{ $titolo }}</a>
                    @if($lettura === 'gestore')
                        @if($s->flag_evidenza)<i class="fas fa-star ml-1 text-[var(--amber)]" title="In evidenza" aria-label="In evidenza"></i>@endif
                        @if($s->flag_riservata)<i class="fas fa-lock ml-1 text-[var(--slate-500)]" title="Riservata" aria-label="Riservata"></i>@endif
                    @endif
                    <div class="pa-elenco-meta">
                        <span class="pa-mono">#{{ $s->id_segnalazione }}</span>
                        · <i class="{{ $s->tipologia?->icona ?? 'fas fa-circle' }}" aria-hidden="true"></i> {{ $s->tipologia?->descrizione ?? '—' }}
                        @if($sede) · <span @if($s->plesso) title="{{ $s->plesso->dettagli() }}" @endif>{{ $sede }}</span>@endif
                    </div>
                    @if(! empty($segui) || ($lettura !== 'segnalante' && ($s->isSollecitata() || $s->isRicorrente())))
                        <div class="mt-1 flex flex-wrap gap-1">
                            @if(! empty($segui))<x-pa.pill tono="primary">Segui</x-pa.pill>@endif
                            @if($lettura !== 'segnalante' && $s->isSollecitata())<x-pa.pill tono="amber">Sollecitata</x-pa.pill>@endif
                            @if($lettura !== 'segnalante' && $s->isRicorrente())<x-pa.pill tono="rose">Ricorrente</x-pa.pill>@endif
                        </div>
                    @endif
                </td>
                @break

            @case('stato')
                <td class="pa-col-stato whitespace-nowrap">
                    @if($lettura === 'segnalante')
                        @php $frase = $stato->fraseSegnalante(); @endphp
                        <x-pa.pill :tono="$stato->tono()" title="{{ $frase['dettaglio'] }}">{{ $frase['titolo'] }}</x-pa.pill>
                    @else
                        <x-pa.pill :tono="$stato->tono()">{{ $stato->etichetta() }}</x-pa.pill>
                    @endif
                </td>
                @break

            @case('assegnata')
                <td class="pa-col-assegnata">
                    @php $assegnatario = $s->nomeAssegnatario(); @endphp
                    @if($assegnatario)
                        <span class="text-[var(--slate-700)]">{{ $assegnatario }}</span>
                    @else
                        <span class="text-[var(--slate-400)]">Da assegnare</span>
                    @endif
                </td>
                @break

            @case('scadenza')
                <td class="pa-col-scadenza whitespace-nowrap">
                    @if($s->isChiusa())
                        <span class="text-sm text-[var(--slate-500)]">Chiusa il {{ $s->data_chiusura?->format('d/m/Y') }}</span>
                    @else
                        <x-pa.scadenza :segnalazione="$s" />
                    @endif
                </td>
                @break

            @case('aggiornata')
                <td class="pa-col-aggiornata whitespace-nowrap text-sm text-[var(--slate-500)]">
                    {{ ($s->data_chiusura ?? $s->data_segnalazione)?->format('d/m/Y') }}
                </td>
                @break

            @case('azioni')
                <td class="pa-col-azioni text-right">
                    @php $rapide = $rapide ?? collect(); @endphp
                    @if($rapide->isNotEmpty())
                        <div x-data="{ open: false, posX: 0, posY: 0 }" class="inline-block text-left">
                            <button type="button"
                                    @click="const r = $el.getBoundingClientRect(); posX = r.right; posY = r.bottom; open = !open"
                                    class="flex h-9 w-9 items-center justify-center rounded-md text-[var(--slate-600)] hover:bg-[var(--slate-100)]"
                                    title="Azioni rapide" aria-label="Azioni rapide">⋮</button>
                            <template x-teleport="body">
                                <div x-show="open" x-cloak @click.outside="open = false"
                                     class="fixed z-50 w-52 rounded-md border border-[var(--slate-200)] bg-white py-1 shadow-lg"
                                     :style="`top: ${posY + 4}px; left: ${posX - 208}px`">
                                    @foreach($rapide as $azione)
                                        <form method="POST" action="{{ route('segnalazioni.azione', $s->id_segnalazione) }}"
                                              onsubmit="return confirm({{ \Illuminate\Support\Js::from('Eseguire: '.$azione->descrizione.'?') }});">
                                            @csrf
                                            <input type="hidden" name="id_azione" value="{{ $azione->id_azione }}">
                                            <button type="submit" class="block w-full px-4 py-2 text-left text-sm text-[var(--slate-700)] hover:bg-[var(--slate-50)]">
                                                {{ $azione->descrizione }}
                                            </button>
                                        </form>
                                    @endforeach
                                </div>
                            </template>
                        </div>
                    @endif
                </td>
                @break
        @endswitch
    @endforeach
</tr>
