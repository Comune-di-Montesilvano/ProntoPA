<?php

namespace Tests\Feature;

use App\Models\Impostazione;
use App\Models\User;
use App\Notifications\AnnualVerificationReminderNotification;
use App\Notifications\AnnualVerificationRequestNotification;
use App\Notifications\AnnualVerificationSuspendedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CheckAnnualAccountVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function approvedUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'approval_status' => 'approved',
            'attivo'          => true,
        ], $overrides));
    }

    public function test_command_exits_early_when_disabled(): void
    {
        Notification::fake();

        Impostazione::create([
            'chiave'  => 'account_verification_enabled',
            'valore'  => '0',
            'tipo'    => 'boolean',
            'gruppo'  => 'verifica_account',
        ]);

        $user = $this->approvedUser([
            'prossima_verifica_annuale_at' => now()->subDay(),
        ]);

        $this->artisan('accounts:annual-check')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertNull($user->fresh()->annual_verification_token_hash);
    }

    public function test_sends_request_to_user_with_null_prossima_verifica(): void
    {
        Notification::fake();

        $user = $this->approvedUser(['prossima_verifica_annuale_at' => null]);

        $this->artisan('accounts:annual-check')->assertSuccessful();

        Notification::assertSentTo($user, AnnualVerificationRequestNotification::class);
        $this->assertNotNull($user->fresh()->annual_verification_token_hash);
    }

    public function test_sends_request_to_user_with_past_prossima_verifica(): void
    {
        Notification::fake();

        $user = $this->approvedUser([
            'prossima_verifica_annuale_at'   => now()->subDay(),
            'annual_verification_sent_at'    => null,
        ]);

        $this->artisan('accounts:annual-check')->assertSuccessful();

        Notification::assertSentTo($user, AnnualVerificationRequestNotification::class);
    }

    public function test_skips_user_already_sent_verification(): void
    {
        Notification::fake();

        $this->approvedUser([
            'prossima_verifica_annuale_at' => now()->subDay(),
            'annual_verification_sent_at'  => now()->subDays(2),
        ]);

        $this->artisan('accounts:annual-check')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_sends_reminder_when_in_window(): void
    {
        Notification::fake();

        $user = $this->approvedUser([
            'prossima_verifica_annuale_at'       => now()->addDays(400),
            'annual_verification_token_hash'     => hash('sha256', 'tok'),
            'annual_verification_sent_at'        => now()->subDays(20),
            'annual_verification_due_at'         => now()->addDays(10),
            'annual_verification_reminder_at'    => null,
        ]);

        $this->artisan('accounts:annual-check')->assertSuccessful();

        Notification::assertSentTo($user, AnnualVerificationReminderNotification::class);
        $this->assertNotNull($user->fresh()->annual_verification_reminder_at);
    }

    public function test_does_not_send_duplicate_reminder(): void
    {
        Notification::fake();

        $this->approvedUser([
            'prossima_verifica_annuale_at'    => now()->addDays(400),
            'annual_verification_token_hash'  => hash('sha256', 'tok'),
            'annual_verification_sent_at'     => now()->subDays(20),
            'annual_verification_due_at'      => now()->addDays(10),
            'annual_verification_reminder_at' => now()->subDay(),
        ]);

        $this->artisan('accounts:annual-check')->assertSuccessful();

        Notification::assertNotSentTo(
            User::first(),
            AnnualVerificationReminderNotification::class
        );
    }

    public function test_suspends_overdue_user_and_notifies(): void
    {
        Notification::fake();

        $user = $this->approvedUser([
            'prossima_verifica_annuale_at'    => now()->addDays(400),
            'annual_verification_token_hash'  => hash('sha256', 'tok'),
            'annual_verification_sent_at'     => now()->subDays(35),
            'annual_verification_due_at'      => now()->subDay(),
        ]);

        $this->artisan('accounts:annual-check')->assertSuccessful();

        Notification::assertSentTo($user, AnnualVerificationSuspendedNotification::class);
        $this->assertFalse($user->fresh()->attivo);
    }

    public function test_does_not_suspend_when_auto_suspend_disabled(): void
    {
        Notification::fake();

        Impostazione::create([
            'chiave'  => 'account_verification_auto_suspend',
            'valore'  => '0',
            'tipo'    => 'boolean',
            'gruppo'  => 'verifica_account',
        ]);

        $user = $this->approvedUser([
            'prossima_verifica_annuale_at'   => now()->addDays(400),
            'annual_verification_token_hash' => hash('sha256', 'tok'),
            'annual_verification_sent_at'    => now()->subDays(35),
            'annual_verification_due_at'     => now()->subDay(),
        ]);

        $this->artisan('accounts:annual-check')->assertSuccessful();

        Notification::assertNotSentTo($user, AnnualVerificationSuspendedNotification::class);
        $this->assertTrue($user->fresh()->attivo);
    }

    public function test_dry_run_makes_no_changes(): void
    {
        Notification::fake();

        $user = $this->approvedUser(['prossima_verifica_annuale_at' => null]);

        $this->artisan('accounts:annual-check --dry-run')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertNull($user->fresh()->annual_verification_token_hash);
    }

    public function test_skips_inactive_users(): void
    {
        Notification::fake();

        $this->approvedUser([
            'attivo'                       => false,
            'prossima_verifica_annuale_at' => null,
        ]);

        $this->artisan('accounts:annual-check')->assertSuccessful();

        Notification::assertNothingSent();
    }
}
