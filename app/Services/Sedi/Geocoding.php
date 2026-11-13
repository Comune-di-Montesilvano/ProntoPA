<?php

namespace App\Services\Sedi;

use App\Models\Impostazione;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Indirizzo → coordinate via Nominatim (OpenStreetMap). Solo dal server, mai dal browser,
 * User-Agent dell'app senza dati personali; chi chiama rispetta 1 richiesta al secondo.
 */
class Geocoding
{
    public const URL = 'https://nominatim.openstreetmap.org/search';

    public const TROVATA = 'trovata';

    public const NON_TROVATA = 'non_trovata';

    public const ERRORE = 'errore';

    /** @return array{lat: float, lng: float}|null */
    public function cerca(string $indirizzo): ?array
    {
        $esito = $this->esegui($indirizzo);

        return $esito['stato'] === self::TROVATA ? ['lat' => $esito['lat'], 'lng' => $esito['lng']] : null;
    }

    /** @return array{stato: string, lat: ?float, lng: ?float} */
    public function esegui(string $indirizzo): array
    {
        $comune = trim((string) Impostazione::get('geocoding_comune', ''));
        $q = trim($indirizzo);
        if ($q === '') {
            return ['stato' => self::NON_TROVATA, 'lat' => null, 'lng' => null];
        }
        if ($comune !== '' && stripos($q, $comune) === false) {
            $q .= ', '.$comune;
        }

        try {
            $risposta = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'ProntoPA (+'.config('app.url').')', 'Accept-Language' => 'it'])
                ->get(self::URL, ['q' => $q, 'format' => 'json', 'limit' => 1, 'countrycodes' => 'it']);
        } catch (Throwable $e) {
            Log::warning('Geocoding non raggiungibile', ['errore' => $e->getMessage()]);

            return ['stato' => self::ERRORE, 'lat' => null, 'lng' => null];
        }

        if (! $risposta->successful()) {
            return ['stato' => self::ERRORE, 'lat' => null, 'lng' => null];
        }

        $primo = $risposta->json('0');
        if (! is_array($primo) || ! is_numeric($primo['lat'] ?? null) || ! is_numeric($primo['lon'] ?? null)) {
            return ['stato' => self::NON_TROVATA, 'lat' => null, 'lng' => null];
        }

        return ['stato' => self::TROVATA, 'lat' => (float) $primo['lat'], 'lng' => (float) $primo['lon']];
    }
}
