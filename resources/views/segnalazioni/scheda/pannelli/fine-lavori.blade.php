{{-- Ditta / operaio: "ho finito", con le foto. Lo stato non cambia: chiude il comune. --}}
@php
    $mime = \App\Models\Impostazione::get('allegati_mime_consentiti', 'image/jpeg,image/png');
    $mio = old('_pannello') === 'fine-lavori';
@endphp
<x-pa.drawer name="fine-lavori" title="Comunica fine lavori" :sottotitolo="'#'.$segnalazione->id_segnalazione" :aperto="$mio">
    <form method="POST" action="{{ route('segnalazioni.fine-lavori', $segnalazione) }}" enctype="multipart/form-data" class="flex flex-1 flex-col">
        @csrf
        <input type="hidden" name="_pannello" value="fine-lavori">
        <div class="flex flex-1 flex-col gap-4 px-6 py-5">
            <div class="pa-field">
                <label for="fine-foto" class="pa-field-label">Foto del lavoro finito</label>
                <input id="fine-foto" type="file" name="allegati[]" multiple accept="{{ $mime }}" capture="environment" required class="text-sm">
                <x-input-error :messages="array_merge($errors->get('allegati'), $errors->get('allegati.*'))" />
            </div>
            <div class="pa-field">
                <label for="fine-nota" class="pa-field-label">Cosa è stato fatto</label>
                <textarea id="fine-nota" name="nota" rows="4" maxlength="2000" class="pa-textarea" required>{{ $mio ? old('nota') : '' }}</textarea>
                <x-input-error :messages="$errors->get('nota')" />
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="pa-field">
                    <label for="fine-ore" class="pa-field-label">Ore impiegate (facoltativo)</label>
                    <input id="fine-ore" type="number" step="0.1" min="0" name="ore_lavoro" class="pa-input" value="{{ $mio ? old('ore_lavoro') : '' }}">
                </div>
                <div class="pa-field">
                    <label for="fine-materiali" class="pa-field-label">Materiali (facoltativo)</label>
                    <input id="fine-materiali" type="text" name="materiali" maxlength="1000" class="pa-input" value="{{ $mio ? old('materiali') : '' }}">
                </div>
            </div>
        </div>
        <footer class="flex justify-end gap-2 border-t border-[var(--slate-200)] px-6 py-4">
            <x-pa.btn x-on:click="$dispatch('apri-pannello', '')">Annulla</x-pa.btn>
            <x-pa.btn type="submit" variant="primary" size="lg">Invia al comune</x-pa.btn>
        </footer>
    </form>
</x-pa.drawer>
