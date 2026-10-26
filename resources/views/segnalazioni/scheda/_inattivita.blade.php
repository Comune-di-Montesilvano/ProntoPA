{{-- Segnalazione ferma (avviso di inattività) e sollecito di chi l'ha aperta. --}}
@php
    $archiviazione = app(\App\Services\Segnalazioni\Inattivita::class)->dataArchiviazione($segnalazione);
    $puoSollecitare = auth()->user()->can('sollecitaComeSegnalante', $segnalazione);
@endphp

@if($segnalazione->avviso_inattivita_at && ! $segnalazione->isChiusa())
    <section class="pa-card pa-passo pa-passo-amber p-4 flex flex-col gap-1" role="status">
        <p class="m-0 font-semibold text-[var(--ink)]">
            Ferma dal {{ $segnalazione->ultimaAttivita()->format('d/m/Y') }}:
            @if($archiviazione)
                se nessuno la aggiorna entro il {{ $archiviazione->format('d/m/Y') }} verrà archiviata.
            @else
                va aggiornata o chiusa.
            @endif
        </p>
        <p class="m-0 text-sm text-[var(--slate-700)]">
            {{ $puoSollecitare ? 'Se il problema c\'è ancora, sollecita qui sotto: basta per tenerla aperta.' : 'Basta una nota, la fine lavori o un cambio di stato per tenerla aperta.' }}
        </p>
    </section>
@endif

@if($puoSollecitare)
    <x-pa.card titolo="Sollecita">
        <form method="POST" action="{{ route('segnalazioni.sollecito-segnalante', $segnalazione) }}" class="flex flex-col gap-3">
            @csrf
            <p class="m-0 text-sm text-[var(--slate-700)]">
                {{ $segnalazione->data_scadenza_sla?->isPast() ? 'Il tempo previsto è superato.' : 'La segnalazione è ferma.' }}
                Il sollecito arriva per email a chi se ne occupa. Puoi inviarne uno ogni 3 giorni lavorativi.
            </p>
            <label for="sollecito-segnalante" class="sr-only">Messaggio</label>
            <textarea id="sollecito-segnalante" name="testo" rows="2" maxlength="2000" class="pa-textarea"
                      placeholder="Il problema c'è ancora, chiedo un aggiornamento.">{{ old('testo') }}</textarea>
            <x-input-error :messages="$errors->get('testo')" />
            <div class="flex justify-end">
                <x-pa.btn type="submit" variant="primary">Invia sollecito</x-pa.btn>
            </div>
        </form>
    </x-pa.card>
@endif
