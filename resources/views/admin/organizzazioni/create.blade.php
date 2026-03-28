<x-app-layout>
    <x-slot name="header">Nuova organizzazione</x-slot>
    <x-slot name="actions">
        <a href="{{ route('admin.organizzazioni.index') }}"
           class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 transition">
            Annulla
        </a>
    </x-slot>

    <div class="bg-white shadow-sm rounded-xl">
        <form method="POST" action="{{ route('admin.organizzazioni.store') }}" class="p-6 space-y-5">
            @csrf

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <x-input-label for="descrizione" value="Nome / Descrizione *" />
                    <x-text-input id="descrizione" name="descrizione" type="text"
                                  class="mt-1 block w-full" :value="old('descrizione')" required maxlength="50" />
                    <x-input-error :messages="$errors->get('descrizione')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="tipo" value="Tipo *" />
                    <input id="tipo" name="tipo" type="text" list="tipi-list"
                           value="{{ old('tipo', 'Scuola') }}"
                           maxlength="50" required
                           class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 @error('tipo') border-red-500 @enderror"
                           placeholder="Es. Scuola, Azienda speciale, Municipio…" />
                    <datalist id="tipi-list">
                        @foreach($tipiEsistenti as $t)
                            <option value="{{ $t }}">
                        @endforeach
                    </datalist>
                    <x-input-error :messages="$errors->get('tipo')" class="mt-1" />
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <x-input-label for="codice_meccanografico" value="Codice meccanografico" />
                    <x-text-input id="codice_meccanografico" name="codice_meccanografico" type="text"
                                  class="mt-1 block w-full" :value="old('codice_meccanografico')" maxlength="50" />
                    <x-input-error :messages="$errors->get('codice_meccanografico')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="dirigente" value="Dirigente / Referente" />
                    <x-text-input id="dirigente" name="dirigente" type="text"
                                  class="mt-1 block w-full" :value="old('dirigente')" maxlength="50" />
                    <x-input-error :messages="$errors->get('dirigente')" class="mt-1" />
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <x-input-label for="email" value="Email" />
                    <x-text-input id="email" name="email" type="email"
                                  class="mt-1 block w-full" :value="old('email')" maxlength="50" />
                    <x-input-error :messages="$errors->get('email')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="recapiti" value="Recapiti telefonici" />
                    <x-text-input id="recapiti" name="recapiti" type="text"
                                  class="mt-1 block w-full" :value="old('recapiti')" maxlength="50" />
                    <x-input-error :messages="$errors->get('recapiti')" class="mt-1" />
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-2 border-t border-gray-100">
                <a href="{{ route('admin.organizzazioni.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Annulla</a>
                <button type="submit"
                        class="inline-flex items-center px-5 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-sm text-white uppercase tracking-widest hover:bg-blue-700 transition">
                    Salva
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
