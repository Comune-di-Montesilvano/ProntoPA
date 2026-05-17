<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_verification_screen_is_not_available(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get('/verify-email');

        $response->assertNotFound();
    }

    public function test_email_verification_routes_are_not_registered(): void
    {
        $this->assertFalse(Route::has('verification.notice'));
        $this->assertFalse(Route::has('verification.verify'));
        $this->assertFalse(Route::has('verification.send'));
    }
}
