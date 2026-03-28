<?php

namespace App\Services;

use App\Models\ApiLog;
use App\Models\Impostazione;
use App\Models\Segnalazione;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebhookService
{
    /**
     * Notifica il sito esterno del Comune al cambio di stato di una segnalazione.
     * Firma il payload con HMAC-SHA256 (header X-Signature).
     * No-op silenzioso se webhook non configurato.
     */
    public function notificaCambioStato(Segnalazione $segnalazione): void
    {
        $url    = Impostazione::get('webhook_cittadini_url') ?: env('WEBHOOK_CITTADINI_URL');
        $secret = Impostazione::get('webhook_cittadini_secret') ?: env('WEBHOOK_CITTADINI_SECRET');

        if (empty($url)) {
            return;
        }

        $payload = [
            'evento'          => 'stato_cambiato',
            'id_segnalazione' => $segnalazione->id_segnalazione,
            'stato'           => [
                'id'          => $segnalazione->stato?->id_stato,
                'descrizione' => $segnalazione->stato?->descrizione,
            ],
            'data_aggiornamento' => now()->toIso8601String(),
        ];

        $body      = json_encode($payload);
        $signature = 'sha256=' . hash_hmac('sha256', $body, (string) $secret);

        try {
            $response = Http::withHeaders([
                'Content-Type'  => 'application/json',
                'X-Signature'   => $signature,
                'User-Agent'    => 'ProntoPA/' . config('app.version', 'dev'),
            ])
            ->timeout(10)
            ->post($url, $payload);

            ApiLog::logOutbound(
                endpoint: $url,
                payload: $payload,
                status: $response->status(),
                response: $response->json() ?? ['body' => $response->body()],
                segnalazioneId: $segnalazione->id_segnalazione,
            );
        } catch (\Throwable $e) {
            Log::warning('Webhook outbound fallito', [
                'url'   => $url,
                'error' => $e->getMessage(),
                'segnalazione_id' => $segnalazione->id_segnalazione,
            ]);

            ApiLog::logOutbound(
                endpoint: $url,
                payload: $payload,
                status: 0,
                response: ['error' => $e->getMessage()],
                segnalazioneId: $segnalazione->id_segnalazione,
            );
        }
    }
}
