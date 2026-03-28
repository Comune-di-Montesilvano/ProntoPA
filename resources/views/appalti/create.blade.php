<x-app-layout>
    <x-slot name="header">Nuovo appalto</x-slot>

    <div class="space-y-4">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <form method="POST" action="{{ route('appalti.store') }}" class="p-6 space-y-4">
                @csrf
                @include('appalti._form')
                <div class="flex justify-end gap-3 pt-2 border-t border-gray-100">
                    <a href="{{ route('appalti.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Annulla</a>
                    <button type="submit"
                            class="inline-flex items-center px-5 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition">
                        Salva
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
