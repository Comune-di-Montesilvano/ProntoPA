<x-app-layout>
    <x-slot name="header">Aspetto</x-slot>

    @php
        $lat = (float) (old('impostazioni.osm_lat', \App\Models\Impostazione::get('osm_lat')) ?: 42.5098);
        $lng = (float) (old('impostazioni.osm_lng', \App\Models\Impostazione::get('osm_lng')) ?: 14.1443);
        $zoom = (int) (old('impostazioni.osm_zoom', \App\Models\Impostazione::get('osm_zoom')) ?: 13);
        $primario = old('impostazioni.ente_colore_primario', \App\Models\Impostazione::get('ente_colore_primario')) ?: '#1D4ED8';
        $secondario = old('impostazioni.ente_colore_secondario', \App\Models\Impostazione::get('ente_colore_secondario')) ?: '#1E40AF';
    @endphp

    <link rel="stylesheet" href="{{ asset('build/vendor/leaflet/leaflet.css') }}?v={{ config('app.version') }}" />

    <form method="POST" action="{{ route('admin.aspetto.update') }}" enctype="multipart/form-data"
          class="bg-white shadow-sm rounded-xl p-5 space-y-5 max-w-3xl">
        @csrf @method('PATCH')

        <h2 class="text-sm font-semibold text-gray-800">Il tuo ente</h2>
        <x-pa.impostazione chiave="ente_nome" etichetta="Nome dell'ente" />
        <x-pa.impostazione chiave="ente_sito_url" tipo="url" etichetta="Sito web dell'ente" />

        <div class="rounded-md border border-gray-200 bg-gray-50 p-3 space-y-2">
            <x-pa.impostazione chiave="ente_logo_url" tipo="url" etichetta="Logo (indirizzo di un'immagine)"
                aiuto="Oppure carica un file qui sotto: ha la precedenza sull'indirizzo." />
            <label for="ente_logo_upload" class="block text-sm font-medium text-gray-700">
                Carica il file del logo (PNG, JPG o WebP, max 1 MB)
            </label>
            @if($haLogoFile)
                <img src="{{ $logoUrl }}" alt="Logo caricato" class="h-12 object-contain rounded border border-gray-200 bg-white p-1">
                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="rimuovi_logo_file" value="1"> Rimuovi il file caricato
                </label>
            @elseif($logoUrl)
                <img src="{{ $logoUrl }}" alt="Logo attuale" class="h-12 object-contain rounded border border-gray-200 bg-white p-1">
            @endif
            <input type="file" id="ente_logo_upload" name="ente_logo_upload" accept="image/png,image/jpeg,image/webp" class="block w-full text-sm">
            @error('ente_logo_upload')
                <p class="text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <h2 class="text-sm font-semibold text-gray-800 pt-2">Colori</h2>
        <div class="flex flex-wrap items-start gap-6" x-data="{ p: @js($primario), s: @js($secondario) }">
            <div @input="p = $event.target.value"><x-pa.impostazione chiave="ente_colore_primario" tipo="colore" etichetta="Colore principale" /></div>
            <div @input="s = $event.target.value"><x-pa.impostazione chiave="ente_colore_secondario" tipo="colore" etichetta="Colore di accento" /></div>
            <div class="flex flex-col gap-1">
                <span class="text-sm font-medium text-gray-800">Anteprima</span>
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center px-3 py-2 rounded-md text-xs font-semibold text-white" :style="'background:' + p">Pulsante</span>
                    <span class="inline-flex items-center px-3 py-2 rounded-md text-xs font-semibold text-white" :style="'background:' + s">Accento</span>
                </div>
            </div>
        </div>

        <h2 class="text-sm font-semibold text-gray-800 pt-2">Email in uscita</h2>
        <x-pa.impostazione chiave="mail_from_name" etichetta="Nome del mittente" />
        <x-pa.impostazione chiave="mail_from_address" tipo="email" etichetta="Indirizzo del mittente" />

        <h2 class="text-sm font-semibold text-gray-800 pt-2">Mappa iniziale</h2>
        <p class="text-xs text-gray-500">Sposta il segnaposto sul centro del tuo comune e scegli quanto ingrandire: è la vista di partenza delle mappe.</p>
        <div id="mappa-aspetto" class="h-72 rounded-md border border-gray-200 z-0"></div>
        {{-- Campi visibili anche senza JavaScript: la mappa li aggiorna. --}}
        <div id="coordinate-mappa" class="grid grid-cols-3 gap-3 max-w-md">
            <x-pa.impostazione chiave="osm_lat" tipo="decimale" etichetta="Latitudine" />
            <x-pa.impostazione chiave="osm_lng" tipo="decimale" etichetta="Longitudine" />
            <x-pa.impostazione chiave="osm_zoom" tipo="intero" etichetta="Ingrandimento" />
        </div>
        <div class="max-w-md">
            <x-pa.impostazione chiave="geocoding_comune" etichetta="Comune delle sedi" />
            <p class="text-xs text-gray-500 mt-1">Aggiunto agli indirizzi delle sedi quando se ne cerca la posizione (es. "Montesilvano" o "65015 Montesilvano PE").</p>
        </div>

        <button type="submit" class="inline-flex items-center px-4 py-2 bg-blue-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition">Salva</button>
    </form>

    <script src="{{ asset('build/vendor/leaflet/leaflet.js') }}?v={{ config('app.version') }}"></script>
    <script>
        (function () {
            const [fLat, fLng, fZoom] = document.querySelectorAll('#coordinate-mappa input');
            const mappa = L.map('mappa-aspetto').setView([{{ $lat }}, {{ $lng }}], {{ $zoom }});
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                maxZoom: 19,
            }).addTo(mappa);
            const marker = L.marker([{{ $lat }}, {{ $lng }}], { draggable: true }).addTo(mappa);

            const scrivi = () => {
                const p = marker.getLatLng();
                fLat.value = p.lat.toFixed(6);
                fLng.value = p.lng.toFixed(6);
                fZoom.value = mappa.getZoom();
            };
            marker.on('dragend', scrivi);
            mappa.on('zoomend', () => { fZoom.value = mappa.getZoom(); });
            mappa.on('click', (e) => { marker.setLatLng(e.latlng); scrivi(); });
        })();
    </script>
</x-app-layout>
