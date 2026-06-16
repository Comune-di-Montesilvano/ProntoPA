<x-public-layout>
    <x-slot name="title">Gestione Lavoro #{{ $segnalazione->id_segnalazione }}</x-slot>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
        
        {{-- Alert Successo/Errore --}}
        @if (session('success'))
            <div class="bg-green-50 border-l-4 border-green-400 p-4 rounded-md shadow-sm">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-check-circle text-green-400"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-green-800">{{ session('success') }}</p>
                    </div>
                </div>
            </div>
        @endif

        {{-- Intestazione --}}
        <div class="bg-white shadow-sm rounded-xl p-6 border border-gray-200">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <span class="text-xs font-mono text-gray-400">#{{ $segnalazione->id_segnalazione }}</span>
                    <h1 class="text-2xl font-bold text-gray-900 mt-1">
                        {{ $segnalazione->tipologia?->descrizione ?? 'Intervento di manutenzione' }}
                    </h1>
                    @if($segnalazione->appalto?->impresa)
                        <p class="text-sm text-gray-500 mt-1">
                            Assegnato a: <strong class="text-gray-700">{{ $segnalazione->appalto->impresa->ragione_sociale }}</strong>
                        </p>
                    @endif
                </div>
                <div>
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold {{ $segnalazione->stato?->badgeClass() }}">
                        {{ $segnalazione->stato?->descrizione }}
                    </span>
                </div>
            </div>
        </div>

        {{-- Dettagli Segnalazione --}}
        <div class="bg-white shadow-sm rounded-xl p-6 border border-gray-200 space-y-4">
            <h2 class="text-lg font-bold text-gray-800 border-b border-gray-100 pb-2">Dettagli Intervento</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                <div>
                    <span class="text-gray-400 block">Data Assegnazione</span>
                    <strong class="text-gray-700">{{ $segnalazione->data_segnalazione?->format('d/m/Y H:i') }}</strong>
                </div>
                <div>
                    <span class="text-gray-400 block">Ubicazione (Plesso)</span>
                    <strong class="text-gray-700">{{ $segnalazione->plesso?->nome ?? '—' }}</strong>
                    @if($segnalazione->plesso?->indirizzo)
                        <span class="text-xs text-gray-400 block italic">{{ $segnalazione->plesso->indirizzo }}</span>
                    @endif
                </div>
                <div>
                    <span class="text-gray-400 block">Priorità</span>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold {{ $segnalazione->badge_priorita_class }}">
                        {{ $segnalazione->label_priorita }}
                    </span>
                    @if($segnalazione->segnalazione_urgente)
                        <span class="ml-1 text-red-600 text-xs font-bold font-sans">Urgente</span>
                    @endif
                </div>
                <div>
                    <span class="text-gray-400 block">Scadenza SLA</span>
                    @if($segnalazione->data_scadenza_sla)
                        <strong class="text-gray-700">{{ $segnalazione->data_scadenza_sla->format('d/m/Y H:i') }}</strong>
                        @if($segnalazione->sla_violato)
                            <span class="text-red-500 font-bold ml-1">(Violato)</span>
                        @endif
                    @else
                        <span class="text-gray-500">—</span>
                    @endif
                </div>
            </div>

            <div class="border border-gray-100 rounded-lg p-4 bg-gray-50 text-sm">
                <span class="font-semibold text-gray-600 block mb-1">Descrizione del problema:</span>
                <p class="text-gray-700 italic whitespace-pre-wrap">{{ $segnalazione->testo_segnalazione }}</p>
            </div>
        </div>

        {{-- Preventivo Attivo --}}
        @if($segnalazione->importo_preventivo > 0)
            <div class="bg-blue-50 border border-blue-200 rounded-xl p-6 text-sm flex justify-between items-center shadow-sm">
                <div>
                    <span class="font-semibold text-blue-800 block">Preventivo di spesa:</span>
                    <span class="text-blue-900 font-bold text-xl">€ {{ number_format($segnalazione->importo_preventivo, 2, ',', '.') }}</span>
                </div>
                @php
                    $statoAccettato = $segnalazione->storicoStati->where('id_stato_segnalazione', 11)->first();
                @endphp
                @if ($statoAccettato)
                    <div class="text-xs text-blue-600 italic">
                        Accettato il {{ $statoAccettato->created_at->format('d/m/Y') }}
                    </div>
                @else
                    <div class="text-xs text-orange-600 font-semibold uppercase bg-orange-100 px-2.5 py-1 rounded">
                        In attesa di approvazione
                    </div>
                @endif
            </div>
        @endif

        {{-- Rapportino prima/dopo --}}
        @if($fotoDopo->isNotEmpty())
            <div class="bg-white border border-gray-200 rounded-xl p-6 space-y-4 shadow-sm">
                <h3 class="font-bold text-gray-800 border-b border-gray-100 pb-2">Rapportino Fotografico (Prima / Dopo)</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="space-y-2">
                        <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider block">Stato Iniziale (Prima)</span>
                        @if($fotoPrima->isEmpty())
                            <div class="bg-gray-50 border border-dashed border-gray-200 rounded-lg h-36 flex items-center justify-center text-gray-400 text-sm">
                                Nessuna foto iniziale caricata.
                            </div>
                        @else
                            <div class="grid grid-cols-2 gap-2">
                                @foreach($fotoPrima as $allegato)
                                    @if(str_starts_with($allegato->tipo, 'image/'))
                                        <div class="relative group rounded-lg overflow-hidden border border-gray-100 aspect-video bg-gray-50">
                                            <img src="{{ route('segnalazioni.allegati.download', [$segnalazione->id_segnalazione, $allegato->id_allegato]) }}" 
                                                 alt="Prima" class="object-cover w-full h-full">
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="space-y-2">
                        <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider block">Intervento Eseguito (Dopo)</span>
                        <div class="grid grid-cols-2 gap-2">
                            @foreach($fotoDopo as $allegato)
                                @if(str_starts_with($allegato->tipo, 'image/'))
                                    <div class="relative group rounded-lg overflow-hidden border border-gray-100 aspect-video bg-gray-50">
                                        <img src="{{ route('segnalazioni.allegati.download', [$segnalazione->id_segnalazione, $allegato->id_allegato]) }}" 
                                             alt="Dopo" class="object-cover w-full h-full">
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- Form Esegui Azione --}}
        @unless($segnalazione->isChiusa())
            @if($azioniDisponibili->isNotEmpty())
                <div class="bg-white shadow-sm rounded-xl p-6 border border-gray-200">
                    <h3 class="font-bold text-gray-800 mb-4 pb-2 border-b border-gray-100">Aggiorna Stato Lavoro</h3>
                    <form method="POST" action="{{ request()->fullUrl() }}"
                          enctype="multipart/form-data"
                          x-data="{ azioneId: null, flagPreventivo: false,
                            azioni: {{ $azioniDisponibili->keyBy('id_azione')->map(fn($a) => ['flag_preventivo' => (bool)$a->flag_preventivo])->toJson() }} }"
                          @change="let a = azioni[azioneId]; flagPreventivo = a ? a.flag_preventivo : false;"
                          class="space-y-4">
                        @csrf

                        <div>
                            <x-input-label for="id_azione" value="Seleziona l'azione da compiere *" />
                            <select id="id_azione" name="id_azione" x-model="azioneId"
                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm"
                                    required>
                                <option value="">— Seleziona azione —</option>
                                @foreach($azioniDisponibili as $azione)
                                    <option value="{{ $azione->id_azione }}">{{ $azione->descrizione }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('id_azione')" class="mt-1" />
                        </div>

                        <div x-show="flagPreventivo" x-cloak class="border-t border-gray-100 pt-4">
                            <x-input-label for="importo_preventivo" value="Importo preventivo (€) *" />
                            <input type="number" id="importo_preventivo" name="importo_preventivo" step="0.01" min="0"
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-base"
                                   placeholder="0.00"
                                   :required="flagPreventivo">
                            <x-input-error :messages="$errors->get('importo_preventivo')" class="mt-1" />
                        </div>

                        <div x-show="azioneId == 6" x-cloak class="space-y-4 border-t border-gray-100 pt-4">
                            <h4 class="font-semibold text-sm text-gray-600">Dettagli Rapportino di Fine Lavoro</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <x-input-label for="ore_lavoro" value="Ore impiegate *" />
                                    <input type="number" id="ore_lavoro" name="ore_lavoro" step="0.1" min="0"
                                           class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm"
                                           :required="azioneId == 6">
                                    <x-input-error :messages="$errors->get('ore_lavoro')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label for="materiali" value="Materiali utilizzati *" />
                                    <input type="text" id="materiali" name="materiali"
                                           class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm"
                                           placeholder="es. Rubinetto, mastice, tubi"
                                           :required="azioneId == 6">
                                    <x-input-error :messages="$errors->get('materiali')" class="mt-1" />
                                </div>
                            </div>
                            <div>
                                <x-input-label for="rapportino_allegati" value="Foto dell'intervento (Almeno una) *" />
                                <input type="file" id="rapportino_allegati" name="allegati[]" multiple accept="image/*"
                                       class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"
                                       :required="azioneId == 6">
                                <p class="text-xs text-gray-400 mt-1">Carica una o più foto che mostrino il lavoro completato.</p>
                                <x-input-error :messages="$errors->get('allegati')" class="mt-1" />
                                <x-input-error :messages="$errors->get('allegati.*')" class="mt-1" />
                            </div>
                        </div>

                        <div>
                            <x-input-label for="nota" x-text='azioneId == 6 ? "Descrizione dell\u0027intervento *" : "Nota/Dettagli (opzionale)"' />
                            <textarea id="nota" name="nota" rows="3" maxlength="2000"
                                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm"
                                      :placeholder="azioneId == 6 ? 'Fornisci una descrizione dettagliata dei lavori eseguiti...' : 'Scrivi una nota per l\'ente...'"
                                      :required="azioneId == 6"></textarea>
                            <x-input-error :messages="$errors->get('nota')" class="mt-1" />
                        </div>

                        <div class="text-right pt-2">
                            <button type="submit"
                                    class="inline-flex items-center px-5 py-2.5 bg-blue-600 rounded-md font-semibold text-sm text-white hover:bg-blue-700 shadow-sm transition">
                                Salva ed Invia
                            </button>
                        </div>
                    </form>
                </div>
            @else
                <div class="bg-gray-50 rounded-xl p-6 text-center text-gray-500 border border-gray-200">
                    Nessuna azione disponibile per questa ditta nello stato attuale della segnalazione.
                </div>
            @endif
        @else
            <div class="bg-gray-50 rounded-xl p-6 text-center text-gray-500 border border-gray-200">
                Segnalazione chiusa — nessuna operazione richiesta.
            </div>
        @endunless

        {{-- Note/Storico communications --}}
        <div class="bg-white shadow-sm rounded-xl p-6 border border-gray-200 space-y-4">
            <h2 class="text-lg font-bold text-gray-800 border-b border-gray-100 pb-2">Comunicazioni</h2>
            @forelse($note as $n)
                <div class="border-b border-gray-100 pb-3 last:border-0 last:pb-0">
                    <div class="flex justify-between text-xs text-gray-400 mb-1">
                        <span>{{ $n->autore?->name ?? 'Operatore Ente' }}</span>
                        <span>{{ $n->data->format('d/m/Y H:i') }}</span>
                    </div>
                    <p class="text-sm text-gray-700 whitespace-pre-wrap">{{ $n->testo }}</p>
                </div>
            @empty
                <p class="text-sm text-gray-400 text-center py-2">Nessuna comunicazione registrata.</p>
            @endforelse
        </div>

    </div>
</x-public-layout>
