<?php

namespace Tests\Unit;

use App\Models\Impostazione;
use App\Services\TelegramBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** Il token del bot sta nell'URL: i messaggi d'errore HTTP non devono finire nei log con il token. */
class TelegramTokenNeiLogTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456:SEGRETO-DEL-BOT';

    protected function setUp(): void
    {
        parent::setUp();
        Impostazione::set('telegram_bot_token', self::TOKEN);
        Http::fake(fn () => throw new ConnectionException('cURL error 28 for https://api.telegram.org/bot'.self::TOKEN.'/getMe'));
    }

    private function loggaSenzaToken(callable $chiamata): void
    {
        Log::spy();
        $chiamata(app(TelegramBotService::class));

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $msg, array $ctx) {
            $this->assertStringNotContainsString(self::TOKEN, json_encode($ctx));
            $this->assertStringContainsString('bot***', $ctx['error']);

            return true;
        });
    }

    public function test_bot_verificato_non_logga_il_token(): void
    {
        $this->loggaSenzaToken(fn ($bot) => $bot->botVerificato());
    }

    public function test_post_non_logga_il_token(): void
    {
        $this->loggaSenzaToken(fn ($bot) => $bot->sendMessage('1', 'ciao'));
    }

    public function test_get_file_non_logga_il_token(): void
    {
        $this->loggaSenzaToken(fn ($bot) => $bot->getFilePath('f'));
    }

    public function test_download_non_logga_il_token(): void
    {
        $this->loggaSenzaToken(fn ($bot) => $bot->downloadFile('x/y'));
    }
}
