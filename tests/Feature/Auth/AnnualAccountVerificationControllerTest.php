<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AnnualAccountVerificationControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'approval_status' => 'approved',
            'attivo'          => true,
        ], $overrides));
    }

    private function signedUrl(User $user, string $token, ?\DateTimeInterface $expiry = null): string
    {
        return URL::temporarySignedRoute(
            'account.verification.annual',
            $expiry ?? now()->addDays(30),
            ['user' => $user->id, 'token' => $token],
        );
    }

    public function test_valid_token_confirms_account(): void
    {
        $plainToken = 'validtoken123';
        $user = $this->makeUser([
            'annual_verification_token_hash' => hash('sha256', $plainToken),
            'annual_verification_sent_at'    => now()->subDays(5),
            'annual_verification_due_at'     => now()->addDays(25),
        ]);

        $response = $this->get($this->signedUrl($user, $plainToken));

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status', 'Verifica annuale completata con successo.');

        $user->refresh();
        $this->assertNotNull($user->email_confermata_annualmente_at);
        $this->assertNotNull($user->prossima_verifica_annuale_at);
        $this->assertNull($user->annual_verification_token_hash);
        $this->assertNull($user->annual_verification_sent_at);
        $this->assertNull($user->annual_verification_due_at);
        $this->assertTrue($user->attivo);
    }

    public function test_invalid_token_is_rejected(): void
    {
        $user = $this->makeUser([
            'annual_verification_token_hash' => hash('sha256', 'correcttoken'),
            'annual_verification_due_at'     => now()->addDays(25),
        ]);

        $response = $this->get($this->signedUrl($user, 'wrongtoken'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('username');
    }

    public function test_expired_due_at_is_rejected(): void
    {
        $plainToken = 'expiredtoken';
        $user = $this->makeUser([
            'annual_verification_token_hash' => hash('sha256', $plainToken),
            'annual_verification_due_at'     => now()->subDay(),
        ]);

        // URL still signed but due_at is past
        $url = URL::temporarySignedRoute(
            'account.verification.annual',
            now()->addDay(),
            ['user' => $user->id, 'token' => $plainToken],
        );

        $response = $this->get($url);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('username');
    }

    public function test_non_approved_user_is_rejected(): void
    {
        $plainToken = 'sometoken';
        $user = $this->makeUser([
            'approval_status'                => 'pending',
            'annual_verification_token_hash' => hash('sha256', $plainToken),
            'annual_verification_due_at'     => now()->addDays(10),
        ]);

        $response = $this->get($this->signedUrl($user, $plainToken));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('username');
    }

    public function test_missing_token_on_user_is_rejected(): void
    {
        $user = $this->makeUser([
            'annual_verification_token_hash' => null,
            'annual_verification_due_at'     => now()->addDays(10),
        ]);

        $response = $this->get($this->signedUrl($user, 'anytoken'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('username');
    }

    public function test_tampered_signature_returns_403(): void
    {
        $user = $this->makeUser();

        $response = $this->get(
            route('account.verification.annual', ['user' => $user->id, 'token' => 'faketoken'])
        );

        $response->assertStatus(403);
    }
}
