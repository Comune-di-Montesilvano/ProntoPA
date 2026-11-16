{{-- Sfondo delle mappe Leaflet: Strade (OpenStreetMap) o Satellite (Esri World Imagery), con selettore.
     Va incluso subito dopo leaflet.js: gli script inline delle pagine lo chiamano prima che giri app.js.
     La scelta resta nel browser di chi guarda (localStorage, facoltativo). --}}
@once
<script>
    window.paStratiMappa = function (mappa) {
        const strati = {
            'Strade': L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                maxZoom: 19,
            }),
            'Satellite': L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
                attribution: 'Immagini © Esri, Maxar, Earthstar Geographics',
                maxZoom: 19,
                maxNativeZoom: 18,
            }),
        };
        let scelta = 'Strade';
        try { if (localStorage.getItem('pa-mappa-sfondo') === 'Satellite') scelta = 'Satellite'; } catch (e) {}
        strati[scelta].addTo(mappa);
        L.control.layers(strati, null, { position: 'topright' }).addTo(mappa);
        mappa.on('baselayerchange', function (e) {
            try { localStorage.setItem('pa-mappa-sfondo', e.name); } catch (err) {}
        });
        return mappa;
    };
</script>
@endonce
