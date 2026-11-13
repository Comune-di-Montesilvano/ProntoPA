<x-app-layout>
    <x-slot name="header">Modifica sede</x-slot>
    <x-slot name="actions">
        <a href="{{ route('admin.enti.show', $sede->id_istituto) }}"
           class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 transition">
            Annulla
        </a>
    </x-slot>

    <div class="bg-white shadow-sm rounded-xl">
        <form method="POST" action="{{ route('admin.sedi.update', $sede->id_plesso) }}" class="p-6 space-y-5">
            @csrf @method('PATCH')

            @php $miur = $sede->isMiur(); @endphp
            @if($miur)
                <p class="text-sm bg-blue-50 text-blue-800 rounded-md p-3">
                    Dati da anagrafe MIUR: nome, codice, indirizzo ed email si aggiornano da
                    <a href="{{ route('admin.anagrafe-miur.index') }}" class="underline">Aggiungi da MIUR</a>.
                </p>
            @endif

            <div>
                <x-input-label for="id_istituto" value="Ente *" />
                <select id="id_istituto" name="id_istituto" @disabled($miur) @if(! $miur) required @endif
                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 @error('id_istituto') border-red-500 @enderror">
                    <option value="">— Seleziona —</option>
                    @foreach($organizzazioni->groupBy(fn($o) => $o->categoria?->nome ?? '—') as $tipo => $items)
                        <optgroup label="{{ $tipo }}">
                            @foreach($items as $org)
                                <option value="{{ $org->id_istituto }}"
                                    {{ old('id_istituto', $sede->id_istituto) == $org->id_istituto ? 'selected' : '' }}>
                                    {{ $org->descrizione }}
                                </option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('id_istituto')" class="mt-1" />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <x-input-label for="nome" value="Nome sede *" />
                    <x-text-input id="nome" name="nome" :readonly="$miur" type="text"
                                  class="mt-1 block w-full"
                                  :value="old('nome', $sede->nome)" required maxlength="255" />
                    <x-input-error :messages="$errors->get('nome')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="codice_meccanografico" value="Codice meccanografico" />
                    <x-text-input id="codice_meccanografico" name="codice_meccanografico" :readonly="$miur" type="text"
                                  class="mt-1 block w-full"
                                  :value="old('codice_meccanografico', $sede->codice_meccanografico)" maxlength="50" />
                    <x-input-error :messages="$errors->get('codice_meccanografico')" class="mt-1" />
                </div>
            </div>

            <div>
                <x-input-label for="indirizzo" value="Indirizzo" />
                <x-text-input id="indirizzo" name="indirizzo" :readonly="$miur" type="text"
                              class="mt-1 block w-full"
                              :value="old('indirizzo', $sede->indirizzo)" maxlength="255" />
                <x-input-error :messages="$errors->get('indirizzo')" class="mt-1" />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
                <div>
                    <x-input-label for="referente" value="Referente" />
                    <x-text-input id="referente" name="referente" type="text"
                                  class="mt-1 block w-full"
                                  :value="old('referente', $sede->referente)" maxlength="50" />
                    <x-input-error :messages="$errors->get('referente')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="email" value="Email" />
                    <x-text-input id="email" name="email" :readonly="$miur" type="email"
                                  class="mt-1 block w-full"
                                  :value="old('email', $sede->email)" maxlength="50" />
                    <x-input-error :messages="$errors->get('email')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="recapiti" value="Recapiti telefonici" />
                    <x-text-input id="recapiti" name="recapiti" type="text"
                                  class="mt-1 block w-full"
                                  :value="old('recapiti', $sede->recapiti)" maxlength="50" />
                    <x-input-error :messages="$errors->get('recapiti')" class="mt-1" />
                </div>
            </div>

            @php
                $haPos = $sede->haCoordinate();
                $cLat = $haPos ? $sede->latitudine : (float) (\App\Models\Impostazione::get('osm_lat') ?: 42.5);
                $cLng = $haPos ? $sede->longitudine : (float) (\App\Models\Impostazione::get('osm_lng') ?: 14.1);
            @endphp
            <div class="space-y-2">
                <x-input-label value="Posizione sulla mappa" />
                <p class="text-xs text-gray-500 m-0">Serve per la mappa della scheda e per "Naviga" di chi va sul posto. Trascina il segnaposto o clicca sulla mappa.</p>
                <div id="mappa-sede" class="h-64 rounded-md border border-gray-200 z-0"></div>
                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        <x-input-label for="latitudine" value="Latitudine" />
                        <x-text-input id="latitudine" name="latitudine" type="text" inputmode="decimal" class="mt-1 block w-40"
                                      :value="old('latitudine', $sede->latitudine)" />
                    </div>
                    <div>
                        <x-input-label for="longitudine" value="Longitudine" />
                        <x-text-input id="longitudine" name="longitudine" type="text" inputmode="decimal" class="mt-1 block w-40"
                                      :value="old('longitudine', $sede->longitudine)" />
                    </div>
                    <button type="button" id="trova-indirizzo"
                            class="inline-flex items-center px-3 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 transition">
                        Trova dall'indirizzo
                    </button>
                    <span id="trova-esito" class="text-xs text-gray-600" role="status"></span>
                </div>
                <x-input-error :messages="$errors->get('latitudine')" class="mt-1" />
                <x-input-error :messages="$errors->get('longitudine')" class="mt-1" />
            </div>

            <div class="flex justify-end gap-3 pt-2 border-t border-gray-100">
                <a href="{{ route('admin.enti.show', $sede->id_istituto) }}"
                   class="text-sm text-gray-500 hover:text-gray-700">Annulla</a>
                <button type="submit"
                        class="inline-flex items-center px-5 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-sm text-white uppercase tracking-widest hover:bg-blue-700 transition">
                    Salva modifiche
                </button>
            </div>
        </form>
    </div>

    @if($altreSedi->isNotEmpty())
        <div class="bg-white shadow-sm rounded-xl mt-6 p-6 space-y-3">
            <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wider">Unisci in un'altra sede</h3>
            <p class="text-sm text-gray-600">
                Per i doppioni (es. sede del vecchio programma accanto a quella MIUR): le
                {{ $storico['segnalazioni'] }} segnalazioni e {{ $storico['deleghe'] }} deleghe di questa sede passano
                alla sede scelta, poi questa viene eliminata. Non si può annullare.
            </p>
            <form method="POST" action="{{ route('admin.sedi.unisci', $sede->id_plesso) }}" class="flex flex-col sm:flex-row gap-3"
                  onsubmit="return confirm(@js('Spostare storico e deleghe ed eliminare "'.$sede->nome.'"?'))">
                @csrf
                <select name="id_destinazione" required aria-label="Sede di destinazione"
                        class="flex-1 border-gray-300 rounded-md shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500">
                    <option value="">— Sede di destinazione —</option>
                    @foreach($altreSedi as $altra)
                        <option value="{{ $altra->id_plesso }}">{{ $altra->etichetta() }}</option>
                    @endforeach
                </select>
                <button class="inline-flex justify-center items-center px-4 py-2 bg-amber-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-amber-700">Unisci</button>
            </form>
            <x-input-error :messages="$errors->get('id_destinazione')" class="mt-1" />
        </div>
    @endif
    @push('head')
        <link rel="stylesheet" href="{{ asset('build/vendor/leaflet/leaflet.css') }}?v={{ config('app.version') }}" />
    @endpush
    @push('scripts')
        <script src="{{ asset('build/vendor/leaflet/leaflet.js') }}?v={{ config('app.version') }}"></script>
        <script>
            (function () {
                const fLat = document.getElementById('latitudine');
                const fLng = document.getElementById('longitudine');
                const esito = document.getElementById('trova-esito');
                const mappa = L.map('mappa-sede', { scrollWheelZoom: false }).setView([{{ $cLat }}, {{ $cLng }}], {{ $haPos ? 17 : 13 }});
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                    maxZoom: 19,
                }).addTo(mappa);
                let marker = null;
                const metti = (lat, lng) => {
                    if (!marker) {
                        marker = L.marker([lat, lng], { draggable: true }).addTo(mappa);
                        marker.on('dragend', () => scrivi(marker.getLatLng()));
                    } else {
                        marker.setLatLng([lat, lng]);
                    }
                };
                const scrivi = (p) => { fLat.value = p.lat.toFixed(7); fLng.value = p.lng.toFixed(7); };
                @if($haPos) metti({{ $cLat }}, {{ $cLng }}); @endif
                mappa.on('click', (e) => { metti(e.latlng.lat, e.latlng.lng); scrivi(e.latlng); });

                document.getElementById('trova-indirizzo').addEventListener('click', async () => {
                    esito.textContent = 'Cerco…';
                    try {
                        const r = await fetch(@js(route('admin.sedi.geocodifica', $sede->id_plesso)), {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                                       'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                            body: JSON.stringify({ indirizzo: document.getElementById('indirizzo').value }),
                        });
                        const dati = await r.json();
                        if (!r.ok) { esito.textContent = dati.message || 'Non trovato.'; return; }
                        metti(dati.lat, dati.lng);
                        scrivi({ lat: dati.lat, lng: dati.lng });
                        mappa.setView([dati.lat, dati.lng], 17);
                        esito.textContent = 'Trovato: controlla il segnaposto e salva.';
                    } catch (e) {
                        esito.textContent = 'Ricerca non riuscita, riprova.';
                    }
                });
            })();
        </script>
    @endpush
</x-app-layout>
