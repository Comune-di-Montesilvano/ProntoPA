{{-- Scheda segnalazione: una pagina, tre letture scelte dal ruolo (LetturaScheda). --}}
<x-app-layout>
    <x-slot name="header">Segnalazione #{{ $segnalazione->id_segnalazione }}</x-slot>
    @php
        // Indietro: la pagina da cui si arriva (elenco con filtri e pagina), se è di questa app e non è
        // questa stessa scheda (dopo un'azione); altrimenti la dashboard del ruolo.
        $prec = url()->previous();
        $base = rtrim(url('/'), '/');
        $indietroUrl = str_starts_with($prec, $base.'/') && ! str_starts_with($prec, url()->current())
            ? $prec : route('dashboard');
    @endphp
    <x-slot name="indietro">{{ $indietroUrl }}</x-slot>

    @include('segnalazioni.scheda.'.$lettura)

    @php($posizione = $segnalazione->posizione())
    @if($lettura !== 'segnalante' && $posizione)
        @push('head')
            <link rel="stylesheet" href="{{ asset('build/vendor/leaflet/leaflet.css') }}?v={{ config('app.version') }}" />
        @endpush
        @push('scripts')
            <script src="{{ asset('build/vendor/leaflet/leaflet.js') }}?v={{ config('app.version') }}"></script>
            @include('partials.strati-mappa')
            <script>
                (function () {
                    const el = document.getElementById('mappa-segnalazione');
                    if (!el) return;
                    const lat = {{ $posizione['lat'] }};
                    const lng = {{ $posizione['lng'] }};
                    const map = L.map(el, { scrollWheelZoom: false, dragging: false, touchZoom: false, doubleClickZoom: false }).setView([lat, lng], 16);
                    // Ferma finché non ci si clicca: la pagina scorre senza trascinare la mappa.
                    el.addEventListener('click', () => { map.dragging.enable(); map.touchZoom.enable(); map.scrollWheelZoom.enable(); }, { once: true });
                    paStratiMappa(map);
                    L.marker([lat, lng]).addTo(map);
                })();
            </script>
        @endpush
    @endif
</x-app-layout>
