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
            inlineKeyboard: $message['inline_keyboard'] ?? null,
        );
    }
}