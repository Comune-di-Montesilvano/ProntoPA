<x-app-layout>
    <x-slot name="header">Delega per {{ $istituto->descrizione }}</x-slot>

    <div class="bg-white shadow-sm rounded-xl p-6" x-data="{ tutto: false }">
        <form method="POST" action="{{ route('scuola.deleghe.store', $istituto) }}" class="space-y-4">
            @csrf
            <label class="flex items-center gap-2 text-sm font-medium text-gray-800">
                <input type="checkbox" name="tutto" value="1" x-model="tutto" class="rounded border-gray-300">
                Tutto l'istituto
            </label>
            <fieldset class="space-y-2" x-show="! tutto">
                <legend class="text-sm text-gray-600 mb-1">Oppure scegli i plessi:</legend>
                @foreach($plessi as $plesso)
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="plessi[]" value="{{ $plesso->id_plesso }}" class="rounded border-gray-300">
                        {{ $plesso->nome }} <span class="text-xs text-gray-400">{{ $plesso->indirizzo }}</span>
                    </label>
                @endforeach
            </fieldset>
            <x-input-error :messages="$errors->get('plessi')" />
            <p class="text-xs text-gray-500">La richiesta arriva alla casella della segreteria ({{ $istituto->email }}), che la approva o la rifiuta.</p>
            <x-primary-button>Invia richiesta</x-primary-button>
        </form>
    </div>
</x-app-layout>
