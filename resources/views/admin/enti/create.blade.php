<x-app-layout>
    <x-slot name="header">Nuovo ente</x-slot>
    <x-slot name="actions">
        <a href="{{ route('admin.enti.index') }}"
           class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 transition">
            Annulla
        </a>
    </x-slot>

    <div class="space-y-6">
        <p class="text-sm text-gray-600">
            Per aggiungere una scuola statale usa invece
            <a href="{{ route('admin.anagrafe-miur.index') }}" class="text-blue-700 underline">Cerca su MIUR</a>:
            nome, codici e sedi arrivano già compilati.
        </p>

        <div class="bg-white shadow-sm rounded-xl">
            <form method="POST" action="{{ route('admin.enti.store') }}" class="p-6 space-y-5">
                @csrf
                @include('admin.enti._form')

                <div class="flex justify-end gap-3 pt-2 border-t border-gray-100">
                    <a href="{{ route('admin.enti.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Annulla</a>
                    <button type="submit"
                            class="inline-flex items-center px-5 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-sm text-white uppercase tracking-widest hover:bg-blue-700 transition">
                        Crea ente
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
