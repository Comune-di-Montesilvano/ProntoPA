{{-- Chiudi senza intervento (azione annulla, stato 9): motivo obbligatorio, visibile anche a chi ha segnalato. --}}
@php
    $azione = $azioniDisponibili->firstWhere('codice', 'annulla');
    $mio = old('_pannello') === 'annulla';
    $motivi = \App\Services\SegnalazioneWorkflowService::MOTIVI_SENZA_INTERVENTO;
@endphp
<x-pa.drawer name="annulla" title="Chiudi senza intervento" :sottotitolo="'#'.$segnalazione->id_segnalazione" :aperto="$mio">
    <form method="POST" action="{{ route('segnalazioni.azione', $segnalazione->id_segnalazione) }}" class="flex flex-1 flex-col"
          x-data="{ motivo: @js($mio ? old('motivo') : null) }">
        @csrf
        <input type="hidden" name="_pannello" value="annulla">
        <input type="hidden" name="id_azione" value="{{ $azione->id_azione }}">
        <div class="flex flex-1 flex-col gap-4 px-6 py-5">
            <fieldset class="m-0 flex flex-col gap-2 border-0 p-0">
                <legend class="pa-field-label mb-1">Perché</legend>
                @foreach($motivi as $valore => $etichetta)
                    <label class="flex items-center gap-2 text-sm">
                        <input type="radio" name="motivo" value="{{ $valore }}" x-model="motivo" required> {{ $etichetta }}
                    </label>
                @endforeach
                <x-input-error :messages="$errors->get('motivo')" />
            </fieldset>
            <div class="pa-field">
                <label for="nota-annulla" class="pa-field-label">
                    <span x-show="motivo === 'altro'">Spiega il motivo</span>
                    <span x-show="motivo !== 'altro'">Nota (facoltativa)</span>
                </label>
                <textarea id="nota-annulla" name="nota" rows="3" maxlength="2000" class="pa-textarea" :required="motivo === 'altro'">{{ $mio ? old('nota') : '' }}</textarea>
                <p class="pa-field-help">Il motivo lo vede anche chi ha segnalato.</p>
                <x-input-error :messages="$errors->get('nota')" />
            </div>
            @if($mio)<x-input-error :messages="$errors->get('azione')" />@endif
        </div>
        <footer class="flex justify-end gap-2 border-t border-[var(--slate-200)] px-6 py-4">
            <x-pa.btn x-on:click="$dispatch('apri-pannello', '')">Annulla</x-pa.btn>
            <x-pa.btn type="submit" variant="danger">Chiudi senza intervento</x-pa.btn>
        </footer>
    </form>
</x-pa.drawer>
