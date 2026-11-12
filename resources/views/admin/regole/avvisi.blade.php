<x-app-layout>
    <x-slot name="header">Avvisi</x-slot>

    <form method="POST" action="{{ route('admin.regole.avvisi.update') }}" class="bg-white shadow-sm rounded-xl p-5 space-y-5 max-w-3xl">
        @csrf @method('PATCH')

        <h2 class="text-sm font-semibold text-gray-800">Riepilogo del mattino ai gestori</h2>
        <x-pa.impostazione chiave="digest_enabled" tipo="bool" etichetta="Invia ogni mattina il riepilogo ai gestori"
            aiuto="Una email con le segnalazioni da seguire." />
        <x-pa.impostazione chiave="digest_ora" tipo="ora" etichetta="Ora di invio" />
        <x-pa.impostazione chiave="digest_skip_weekend" tipo="bool" etichetta="Non inviarlo sabato e domenica" />

        <h2 class="text-sm font-semibold text-gray-800 pt-2">Avvisi su Telegram</h2>
        <x-pa.impostazione chiave="telegram_notifica_operatore_assegnazione" tipo="bool" etichetta="Avvisa l'operaio quando gli viene assegnato un lavoro" />
        <x-pa.impostazione chiave="telegram_notifica_sla_warning" tipo="bool" etichetta="Avvisa quando una scadenza sta per arrivare" />
        <x-pa.impostazione chiave="telegram_notifica_nuova_nota" tipo="bool" etichetta="Avvisa quando viene aggiunta una nota visibile" />

        <button type="submit" class="inline-flex items-center px-4 py-2 bg-blue-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition">Salva</button>
    </form>
</x-app-layout>
