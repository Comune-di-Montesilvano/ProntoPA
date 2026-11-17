{{-- Riga di un elenco di segnalazioni (tabella .pa-elenco, a scheda sotto 768px).
     Parametri: s, colonne (sede|tipologia|seleziona|stato|priorita|scadenza|segnalazione|aggiornata|affida|azioni),
     lettura ('gestore'|'lavoro'|'segnalante', default gestore), rapide (azioni rapide, opzionale),
     segui (true = visibile solo per adesione, lettura segnalante), membri (caposquadra, colonna affida),
     celle (celle di gruppo con rowspan da GruppiElenco, solo elenco gestione raggruppato).
     Colori (1.0.4): stato = pallino, tipologia = sfondo pieno, priorità = etichetta (Media non si mostra). --}}
@php
    $lettura = $lettura ?? 'gestore';
    $stato = $s->statoEnum();
    $url = route('segnalazioni.show', $s->id_segnalazione);
@endphp
<tr data-segnalazione="{{ $s->id_segnalazione }}">
    @foreach(($celle ?? []) as $cella)
        @include('segnalazioni._cella-gruppo', ['cella' => $cella])
    @endforeach
    @foreach($colonne as $colonna)
        @switch($colonna)
            @case('sede')
                <td class="pa-col-sede">
                    <span class="pa-sede" @if($s->plesso) title="{{ $s->plesso->dettagli() }}" @endif>
                        <i class="fas fa-school" aria-hidden="true"></i>{{ $s->plesso?->nomeLeggibile() ?? 'Senza sede' }}
                    </span>
                </td>
                @break

            @case('tipologia')
                <td class="pa-col-tipologia pa-cella-tipologia pa-tono-{{ $s->tipologia?->tono() ?? 'grigio' }}">
                    <i class="{{ $s->tipologia?->icona ?: 'fas fa-tag' }}" aria-hidden="true"></i>
                    <span>{{ $s->tipologia?->nomeLeggibile() ?? 'Senza tipologia' }}</span>
                </td>
                @break

            @case('seleziona')
                <td class="pa-col-seleziona">
                    <input type="checkbox" value="{{ $s->id_segnalazione }}" x-model="scelte"
                           aria-label="Seleziona #{{ $s->id_segnalazione }}">
                </td>
                @break

            @case('priorita')
                <td class="pa-col-priorita">
                    <x-pa.priorita :livello="$s->livello_priorita" />
                </td>
                @break

            @case('segnalazione')
                <td class="pa-col-segnalazione">
                    <a href="{{ $url }}" class="pa-elenco-testo" data-anteprima="{{ route('segnalazioni.anteprima', $s->id_segnalazione) }}">
                        <span class="pa-mono pa-elenco-id">#{{ $s->id_segnalazione }}</span>
                        @if($lettura === 'gestore' && $s->flag_evidenza)<i class="fas fa-star text-[var(--amber)]" title="In evidenza" aria-label="In evidenza"></i>@endif
                        {{ $s->titoloLeggibile(260) }}
                    </a>
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
                <td class="pa-col-stato">
                    @if($lettura === 'segnalante')
                        <x-pa.stato :stato="$stato" segnalante />
                    @elseif($lettura === 'gestore')
                        <x-pa.stato :stato="$stato"
                            :chi="$s->isChiusa() ? null : ($s->nomeAssegnatario() ?? ($stato === \App\Enums\SegnalazioneStato::NUOVA ? 'da assegnare' : null))" />
                    @else
                        <x-pa.stato :stato="$stato" />
                    @endif
                </td>
                @break

            @case('scadenza')
                <td class="pa-col-scadenza">
                    @if($s->isChiusa())
                        <span class="text-sm text-[var(--slate-500)]">Chiusa il {{ $s->data_chiusura?->format('d/m/Y') }}</span>
                    @else
                        <x-pa.scadenza :segnalazione="$s" />
                    @endif
                </td>
                @break

            @case('aggiornata')
                <td class="pa-col-aggiornata whitespace-nowrap text-sm text-[var(--slate-500)]">
                    @php $ultimo = $s->storico_stati_max_data_registrazione ?? $s->data_chiusura ?? $s->data_segnalazione; @endphp
                    {{ $ultimo ? \Illuminate\Support\Carbon::parse($ultimo)->format('d/m/Y') : '—' }}
                </td>
                @break

            @case('affida')
                <td class="pa-col-affida">
                    @if(! $s->id_operatore_assegnato && ($membri ?? collect())->isNotEmpty())
                        <form method="POST" action="{{ route('operaio.smista', $s->id_segnalazione) }}" class="flex gap-2">
                            @csrf
                            <label for="membro-{{ $s->id_segnalazione }}" class="sr-only">Affida a</label>
                            <select name="id_membro" id="membro-{{ $s->id_segnalazione }}" class="pa-input pa-input-auto" required>
                                <option value="">Affida a…</option>
                                @foreach($membri as $membro)
                                    <option value="{{ $membro->id }}">{{ $membro->name }}</option>
                                @endforeach
                            </select>
                            <x-pa.btn type="submit" size="sm">Affida</x-pa.btn>
                        </form>
                    @else
                        <span class="text-sm text-[var(--slate-700)]">{{ $s->operatore?->name ?? '—' }}</span>
                    @endif
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
