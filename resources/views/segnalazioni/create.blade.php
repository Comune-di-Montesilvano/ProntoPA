<x-app-layout>
    <x-slot name="header">Nuova segnalazione</x-slot>
    <x-slot name="actions">
        <a href="{{ url()->previous() }}"
           class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 transition">
            Annulla
        </a>
    </x-slot>

    <div class="space-y-4">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <form method="POST" action="{{ route('segnalazioni.store') }}" class="p-6 space-y-5">
                    @csrf

                    {{-- Tipologia --}}
                    <div>
                        <x-input-label for="id_tipologia_segnalazione" value="Tipologia *" />
                        <select id="id_tipologia_segnalazione"
                                name="id_tipologia_segnalazione"
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm @error('id_tipologia_segnalazione') border-red-500 @enderror"
                                required>
                            <option value="">— Seleziona —</option>
                            @foreach($tipologie->groupBy(fn($t) => $t->gruppo?->descrizione ?? 'Altro') as $gruppo => $items)
                                <optgroup label="{{ $gruppo }}">
                                    @foreach($items as $t)
                                        <option value="{{ $t->id_tipologia_segnalazione }}"
                                            {{ old('id_tipologia_segnalazione') == $t->id_tipologia_segnalazione ? 'selected' : '' }}>
                                            {{ $t->descrizione }}
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('id_tipologia_segnalazione')" class="mt-1" />
                    </div>

                    {{-- Provenienza --}}
                    <div>
                        <x-input-label for="id_provenienza" value="Provenienza *" />
                        <select id="id_provenienza"
                                name="id_provenienza"
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm @error('id_provenienza') border-red-500 @enderror"
                                required>
                            <option value="">— Seleziona —</option>
                            @foreach($provenienze as $p)
                                <option value="{{ $p->id_provenienza }}"
                                    {{ old('id_provenienza') == $p->id_provenienza ? 'selected' : '' }}>
                                    {{ $p->descrizione }}
                                </option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('id_provenienza')" class="mt-1" />
                    </div>

                    {{-- Plesso (opzionale) --}}
                    <div>
                        <x-input-label for="id_plesso" value="Plesso / Sede" />
                        <select id="id_plesso"
                                name="id_plesso"
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                            <option value="">— Nessuno —</option>
                            @foreach($plessi->groupBy(fn($p) => $p->istituto?->nome ?? 'Altro') as $istituto => $items)
                                <optgroup label="{{ $istituto }}">
                                    @foreach($items as $p)
                                        <option value="{{ $p->id_plesso }}"
                                            {{ old('id_plesso') == $p->id_plesso ? 'selected' : '' }}>
                                            {{ $p->nome }}
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('id_plesso')" class="mt-1" />
                    </div>

                    {{-- Testo --}}
                    <div>
                        <x-input-label for="testo_segnalazione" value="Descrizione del problema *" />
                        <textarea id="testo_segnalazione"
                                  name="testo_segnalazione"
                                  rows="5"
                                  maxlength="2000"
                                  class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm @error('testo_segnalazione') border-red-500 @enderror"
                                  required
                                  placeholder="Descrivi il problema in modo dettagliato...">{{ old('testo_segnalazione') }}</textarea>
                        <x-input-error :messages="$errors->get('testo_segnalazione')" class="mt-1" />
                    </div>

                    {{-- Segnalante (opzionale) --}}
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <x-input-label for="segnalante" value="Segnalante" />
                            <x-text-input id="segnalante" name="segnalante" type="text"
                                class="mt-1 block w-full text-sm"
                                :value="old('segnalante')"
                                placeholder="Nome e cognome" />
                            <x-input-error :messages="$errors->get('segnalante')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="email" value="Email" />
                            <x-text-input id="email" name="email" type="email"
                                class="mt-1 block w-full text-sm"
                                :value="old('email')" />
                            <x-input-error :messages="$errors->get('email')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="telefono" value="Telefono" />
                            <x-text-input id="telefono" name="telefono" type="text"
                                class="mt-1 block w-full text-sm"
                                :value="old('telefono')" />
                            <x-input-error :messages="$errors->get('telefono')" class="mt-1" />
                        </div>
                    </div>

                    {{-- Geolocalizzazione --}}
                    <input type="hidden" name="latitudine" id="latitudine" value="{{ old('latitudine', '0') }}">
                    <input type="hidden" name="longitudine" id="longitudine" value="{{ old('longitudine', '0') }}">

                    <div>
                        <x-input-label value="Posizione (opzionale)" />
                        <p class="text-xs text-gray-400 mb-2">Clicca sulla mappa per indicare la posizione del problema.</p>
                        <div id="mappa-inserimento" class="h-52 w-full rounded-lg border border-gray-300 overflow-hidden"></div>
                        <div id="coord-display" class="mt-1 text-xs text-gray-400 hidden">
                            Coordinate: <span id="coord-text"></span>
                            <button type="button" onclick="resetCoords()" class="ml-2 text-red-400 hover:text-red-600">Rimuovi</button>
                        </div>
                    </div>

                    {{-- Actions --}}
                    <div class="flex items-center justify-end gap-3 pt-2 border-t border-gray-100">
                        <a href="{{ url()->previous() }}"
                           class="text-sm text-gray-500 hover:text-gray-700">
                            Annulla
                        </a>
                        <button type="submit"
                                class="inline-flex items-center px-5 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition">
                            Invia segnalazione
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    @push('head')
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    @endpush
    @push('scripts')
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
        <script>
            const defaultLat = {{ \App\Models\Impostazione::get('osm_lat', 42.5098) }};
            const defaultLng = {{ \App\Models\Impostazione::get('osm_lng', 14.1443) }};
            const defaultZoom = {{ \App\Models\Impostazione::get('osm_zoom', 13) }};

            const map = L.map('mappa-inserimento').setView([defaultLat, defaultLng], defaultZoom);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                maxZoom: 19,
            }).addTo(map);

            let marker = null;

            map.on('click', function (e) {
                const lat = e.latlng.lat.toFixed(6);
                const lng = e.latlng.lng.toFixed(6);
                document.getElementById('latitudine').value = lat;
                document.getElementById('longitudine').value = lng;
                document.getElementById('coord-text').textContent = lat + ', ' + lng;
                document.getElementById('coord-display').classList.remove('hidden');
                if (marker) { map.removeLayer(marker); }
                marker = L.marker([lat, lng]).addTo(map);
            });

            function resetCoords() {
                document.getElementById('latitudine').value = '0';
                document.getElementById('longitudine').value = '0';
                document.getElementById('coord-display').classList.add('hidden');
                if (marker) { map.removeLayer(marker); marker = null; }
            }
        </script>
    @endpush
</x-app-layout>

