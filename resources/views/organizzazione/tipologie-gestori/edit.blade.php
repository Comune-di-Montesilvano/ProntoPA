<x-app-layout>
    <x-slot name="header">Tipologie di {{ $utente->name }}</x-slot>
    <x-slot name="actions">
        <x-pa.btn :href="route('organizzazione.tipologie-gestori.index')">Annulla</x-pa.btn>
    </x-slot>

    <form method="POST" action="{{ route('organizzazione.tipologie-gestori.update', $utente) }}"
          x-data="{ tutte: {{ $puoTutte && old('tutte', $utente->gestore_tutte_tipologie) ? 'true' : 'false' }} }"
          class="flex flex-col gap-4">
        @csrf
        @method('PUT')

        @if($puoTutte)
        <x-pa.card>
            <label class="flex items-start gap-2">
                <input type="hidden" name="tutte" value="0">
                <input type="checkbox" name="tutte" value="1" x-model="tutte" class="mt-0.5 rounded border-gray-300 text-blue-600">
                <span class="text-sm text-[var(--ink)] font-medium">Tutte le tipologie, anche quelle aggiunte in futuro</span>
            </label>
        </x-pa.card>
        @else
        <x-pa.card>
            <p class="m-0 text-sm text-[var(--slate-600)]">Amministratore: vede già tutte le segnalazioni. Le tipologie scelte qui servono a ricevere gli avvisi (nuove segnalazioni, tempi) e a non lasciarle senza gestore.</p>
        </x-pa.card>
        @endif

        <div x-show="!tutte" class="flex flex-col gap-4">
            @foreach($tipologie->groupBy(fn ($t) => $t->gruppo?->descrizione ?? 'Senza gruppo') as $gruppo => $voci)
                <x-pa.card :titolo="$gruppo">
                    <div x-data class="flex flex-col gap-2">
                        <label class="flex items-center gap-2 text-xs text-[var(--slate-600)]">
                            <input type="checkbox" class="rounded border-gray-300"
                                   @change="$el.closest('[x-data]').querySelectorAll('input[name=\'tipologie[]\']').forEach(c => c.checked = $el.checked)">
                            Tutto il gruppo
                        </label>
                        @foreach($voci as $t)
                            <label class="flex items-center gap-2 text-sm text-[var(--ink)]">
                                <input type="checkbox" name="tipologie[]" value="{{ $t->id_tipologia_segnalazione }}"
                                       class="rounded border-gray-300 text-blue-600"
                                       @checked(in_array($t->id_tipologia_segnalazione, old('tipologie', $selezionate)))>
                                {{ $t->descrizione }}
                            </label>
                        @endforeach
                    </div>
                </x-pa.card>
            @endforeach
        </div>

        <x-input-error :messages="$errors->get('tipologie')" />

        <div class="flex justify-end">
            <x-pa.btn type="submit" variant="primary">Salva</x-pa.btn>
        </div>
    </form>
</x-app-layout>
