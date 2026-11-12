<x-app-layout>
    <x-slot name="header">Integrazioni</x-slot>

    @php
        $schede = [
            'spid' => ['titolo' => 'Accesso SPID e CIE', 'desc' => 'Permette a scuole e ditte di entrare con la propria identità digitale.'],
            'telegram' => ['titolo' => 'Telegram', 'desc' => 'Avvisi e azioni rapide per gestori e operai tramite il bot.'],
            'webhook' => ['titolo' => 'Aggiornamenti al sito del Comune', 'desc' => 'A ogni cambio di stato una segnalazione viene comunicata a un altro sistema.'],
            'ai' => ['titolo' => 'Assistente locale', 'desc' => 'Titolo proposto, suggerimenti e ricerca dei duplicati simili, sempre in secondo piano.'],
            'antivirus' => ['titolo' => 'Antivirus sugli allegati', 'desc' => 'Controlla ogni file caricato; un file infetto viene messo in quarantena.'],
            'allegati' => ['titolo' => 'Allegati', 'desc' => 'Limiti e posizione dei file caricati con le segnalazioni.'],
            'miur' => ['titolo' => 'Anagrafe scuole MIUR', 'desc' => 'Da dove si scaricano i dati ufficiali delle scuole.'],
            'deleghe' => ['titolo' => 'Deleghe delle scuole', 'desc' => 'Tempi e limiti delle richieste di delega.'],
        ];
        $bottone = 'inline-flex items-center px-4 py-2 bg-blue-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition';
        $bottoneChiaro = 'inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 transition';
    @endphp

    <div class="space-y-5 max-w-3xl">
        @foreach($slugs as $slug)
            @php
                $scheda = $schede[$slug];
                $esito = $esiti[$slug] ?? null;
                $haStato = array_key_exists($slug, $attivo);
                $stato = ! $haStato ? null : ($attivo[$slug] ? (($esito && ! $esito['ok']) ? 'problema' : 'attiva') : 'spenta');
            @endphp
            <section id="{{ $slug }}" class="bg-white shadow-sm rounded-xl p-5 space-y-4">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h2 class="text-sm font-semibold text-gray-800">{{ $scheda['titolo'] }}</h2>
                        <p class="text-xs text-gray-500">{{ $scheda['desc'] }}</p>
                    </div>
                    @if($stato === 'attiva')
                        <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">Attiva</span>
                    @elseif($stato === 'problema')
                        <span class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800">Problema</span>
                    @elseif($stato === 'spenta')
                        <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700">Non attiva</span>
                    @endif
                </div>

                @if($haStato && ($motivi[$slug] ?? '') !== '')
                    <p class="text-sm text-gray-600">{{ $motivi[$slug] }}</p>
                @endif

                @if($esito)
                    <p class="text-sm {{ $esito['ok'] ? 'text-green-700' : 'text-red-700' }}">
                        Ultima prova ({{ \Illuminate\Support\Carbon::parse($esito['quando'])->timezone(config('app.timezone'))->format('d/m/Y H:i') }}): {{ $esito['messaggio'] }}
                    </p>
                @endif

                @if($slug === 'spid')
                    <div class="text-sm">
                        <span class="font-medium text-gray-800">Indirizzo di ritorno da comunicare al servizio SPID</span>
                        <code class="mt-1 block rounded bg-gray-50 border border-gray-200 px-2 py-1 text-xs break-all">{{ $redirectUri }}</code>
                    </div>
                @endif

                <form method="POST" action="{{ route('admin.integrazioni.update', $slug) }}" class="space-y-4">
                    @csrf @method('PATCH')
                    @switch($slug)
                        @case('spid')
                            <x-pa.impostazione chiave="oidc_issuer" tipo="url" etichetta="Indirizzo del servizio di accesso"
                                aiuto="La radice, senza /OIDC finale." />
                            <x-pa.impostazione chiave="oidc_client_id" etichetta="Identificativo del client" />
                            <x-pa.impostazione chiave="oidc_client_secret" tipo="segreto_cifrato" etichetta="Segreto del client"
                                aiuto="Se cambi la chiave dell'applicazione va reinserito." />
                            @break
                        @case('telegram')
                            <x-pa.impostazione chiave="telegram_bot_token" tipo="segreto" etichetta="Token del bot" />
                            <x-pa.impostazione chiave="telegram_bot_username" etichetta="Nome del bot" aiuto="Senza la @." />
                            <x-pa.impostazione chiave="telegram_webhook_secret" tipo="segreto" etichetta="Segreto del collegamento"
                                aiuto="Serve a Telegram per autenticare le sue chiamate." />
                            @break
                        @case('webhook')
                            <x-pa.impostazione chiave="webhook_cittadini_url" tipo="url" etichetta="Indirizzo a cui inviare gli aggiornamenti" />
                            <x-pa.impostazione chiave="webhook_cittadini_secret" tipo="segreto" etichetta="Segreto di firma"
                                aiuto="Ogni invio viene firmato con questo segreto." />
                            @break
                        @case('ai')
                            <x-pa.impostazione chiave="ai_enabled" tipo="bool" etichetta="Usa l'assistente locale" />
                            <x-pa.impostazione chiave="ai_ollama_url" tipo="url" etichetta="Indirizzo del servizio" />
                            <x-pa.impostazione chiave="ai_modello" etichetta="Modello per testi e suggerimenti" />
                            <x-pa.impostazione chiave="ai_embedding_modello" etichetta="Modello per cercare i duplicati simili" />
                            @break
                        @case('antivirus')
                            <x-pa.impostazione chiave="antivirus_enabled" tipo="bool" etichetta="Controlla gli allegati con l'antivirus"
                                aiuto="Serve il container del profilo Docker `security`: senza, i file restano non controllati." />
                            @break
                        @case('allegati')
                            <x-pa.impostazione chiave="allegati_max_size_mb" tipo="intero" etichetta="Dimensione massima di un file (MB)" />
                            <x-pa.impostazione chiave="allegati_max_per_request" tipo="intero" etichetta="File al massimo per segnalazione" />
                            <x-pa.impostazione chiave="allegati_mime_consentiti" etichetta="Tipi di file consentiti"
                                aiuto="Elenco separato da virgole, es. image/jpeg,application/pdf." />
                            <x-pa.impostazione chiave="allegati_storage_disk" tipo="disco" etichetta="Dove salvare i file" />
                            @break
                        @case('miur')
                            <x-pa.impostazione chiave="miur_anagrafe_url" tipo="url" etichetta="Indirizzo dei dati aperti MIUR" />
                            <x-pa.impostazione chiave="miur_comune_default" etichetta="Comune proposto di default" />
                            @break
                        @case('deleghe')
                            <x-pa.impostazione chiave="deleghe_max_pendenti" tipo="intero" etichetta="Richieste in attesa al massimo per persona" />
                            <x-pa.impostazione chiave="deleghe_giorni_stop_rifiuto" tipo="intero" etichetta="Giorni di attesa dopo un rifiuto" />
                            <x-pa.impostazione chiave="deleghe_giorni_scadenza_richiesta" tipo="intero" etichetta="Giorni dopo cui una richiesta scade" />
                            <x-pa.impostazione chiave="deleghe_email_giorno_istituto" tipo="intero" etichetta="Email al giorno alla stessa scuola" />
                            <x-pa.impostazione chiave="deleghe_mesi_validita" tipo="intero" etichetta="Mesi di validità di una delega" />
                            <x-pa.impostazione chiave="deleghe_giorni_avviso_delegato" tipo="intero" etichetta="Giorni di preavviso prima della scadenza" />
                            @break
                    @endswitch
                    <button type="submit" class="{{ $bottone }}">Salva</button>
                </form>

                @if(in_array($slug, $conProva, true))
                    <form method="POST" action="{{ route('admin.integrazioni.prova', $slug) }}">
                        @csrf
                        <button type="submit" class="{{ $bottoneChiaro }}">Prova adesso</button>
                    </form>
                @endif
            </section>
        @endforeach

        <section id="sincronizzazione-ad" class="bg-white shadow-sm rounded-xl p-5 space-y-2">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 class="text-sm font-semibold text-gray-800">Sincronizzazione con Active Directory</h2>
                    <p class="text-xs text-gray-500">Si configura sul server, non da qui.</p>
                </div>
                @if($syncConfigurata)
                    <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">Configurata</span>
                @else
                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700">Non configurata</span>
                @endif
            </div>
            <a href="{{ route('admin.utenti.dominio') }}"
               class="text-sm text-blue-700 hover:underline">Vai agli utenti del dominio</a>
        </section>
    </div>
</x-app-layout>
