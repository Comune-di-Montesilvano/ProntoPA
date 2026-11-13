{{-- Scadenza: si rimanda con un motivo che vede anche chi ha segnalato (0.9.20, al posto di Sospendi). --}}
<x-pa.drawer name="scadenza" title="Scadenza" :sottotitolo="'#'.$segnalazione->id_segnalazione" :aperto="old('_pannello') === 'scadenza'">
    <form method="POST" action="{{ route('segnalazioni.scadenza', $segnalazione->id_segnalazione) }}" class="flex flex-1 flex-col">
        @csrf @method('PATCH')
        <input type="hidden" name="_pannello" value="scadenza">
        <div class="flex flex-1 flex-col gap-4 px-6 py-5">
            <p class="m-0 text-sm text-[var(--slate-600)]">
                Ora scade il <strong>{{ $segnalazione->data_scadenza_sla?->format('d/m/Y H:i') ?? '—' }}</strong>
                ({{ $segnalazione->scadenza_manuale ? 'fissata a mano' : 'calcolata dalla priorità' }}).
            </p>
            <div class="pa-field">
                <label for="scadenza-data" class="pa-field-label">Nuova scadenza</label>
                <input id="scadenza-data" type="date" name="data_scadenza" required min="{{ now()->format('Y-m-d') }}" class="pa-input"
                       value="{{ old('_pannello') === 'scadenza' ? old('data_scadenza') : '' }}">
                <p class="pa-field-help">Vale fino alla chiusura dell'ufficio di quel giorno. Nessun ricalcolo automatico la cambia.</p>
                <x-input-error :messages="$errors->get('data_scadenza')" />
            </div>
            <div class="pa-field">
                <label for="scadenza-motivo" class="pa-field-label">Motivo</label>
                <textarea id="scadenza-motivo" name="motivo" rows="3" maxlength="1000" required class="pa-textarea"
                          placeholder="Es. linea in fibra guasta, si verifica dopo la riparazione">{{ old('_pannello') === 'scadenza' ? old('motivo') : '' }}</textarea>
                <p class="pa-field-help">Lo vede anche chi ha segnalato, che riceve un'email.</p>
                <x-input-error :messages="$errors->get('motivo')" />
            </div>
        </div>
        <footer class="flex flex-wrap items-center justify-end gap-2 border-t border-[var(--slate-200)] px-6 py-4">
            @if($segnalazione->scadenza_manuale)
                <button type="submit" form="scadenza-automatica" class="mr-auto text-sm font-semibold text-[var(--ente-primary)] underline">Torna al calcolo automatico</button>
            @endif
            <x-pa.btn x-on:click="$dispatch('apri-pannello', '')">Annulla</x-pa.btn>
            <x-pa.btn type="submit" variant="primary">Sposta scadenza</x-pa.btn>
        </footer>
    </form>
    @if($segnalazione->scadenza_manuale)
        <form id="scadenza-automatica" method="POST" action="{{ route('segnalazioni.scadenza-automatica', $segnalazione) }}" class="hidden">
            @csrf @method('PATCH')
        </form>
    @endif
</x-pa.drawer>
