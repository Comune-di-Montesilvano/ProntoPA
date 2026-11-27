<x-app-layout>
    <x-slot name="header">Avvisi</x-slot>

    <form method="POST" action="{{ route('admin.regole.avvisi.update') }}" class="bg-white shadow-sm rounded-xl p-5 space-y-5 max-w-3xl">
        @csrf @method('PATCH')

        <h2 class="text-sm font-semibold text-gray-800">Quadro del mattino</h2>
        <x-pa.impostazione chiave="digest_enabled" tipo="bool" etichetta="Invia ogni mattina il quadro: nuove, da fare, in ritardo"
            aiuto="Una email ai gestori con le segnalazioni da seguire." />
        <x-pa.impostazione chiave="digest_ora" tipo="ora" etichetta="Ora di invio" />

        <h2 class="text-sm font-semibold text-gray-800 pt-2">Quanto spesso</h2>
        <p class="text-xs text-gray-500">Gli avvisi di ogni tipo vengono raccolti in un unico riepilogo, con il ritmo scelto qui.</p>
        @foreach(\App\Support\Avvisi\Categorie::ORDINE as $categoria)
            <x-pa.impostazione :chiave="'avvisi_ritmo_' . $categoria" tipo="ritmo" :etichetta="\App\Support\Avvisi\Categorie::etichetta($categoria)" />
        @endforeach

        <h2 class="text-sm font-semibold text-gray-800 pt-2">Avvisi su Telegram</h2>
        <x-pa.impostazione chiave="telegram_riepiloghi" tipo="bool" etichetta="Manda i riepiloghi anche su Telegram" />

        <button type="submit" class="inline-flex items-center px-4 py-2 bg-blue-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition">Salva</button>
    </form>
</x-app-layout>
