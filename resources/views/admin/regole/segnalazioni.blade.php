<x-app-layout>
    <x-slot name="header">Regole delle segnalazioni</x-slot>

    <form method="POST" action="{{ route('admin.regole.segnalazioni.update') }}" class="bg-white shadow-sm rounded-xl p-5 space-y-5 max-w-3xl">
        @csrf @method('PATCH')

        <x-pa.impostazione chiave="reopen_days_limit" tipo="intero" etichetta="Giorni per poter riaprire una segnalazione chiusa"
            aiuto="Passati questi giorni dalla chiusura non si può più riaprire. 0 = non si può mai riaprire." />

        <x-pa.impostazione chiave="adesioni_enabled" tipo="bool" etichetta="Suggerisci segnalazioni simili e consenti di aderire"
            aiuto="Chi segnala vede se esiste già una segnalazione uguale e può aderire invece di crearne una nuova." />
        <x-pa.impostazione chiave="adesioni_soglia_priorita" tipo="intero" etichetta="Adesioni per aumentare la priorità"
            aiuto="Ogni quante adesioni la priorità sale di un livello." />
        <x-pa.impostazione chiave="dedup_raggio_metri" tipo="intero" etichetta="Distanza massima per considerarle simili (metri)"
            aiuto="Due segnalazioni con posizione entro questa distanza sono considerate la stessa." />
        <x-pa.impostazione chiave="dedup_giorni" tipo="intero" etichetta="Giorni in cui cercare segnalazioni simili"
            aiuto="Si confrontano solo le segnalazioni degli ultimi giorni indicati." />

        <x-pa.impostazione chiave="publication_enabled" tipo="bool" etichetta="Pubblica automaticamente le segnalazioni"
            aiuto="Le segnalazioni diventano pubbliche quando raggiungono lo stato scelto qui sotto." />
        <x-pa.impostazione chiave="publication_auto_state_id" tipo="stato" etichetta="Pubblica quando la segnalazione arriva a"
            aiuto="La regola scatta ai cambi di stato." />

        <x-pa.impostazione chiave="etichetta_ufficio_segnalante" tipo="testo" etichetta="Come si presenta il personale a chi ha segnalato"
            aiuto="Chi ha segnalato vede questa dicitura al posto dei nomi interni (es. Ufficio manutenzioni)." />

        <button type="submit" class="inline-flex items-center px-4 py-2 bg-blue-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition">Salva</button>
    </form>

    <form method="POST" action="{{ route('admin.regole.pubblica-esistenti') }}" class="mt-4 max-w-3xl rounded-xl border border-gray-200 bg-gray-50 px-5 py-4 text-sm text-gray-700">
        @csrf
        <p>Per le segnalazioni già presenti (per esempio importate) applica la regola ora, dopo aver salvato.</p>
        <button type="submit"
                onclick="return confirm('Pubblicare ora tutte le segnalazioni che rispettano la regola salvata?')"
                class="mt-2 inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 rounded-md text-xs font-semibold text-gray-700 hover:bg-gray-100">
            Applica alle segnalazioni esistenti
        </button>
    </form>
</x-app-layout>
