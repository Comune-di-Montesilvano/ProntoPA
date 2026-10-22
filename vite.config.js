import { cpSync } from 'node:fs';
import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';

// Librerie usate da script inline nelle view (variabili globali L e Chart, codice
// sincrono subito dopo il <script src>): build UMD copiate da node_modules e servite
// da ProntoPA, niente CDN. Versioni fissate da package-lock, aggiornate da Dependabot.
const copiaVendor = () => {
    cpSync('node_modules/leaflet/dist', 'public/build/vendor/leaflet', { recursive: true });
    cpSync('node_modules/chart.js/dist/chart.umd.min.js', 'public/build/vendor/chart.umd.min.js');
};

export default defineConfig({
    plugins: [
        tailwindcss(),
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        {
            name: 'vendor-umd',
            // build: dopo lo svuotamento di public/build; dev: all'avvio del server
            closeBundle: copiaVendor,
            configureServer: copiaVendor,
        },
    ],
});
