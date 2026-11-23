<x-app-layout>
    <x-slot name="header">Delega per {{ $istituto->descrizione }}</x-slot>

    <div class="bg-white shadow-sm rounded-xl p-6" x-data="{ tutto: false }">
        @if($copertura['intero'])
            <p class="text-sm text-gray-700">Hai già una delega attiva per tutto l'istituto.</p>
            <a href="{{ route('scuola.deleghe.index') }}" class="mt-3 inline-block text-sm text-blue-700 hover:underline">Torna alle tue deleghe</a>
        @else
        @if($inAttesa)
            <p class="mb-4 text-sm text-amber-700">Hai già una richiesta in attesa per questa scuola: aspetta la risposta della segreteria prima di inviarne un'altra.</p>
        @endif
        <form method="POST" action="{{ route('scuola.deleghe.store', $istituto) }}" class="space-y-4">
            @csrf
            <label class="flex items-center gap-2 text-sm font-medium text-gray-800">
                <input type="checkbox" name="tutto" value="1" x-model="tutto" class="rounded border-gray-300">
                Tutto l'istituto
            </label>
            <fieldset class="space-y-2" x-show="! tutto">
                <legend class="text-sm text-gray-600 mb-1">Oppure scegli i plessi:</legend>
                @foreach($plessi as $plesso)
                    @php($gia = in_array((int) $plesso->id_plesso, $copertura['plessi'], true))
                    <label class="flex items-center gap-2 text-sm {{ $gia ? 'text-gray-400' : 'text-gray-700' }}">
                        @if($gia)
                            <input type="checkbox" checked disabled data-gia-delegato="{{ $plesso->id_plesso }}" class="rounded border-gray-300">
                        @else
                            <input type="checkbox" name="plessi[]" value="{{ $plesso->id_plesso }}" class="rounded border-gray-300">
                        @endif
                        {{ $plesso->nome }} <span class="text-xs text-gray-400">{{ $plesso->dettagli() }}</span>
                        @if($gia)<span class="text-xs text-green-700">già delegato</span>@endif
                    </label>
                @endforeach
            </fieldset>
            <x-input-error :messages="$errors->get('plessi')" />
            <p class="text-xs text-gray-500">La richiesta arriva alla casella della segreteria ({{ $istituto->email }}), che la approva o la rifiuta.</p>
            <x-primary-button>Invia richiesta</x-primary-button>
        </form>
        @endif
    </div>
</x-app-layout>
