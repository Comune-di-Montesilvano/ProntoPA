<x-app-layout>
    <x-slot name="header">Modifica organizzazione</x-slot>
    <x-slot name="actions">
        <a href="{{ route('admin.organizzazioni.index') }}"
           class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 transition">
            Annulla
        </a>
    </x-slot>

    <div class="bg-white shadow-sm rounded-xl">
        <form method="POST" action="{{ route('admin.organizzazioni.update', $organizzazione->id_istituto) }}" class="p-6 space-y-5">
            @csrf @method('PATCH')

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <x-input-label for="descrizione" value="Nome / Descrizione *" />
                    <x-text-input id="descrizione" name="descrizione" type="text"
                                  class="mt-1 block w-full"
                                  :value="old('descrizione', $organizzazione->descrizione)" required maxlength="50" />
                    <x-input-error :messages="$errors->get('descrizione')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="tipo" value="Tipo *" />
                    <input id="tipo" name="tipo" type="text" list="tipi-list"
                           value="{{ old('tipo', $organizzazione->tipo) }}"
                           maxlength="50" required
                           class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 @error('tipo') border-red-500 @enderror" />
                    <datalist id="tipi-list">
                        @foreach($tipiEsistenti as $t)
                            <option value="{{ $t }}">
                        @endforeach
                    </datalist>
                    <x-input-error :messages="$errors->get('tipo')" class="mt-1" />
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
                <div>
                    <x-input-label for="tipo_ente" value="Tipologia ente *" />
                    <select id="tipo_ente" name="tipo_ente" required
                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 @error('tipo_ente') border-red-500 @enderror">
                        @php $tipoEnte = old('tipo_ente', $organizzazione->tipo_ente ?? 'scuola'); @endphp
                        <option value="comune" {{ $tipoEnte === 'comune' ? 'selected' : '' }}>Comune</option>
                        <option value="scuola" {{ $tipoEnte === 'scuola' ? 'selected' : '' }}>Scuola</option>
                        <option value="asl" {{ $tipoEnte === 'asl' ? 'selected' : '' }}>ASL</option>
                        <option value="provincia" {{ $tipoEnte === 'provincia' ? 'selected' : '' }}>Provincia</option>
                        <option value="regione" {{ $tipoEnte === 'regione' ? 'selected' : '' }}>Regione</option>
                        <option value="altro" {{ $tipoEnte === 'altro' ? 'selected' : '' }}>Altro ente</option>
                    </select>
                    <x-input-error :messages="$errors->get('tipo_ente')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="partita_iva" value="Partita IVA" />
                    <x-text-input id="partita_iva" name="partita_iva" type="text"
                                  class="mt-1 block w-full"
                                  :value="old('partita_iva', $organizzazione->partita_iva)" maxlength="11" />
                    <x-input-error :messages="$errors->get('partita_iva')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="codice_fiscale" value="Codice fiscale" />
                    <x-text-input id="codice_fiscale" name="codice_fiscale" type="text"
                                  class="mt-1 block w-full"
                                  :value="old('codice_fiscale', $organizzazione->codice_fiscale)" maxlength="16" />
                    <x-input-error :messages="$errors->get('codice_fiscale')" class="mt-1" />
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <x-input-label for="codice_meccanografico" value="Codice meccanografico" />
                    <x-text-input id="codice_meccanografico" name="codice_meccanografico" type="text"
                                  class="mt-1 block w-full"
                                  :value="old('codice_meccanografico', $organizzazione->codice_meccanografico)" maxlength="50" />
                    <x-input-error :messages="$errors->get('codice_meccanografico')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="dirigente" value="Dirigente / Referente" />
                    <x-text-input id="dirigente" name="dirigente" type="text"
                                  class="mt-1 block w-full"
                                  :value="old('dirigente', $organizzazione->dirigente)" maxlength="50" />
                    <x-input-error :messages="$errors->get('dirigente')" class="mt-1" />
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <x-input-label for="email" value="Email" />
                    <x-text-input id="email" name="email" type="email"
                                  class="mt-1 block w-full"
                                  :value="old('email', $organizzazione->email)" maxlength="50" />
                    <x-input-error :messages="$errors->get('email')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="recapiti" value="Recapiti telefonici" />
                    <x-text-input id="recapiti" name="recapiti" type="text"
                                  class="mt-1 block w-full"
                                  :value="old('recapiti', $organizzazione->recapiti)" maxlength="50" />
                    <x-input-error :messages="$errors->get('recapiti')" class="mt-1" />
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <x-input-label for="domini_email_istituzionali" value="Domini email istituzionali" />
                    <x-text-input id="domini_email_istituzionali" name="domini_email_istituzionali" type="text"
                                  class="mt-1 block w-full"
                                  :value="old('domini_email_istituzionali', $organizzazione->domini_email_istituzionali)"
                                  placeholder="es. comune.it scuola.edu.it" maxlength="255" />
                    <x-input-error :messages="$errors->get('domini_email_istituzionali')" class="mt-1" />
                    <p class="mt-1 text-xs text-gray-500">Separare i domini con spazio, virgola o punto e virgola.</p>
                </div>
                <div class="flex items-end">
                    <label for="attivo" class="inline-flex items-center gap-2">
                        <input id="attivo" name="attivo" type="checkbox" value="1"
                               class="rounded border-gray-300 text-blue-600 shadow-sm"
                               {{ old('attivo', $organizzazione->attivo ?? true) ? 'checked' : '' }}>
                        <span class="text-sm text-gray-700">Ente attivo per registrazioni</span>
                    </label>
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-2 border-t border-gray-100">
                <a href="{{ route('admin.organizzazioni.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Annulla</a>
                <button type="submit"
                        class="inline-flex items-center px-5 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-sm text-white uppercase tracking-widest hover:bg-blue-700 transition">
                    Salva modifiche
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
