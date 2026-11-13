{{-- Partial condiviso da create e edit --}}
<div>
    <x-input-label for="descrizione" value="Descrizione *" />
    <x-text-input id="descrizione" name="descrizione" type="text"
        class="mt-1 block w-full text-sm"
        :value="old('descrizione', $appalto->descrizione ?? '')"
        required />
    <x-input-error :messages="$errors->get('descrizione')" class="mt-1" />
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div>
        <x-input-label for="id_impresa" value="Impresa" />
        <select id="id_impresa" name="id_impresa"
                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
            <option value="">— Nessuna —</option>
            @foreach($imprese as $imp)
                <option value="{{ $imp->id_impresa }}"
                    {{ old('id_impresa', $appalto->id_impresa ?? '') == $imp->id_impresa ? 'selected' : '' }}>
                    {{ $imp->ragione_sociale }}
                </option>
            @endforeach
        </select>
        <x-input-error :messages="$errors->get('id_impresa')" class="mt-1" />
    </div>
    <div>
        <x-input-label for="CIG" value="CIG" />
        <x-text-input id="CIG" name="CIG" type="text"
            class="mt-1 block w-full text-sm font-mono"
            :value="old('CIG', $appalto->CIG ?? '')" />
        <x-input-error :messages="$errors->get('CIG')" class="mt-1" />
    </div>
    <div>
        <x-input-label for="importo_appalto" value="Importo (€)" />
        <x-text-input id="importo_appalto" name="importo_appalto" type="number" step="0.01" min="0"
            class="mt-1 block w-full text-sm"
            :value="old('importo_appalto', $appalto->importo_appalto ?? '')" />
        <x-input-error :messages="$errors->get('importo_appalto')" class="mt-1" />
    </div>
</div>

<div class="flex items-center gap-2">
    <input type="checkbox" id="valido" name="valido" value="1"
           class="rounded border-gray-300 text-blue-600"
           {{ old('valido', $appalto->valido ?? true) ? 'checked' : '' }}>
    <x-input-label for="valido" value="Appalto attivo (valido)" />
</div>

@php
    $selezionate = array_map('intval', old('tipologie', isset($appalto) ? $appalto->tipologie->pluck('id_tipologia_segnalazione')->all() : []));
@endphp
<fieldset x-data="{ tutte: {{ old('tutte_tipologie', $appalto->tutte_tipologie ?? false) ? 'true' : 'false' }} }" class="flex flex-col gap-3">
    <legend class="text-sm font-medium text-gray-700">Tipologie coperte</legend>
    <p class="m-0 text-xs text-gray-500">Nel pannello Assegna la ditta compare per prima sulle segnalazioni di queste tipologie.</p>
    <label class="flex items-center gap-2 text-sm">
        <input type="hidden" name="tutte_tipologie" value="0">
        <input type="checkbox" name="tutte_tipologie" value="1" x-model="tutte" class="rounded border-gray-300 text-blue-600">
        Tutte le tipologie
    </label>
    <div x-show="!tutte" class="grid grid-cols-1 sm:grid-cols-2 gap-2">
        @foreach($tipologie as $t)
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="tipologie[]" value="{{ $t->id_tipologia_segnalazione }}" class="rounded border-gray-300 text-blue-600"
                       @checked(in_array($t->id_tipologia_segnalazione, $selezionate))>
                {{ $t->descrizione }}
            </label>
        @endforeach
    </div>
    <x-input-error :messages="$errors->get('tipologie')" class="mt-1" />
</fieldset>
