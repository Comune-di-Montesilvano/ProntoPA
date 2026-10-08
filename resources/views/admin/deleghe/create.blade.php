<x-app-layout>
    <x-slot name="header">Pre-delega</x-slot>

    <div class="bg-white shadow-sm rounded-xl p-6" x-data="{ istituto: '{{ old('id_istituto') }}' }">
        <p class="text-sm text-gray-600 mb-4">Delega attiva subito, senza passare dalla segreteria (elenco iniziale della scuola, casi noti). Viene agganciata alla persona al suo primo accesso con SPID/CIE.</p>
        <form method="POST" action="{{ route('admin.deleghe.store') }}" class="space-y-4">
            @csrf
            <div>
                <x-input-label for="codice_fiscale" value="Codice fiscale *" />
                <x-text-input id="codice_fiscale" name="codice_fiscale" :value="old('codice_fiscale')" required maxlength="16" class="mt-1 block w-full uppercase" />
                <x-input-error :messages="$errors->get('codice_fiscale')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="id_istituto" value="Scuola *" />
                <select id="id_istituto" name="id_istituto" x-model="istituto" required class="mt-1 block w-full border-gray-300 rounded-md">
                    <option value="">— Seleziona —</option>
                    @foreach($istituti as $ist)
                        <option value="{{ $ist->id_istituto }}">{{ $ist->descrizione }}</option>
                    @endforeach
                </select>
            </div>
            @foreach($istituti as $ist)
                <fieldset x-show="istituto === '{{ $ist->id_istituto }}'" class="space-y-1">
                    <legend class="text-sm text-gray-600">Plessi (nessuno = tutto l'istituto)</legend>
                    @foreach($ist->plessi as $plesso)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="plessi[]" value="{{ $plesso->id_plesso }}" x-bind:disabled="istituto !== '{{ $ist->id_istituto }}'" class="rounded border-gray-300">
                            {{ $plesso->nome }}
                        </label>
                    @endforeach
                </fieldset>
            @endforeach
            <div>
                <x-input-label for="motivo" value="Motivo *" />
                <x-text-input id="motivo" name="motivo" :value="old('motivo')" required maxlength="255" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('motivo')" class="mt-1" />
            </div>
            <x-primary-button>Crea pre-delega</x-primary-button>
        </form>
    </div>
</x-app-layout>
