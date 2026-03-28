<x-app-layout>
    <x-slot name="header">Amministrazione</x-slot>

    <div class="space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <a href="{{ route('admin.impostazioni.index') }}"
                   class="bg-white shadow-sm rounded-lg p-6 hover:shadow-md transition group">
                    <div class="text-blue-600 text-2xl mb-2">&#9881;</div>
                    <div class="font-semibold text-gray-800 group-hover:text-blue-600">Impostazioni ente</div>
                    <div class="text-sm text-gray-500 mt-1">Brandizzazione, email, mappa, webhook</div>
                </a>
                <a href="{{ route('gestione.dashboard') }}"
                   class="bg-white shadow-sm rounded-lg p-6 hover:shadow-md transition group">
                    <div class="text-blue-600 text-2xl mb-2">&#128203;</div>
                    <div class="font-semibold text-gray-800 group-hover:text-blue-600">Gestione segnalazioni</div>
                    <div class="text-sm text-gray-500 mt-1">Dashboard operativa segnalazioni</div>
                </a>
                <a href="{{ route('imprese.index') }}"
                   class="bg-white shadow-sm rounded-lg p-6 hover:shadow-md transition group">
                    <div class="text-blue-600 text-2xl mb-2">&#127970;</div>
                    <div class="font-semibold text-gray-800 group-hover:text-blue-600">Imprese</div>
                    <div class="text-sm text-gray-500 mt-1">Anagrafica imprese appaltatrici</div>
                </a>
                <a href="{{ route('appalti.index') }}"
                   class="bg-white shadow-sm rounded-lg p-6 hover:shadow-md transition group">
                    <div class="text-blue-600 text-2xl mb-2">&#128196;</div>
                    <div class="font-semibold text-gray-800 group-hover:text-blue-600">Appalti</div>
                    <div class="text-sm text-gray-500 mt-1">Gestione contratti e CIG</div>
                </a>
                <a href="{{ route('statistiche.index') }}"
                   class="bg-white shadow-sm rounded-lg p-6 hover:shadow-md transition group">
                    <div class="text-blue-600 text-2xl mb-2">&#128200;</div>
                    <div class="font-semibold text-gray-800 group-hover:text-blue-600">Statistiche</div>
                    <div class="text-sm text-gray-500 mt-1">Grafici e KPI segnalazioni</div>
                </a>
        </div>
    </div>
</x-app-layout>
