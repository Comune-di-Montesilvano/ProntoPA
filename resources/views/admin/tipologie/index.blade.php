<x-app-layout>
    <x-slot name="header">Tipologie</x-slot>

    <div class="space-y-6">
        <p class="m-0 text-sm text-gray-600">
            Un solo elenco, uguale per tutte le sedi: è quello che vede chi segnala.
            Le tipologie disattivate non si possono più scegliere ma restano sulle segnalazioni vecchie.
            Unire due tipologie sposta su quella che resta segnalazioni, regole di scadenza, gestori e appalti.
        </p>

        <form method="POST" action="{{ route('admin.tipologie.store') }}" class="bg-white shadow-sm rounded-xl p-4 flex flex-wrap items-end gap-3">
            @csrf
            <div class="pa-field grow">
                <label for="nuova-descrizione" class="pa-field-label">Nuova tipologia</label>
                <input id="nuova-descrizione" name="descrizione" required maxlength="100" class="pa-input" value="{{ old('descrizione') }}">
            </div>
            <div class="pa-field">
                <label for="nuova-icona" class="pa-field-label">Icona (facoltativa)</label>
                <input id="nuova-icona" name="icona" maxlength="50" class="pa-input" placeholder="fas fa-wrench" value="{{ old('icona') }}">
            </div>
            <x-pa.btn type="submit" variant="primary">Aggiungi</x-pa.btn>
            <div class="w-full">
                <x-input-error :messages="$errors->get('descrizione')" />
                <x-input-error :messages="$errors->get('icona')" />
                <x-input-error :messages="$errors->get('id_destinazione')" />
            </div>
        </form>

        <div class="bg-white shadow-sm rounded-xl divide-y divide-gray-100">
            @foreach($tipologie as $t)
                <div class="flex flex-wrap items-end gap-3 p-4 {{ $t->attiva ? '' : 'opacity-60' }}">
                    <form method="POST" action="{{ route('admin.tipologie.update', $t) }}" class="flex flex-wrap items-end gap-3 grow">
                        @csrf @method('PATCH')
                        <span class="w-6 text-center text-gray-500"><i class="{{ $t->icona ?: 'fas fa-tag' }}" aria-hidden="true"></i></span>
                        <div class="pa-field grow">
                            <label class="sr-only" for="descrizione-{{ $t->id_tipologia_segnalazione }}">Nome</label>
                            <input id="descrizione-{{ $t->id_tipologia_segnalazione }}" name="descrizione" required maxlength="100" class="pa-input" value="{{ $t->descrizione }}">
                        </div>
                        <div class="pa-field">
                            <label class="sr-only" for="icona-{{ $t->id_tipologia_segnalazione }}">Icona</label>
                            <input id="icona-{{ $t->id_tipologia_segnalazione }}" name="icona" maxlength="50" class="pa-input" value="{{ $t->icona }}">
                        </div>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="hidden" name="attiva" value="0">
                            <input type="checkbox" name="attiva" value="1" @checked($t->attiva)> Attiva
                        </label>
                        <span class="text-xs text-gray-500">{{ $t->segnalazioni_count }} segnalazioni</span>
                        <x-pa.btn type="submit">Salva</x-pa.btn>
                    </form>
                    <form method="POST" action="{{ route('admin.tipologie.unisci', $t) }}" class="flex items-end gap-2"
                          x-data x-on:submit="if (! confirm('Unire «{{ addslashes($t->descrizione) }}» nella tipologia scelta? Non si può annullare.')) $event.preventDefault()">
                        @csrf
                        <label class="sr-only" for="unisci-{{ $t->id_tipologia_segnalazione }}">Unisci in</label>
                        <select id="unisci-{{ $t->id_tipologia_segnalazione }}" name="id_destinazione" required class="pa-input">
                            <option value="">Unisci in…</option>
                            @foreach($tipologie as $altra)
                                @continue($altra->is($t))
                                <option value="{{ $altra->id_tipologia_segnalazione }}">{{ $altra->descrizione }}</option>
                            @endforeach
                        </select>
                        <x-pa.btn type="submit">Unisci</x-pa.btn>
                    </form>
                </div>
            @endforeach
        </div>
    </div>
</x-app-layout>
