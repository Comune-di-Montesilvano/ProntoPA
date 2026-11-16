{{-- Chiedi informazioni a chi ha segnalato (1.0.2): la segnalazione aspetta la risposta con la scadenza ferma. --}}
@php
    $aperto = old('_pannello') === 'informazioni';
    $chi = $segnalazione->utente?->isSpid() ? 'la scuola' : 'chi ha segnalato';
@endphp
<x-pa.drawer name="informazioni" title="Chiedi informazioni" :sottotitolo="'#'.$segnalazione->id_segnalazione" :aperto="$aperto">
    <form method="POST" action="{{ route('segnalazioni.richiesta-informazioni', $segnalazione) }}" class="flex flex-1 flex-col">
        @csrf
        <input type="hidden" name="_pannello" value="informazioni">
        <div class="flex flex-1 flex-col gap-4 px-6 py-5">
            <p class="m-0 text-sm text-[var(--slate-700)]">
                Arriva per email a {{ $chi }}. Finché non risponde la segnalazione resta in attesa e la scadenza è ferma;
                chi ci lavora la trova come l'ha lasciata.
            </p>
            <div class="pa-field">
                <label for="informazioni-testo" class="pa-field-label">Cosa ti serve sapere</label>
                <textarea id="informazioni-testo" name="testo" rows="4" maxlength="2000" required class="pa-textarea">{{ $aperto ? old('testo') : '' }}</textarea>
                <x-input-error :messages="$errors->get('testo')" />
            </div>
            <div class="pa-field">
                <label for="informazioni-giorni" class="pa-field-label">Tempo per rispondere (giorni lavorativi)</label>
                <input id="informazioni-giorni" name="giorni" type="number" min="1" max="30" required class="pa-input w-28"
                       value="{{ $aperto ? old('giorni') : (int) \App\Models\Impostazione::get('giorni_risposta_informazioni', 10) }}">
                <x-input-error :messages="$errors->get('giorni')" />
            </div>
            <label class="inline-flex items-start gap-2 text-sm text-[var(--slate-700)]">
                <input type="hidden" name="chiudi_senza_risposta" value="0">
                <input type="checkbox" name="chiudi_senza_risposta" value="1" class="mt-0.5 rounded border-[var(--slate-300)]"
                       @checked(! $aperto || old('chiudi_senza_risposta'))>
                Chiudi la segnalazione se non risponde entro il termine
            </label>
        </div>
        <footer class="flex justify-end gap-2 border-t border-[var(--slate-200)] px-6 py-4">
            <x-pa.btn x-on:click="$dispatch('apri-pannello', '')">Annulla</x-pa.btn>
            <x-pa.btn type="submit" variant="primary">Invia la richiesta</x-pa.btn>
        </footer>
    </form>
</x-pa.drawer>
