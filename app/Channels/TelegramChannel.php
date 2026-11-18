<?php

namespace App\Channels;

use App\Services\TelegramBotService;

class TelegramChannel
{
    public function __construct(
        private readonly TelegramBotService $telegram,
    ) {}

    public function send(object $notifiable, object $notification): void
    {
        if (! method_exists($notification, 'toTelegram')) {
            return;
        }

        $chatId = method_exists($notifiable, 'routeNotificationForTelegram')
            ? $notifiable->routeNotificationForTelegram()
            : null;

        if (! $chatId) {
            return;
        }

        $message = $notification->toTelegram($notifiable);

        if (! is_array($message) || empty($message['text'])) {
            return;
        }

        $this->telegram->sendMessage(
            chatId: (string) $chatId,
            text: $message['text'],
            inlineKeyboard: $message['inline_keyboard'] ?? $this->pulsanteApri($notification),
        );
    }

    /**
     * Avviso su una segnalazione (1.0.15): pulsante per aprirla nel bot, con le azioni.
     *
     * @return list<list<array{text: string, callback_data: string}>>|null
     */
    private function pulsanteApri(object $notification): ?array
    {
        $id = match (true) {
            isset($notification->segnalazione) && $notification->segnalazione instanceof \App\Models\Segnalazione => $notification->segnalazione->id_segnalazione,
            isset($notification->idSegnalazione) => $notification->idSegnalazione,
            default => null,
        };

        return $id ? [[['text' => '📂 Apri #'.$id, 'callback_data' => 'apri:'.$id]]] : null;
    }
}