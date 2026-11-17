{{-- Sede: il comune la corregge tra le sedi dello stesso istituto (1.0.6). --}}
@if($segnalazione->plesso)
    @php $sedi = $segnalazione->plesso->stessoIstituto(); @endphp
    @if($sedi->count() > 1)
        <x-pa.drawer name="sede" title="Cambia sede" :sottotitolo="'#'.$segnalazione->id_segnalazione.' · '.($segnalazione->plesso->istituto?->descrizione ?? '')" :aperto="old('_pannello') === 'sede'">
            <form method="POST" action="{{ route('segnalazioni.sede', $segnalazione->id_segnalazione) }}" class="flex flex-1 flex-col">
                @csrf @method('PATCH')
                <input type="hidden" name="_pannello" value="sede">
                <fieldset class="flex flex-1 flex-col gap-2 px-6 py-5">
                    <legend class="sr-only">Sede</legend>
                    @foreach($sedi as $sede)
                        <label class="flex cursor-pointer items-start gap-3 rounded-md border border-[var(--slate-200)] px-3 py-2 hover:bg-[var(--slate-50)] has-[:checked]:border-[var(--ente-primary)] has-[:checked]:bg-[var(--slate-50)]">
                            <input type="radio" name="id_plesso" value="{{ $sede->id_plesso }}" class="mt-1"
                                   @checked((int) old('id_plesso', $segnalazione->id_plesso) === $sede->id_plesso)>
                            <span class="flex flex-col">
                                <span class="text-sm font-semibold text-[var(--ink)]">{{ $sede->nomeLeggibile() }}</span>
                                @if($sede->indirizzo)<span class="text-xs text-[var(--slate-500)]">{{ $sede->indirizzo }}</span>@endif
                            </span>
                        </label>
                    @endforeach
                    <p class="m-0 mt-1 text-xs text-[var(--slate-500)]">Solo sedi dello stesso istituto. Resta una nota interna; scadenza e assegnazione non cambiano.</p>
                    <x-input-error :messages="$errors->get('id_plesso')" />
                </fieldset>
                <footer class="flex justify-end gap-2 border-t border-[var(--slate-200)] px-6 py-4">
                    <x-pa.btn x-on:click="$dispatch('apri-pannello', '')">Annulla</x-pa.btn>
                    <x-pa.btn type="submit" variant="primary">Salva</x-pa.btn>
                </footer>
            </form>
        </x-pa.drawer>
    @endif
@endif
