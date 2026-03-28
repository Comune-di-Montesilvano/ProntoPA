<x-app-layout>
    <x-slot name="header">Segnalazione #{{ $segnalazione->id_segnalazione }}</x-slot>
    <x-slot name="actions">
        <a href="{{ route('segnalazioni.stampa', $segnalazione->id_segnalazione) }}" target="_blank"
           class="inline-flex items-center px-3 py-2 bg-white border border-gray-300 rounded-md font-semibold text-sm text-gray-700 hover:bg-gray-50 transition">
            Stampa
        </a>
        @can('update', $segnalazione)
            <form method="POST" action="{{ route('segnalazioni.evidenza', $segnalazione->id_segnalazione) }}">
                @csrf
                <button type="submit"
                        class="inline-flex items-center px-4 py-2 rounded-md font-semibold text-sm text-white transition
                               {{ $segnalazione->flag_evidenza
                                   ? 'bg-yellow-500 hover:bg-yellow-600'
                                   : 'bg-green-600 hover:bg-green-700' }}">
                    {{ $segnalazione->flag_evidenza ? '★ In evidenza' : '☆ Metti in evidenza' }}
                </button>
            </form>
        @endcan
    </x-slot>

    <div x-data="{ tab: window.location.hash === '#note' ? 'note' : (window.location.hash === '#storico' ? 'storico' : (window.location.hash === '#gestione' ? 'gestione' : 'dati')) }">

        {{-- Info bar stato + operatore --}}
        <div class="bg-white border-l-4 {{ $segnalazione->flag_evidenza ? 'border-yellow-400' : 'border-blue-400' }} rounded-lg shadow-sm px-5 py-3 mb-4 flex flex-wrap items-center gap-x-6 gap-y-1 text-sm">
            <div>
                <span class="text-gray-500">Stato:</span>
                <strong class="ml-1">{{ $segnalazione->stato?->descrizione ?? '—' }}</strong>
            </div>
            @if($segnalazione->operatore)
                <div>
                    <span class="text-gray-500">Operatore assegnato:</span>
                    <strong class="ml-1 text-blue-700">{{ $segnalazione->operatore->name }}</strong>
                </div>
            @endif
            @if($segnalazione->appalto?->impresa)
                <div>
                    <span class="text-gray-500">Impresa:</span>
                    <strong class="ml-1">{{ $segnalazione->appalto->impresa->ragione_sociale }}</strong>
                </div>
            @endif
            @if($segnalazione->flag_evidenza)
                <div class="ml-auto text-yellow-500 font-bold">★ In evidenza</div>
            @endif
        </div>

        {{-- Tab navigation --}}
        <div class="border-b border-gray-200 mb-4">
            <nav class="-mb-px flex space-x-6">
                @foreach([
                    'dati'     => 'Segnalazione',
                    'note'     => 'Note / Comunicazioni',
                    'storico'  => 'Storico segnalazione',
                    'gestione' => 'Gestione Segnalazione',
                ] as $key => $label)
                    @if($key === 'gestione')
                        @can('update', $segnalazione)
                            <button @click="tab = 'gestione'"
                                    :class="tab === 'gestione' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                                    class="whitespace-nowrap pb-3 px-1 border-b-2 font-medium text-base">
                                {{ $label }}
                                @if($azioniDisponibili->count() > 0)
                                    <span class="ml-1 bg-blue-100 text-blue-600 text-xs px-1.5 py-0.5 rounded-full">{{ $azioniDisponibili->count() }}</span>
                                @endif
                            </button>
                        @endcan
                    @elseif($key === 'storico')
                        <button @click="tab = 'storico'"
                                :class="tab === 'storico' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                                class="whitespace-nowrap pb-3 px-1 border-b-2 font-medium text-base">
                            {{ $label }}
                            @if($segnalazione->storicoStati->count() > 0)
                                <span class="ml-1 bg-gray-100 text-gray-600 text-xs px-1.5 py-0.5 rounded-full">{{ $segnalazione->storicoStati->count() }}</span>
                            @endif
                        </button>
                    @else
                        <button @click="tab = '{{ $key }}'"
                                :class="tab === '{{ $key }}' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                                class="whitespace-nowrap pb-3 px-1 border-b-2 font-medium text-base">
                            {{ $label }}
                            @if($key === 'note' && $segnalazione->note->count() > 0)
                                <span class="ml-1 bg-gray-100 text-gray-600 text-xs px-1.5 py-0.5 rounded-full">{{ $segnalazione->note->count() }}</span>
                            @endif
                        </button>
                    @endif
                @endforeach
            </nav>
        </div>

        {{-- TAB: Dati --}}
        <div x-show="tab === 'dati'" class="space-y-4">
            <div class="bg-white shadow-sm rounded-lg p-6 space-y-5">

                {{-- Tipologia --}}
                <div>
                    @if($segnalazione->tipologia?->gruppo)
                        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">
                            {{ $segnalazione->tipologia->gruppo->descrizione }}
                        </div>
                    @endif
                    <h2 class="text-xl font-bold text-gray-900 uppercase">
                        Tipologia : {{ $segnalazione->tipologia?->descrizione ?? '—' }}
                    </h2>
                </div>

                <hr class="border-gray-200">

                {{-- Meta row: data / provenienza --}}
                <div class="flex flex-wrap gap-x-12 gap-y-2 text-base">
                    <div>
                        <span class="font-semibold text-gray-700">Data segnalazione :</span>
                        <span class="ml-1">{{ $segnalazione->data_segnalazione?->format('d-m-y H:i') }}</span>
                    </div>
                    <div class="sm:ml-auto">
                        <span class="text-gray-500">Provenienza :</span>
                        <strong class="ml-1 italic text-blue-700">{{ strtoupper($segnalazione->provenienza?->descrizione ?? '—') }}</strong>
                    </div>
                </div>

                {{-- Plesso --}}
                @if($segnalazione->id_plesso && $segnalazione->plesso)
                    @php $plesso = $segnalazione->plesso; $istituto = $plesso->istituto; @endphp
                    <div class="flex flex-wrap gap-x-12 gap-y-3 text-base">
                        <div class="flex-1 min-w-[260px]">
                            <div class="font-semibold text-gray-700 mb-1">PLESSO:</div>
                            <div class="font-bold">
                                {{ $plesso->nome }}
                                @if($plesso->codice_meccanografico)
                                    <span class="text-blue-600 font-normal">({{ $plesso->codice_meccanografico }})</span>
                                @endif
                            </div>
                            @if($plesso->indirizzo)
                                <div class="text-gray-500 text-sm italic mt-0.5">{{ $plesso->indirizzo }}</div>
                            @endif
                            @if($plesso->referente)
                                <div class="text-blue-600 text-sm italic mt-1">
                                    Referente : {{ $plesso->referente }}
                                    @if($plesso->recapiti) — ☎ {{ $plesso->recapiti }} @endif
                                    @if($plesso->email) ✉ {{ $plesso->email }} @endif
                                </div>
                            @endif
                            @if($istituto && $istituto->dirigente)
                                <div class="text-blue-600 text-sm italic mt-0.5">
                                    Dirigente Scolastico : {{ $istituto->dirigente }}
                                    @if($istituto->recapiti) — ☎ {{ $istituto->recapiti }} @endif
                                    @if($istituto->email) ✉ {{ $istituto->email }} @endif
                                </div>
                            @endif
                        </div>
                        @if($istituto)
                            <div class="sm:text-right text-sm">
                                <span class="text-gray-500">Direzione didattica :</span>
                                <strong class="ml-1 text-blue-700 italic">{{ $istituto->descrizione }}</strong>
                            </div>
                        @endif
                    </div>
                @endif

                {{-- Testo segnalazione --}}
                <div class="border border-gray-300 rounded-md p-4">
                    <div class="font-bold text-gray-800 mb-3">Testo segnalazione:</div>
                    <div class="text-gray-700 italic leading-relaxed whitespace-pre-wrap text-base">{{ $segnalazione->testo_segnalazione }}</div>
                </div>

                {{-- Registratore --}}
                @if($segnalazione->utente)
                    <div class="text-sm text-gray-500">
                        Operatore che ha registrato la segnalazione :
                        <strong class="italic text-gray-800 text-base ml-1">{{ strtoupper($segnalazione->utente->name) }}</strong>
                    </div>
                @endif

                @if($segnalazione->data_chiusura)
                    <div class="text-sm text-gray-500">
                        Data chiusura :
                        <strong class="ml-1">{{ \Carbon\Carbon::parse($segnalazione->data_chiusura)->format('d/m/Y H:i') }}</strong>
                    </div>
                @endif
            </div>

            {{-- Mappa Leaflet --}}
            @if($segnalazione->latitudine && $segnalazione->longitudine && $segnalazione->latitudine != 0)
                <div class="bg-white shadow-sm rounded-lg overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-100 text-sm font-medium text-gray-500 uppercase tracking-wider">
                        Posizione segnalata
                    </div>
                    <div id="mappa-segnalazione" class="h-64 w-full"></div>
                </div>
            @endif
        </div>

        {{-- TAB: Note --}}
        <div x-show="tab === 'note'" id="note" class="space-y-4">
            @forelse($segnalazione->note as $nota)
                <div class="bg-white shadow-sm rounded-lg p-4">
                    <div class="flex items-start justify-between mb-2">
                        <div class="font-medium text-gray-700">{{ $nota->autore?->name ?? 'Sistema' }}</div>
                        <div class="text-gray-400 text-sm">
                            {{ $nota->data?->format('d/m/Y H:i') }}
                            @if($nota->visibile_web)
                                <span class="ml-2 bg-green-100 text-green-700 px-1.5 py-0.5 rounded text-xs">web</span>
                            @endif
                            @if($nota->visibile_impresa)
                                <span class="ml-1 bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded text-xs">impresa</span>
                            @endif
                        </div>
                    </div>
                    <div class="text-gray-800 whitespace-pre-wrap text-base">{{ $nota->testo }}</div>
                </div>
            @empty
                <div class="bg-white shadow-sm rounded-lg p-6 text-center text-gray-400">Nessuna nota.</div>
            @endforelse

            <div class="bg-white shadow-sm rounded-lg p-5">
                <h3 class="font-semibold text-gray-700 mb-3">Aggiungi nota</h3>
                <form method="POST" action="{{ route('segnalazioni.nota', $segnalazione->id_segnalazione) }}" class="space-y-3">
                    @csrf
                    <textarea name="testo" rows="3" maxlength="2000"
                              class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-base"
                              placeholder="Testo della nota..." required></textarea>
                    <x-input-error :messages="$errors->get('testo')" class="mt-1" />
                    @can('update', $segnalazione)
                        <div class="flex items-center gap-4 text-sm">
                            <label class="flex items-center gap-1.5 text-gray-600">
                                <input type="checkbox" name="visibile_web" value="1" class="rounded border-gray-300">
                                Visibile web
                            </label>
                            <label class="flex items-center gap-1.5 text-gray-600">
                                <input type="checkbox" name="visibile_impresa" value="1" class="rounded border-gray-300">
                                Visibile impresa
                            </label>
                        </div>
                    @endcan
                    <div class="text-right">
                        <button type="submit"
                                class="inline-flex items-center px-4 py-2 bg-gray-800 rounded-md font-semibold text-sm text-white hover:bg-gray-700 transition">
                            Aggiungi nota
                        </button>
                    </div>
                </form>
            </div>
        </div>

        {{-- TAB: Storico --}}
        <div x-show="tab === 'storico'" id="storico">
            <div class="bg-white shadow-sm rounded-lg overflow-hidden">
                @if($segnalazione->storicoStati->isEmpty())
                    <div class="p-6 text-center text-gray-400">Nessuna transizione registrata.</div>
                @else
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-800 text-white">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold">Data</th>
                                <th class="px-4 py-3 text-left font-semibold">Stato</th>
                                <th class="px-4 py-3 text-left font-semibold">Operatore</th>
                                <th class="px-4 py-3 text-left font-semibold">Impresa</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            @foreach($segnalazione->storicoStati->sortBy('data_registrazione') as $h)
                                <tr>
                                    <td class="px-4 py-3 text-gray-500 whitespace-nowrap">
                                        {{ $h->data_registrazione ? \Carbon\Carbon::parse($h->data_registrazione)->format('d/m/Y - H:i') : '—' }}
                                    </td>
                                    <td class="px-4 py-3 font-semibold uppercase text-sm">
                                        {{ $h->stato?->descrizione ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-blue-700">{{ $h->utente?->name ?? '—' }}</td>
                                    <td class="px-4 py-3 text-gray-500">{{ $segnalazione->appalto?->impresa?->ragione_sociale ?? '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        {{-- TAB: Gestione --}}
        @can('update', $segnalazione)
            <div x-show="tab === 'gestione'" id="gestione" class="space-y-4">
                @if($segnalazione->isChiusa())
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-center text-gray-500">
                        Segnalazione chiusa — nessuna azione disponibile.
                    </div>
                @elseif($azioniDisponibili->isEmpty())
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-center text-gray-500">
                        Nessuna azione disponibile.
                    </div>
                @else
                    <div class="bg-white shadow-sm rounded-lg p-5">
                        <h3 class="font-semibold text-gray-700 mb-4">Esegui azione</h3>
                        <form method="POST" action="{{ route('segnalazioni.azione', $segnalazione->id_segnalazione) }}"
                              x-data="{ azioneId: null, flagOperatore: false, flagAppalto: false,
                                azioni: {{ $azioniDisponibili->keyBy('id_azione')->map(fn($a) => ['flag_operatore' => (bool)$a->flag_operatore, 'flag_appalto' => (bool)$a->flag_appalto])->toJson() }} }"
                              @change="let a = azioni[azioneId]; flagOperatore = a ? a.flag_operatore : false; flagAppalto = a ? a.flag_appalto : false;"
                              class="space-y-4">
                            @csrf

                            <div>
                                <x-input-label for="id_azione" value="Azione *" />
                                <select id="id_azione" name="id_azione" x-model="azioneId"
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-base"
                                        required>
                                    <option value="">— Seleziona azione —</option>
                                    @foreach($azioniDisponibili as $azione)
                                        <option value="{{ $azione->id_azione }}">{{ $azione->descrizione }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('id_azione')" class="mt-1" />
                            </div>

                            <div x-show="flagOperatore" x-cloak>
                                <x-input-label for="id_operatore" value="Assegna a operatore" />
                                <select id="id_operatore" name="id_operatore"
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-base">
                                    <option value="">— Nessuno —</option>
                                    @foreach($operatori as $op)
                                        <option value="{{ $op->id }}">{{ $op->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div x-show="flagAppalto" x-cloak>
                                <x-input-label for="id_appalto" value="ID Appalto" />
                                <x-text-input id="id_appalto" name="id_appalto" type="number"
                                    class="mt-1 block w-full" placeholder="Numero appalto" />
                            </div>

                            <div>
                                <x-input-label for="nota" value="Nota (opzionale)" />
                                <textarea id="nota" name="nota" rows="2" maxlength="2000"
                                          class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-base"
                                          placeholder="Nota interna..."></textarea>
                            </div>

                            <div class="text-right">
                                <button type="submit"
                                        class="inline-flex items-center px-5 py-2 bg-blue-600 rounded-md font-semibold text-sm text-white hover:bg-blue-700 transition">
                                    Esegui azione
                                </button>
                            </div>
                        </form>
                    </div>
                @endif
            </div>
        @endcan

    </div>

    @if($segnalazione->latitudine && $segnalazione->longitudine && $segnalazione->latitudine != 0)
        @push('head')
            <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
        @endpush
        @push('scripts')
            <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
            <script>
                (function () {
                    const lat = {{ (float) $segnalazione->latitudine }};
                    const lng = {{ (float) $segnalazione->longitudine }};
                    const map = L.map('mappa-segnalazione').setView([lat, lng], 16);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                        maxZoom: 19,
                    }).addTo(map);
                    L.marker([lat, lng]).addTo(map)
                        .bindPopup('Segnalazione #{{ $segnalazione->id_segnalazione }}')
                        .openPopup();
                })();
            </script>
        @endpush
    @endif
</x-app-layout>
