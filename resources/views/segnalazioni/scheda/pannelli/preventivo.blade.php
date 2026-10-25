{{-- Preventivo: la ditta propone un importo, il comune poi accetta o rifiuta. --}}
@php
    $azione = $azioniDisponibili->firstWhere('codice', 'presenta_preventivo');
    $mio = old('_pannello') === 'preventivo';
@endphp
<x-pa.drawer name="preventivo" title="Invia preventivo" :sottotitolo="'#'.$segnalazione->id_segnalazione" :aperto="$mio">
    <form method="POST" action="{{ route('segnalazioni.azione', $segnalazione->id_segnalazione) }}" class="flex flex-1 flex-col">
        @csrf
        <input type="hidden" name="_pannello" value="preventivo">
        <input type="hidden" name="id_azione" value="{{ $azione->id_azione }}">
        <div class="flex flex-1 flex-col gap-4 px-6 py-5">
            <div class="pa-field">
                <label for="preventivo-importo" class="pa-field-label">Importo (€)</label>
                <input id="preventivo-importo" type="number" step="0.01" min="0" name="importo_preventivo" class="pa-input" required value="{{ $mio ? old('importo_preventivo') : '' }}">
                <x-input-error :messages="$errors->get('importo_preventivo')" />
            </div>
            <div class="pa-field">
                <label for="preventivo-nota" class="pa-field-label">Descrizione del lavoro previsto</label>
                <textarea id="preventivo-nota" name="nota" rows="4" maxlength="2000" class="pa-textarea">{{ $mio ? old('nota') : '' }}</textarea>
            </div>
            @if($mio)<x-input-error :messages="$errors->get('azione')" />@endif
        </div>
        <footer class="flex justify-end gap-2 border-t border-[var(--slate-200)] px-6 py-4">
            <x-pa.btn x-on:click="$dispatch('apri-pannello', '')">Annulla</x-pa.btn>
            <x-pa.btn type="submit" variant="primary">Invia preventivo</x-pa.btn>
        </footer>
    </form>
</x-pa.drawer>
