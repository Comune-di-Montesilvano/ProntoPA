<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagina_verifica_visibile_a_utente_non_verificato(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/verify-email')->assertOk()->assertSee('conferma');
    }

    public function test_link_firmato_verifica_email(): void
    {
        Event::fake();
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id, 'hash' => sha1($user->email),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect();

        Event::assertDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_hash_errato_non_verifica(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id, 'hash' => sha1('altra@email.it'),
        ]);

        $this->actingAs($user)->get($url);

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_nuovo_link_solo_dopo_attesa(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $this->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class, \Illuminate\Routing\Middleware\ThrottleRequests::class]);
        $user = User::factory()->unverified()->create();
        $user->sendEmailVerificationNotification();

        $this->actingAs($user)->get('/verify-email')->assertSee('Puoi chiedere un nuovo link tra');
        $this->actingAs($user)->post(route('verification.send'));
        \Illuminate\Support\Facades\Notification::assertSentToTimes($user, \Illuminate\Auth\Notifications\VerifyEmail::class, 1);

        $this->travel(User::ATTESA_REINVIO_VERIFICA + 1)->seconds();
        $this->actingAs($user)->post(route('verification.send'))->assertSessionHas('status', 'verification-link-sent');
        \Illuminate\Support\Facades\Notification::assertSentToTimes($user, \Illuminate\Auth\Notifications\VerifyEmail::class, 2);
    }

    public function test_email_di_conferma_in_italiano_con_nome_ente(): void
    {
        \App\Models\Impostazione::set('ente_nome', 'Comune di Prova');
        \App\Models\Impostazione::set('ente_colore_primario', '#0066cc');
        $user = User::factory()->unverified()->create();

        $html = (string) (new \Illuminate\Auth\Notifications\VerifyEmail)->toMail($user)->render();

        $this->assertStringContainsString('Buongiorno,', $html);
        $this->assertStringContainsString('Cordiali saluti', $html);
        $this->assertStringContainsString('Comune di Prova', $html);
        $this->assertStringContainsString('background-color: #0066cc', $html);
        $this->assertStringContainsString('Se il pulsante', $html);
        $this->assertStringNotContainsString('Regards', $html);
        $this->assertStringNotContainsString('All rights reserved', $html);
    }
}
