<?php

namespace App\Console\Commands;

use App\Models\Impostazione;
use App\Services\TelegramBotService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class TelegramSetWebhook extends Command
{
    protected $signature = 'telegram:set-webhook {--url=} {--secret=}';

    protected $description = 'Registra il webhook Telegram per il bot configurato';

    public function handle(TelegramBotService $telegram): int
    {
        if (! $telegram->isConfigured()) {
            $this->error('Configura prima telegram_bot_token nelle impostazioni.');
            return self::FAILURE;
        }

        $url = rtrim($this->option('url') ?: config('app.url'), '/') . '/api/telegram/webhook';
        $secret = $this->option('secret') ?: Impostazione::get('telegram_webhook_secret');

        if (! $secret) {
            $secret = Str::random(32);
            Impostazione::set('telegram_webhook_secret', $secret);
        }

        if ($telegram->setWebhook($url, $secret)) {
            $this->info('Webhook Telegram registrato: ' . $url);
            return self::SUCCESS;
        }

        $this->error('Registrazione webhook Telegram fallita.');
        return self::FAILURE;
    }
}