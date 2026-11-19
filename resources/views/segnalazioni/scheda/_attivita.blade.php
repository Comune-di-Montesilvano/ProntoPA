{{-- Storia (note, passaggi, allegati) per giorno, dalla più recente, già filtrata per chi legge (Attivita::per → Storia::giorni). --}}
@php
    $antivirus = (bool) \App\Models\Impostazione::get('antivirus_enabled', false);
    $storia ??= \App\Support\Segnalazioni\Storia::giorni($attivita);
    $chiHaSegnalato = $segnalazione->utente?->isSpid() ? 'alla scuola' : 'a chi ha segnalato';
    $pubblica = fn (array $v) => $v['forma'] === 'messaggio'
        && ($v['letta_da'] === null ? $lettura !== 'gestore' : ! in_array($v['letta_da'], ['Solo il comune', 'La legge la ditta'], true));
@endphp
<div x-data="{ filtro: 'tutto' }" class="flex flex-col">
    @if($lettura === 'gestore' && $attivita->isNotEmpty())
        <div class="pa-storia-filtri" role="group" aria-label="Filtra la storia">
            <button type="button" class="pa-storia-chip" :class="filtro === 'tutto' && 'is-on'" x-on:click="filtro = 'tutto'">Tutto</button>
            <button type="button" class="pa-storia-chip" :class="filtro === 'passaggio' && 'is-on'" x-on:click="filtro = 'passaggio'">Solo passaggi</button>
            <button type="button" class="pa-storia-chip" :class="filtro === 'pubblico' && 'is-on'" x-on:click="filtro = 'pubblico'">Solo messaggi {{ $chiHaSegnalato }}</button>
        </div>
    @endif

    @forelse($storia as $gruppo)
        @if(isset($gruppo['vuoto']))
            @php [$quali, $resto] = array_pad(explode(' · ', $gruppo['vuoto'], 2), 2, ''); @endphp
            <p class="pa-storia-giorno" x-show="filtro === 'tutto'">{{ $quali }} <small>{{ $resto }}</small></p>
            @continue
        @endif
        <p class="pa-storia-giorno">{{ $gruppo['intestazione'] }}</p>
        <ol class="pa-storia">
            @foreach($gruppo['voci'] as $voce)
                @php
                    $pub = $pubblica($voce);
                    $passaggio = $voce['forma'] === 'passaggio';
                    $nodo = match (true) {
                        $voce['stato'] === \App\Enums\SegnalazioneStato::SOSPESA => 'is-pausa',
                        str_starts_with($voce['frase'], "l'ha segnalata di nuovo") => 'is-dup',
                        $voce['stato'] !== null => 'is-stato',
                        $pub => 'is-pub',
                        default => '',
                    };
                    $conStato = $voce['stato'] !== null && $lettura !== 'segnalante' && ! $voce['stato']->isTerminale();
                @endphp
                <li x-show="filtro === 'tutto' || (filtro === 'passaggio' && {{ $passaggio ? 'true' : 'false' }}) || (filtro === 'pubblico' && {{ $pub ? 'true' : 'false' }})">
                    <time datetime="{{ $voce['quando']->toIso8601String() }}">{{ $voce['quando']->format('H:i') }}</time>
                    <span class="pa-storia-nodo {{ $nodo }}" aria-hidden="true"></span>
                    <div class="min-w-0">
                        @if(! $passaggio || $voce['nota'] !== null)
                            <div class="pa-storia-bolla {{ $pub ? 'is-pub' : '' }}">
                                <div class="pa-storia-chi">
                                    <span><strong>{{ $voce['autore'] }}</strong> {{ $voce['frase'] }}@if($conStato) → <span class="pa-storia-stato">{{ $voce['stato']->label() }}</span>@endif</span>
                                    @if($voce['letta_da'])
                                        <span class="pa-storia-vede {{ $voce['letta_da'] === 'Solo il comune' ? '' : 'is-pub' }}">
                                            <span aria-hidden="true"><i class="fas {{ $voce['letta_da'] === 'Solo il comune' ? 'fa-lock' : 'fa-eye' }}"></i></span>{{ $voce['letta_da'] }}
                                        </span>
                                    @endif
                                </div>
                                @if($voce['testo'] !== null)<p>{{ $voce['testo'] }}</p>@endif
                                @if($voce['nota'] !== null)<p>{{ $voce['nota'] }}</p>@endif
                            </div>
                        @else
                            <p class="pa-storia-fatto">
                                <strong>{{ $voce['autore'] }}</strong> {{ $voce['frase'] }}@if($conStato) → <span class="pa-storia-stato">{{ $voce['stato']->label() }}</span>@endif
                            </p>
                        @endif

                        @if($allegato = $voce['allegato'])
                            @php $url = route('segnalazioni.allegati.download', [$segnalazione->id_segnalazione, $allegato->id_allegato]); @endphp
                            <div class="mt-2 flex flex-wrap items-center gap-3">
                                @if($allegato->isInfetto())
                                    <span class="text-sm pa-testo-rose font-semibold">{{ $allegato->nome_originale }} — infetto, in quarantena</span>
                                @elseif($allegato->isFotoVisibile())
                                    @include('segnalazioni.scheda._miniatura', ['classe' => 'h-24 w-32 object-cover'])
                                @else
                                    <a href="{{ $url }}" class="text-sm font-semibold text-[var(--ente-primary)] underline">{{ $allegato->nome_originale }}</a>
                                @endif
                                @if($antivirus && in_array($allegato->stato_scansione, ['errore', 'in_attesa'], true))
                                    <span class="text-xs text-[var(--slate-500)]">{{ $allegato->stato_scansione === 'errore' ? 'Scansione non riuscita' : 'In attesa di scansione' }}</span>
                                @endif
                                @can('deleteAllegato', $segnalazione)
                                    <form method="POST" action="{{ route('segnalazioni.allegati.destroy', [$segnalazione->id_segnalazione, $allegato->id_allegato]) }}"
                                          onsubmit="return confirm('Eliminare questo allegato?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-xs text-[var(--slate-500)] underline hover:text-[var(--rose)]">Elimina</button>
                                    </form>
                                @endcan
                            </div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    @empty
        <p class="py-6 text-center text-sm text-[var(--slate-500)]">Ancora nessun aggiornamento.</p>
    @endforelse
</div>
