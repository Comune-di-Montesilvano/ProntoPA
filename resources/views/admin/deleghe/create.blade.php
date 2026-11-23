<x-app-layout>
    <x-slot name="header">Pre-delega</x-slot>

    <div class="bg-white shadow-sm rounded-xl p-6" x-data="{ istituto: '{{ old('id_istituto', $idIstituto) }}' }">
        <p class="text-sm text-gray-600 mb-4">Delega attiva subito, senza passare dalla segreteria (elenco iniziale della scuola, casi noti). Viene agganciata alla persona al suo primo accesso con SPID/CIE.</p>
        <form method="POST" action="{{ route('admin.deleghe.store') }}" class="space-y-4">
            @csrf
            <div>
                <x-input-label for="codice_fiscale" value="Codice fiscale *" />
                {{-- al cambio del codice fiscale la pagina si ricarica per mostrare i plessi già delegati --}}
                <x-text-input id="codice_fiscale" name="codice_fiscale" :value="old('codice_fiscale', $codiceFiscale)" required maxlength="16" class="mt-1 block w-full uppercase"
                    x-on:change="const cf = $event.target.value.trim().toUpperCase(); if (/^[A-Z0-9]{16}$/.test(cf) && cf !== '{{ $codiceFiscale }}') { window.location = '{{ route('admin.deleghe.create') }}?codice_fiscale=' + cf + '&id_istituto=' + istituto }" />
                <x-input-error :messages="$errors->get('codice_fiscale')" class="mt-1" />
                @if($codiceFiscale !== '' && $coperti->isNotEmpty())
                    <p class="mt-1 text-xs text-gray-600">Questa persona ha già deleghe attive: i plessi coperti sono segnati e non si possono scegliere di nuovo.</p>
                @endif
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
                @php($cop = $coperti->get($ist->id_istituto, ['intero' => false, 'plessi' => []]))
                <fieldset x-show="istituto === '{{ $ist->id_istituto }}'" class="space-y-1">
                    <legend class="text-sm text-gray-600">Plessi (nessuno = tutto l'istituto)</legend>
                    @if($cop['intero'])
                        <p class="text-sm text-amber-700">Già delegata per tutto l'istituto.</p>
                    @endif
                    @foreach($ist->plessi as $plesso)
                        @php($gia = $cop['intero'] || in_array((int) $plesso->id_plesso, $cop['plessi'], true))
                        <label class="flex items-center gap-2 text-sm {{ $gia ? 'text-gray-400' : '' }}">
                            @if($gia)
                                <input type="checkbox" checked disabled data-gia-delegato="{{ $plesso->id_plesso }}" class="rounded border-gray-300">
                            @else
                                <input type="checkbox" name="plessi[]" value="{{ $plesso->id_plesso }}" x-bind:disabled="istituto !== '{{ $ist->id_istituto }}'" class="rounded border-gray-300">
                            @endif
                            {{ $plesso->nome }} <span class="text-xs text-gray-400">{{ $plesso->dettagli() }}</span>
                            @if($gia)<span class="text-xs text-green-700">già delegato</span>@endif
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
