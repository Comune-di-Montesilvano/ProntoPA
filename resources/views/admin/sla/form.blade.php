<x-app-layout>
    <x-slot name="header">{{ $regola->id_sla ? 'Modifica regola SLA' : 'Nuova regola SLA' }}</x-slot>
    <x-slot name="actions">
        <a href="{{ route('admin.sla.index') }}"
           class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 transition">
            Annulla
        </a>
    </x-slot>

    <div class="max-w-xl">
        <div class="bg-white shadow-sm rounded-xl p-6">
            <form method="POST"
                  action="{{ $regola->id_sla ? route('admin.sla.update', $regola->id_sla) : route('admin.sla.store') }}">
                @csrf
                @if($regola->id_sla) @method('PUT') @endif

                <div class="space-y-5">
                    <div>
                        <x-input-label for="id_tipologia_segnalazione" value="Tipologia (vuoto = tutte)" />
                        <select id="id_tipologia_segnalazione" name="id_tipologia_segnalazione"
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500">
                            <option value="">— Tutte le tipologie (default) —</option>
                            @foreach($tipologie as $t)
                                <option value="{{ $t->id_tipologia_segnalazione }}"
                                    {{ old('id_tipologia_segnalazione', $regola->id_tipologia_segnalazione) == $t->id_tipologia_segnalazione ? 'selected' : '' }}>
                                    {{ $t->descrizione }}
                                </option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('id_tipologia_segnalazione')" class="mt-1" />
                    </div>

                    @if($specializzazioni->isNotEmpty())
                    <div>
                        <x-input-label for="id_specializzazione" value="Specializzazione (vuoto = tutte)" />
                        <select id="id_specializzazione" name="id_specializzazione"
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500">
                            <option value="">— Tutte —</option>
                            @foreach($specializzazioni as $sp)
                                <option value="{{ $sp->id_specializzazione }}"
                                    {{ old('id_specializzazione', $regola->id_specializzazione) == $sp->id_specializzazione ? 'selected' : '' }}>
                                    {{ $sp->descrizione }}
                                </option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('id_specializzazione')" class="mt-1" />
                    </div>
                    @endif

                    <div>
                        <x-input-label for="livello_priorita" value="Priorità *" />
                        <select id="livello_priorita" name="livello_priorita" required
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500 @error('livello_priorita') border-red-500 @enderror">
                            @foreach([1=>'Bassa',2=>'Media',3=>'Alta',4=>'Critica'] as $val => $label)
                                <option value="{{ $val }}"
                                    {{ old('livello_priorita', $regola->livello_priorita) == $val ? 'selected' : '' }}>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('livello_priorita')" class="mt-1" />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="ore_target" value="Tempo massimo *" />
                            <input type="number" id="ore_target" name="ore_target" min="1" required
                                   value="{{ old('ore_target', $regola->ore_target) }}"
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500 @error('ore_target') border-red-500 @enderror">
                            <x-input-error :messages="$errors->get('ore_target')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="unita" value="Unità *" />
                            <select id="unita" name="unita" required
                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500 @error('unita') border-red-500 @enderror">
                                @foreach(['ore' => 'Ore lavorative', 'giorni' => 'Giorni lavorativi'] as $val => $label)
                                    <option value="{{ $val }}" {{ old('unita', $regola->unita ?? 'ore') === $val ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('unita')" class="mt-1" />
                        </div>
                    </div>
                    <p class="-mt-3 text-xs text-gray-400">
                        Si conta solo l'orario d'ufficio (weekend, festivi e patrono esclusi), impostato in
                        <a href="{{ route('admin.impostazioni.index') }}" class="underline">Impostazioni → Orario d'ufficio</a>.
                        N giorni lavorativi = entro la chiusura dell'N-esimo giorno lavorativo dopo quello della segnalazione.
                    </p>

                    <div>
                        <x-input-label for="ore_warning" value="Avviso a rischio (ore lavorative) *" />
                        <input type="number" id="ore_warning" name="ore_warning" min="1" required
                               value="{{ old('ore_warning', $regola->ore_warning) }}"
                               class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500 @error('ore_warning') border-red-500 @enderror">
                        <p class="mt-1 text-xs text-gray-400">Ore lavorative prima della scadenza in cui la segnalazione risulta a rischio</p>
                        <x-input-error :messages="$errors->get('ore_warning')" class="mt-1" />
                    </div>

                    <fieldset class="rounded-md border border-gray-200 p-4">
                        <legend class="px-1 text-sm font-medium text-gray-700">Segnalazioni ferme (giorni lavorativi, vuoto = spento)</legend>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <x-input-label for="giorni_avviso_inattivita" value="Avviso dopo" />
                                <input type="number" id="giorni_avviso_inattivita" name="giorni_avviso_inattivita" min="1" max="365"
                                       value="{{ old('giorni_avviso_inattivita', $regola->giorni_avviso_inattivita) }}"
                                       placeholder="{{ [1 => 30, 2 => 15, 3 => 5, 4 => 2][$regola->livello_priorita ?? 2] ?? 15 }}"
                                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500">
                                <x-input-error :messages="$errors->get('giorni_avviso_inattivita')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="giorni_archiviazione_inattivita" value="Archiviata dopo l'avviso" />
                                <input type="number" id="giorni_archiviazione_inattivita" name="giorni_archiviazione_inattivita" min="1" max="365"
                                       value="{{ old('giorni_archiviazione_inattivita', $regola->giorni_archiviazione_inattivita) }}"
                                       placeholder="{{ [1 => 15, 2 => 15, 3 => 10, 4 => 5][$regola->livello_priorita ?? 2] ?? 15 }}"
                                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500">
                                <x-input-error :messages="$errors->get('giorni_archiviazione_inattivita')" class="mt-1" />
                            </div>
                        </div>
                        <p class="mt-2 text-xs text-gray-400">
                            Senza note, cambi di stato, allegati o solleciti per questi giorni, chi ha aperto, chi ha in carico (anche la ditta) e i supervisori
                            ricevono un avviso; se poi nessuno aggiorna la segnalazione viene archiviata. Le sospese sono escluse.
                            Valori suggeriti: Critica 2 + 5, Alta 5 + 10, Media 15 + 15, Bassa 30 + 15.
                        </p>
                    </fieldset>

                    <div>
                        <x-input-label for="descrizione" value="Descrizione (opzionale)" />
                        <input type="text" id="descrizione" name="descrizione" maxlength="100"
                               value="{{ old('descrizione', $regola->descrizione) }}"
                               class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500">
                        <x-input-error :messages="$errors->get('descrizione')" class="mt-1" />
                    </div>

                    <div class="flex justify-end pt-2 border-t border-gray-100">
                        <button type="submit"
                                class="inline-flex items-center px-5 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-sm text-white uppercase tracking-widest hover:bg-blue-700 transition">
                            {{ $regola->id_sla ? 'Salva modifiche' : 'Crea regola' }}
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
