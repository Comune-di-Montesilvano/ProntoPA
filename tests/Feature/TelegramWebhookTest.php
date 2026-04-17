<?php

namespace Tests\Feature;

use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            TabelleRiferimentoSeeder::class,
            ImpostazioniSeeder::class,
            RolesAndPermissionsSeeder::class,
        ]);

        Impostazione::set('telegram_bot_token', '123456:telegram-test-token');
        Impostazione::set('telegram_webhook_secret', 'secret-token');
        Impostazione::set('telegram_bot_username', 'prontopa_test_bot');
    }

    public function test_start_command_links_user_to_chat(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create([
            'telegram_link_token' => 'ABC123TOKEN',
            'telegram_link_expires_at' => now()->addHour(),
        ]);

        $response = $this->postJson('/api/telegram/webhook', [
            'message' => [
                'chat' => ['id' => '998877'],
                'text' => '/start ABC123TOKEN',
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'secret-token',
        ]);

        $response->assertOk();
        $user->refresh();

        $this->assertSame('998877', $user->telegram_chat_id);
        $this->assertNotNull($user->telegram_verified_at);
        $this->assertNull($user->telegram_link_token);
    }

    public function test_lista_command_sends_visible_items_to_linked_user(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create([
            'telegram_chat_id' => '112233',
            'telegram_verified_at' => now(),
        ]);
        $user->syncRoles(['segnalatore']);

        Segnalazione::create([
            'id_tipologia_segnalazione' => 1,
            'id_utente_segnalazione' => $user->id,
            'testo_segnalazione' => 'Lampione spento in via Roma',
            'flag_riservata' => false,
            'flag_pubblicata' => true,
            'id_stato_segnalazione' => 2,
            'id_provenienza' => 1,
        ]);

        $response = $this->postJson('/api/telegram/webhook', [
            'message' => [
                'chat' => ['id' => '112233'],
                'text' => '/lista',
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'secret-token',
        ]);

        $response->assertOk();

        Http::assertSent(function ($request) {
            return Str::contains($request->url(), 'sendMessage')
                && Str::contains((string) $request['text'], 'Segnalazioni aperte')
                && Str::contains((string) $request['text'], 'Lampione spento');
        });
    }

    public function test_apri_command_includes_inline_actions_when_available(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create([
            'telegram_chat_id' => '445566',
            'telegram_verified_at' => now(),
            'amministratore' => true,
        ]);
        $user->syncRoles(['admin']);

        $segnalazione = Segnalazione::create([
            'id_tipologia_segnalazione' => 1,
            'id_utente_segnalazione' => $user->id,
            'testo_segnalazione' => 'Perdita idrica nel cortile',
            'flag_riservata' => false,
            'flag_pubblicata' => true,
            'id_stato_segnalazione' => 2,
            'id_provenienza' => 1,
        ]);

        $response = $this->postJson('/api/telegram/webhook', [
            'message' => [
                'chat' => ['id' => '445566'],
                'text' => '/apri ' . $segnalazione->id_segnalazione,
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'secret-token',
        ]);

        $response->assertOk();

        Http::assertSent(function ($request) use ($segnalazione) {
            $replyMarkup = $request['reply_markup'] ?? [];
            $keyboard = $replyMarkup['inline_keyboard'] ?? [];

            return Str::contains($request->url(), 'sendMessage')
                && Str::contains((string) $request['text'], 'Segnalazione #' . $segnalazione->id_segnalazione)
                && collect($keyboard)->flatten(1)->contains(fn ($button) => Str::contains((string) ($button['callback_data'] ?? ''), 'azione:' . $segnalazione->id_segnalazione));
        });
    }

    public function test_callback_query_executes_inline_action_and_updates_state(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $admin = User::factory()->create([
            'telegram_chat_id' => '445566',
            'telegram_verified_at' => now(),
            'amministratore' => true,
        ]);
        $admin->syncRoles(['admin']);

        $segnalatore = User::factory()->create();
        $segnalatore->syncRoles(['segnalatore']);

        $segnalazione = Segnalazione::create([
            'id_tipologia_segnalazione' => 1,
            'id_utente_segnalazione' => $segnalatore->id,
            'testo_segnalazione' => 'Interruttore guasto in aula magna',
            'flag_riservata' => false,
            'flag_pubblicata' => true,
            'id_stato_segnalazione' => 2,
            'id_provenienza' => 1,
        ]);

        $response = $this->postJson('/api/telegram/webhook', [
            'callback_query' => [
                'id' => 'cbq-1',
                'data' => 'azione:' . $segnalazione->id_segnalazione . ':3',
                'message' => [
                    'chat' => ['id' => '445566'],
                ],
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'secret-token',
        ]);

        $response->assertOk();

        $segnalazione->refresh();

        $this->assertSame(3, $segnalazione->id_stato_segnalazione);
        $this->assertNotNull($segnalazione->data_chiusura);

        Http::assertSent(function ($request) use ($segnalazione) {
            return Str::contains($request->url(), 'answerCallbackQuery')
                && $request['callback_query_id'] === 'cbq-1'
                && $request['text'] === 'Azione eseguita.';
        });

        Http::assertSent(function ($request) use ($segnalazione) {
            return Str::contains($request->url(), 'sendMessage')
                && Str::contains((string) $request['text'], 'Segnalazione #' . $segnalazione->id_segnalazione)
                && Str::contains((string) $request['text'], 'Stato:');
        });
    }
}