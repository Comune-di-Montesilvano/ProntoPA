<x-app-layout>
    <x-slot name="header">Modifica profilo</x-slot>
    <x-slot name="actions">
        <a href="{{ route('admin.profili.index') }}"
           class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 transition">
            Annulla
        </a>
    </x-slot>

    <div class="bg-white shadow-sm rounded-xl">
        <form method="POST" action="{{ route('admin.profili.update', $profilo->id_profilo) }}" class="p-6 space-y-5"
              x-data="{
                limitaIstituto: {{ old('limita_istituto', $profilo->limita_istituto) ? 'true' : 'false' }},
                limitaSeg: {{ old('limita_segnalazioni', $profilo->limita_segnalazioni ?? 0) }}
              }">
            @csrf @method('PATCH')

            <div>
                <x-input-label for="descrizione" value="Descrizione *" />
                <x-text-input id="descrizione" name="descrizione" type="text"
                              class="mt-1 block w-full"
                              :value="old('descrizione', $profilo->descrizione)" required maxlength="50" />
                <x-input-error :messages="$errors->get('descrizione')" class="mt-1" />
            </div>

            {{-- Vincolo organizzazione --}}
            <div class="rounded-lg border border-gray-200 p-4 space-y-4">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="limita_istituto" value="1"
                           x-model="limitaIstituto"
                           {{ old('limita_istituto', $profilo->limita_istituto) ? 'checked' : '' }}
                           class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    <span class="text-sm font-medium text-gray-700">Limita a una specifica organizzazione</span>
                </label>
                <p class="text-xs text-gray-400">Gli utenti con questo profilo vedranno solo le segnalazioni delle sedi dell'organizzazione selezionata.</p>

                <div x-show="limitaIstituto" x-cloak>
                    <x-input-label for="id_istituto" value="Organizzazione" />
                    <select id="id_istituto" name="id_istituto"
                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 @error('id_istituto') border-red-500 @enderror">
                        <option value="">— Seleziona —</option>
                        @foreach($organizzazioni->groupBy('tipo') as $tipo => $items)
                            <optgroup label="{{ $tipo }}">
                                @foreach($items as $org)
                                    <option value="{{ $org->id_istituto }}"
                                        {{ old('id_istituto', $profilo->id_istituto) == $org->id_istituto ? 'selected' : '' }}>
                                        {{ $org->descrizione }}
                                    </option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('id_istituto')" class="mt-1" />
                </div>
            </div>

            {{-- Limite tipologia segnalazioni --}}
            <div>
                <x-input-label for="limita_segnalazioni" value="Limite tipologia segnalazioni" />
                <select id="limita_segnalazioni" name="limita_segnalazioni"
                        x-model="limitaSeg"
                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                    <option value="0">Nessun limite</option>
                    <option value="1">Solo edifici dell'organizzazione</option>
                    <option value="3">Solo una tipologia specifica</option>
                </select>
                <x-input-error :messages="$errors->get('limita_segnalazioni')" class="mt-1" />
            </div>

            <div x-show="limitaSeg == 3" x-cloak>
                <x-input-label for="id_tipologia_segnalazione" value="Tipologia specifica" />
                <select id="id_tipologia_segnalazione" name="id_tipologia_segnalazione"
                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                    <option value="">— Seleziona —</option>
                    @foreach($tipologie as $tip)
                        <option value="{{ $tip->id_tipologia_segnalazione }}"
                            {{ old('id_tipologia_segnalazione', $profilo->id_tipologia_segnalazione) == $tip->id_tipologia_segnalazione ? 'selected' : '' }}>
                            {{ $tip->descrizione }}
                        </option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('id_tipologia_segnalazione')" class="mt-1" />
            </div>

            <div class="flex justify-end gap-3 pt-2 border-t border-gray-100">
                <a href="{{ route('admin.profili.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Annulla</a>
                <button type="submit"
                        class="inline-flex items-center px-5 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-sm text-white uppercase tracking-widest hover:bg-blue-700 transition">
                    Salva modifiche
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
