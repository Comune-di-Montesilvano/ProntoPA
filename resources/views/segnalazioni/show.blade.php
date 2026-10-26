{{-- Scheda segnalazione: una pagina, tre letture scelte dal ruolo (LetturaScheda). --}}
<x-app-layout>
    <x-slot name="header">Segnalazione #{{ $segnalazione->id_segnalazione }}</x-slot>

    @include('segnalazioni.scheda.'.$lettura)

    @if($lettura !== 'segnalante' && $segnalazione->latitudine && $segnalazione->longitudine && (float) $segnalazione->latitudine != 0)
        @push('head')
            <link rel="stylesheet" href="{{ asset('build/vendor/leaflet/leaflet.css') }}?v={{ config('app.version') }}" />
        @endpush
        @push('scripts')
            <script src="{{ asset('build/vendor/leaflet/leaflet.js') }}?v={{ config('app.version') }}"></script>
            <script>
                (function () {
                    const el = document.getElementById('mappa-segnalazione');
                    if (!el) return;
                    const lat = {{ (float) $segnalazione->latitudine }};
                    const lng = {{ (float) $segnalazione->longitudine }};
                    const map = L.map(el, { scrollWheelZoom: false }).setView([lat, lng], 16);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                        maxZoom: 19,
                    }).addTo(map);
                    L.marker([lat, lng]).addTo(map);
                })();
            </script>
        @endpush
    @endif
</x-app-layout>
