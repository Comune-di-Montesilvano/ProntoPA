{{-- Chiudi (lavoro fatto): nota e foto facoltative. --}}
@php
    $azione = $azioniDisponibili->firstWhere('codice', 'chiudi');
    $giaRendicontata = $segnalazione->haFineLavori();
    $mime = \App\Models\Impostazione::get('allegati_mime_consentiti', 'image/jpeg,image/png');
    $mio = old('_pannello') === 'chiudi';
@endphp
<x-pa.drawer name="chiudi" title="Chiudi la segnalazione" :sottotitolo="'#'.$segnalazione->id_segnalazione" :aperto="$mio">
    <form method="POST" action="{{ route('segnalazioni.azione', $segnalazione->id_segnalazione) }}" enctype="multipart/form-data" class="flex flex-1 flex-col">
        @csrf
        <input type="hidden" name="_pannello" value="chiudi">
        <input type="hidden" name="id_azione" value="{{ $azione->id_azione }}">
        <div class="flex flex-1 flex-col gap-4 px-6 py-5">
            @if($giaRendicontata)
                <p class="m-0 text-sm text-[var(--slate-700)]">La fine dei lavori è già stata comunicata: verificala in Attività e chiudi.</p>
            @endif
            <div class="pa-field">
                <label for="chiudi-nota" class="pa-field-label">Cosa è stato fatto (facoltativo)</label>
                <textarea id="chiudi-nota" name="nota" rows="3" maxlength="2000" class="pa-textarea">{{ $mio ? old('nota') : '' }}</textarea>
                <x-input-error :messages="$errors->get('nota')" />
            </div>
            <div class="pa-field">
                <label for="chiudi-foto" class="pa-field-label">Foto (facoltative)</label>
                <input id="chiudi-foto" type="file" name="allegati[]" multiple accept="{{ $mime }}" class="text-sm">
                <x-input-error :messages="array_merge($errors->get('allegati'), $errors->get('allegati.*'))" />
            </div>
            <x-input-error :messages="$errors->get('azione')" />
        </div>
        <footer class="flex justify-end gap-2 border-t border-[var(--slate-200)] px-6 py-4">
            <x-pa.btn x-on:click="$dispatch('apri-pannello', '')">Annulla</x-pa.btn>
            <x-pa.btn type="submit" variant="primary">Chiudi la segnalazione</x-pa.btn>
        </footer>
    </form>
</x-pa.drawer>
