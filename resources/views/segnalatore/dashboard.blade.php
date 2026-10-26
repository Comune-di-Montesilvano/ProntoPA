<x-app-layout>
    <x-slot name="header">Le mie segnalazioni</x-slot>
    <x-slot name="actions">
        <x-pa.btn variant="primary" :href="route('segnalazioni.create')">
            <i class="fas fa-plus" aria-hidden="true"></i> <span class="sm:hidden">Nuova</span><span class="hidden sm:inline">Nuova segnalazione</span>
        </x-pa.btn>
    </x-slot>

    @include('segnalazioni._elenco-segnalante', ['vuoto' => 'Non hai ancora inviato nessuna segnalazione.'])
</x-app-layout>
