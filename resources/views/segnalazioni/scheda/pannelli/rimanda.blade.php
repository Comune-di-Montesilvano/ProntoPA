{{-- Operaio, caposquadra o ditta: "non è roba mia / serve altro". Torna al gestore che aveva assegnato. --}}
@php($mio = old('_pannello') === 'rimanda')
<x-pa.drawer name="rimanda" title="Rimanda al comune" :sottotitolo="'#'.$segnalazione->id_segnalazione" :aperto="$mio">
    <form method="POST" action="{{ route('segnalazioni.rimanda', $segnalazione) }}" class="flex flex-1 flex-col">
        @csrf
        <input type="hidden" name="_pannello" value="rimanda">
        <div class="flex flex-1 flex-col gap-4 px-6 py-5">
            <p class="m-0 text-sm text-[var(--slate-600)]">
                Il lavoro torna al comune, che lo riassegnerà. Da quel momento non lo vedi più tra i tuoi.
            </p>
            <div class="pa-field">
                <label for="rimanda-nota" class="pa-field-label">Perché lo rimandi</label>
                <textarea id="rimanda-nota" name="nota" rows="4" maxlength="2000" class="pa-textarea" required
                          placeholder="Es. serve un elettricista, non è un lavoro da falegname">{{ $mio ? old('nota') : '' }}</textarea>
                <x-input-error :messages="$errors->get('nota')" />
            </div>
        </div>
        <footer class="flex justify-end gap-2 border-t border-[var(--slate-200)] px-6 py-4">
            <x-pa.btn x-on:click="$dispatch('apri-pannello', '')">Annulla</x-pa.btn>
            <x-pa.btn type="submit" variant="primary" size="lg">Rimanda al comune</x-pa.btn>
        </footer>
    </form>
</x-pa.drawer>
