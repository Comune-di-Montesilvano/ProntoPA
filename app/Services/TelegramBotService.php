<?php

namespace App\Services;

use App\Models\Impostazione;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramBotService
{
    public function isConfigured(): bool
    {
        return filled($this->token());
    }

    public function sendMessage(string $chatId, string $text, ?array $inlineKeyboard = null): bool
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
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
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