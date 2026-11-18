<?php

namespace App\Services;

use App\Models\Impostazione;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TelegramBotService
{
    /** Menu dei comandi del bot (setMyCommands): gli stessi gestiti da TelegramWebhookController. */
    public const COMANDI = [
        'lista' => 'Segnalazioni aperte, le più recenti',
        'oggi' => 'I lavori assegnati a te',
        'priorita' => 'Segnalazioni più urgenti',
        'scadenze' => 'Scadute o in scadenza entro 24 ore',
        'apri' => 'Dettaglio di una segnalazione: /apri 1234',
        'chiudi' => 'Chiude una segnalazione: /chiudi 1234',
        'note' => 'Note di una segnalazione, o aggiungine una: /note 1234 testo',
        'aiuto' => 'Cosa fa ogni tasto',
    ];

    public function isConfigured(): bool
    {
        return filled($this->token());
    }

    /** Tastiera fissa sotto la chat (1.0.11): il testo di ogni tasto vale come il comando corrispondente. */
    public const TASTIERA = [
        ['📋 Lista', '📅 Oggi', '🔥 Priorità'],
        ['⏰ Scadenze', '🔍 Cerca n°', '❓ Aiuto'],
    ];

    /**
     * @param  array<int, array<int, array<string, mixed>>>|null  $inlineKeyboard  pulsanti sotto il messaggio
     * @param  bool  $tastiera  mostra (o riporta) la tastiera fissa TASTIERA; ignorato se ci sono pulsanti inline
     */
    public function sendMessage(string $chatId, string $text, ?array $inlineKeyboard = null, bool $tastiera = false): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
        ];

        if ($inlineKeyboard) {
            $payload['reply_markup'] = [
                'inline_keyboard' => $inlineKeyboard,
            ];
        } elseif ($tastiera) {
            $payload['reply_markup'] = [
                'keyboard' => array_map(fn ($riga) => array_map(fn ($testo) => ['text' => $testo], $riga), self::TASTIERA),
                'resize_keyboard' => true,
                'is_persistent' => true,
            ];
        }

        return $this->post('sendMessage', $payload);
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text): bool
    {
        if (! $this->isConfigured() || $callbackQueryId === '') {
            return false;
        }

        return $this->post('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
        ]);
    }

    /** Indirizzo a cui Telegram deve consegnare i messaggi del bot. */
    public function urlWebhook(): string
    {
        return rtrim((string) config('app.url'), '/') . '/api/telegram/webhook';
    }

    /**
     * Collega il bot a questo ProntoPA (setWebhook su urlWebhook()). Senza segreto ne genera uno:
     * senza, il webhook accetterebbe chiamate di chiunque.
     */
    public function registraWebhook(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $secret = (string) Impostazione::get('telegram_webhook_secret');
        if ($secret === '') {
            $secret = Str::random(32);
            Impostazione::set('telegram_webhook_secret', $secret);
        }

        return $this->setWebhook($this->urlWebhook(), $secret);
    }

    /** Imposta il menu dei comandi del bot (prima andava fatto a mano da BotFather con /setcommands). */
    public function impostaComandi(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $comandi = [];
        foreach (self::COMANDI as $comando => $descrizione) {
            $comandi[] = ['command' => $comando, 'description' => $descrizione];
        }

        return $this->post('setMyCommands', ['commands' => $comandi]);
    }

    /**
     * Nome e descrizioni del bot dai dati dell'ente (1.0.11; prima a mano da BotFather).
     * Limiti di Telegram: nome 64, descrizione breve 120, descrizione 512 caratteri.
     */
    public function impostaProfilo(string $ente): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $ente = trim($ente) !== '' ? trim($ente) : 'ProntoPA';
        $nome = $ente === 'ProntoPA' ? 'ProntoPA' : 'ProntoPA · '.$ente;
        $breve = "Segnalazioni e lavori di manutenzione di {$ente}: avvisi e comandi rapidi per comune, operai e ditte.";
        $descrizione = "ProntoPA, {$ente}.\n\n"
            ."Avvisi sui lavori assegnati e sulle scadenze, e le segnalazioni a portata di mano: lista, lavori di oggi, priorità, note e foto.\n\n"
            .'Per collegarti: in ProntoPA apri Profilo → Telegram → «Collega Telegram», poi premi Avvia qui. '
            .'Riservato a personale del comune, operai e ditte.';

        $ok = $this->post('setMyName', ['name' => Str::limit($nome, 64, '')]);
        $ok = $this->post('setMyShortDescription', ['short_description' => Str::limit($breve, 120, '')]) && $ok;

        return $this->post('setMyDescription', ['description' => Str::limit($descrizione, 512, '')]) && $ok;
    }

    /** Foto del profilo del bot (JPG). Telegram non riusa le foto: ogni chiamata ne aggiunge una, chiamare solo se cambia. */
    public function impostaFoto(string $jpeg): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $response = Http::timeout(20)
                ->attach('foto', $jpeg, 'foto.jpg', ['Content-Type' => 'image/jpeg'])
                ->post($this->apiBaseUrl().'/setMyProfilePhoto', [
                    'photo' => json_encode(['type' => 'static', 'photo' => 'attach://foto']),
                ]);

            return $response->successful() && (bool) $response->json('ok', false);
        } catch (\Throwable $exception) {
            Log::warning('Telegram setMyProfilePhoto failed', ['error' => $this->senzaToken($exception->getMessage())]);

            return false;
        }
    }

    /**
     * Stato del collegamento visto da Telegram (getWebhookInfo): url e ultimo errore di consegna.
     *
     * @return array{url: string, errore: ?string}|null null se Telegram non risponde
     */
    public function infoWebhook(): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::timeout(3)->get($this->apiBaseUrl() . '/getWebhookInfo');
            if ($response->successful() && (bool) $response->json('ok', false)) {
                $errore = $response->json('result.last_error_message');

                return [
                    'url' => (string) $response->json('result.url', ''),
                    'errore' => is_string($errore) && $errore !== '' ? $errore : null,
                ];
            }
        } catch (\Throwable $exception) {
            Log::warning('Telegram getWebhookInfo failed', ['error' => $this->senzaToken($exception->getMessage())]);
        }

        return null;
    }

    public function setWebhook(string $url, ?string $secret = null): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $payload = ['url' => $url];

        if ($secret) {
            $payload['secret_token'] = $secret;
        }

        return $this->post('setWebhook', $payload);
    }

    private function post(string $method, array $payload): bool
    {
        try {
            $response = Http::asJson()
                ->timeout(10)
                ->post($this->apiBaseUrl() . '/' . $method, $payload);

            return $response->successful() && (bool) $response->json('ok', false);
        } catch (\Throwable $exception) {
            Log::warning('Telegram API call failed', [
                'method' => $method,
                'error' => $this->senzaToken($exception->getMessage()),
            ]);

            return false;
        }
    }

    /** Username del bot se il token è valido (getMe), altrimenti null. Timeout 2 s, mai eccezioni. */
    public function botVerificato(): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::timeout(2)->get($this->apiBaseUrl() . '/getMe');
            if ($response->successful() && (bool) $response->json('ok', false)) {
                $username = $response->json('result.username');

                return is_string($username) && $username !== '' ? $username : null;
            }
        } catch (\Throwable $exception) {
            Log::warning('Telegram getMe failed', ['error' => $this->senzaToken($exception->getMessage())]);
        }

        return null;
    }

    public function getFilePath(string $fileId): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::get($this->apiBaseUrl() . '/getFile', ['file_id' => $fileId]);
            if ($response->successful() && (bool) $response->json('ok', false)) {
                return $response->json('result.file_path');
            }
        } catch (\Throwable $exception) {
            Log::warning('Telegram getFile failed', ['error' => $this->senzaToken($exception->getMessage())]);
        }

        return null;
    }

    public function downloadFile(string $filePath): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::timeout(20)->get('https://api.telegram.org/file/bot' . $this->token() . '/' . $filePath);
            if ($response->successful()) {
                return $response->body();
            }
        } catch (\Throwable $exception) {
            Log::warning('Telegram download file failed', ['error' => $this->senzaToken($exception->getMessage())]);
        }

        return null;
    }

    /** Il token sta nell'URL: i messaggi di errore HTTP lo riportano, quindi mai nei log. */
    private function senzaToken(string $messaggio): string
    {
        $token = (string) $this->token();

        return $token === '' ? $messaggio : str_replace($token, '***', $messaggio);
    }

    private function apiBaseUrl(): string
    {
        return 'https://api.telegram.org/bot' . $this->token();
    }

    private function token(): ?string
    {
        return Impostazione::get('telegram_bot_token');
    }
}