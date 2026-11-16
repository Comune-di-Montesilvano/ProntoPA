{{-- Tipologia: la corregge il comune (1.0.4). Stessi riquadri colorati della nuova segnalazione. --}}
<x-pa.drawer name="tipologia" title="Cambia tipologia" :sottotitolo="'#'.$segnalazione->id_segnalazione" :aperto="old('_pannello') === 'tipologia'">
    <form method="POST" action="{{ route('segnalazioni.tipologia', $segnalazione->id_segnalazione) }}" class="flex flex-1 flex-col"
          x-data="{ scelta: {{ (int) old('id_tipologia_segnalazione', $segnalazione->id_tipologia_segnalazione) }} }">
        @csrf @method('PATCH')
        <input type="hidden" name="_pannello" value="tipologia">
        <input type="hidden" name="id_tipologia_segnalazione" :value="scelta">
        <div class="flex flex-1 flex-col gap-3 px-6 py-5">
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                @foreach(\App\Models\TipologiaSegnalazione::attive()->orderBy('descrizione')->get() as $t)
                    <button type="button" x-on:click="scelta = {{ $t->id_tipologia_segnalazione }}"
                            :class="scelta == {{ $t->id_tipologia_segnalazione }} ? 'pa-riquadro-scelto' : ''"
                            :aria-pressed="scelta == {{ $t->id_tipologia_segnalazione }}"
                            class="pa-riquadro-tipologia pa-tono-{{ $t->tono() }}">
                        <span><i class="{{ $t->icona ?: 'fas fa-tag' }} text-lg" aria-hidden="true"></i></span>
                        <span class="text-xs font-semibold text-center leading-tight">{{ $t->nomeLeggibile() }}</span>
                    </button>
                @endforeach
            </div>
            <p class="m-0 text-xs text-[var(--slate-500)]">La scadenza si ricalcola con la regola della nuova tipologia (se non è stata fissata a mano) e ne vengono avvisati i gestori.</p>
            <x-input-error :messages="$errors->get('id_tipologia_segnalazione')" />
        </div>
        <footer class="flex justify-end gap-2 border-t border-[var(--slate-200)] px-6 py-4">
            <x-pa.btn x-on:click="$dispatch('apri-pannello', '')">Annulla</x-pa.btn>
            <x-pa.btn type="submit" variant="primary">Salva</x-pa.btn>
        </footer>
    </form>
</x-pa.drawer>
