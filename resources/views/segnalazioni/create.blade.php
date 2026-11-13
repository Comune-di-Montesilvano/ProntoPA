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
            <form method="POST" action="{{ route('segnalazioni.store') }}" class="p-6 space-y-6"
                  enctype="multipart/form-data"
                  x-data="{ tipologiaId: {{ old('id_tipologia_segnalazione', 'null') }}, simili: [], ignora: false }"
                  x-init="$watch('tipologiaId', () => { ignora = false; controllaSimili($data) })"
                  x-on:change.capture="if ($event.target.name === 'id_plesso') { ignora = false; controllaSimili($data) }">
                @csrf

                {{-- Tipologia — griglia icone --}}
                <div>
                    <x-input-label value="Tipologia *" />
                    <input type="hidden" name="id_tipologia_segnalazione"
                           :value="tipologiaId"
                           x-ref="tipologiaInput">
                    @error('id_tipologia_segnalazione')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror

                    @foreach($tipologie->groupBy(fn($t) => $t->gruppo?->descrizione ?? 'Altro') as $gruppo => $items)
                        <p class="mt-4 mb-2 text-xs font-semibold text-gray-400 uppercase tracking-widest">{{ $gruppo }}</p>
                        <div class="grid grid-cols-3 sm:grid-cols-5 md:grid-cols-6 gap-2">
                            @foreach($items as $t)
                                <button type="button"
                                        @click="tipologiaId = {{ $t->id_tipologia_segnalazione }}"
                                        :class="tipologiaId == {{ $t->id_tipologia_segnalazione }}
                                            ? 'border-blue-500 bg-blue-50 text-blue-700'
                                            : 'border-gray-200 bg-white text-gray-500 hover:border-blue-300 hover:text-blue-600'"
                                        class="flex flex-col items-center gap-1.5 px-2 py-3 border-2 rounded-lg transition cursor-pointer select-none">
                                    <i class="{{ $t->icona ?? 'fas fa-circle' }} text-lg"></i>
                                    <span class="text-xs font-semibold uppercase text-center leading-tight">{{ $t->descrizione }}</span>
                                </button>
                            @endforeach
                        </div>
                    @endforeach
                </div>

                @php $scuola = auth()->user()->segnalaComeScuola(); @endphp

                {{-- Plesso --}}
                <div>
                    <x-input-label for="id_plesso" :value="$scuola ? 'Plesso / Sede *' : 'Plesso / Sede'" />
                    <select id="id_plesso" name="id_plesso" @required($scuola)
                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 @error('id_plesso') border-red-500 @enderror">
                        <option value="">{{ $scuola ? '— Seleziona —' : '— Nessuno —' }}</option>
                        @foreach($plessi->groupBy(fn($p) => $p->istituto?->categoria?->nome ?? 'Altro') as $categoria => $items)
                            <optgroup label="{{ $categoria }}">
                                @foreach($items as $p)
                                    <option value="{{ $p->id_plesso }}"
                                        {{ old('id_plesso') == $p->id_plesso ? 'selected' : '' }}>
                                        {{ $p->istituto ? $p->istituto->descrizione.' — ' : '' }}{{ $p->etichetta() }}
                                    </option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('id_plesso')" class="mt-1" />
                </div>

                {{-- Priorità e tempo previsto (indicativo: la priorità la decide l'ufficio) --}}
                <div x-data="{ priorita: {{ (int) old('livello_priorita', 2) }}, tempi: @js($tempiPrevisti) }">
                    <x-input-label value="Priorità" />
                    <input type="hidden" name="livello_priorita" :value="priorita">
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach([1 => 'Bassa', 2 => 'Media', 3 => 'Alta', 4 => 'Critica'] as $val => $label)
                            <button type="button"
                                    @click="priorita = {{ $val }}"
                                    :class="priorita == {{ $val }}
                                        ? '{{ match($val) { 1 => 'bg-gray-200 text-gray-700 border-gray-400', 2 => 'bg-blue-100 text-blue-700 border-blue-400', 3 => 'bg-orange-100 text-orange-700 border-orange-400', 4 => 'bg-red-100 text-red-700 border-red-500' } }}'
                                        : 'bg-white text-gray-500 border-gray-200 hover:border-gray-400'"
                                    class="px-4 py-2 border-2 rounded-lg text-sm font-semibold transition select-none">
                                {{ $label }}
                                @if($val === 4) <span class="ml-1 text-red-500">!</span>@endif
                            </button>
                        @endforeach
                    </div>
                    <template x-if="tempi[tipologiaId]?.[priorita] ?? tempi[0]?.[priorita]">
                        <p class="mt-3 text-sm text-gray-700">
                            Intervento previsto entro <strong x-text="tempi[tipologiaId]?.[priorita] ?? tempi[0]?.[priorita]"></strong>.
                        </p>
                    </template>
                    <p class="mt-1 text-xs text-gray-500">La priorità definitiva la stabilisce l'ufficio.</p>
                    <p class="mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                        Per pericoli immediati (incendio, fuga di gas, crolli) chiama il 112: il comune interviene in orario d'ufficio.
                    </p>
                    <x-input-error :messages="$errors->get('livello_priorita')" class="mt-1" />
                </div>

                {{-- Titolo --}}
                <div>
                    <x-input-label for="titolo" value="Titolo *" />
                    <x-text-input id="titolo" name="titolo" type="text" maxlength="100" required
                                  class="mt-1 block w-full @error('titolo') border-red-500 @enderror"
                                  :value="old('titolo')" placeholder="In breve, es. Fornitura 15 panchine plesso Colonnetta" />
                    <p class="mt-1 text-xs text-gray-500">Massimo 100 caratteri.</p>
                    <x-input-error :messages="$errors->get('titolo')" class="mt-1" />
                </div>

                {{-- Testo --}}
                <div>
                    <x-input-label for="testo_segnalazione" value="Descrizione del problema *" />
                    <textarea id="testo_segnalazione" name="testo_segnalazione"
                              rows="5" maxlength="2000"
                              class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 @error('testo_segnalazione') border-red-500 @enderror"
                              required
                              placeholder="Descrivi il problema in modo dettagliato...">{{ old('testo_segnalazione') }}</textarea>
                    <x-input-error :messages="$errors->get('testo_segnalazione')" class="mt-1" />
                </div>

                {{-- Più dettagli (progressive disclosure) --}}
                {{-- Per le scuole i dettagli sono obbligatori e sempre visibili --}}
                <div x-data="{ dettagli: {{ $scuola || $errors->any() ? 'true' : 'false' }} }" class="mt-6">
                    @unless($scuola)
                    <button type="button" @click="dettagli = !dettagli"
                            class="flex items-center gap-2 text-sm font-medium text-blue-700">
                        <span x-text="dettagli ? '▾' : '▸'"></span> Più dettagli
                    </button>
                    @endunless
                    <div x-show="dettagli" class="{{ $scuola ? '' : 'mt-4 ' }}space-y-6">


                        {{-- Ubicazione --}}
                        <div>
                            <x-input-label :value="$scuola ? 'Dove si trova il problema *' : 'Dove si trova il problema'" />
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach(($scuola ? [] : [0 => 'Non specificato']) + [1 => 'Interno edificio', 2 => 'Esterno', 3 => 'Impianto', 4 => 'Area verde'] as $val => $label)
                                    <label class="inline-flex items-center gap-1.5 cursor-pointer">
                                        <input type="radio" name="ubicazione_tipo" value="{{ $val }}" @required($scuola)
                                               {{ (string) old('ubicazione_tipo', $scuola ? '' : '0') === (string) $val ? 'checked' : '' }}
                                               class="text-blue-600 focus:ring-blue-500">
                                        <span class="text-sm text-gray-700">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <x-input-error :messages="$errors->get('ubicazione_tipo')" class="mt-1" />
                        </div>

                        {{-- Allegati --}}
                        @php
                            $allegatiMaxSizeMb      = (int) \App\Models\Impostazione::get('allegati_max_size_mb', 10);
                            $allegatiMaxPerRequest  = (int) \App\Models\Impostazione::get('allegati_max_per_request', 5);
                            $allegatiMime           = \App\Models\Impostazione::get('allegati_mime_consentiti', 'image/jpeg,image/png');
                        @endphp
                        <div>
                            <x-input-label for="allegati_create" value="Allegati (foto / video — opzionale)" />
                            <p class="text-xs text-gray-400 mb-2">
                                Max {{ $allegatiMaxPerRequest }} file, {{ $allegatiMaxSizeMb }}&nbsp;MB ciascuno.
                                Formati accettati: foto e video.
                            </p>
                            <input id="allegati_create" type="file" name="allegati[]" multiple
                                   accept="{{ $allegatiMime }}"
                                   capture="environment"
                                   class="block w-full text-sm text-gray-500
                                          file:mr-4 file:py-2 file:px-4
                                          file:rounded-md file:border-0
                                          file:text-sm file:font-semibold
                                          file:bg-blue-50 file:text-blue-700
                                          hover:file:bg-blue-100" />
                            @error('allegati')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                            @error('allegati.*')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                    </div>{{-- /x-show dettagli --}}
                </div>{{-- /x-data dettagli --}}

                {{-- Geolocalizzazione (outside collapse — Leaflet must init on visible DOM) --}}
                <input type="hidden" name="latitudine" id="latitudine" value="{{ old('latitudine', '0') }}">
                <input type="hidden" name="longitudine" id="longitudine" value="{{ old('longitudine', '0') }}">

                {{-- Scuole: la posizione è il plesso, niente mappa --}}
                <div @class(['hidden' => $scuola])>
                    <x-input-label value="Posizione (opzionale)" />
                    <p class="text-xs text-gray-400 mb-2">Clicca sulla mappa per indicare la posizione del problema.</p>
                    <div id="mappa-inserimento" class="h-52 w-full rounded-lg border border-gray-300 overflow-hidden"></div>
                    <div id="coord-display" class="mt-1 text-xs text-gray-400 hidden">
                        Coordinate: <span id="coord-text"></span>
                        <button type="button" onclick="resetCoords()" class="ml-2 text-red-400 hover:text-red-600">Rimuovi</button>
                    </div>
                </div>

                {{-- Banner segnalazioni simili --}}
                <template x-if="simili.length > 0 && !ignora">
                    <div class="mb-4 rounded-lg border border-yellow-400 bg-yellow-50 p-4">
                        <p class="font-semibold text-yellow-800">
                            ⚠ <span x-text="simili.length"></span> segnalazioni simili già aperte
                        </p>
                        <ul class="mt-2 space-y-2">
                            <template x-for="s in simili" :key="s.id">
                                <li class="flex items-center justify-between gap-3 text-sm">
                                    <span>
                                        <strong x-text="'#' + s.id"></strong>
                                        <template x-if="s.testo"><span x-text="' ' + s.testo"></span></template>
                                        <em class="text-gray-500" x-text="' (' + s.stato + ', ' + s.data + ')'"></em>
                                        <template x-if="s.adesioni > 0"><span class="text-purple-700" x-text="' +' + s.adesioni + ' adesioni'"></span></template>
                                    </span>
                                    <button type="button"
                                            @click="aderisciSimile(s.id)"
                                            class="shrink-0 rounded bg-yellow-600 px-3 py-1 text-xs font-medium text-white hover:bg-yellow-700">
                                        È la stessa cosa → aggiungiti
                                    </button>
                                </li>
                            </template>
                        </ul>
                        <button type="button" @click="ignora = true"
                                class="mt-3 text-xs text-yellow-700 underline">
                            È un problema diverso, continua con l'invio
                        </button>
                    </div>
                </template>

                {{-- Actions --}}
                <div class="flex items-center justify-end gap-3 pt-2 border-t border-gray-100">
                    <a href="{{ url()->previous() }}" class="text-sm text-gray-500 hover:text-gray-700">Annulla</a>
                    <button type="submit" dusk="submit-segnalazione"
                            class="inline-flex items-center px-5 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-sm text-white uppercase tracking-widest hover:bg-blue-700 transition">
                        Invia segnalazione
                    </button>
                </div>
            </form>
        </div>
    </div>

    @push('head')
        <link rel="stylesheet" href="{{ asset('build/vendor/leaflet/leaflet.css') }}?v={{ config('app.version') }}" />
    @endpush
    @push('scripts')
        <script>
        async function controllaSimili(data) {
            const tip = data.tipologiaId;
            if (!tip) { data.simili = []; return; }
            const params = new URLSearchParams({ id_tipologia_segnalazione: tip });
            const ple = document.querySelector('[name=id_plesso]')?.value;
            const lat = document.getElementById('latitudine')?.value;
            const lng = document.getElementById('longitudine')?.value;
            const txt = document.querySelector('[name=testo_segnalazione]')?.value;
            if (ple) params.set('id_plesso', ple);
            if (lat && lng && lat !== '0') { params.set('latitudine', lat); params.set('longitudine', lng); }
            if (txt && txt.length > 10) params.set('testo', txt.substring(0, 500));
            try {
                const res = await fetch('{{ route('segnalazioni.simili') }}?' + params, {
                    headers: { 'Accept': 'application/json' },
                });
                data.simili = res.ok ? await res.json() : [];
            } catch (e) {
                data.simili = [];
            }
        }

        let _aderisciSubmitting = false;
        function aderisciSimile(id) {
            if (_aderisciSubmitting) return;
            _aderisciSubmitting = true;
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '{{ url('segnalazioni') }}/' + id + '/adesioni';
            const csrf = document.createElement('input');
            csrf.type = 'hidden'; csrf.name = '_token'; csrf.value = '{{ csrf_token() }}';
            form.appendChild(csrf);
            document.body.appendChild(form);
            form.submit();
        }
        </script>
        <script src="{{ asset('build/vendor/leaflet/leaflet.js') }}?v={{ config('app.version') }}"></script>
        <script>
            const defaultLat  = {{ \App\Models\Impostazione::get('osm_lat', 42.5098) }};
            const defaultLng  = {{ \App\Models\Impostazione::get('osm_lng', 14.1443) }};
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
