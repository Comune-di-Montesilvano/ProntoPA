{{-- Titolo: lo corregge il comune (0.9.12). --}}
<x-pa.drawer name="titolo" title="Titolo" :sottotitolo="'#'.$segnalazione->id_segnalazione" :aperto="old('_pannello') === 'titolo'">
    <form method="POST" action="{{ route('segnalazioni.titolo', $segnalazione->id_segnalazione) }}" class="flex flex-1 flex-col">
        @csrf @method('PATCH')
        <input type="hidden" name="_pannello" value="titolo">
        <div class="flex flex-1 flex-col gap-2 px-6 py-5">
            <label for="titolo-scheda" class="pa-field-label">Titolo</label>
            <input id="titolo-scheda" name="titolo" type="text" maxlength="100" required
                   value="{{ old('titolo', $segnalazione->titolo) }}"
                   class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
            <p class="m-0 text-xs text-[var(--slate-500)]">Massimo 100 caratteri.</p>
            <x-input-error :messages="$errors->get('titolo')" />
        </div>
        <footer class="flex justify-end gap-2 border-t border-[var(--slate-200)] px-6 py-4">
            <x-pa.btn x-on:click="$dispatch('apri-pannello', '')">Annulla</x-pa.btn>
            <x-pa.btn type="submit" variant="primary">Salva</x-pa.btn>
        </footer>
    </form>
</x-pa.drawer>
