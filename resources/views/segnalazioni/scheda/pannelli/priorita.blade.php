{{-- Priorità e urgenza: decide il comune (0.8.21). --}}
<x-pa.drawer name="priorita" title="Priorità" :sottotitolo="'#'.$segnalazione->id_segnalazione" :aperto="old('_pannello') === 'priorita'">
    <form method="POST" action="{{ route('segnalazioni.priorita', $segnalazione->id_segnalazione) }}" class="flex flex-1 flex-col">
        @csrf @method('PATCH')
        <input type="hidden" name="_pannello" value="priorita">
        <div class="flex flex-1 flex-col gap-4 px-6 py-5">
            <fieldset class="m-0 flex flex-col gap-2 border-0 p-0">
                <legend class="pa-field-label mb-1">Livello</legend>
                @foreach([1 => 'Bassa', 2 => 'Media', 3 => 'Alta', 4 => 'Critica'] as $val => $label)
                    <label class="flex items-center gap-2 text-sm">
                        <input type="radio" name="livello_priorita" value="{{ $val }}" @checked((int) ($segnalazione->livello_priorita ?: 2) === $val)>
                        <x-pa.prio :livello="$val" />
                    </label>
                @endforeach
            </fieldset>
            <input type="hidden" name="segnalazione_urgente" value="0">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="segnalazione_urgente" value="1" class="rounded border-[var(--slate-300)]" @checked($segnalazione->segnalazione_urgente)>
                Urgente
            </label>
            <x-input-error :messages="$errors->get('livello_priorita')" />
        </div>
        <footer class="flex justify-end gap-2 border-t border-[var(--slate-200)] px-6 py-4">
            <x-pa.btn x-on:click="$dispatch('apri-pannello', '')">Annulla</x-pa.btn>
            <x-pa.btn type="submit" variant="primary">Salva</x-pa.btn>
        </footer>
    </form>
</x-pa.drawer>
