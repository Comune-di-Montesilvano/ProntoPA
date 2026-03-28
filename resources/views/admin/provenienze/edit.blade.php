<x-app-layout>
    <x-slot name="header">Modifica provenienza</x-slot>
    <x-slot name="actions">
        <a href="{{ route('admin.provenienze.index') }}"
           class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 transition">
            Annulla
        </a>
    </x-slot>

    <div class="bg-white shadow-sm rounded-xl">
        <form method="POST" action="{{ route('admin.provenienze.update', $provenienza->id_provenienza) }}" class="p-6 space-y-5">
            @csrf @method('PATCH')

            <div>
                <x-input-label for="descrizione" value="Descrizione *" />
                <x-text-input id="descrizione" name="descrizione" type="text"
                              class="mt-1 block w-full"
                              :value="old('descrizione', $provenienza->descrizione)"
                              required maxlength="50" />
                <x-input-error :messages="$errors->get('descrizione')" class="mt-1" />
            </div>

            <div class="flex justify-end gap-3 pt-2 border-t border-gray-100">
                <a href="{{ route('admin.provenienze.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Annulla</a>
                <button type="submit"
                        class="inline-flex items-center px-5 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-sm text-white uppercase tracking-widest hover:bg-blue-700 transition">
                    Salva modifiche
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
