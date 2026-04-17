<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTelegramLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_user_can_generate_a_telegram_link_token_from_profile(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->from('/profile')
            ->post('/profile/telegram/link');

        $response->assertRedirect('/profile');
        $response->assertSessionHas('status', 'telegram-link-generated');

        $user->refresh();

        $this->assertNotNull($user->telegram_link_token);
        $this->assertNotNull($user->telegram_link_expires_at);
    }

    public function test_user_can_unlink_telegram_from_profile(): void
    {
        $user = User::factory()->create([
            'telegram_chat_id' => '998877',
            'telegram_link_token' => 'TOKENATTIVO',
            'telegram_link_expires_at' => now()->addHour(),
            'telegram_verified_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->from('/profile')
            ->delete('/profile/telegram/link');

        $response->assertRedirect('/profile');
        $response->assertSessionHas('status', 'telegram-unlinked');

        $user->refresh();

        $this->assertNull($user->telegram_chat_id);
        $this->assertNull($user->telegram_link_token);
        $this->assertNull($user->telegram_link_expires_at);
        $this->assertNull($user->telegram_verified_at);
    }
}