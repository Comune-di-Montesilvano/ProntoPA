{{-- Sollecito a chi ha il lavoro: nota + email (DestinatariAssegnatario). --}}
<x-pa.drawer name="sollecita" title="Sollecita" :sottotitolo="'#'.$segnalazione->id_segnalazione" :aperto="old('_pannello') === 'sollecita'">
    <form method="POST" action="{{ route('segnalazioni.sollecita', $segnalazione) }}" class="flex flex-1 flex-col">
        @csrf
        <input type="hidden" name="_pannello" value="sollecita">
        <div class="flex flex-1 flex-col gap-4 px-6 py-5">
            <p class="m-0 text-sm text-[var(--slate-700)]">
                Arriva per email a chi ha il lavoro
                @if($segnalazione->id_appalto) (persone delegate della ditta e indirizzo aziendale)@endif
                e resta nell'attività.
            </p>
            <div class="pa-field">
                <label for="sollecito-testo" class="pa-field-label">Messaggio</label>
                <textarea id="sollecito-testo" name="testo" rows="4" maxlength="2000" class="pa-textarea">{{ old('_pannello') === 'sollecita' ? old('testo') : 'aggiornare sullo stato del lavoro.' }}</textarea>
                <p class="pa-field-help">Verrà preceduto da "Sollecito:".</p>
                <x-input-error :messages="$errors->get('testo')" />
            </div>
        </div>
        <footer class="flex justify-end gap-2 border-t border-[var(--slate-200)] px-6 py-4">
            <x-pa.btn x-on:click="$dispatch('apri-pannello', '')">Annulla</x-pa.btn>
            <x-pa.btn type="submit" variant="primary">Invia sollecito</x-pa.btn>
        </footer>
    </form>
</x-pa.drawer>
