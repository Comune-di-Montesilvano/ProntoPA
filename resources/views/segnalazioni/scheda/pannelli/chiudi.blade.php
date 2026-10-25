{{-- Chiudi: con il rapportino, salvo che ditta/operaio l'abbiano già mandato (fine lavori). --}}
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
                <p class="m-0 text-sm text-[var(--slate-700)]">La fine dei lavori è già stata comunicata con le foto: verificale in Attività e chiudi.</p>
            @else
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="pa-field">
                        <label for="chiudi-ore" class="pa-field-label">Ore impiegate</label>
                        <input id="chiudi-ore" type="number" step="0.1" min="0" name="ore_lavoro" class="pa-input" required value="{{ $mio ? old('ore_lavoro') : '' }}">
                        <x-input-error :messages="$errors->get('ore_lavoro')" />
                    </div>
                    <div class="pa-field">
                        <label for="chiudi-materiali" class="pa-field-label">Materiali usati</label>
                        <input id="chiudi-materiali" type="text" name="materiali" maxlength="1000" class="pa-input" required value="{{ $mio ? old('materiali') : '' }}">
                        <x-input-error :messages="$errors->get('materiali')" />
                    </div>
                </div>
                <div class="pa-field">
                    <label for="chiudi-foto" class="pa-field-label">Foto del lavoro finito</label>
                    <input id="chiudi-foto" type="file" name="allegati[]" multiple accept="{{ $mime }}" required class="text-sm">
                    <x-input-error :messages="array_merge($errors->get('allegati'), $errors->get('allegati.*'))" />
                </div>
            @endif
            <div class="pa-field">
                <label for="chiudi-nota" class="pa-field-label">{{ $giaRendicontata ? 'Nota (facoltativa)' : 'Descrizione dell\'intervento' }}</label>
                <textarea id="chiudi-nota" name="nota" rows="3" maxlength="2000" class="pa-textarea" @required(! $giaRendicontata)>{{ $mio ? old('nota') : '' }}</textarea>
                <x-input-error :messages="$errors->get('nota')" />
            </div>
            <x-input-error :messages="$errors->get('azione')" />
        </div>
        <footer class="flex justify-end gap-2 border-t border-[var(--slate-200)] px-6 py-4">
            <x-pa.btn x-on:click="$dispatch('apri-pannello', '')">Annulla</x-pa.btn>
            <x-pa.btn type="submit" variant="primary">Chiudi la segnalazione</x-pa.btn>
        </footer>
    </form>
</x-pa.drawer>
