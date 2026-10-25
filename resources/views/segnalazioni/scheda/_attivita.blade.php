{{-- Storia unica (note, stati, allegati) dalla più recente, già filtrata per chi legge (Attivita::per). --}}
@php $antivirus = (bool) \App\Models\Impostazione::get('antivirus_enabled', false); @endphp
<ol class="m-0 p-0 list-none flex flex-col">
    @forelse($attivita as $voce)
        <li class="flex gap-3 py-3 border-t border-[var(--slate-100)] first:border-t-0">
            <span aria-hidden="true"
                  class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-bold
                         {{ $voce['tipo'] === 'nota' ? 'bg-[var(--ente-primary-100)] text-[var(--ente-primary)]' : 'bg-[var(--slate-100)] text-[var(--slate-600)]' }}">
                {{ $voce['iniziali'] }}
            </span>
            <div class="min-w-0 flex-1">
                <p class="m-0 text-sm text-[var(--slate-700)]">
                    <strong class="text-[var(--ink)]">{{ $voce['autore'] }}</strong>
                    @if($voce['tipo'] !== 'nota' || $voce['testo'] === null) {{ $voce['frase'] }} @endif
                    <span class="text-[var(--slate-500)]">· {{ $voce['quando']->format('d/m/Y H:i') }}</span>
                    @if($voce['tag'])
                        <x-pa.pill :tono="$voce['tag'] === 'Interna' ? 'slate' : 'primary'" class="ml-1">{{ $voce['tag'] }}</x-pa.pill>
                    @endif
                </p>

                @if($voce['testo'] !== null)
                    <p class="m-0 mt-1 text-[15px] text-[var(--ink)] whitespace-pre-wrap break-words">{{ $voce['testo'] }}</p>
                @endif

                @if($allegato = $voce['allegato'])
                    @php $url = route('segnalazioni.allegati.download', [$segnalazione->id_segnalazione, $allegato->id_allegato]); @endphp
                    <div class="mt-2 flex flex-wrap items-center gap-3">
                        @if($allegato->isInfetto())
                            <span class="text-sm pa-testo-rose font-semibold">{{ $allegato->nome_originale }} — infetto, in quarantena</span>
                        @elseif(str_starts_with((string) $allegato->tipo, 'image/'))
                            <a href="{{ $url }}" class="block overflow-hidden rounded-md border border-[var(--slate-200)]">
                                <img src="{{ $url }}" alt="{{ $allegato->nome_originale }}" loading="lazy" class="h-24 w-32 object-cover">
                            </a>
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
    @empty
        <li class="py-6 text-center text-sm text-[var(--slate-500)]">Ancora nessun aggiornamento.</li>
    @endforelse
</ol>
