<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeOidcProvider;
use Tests\TestCase;

class SpidLogoutTest extends TestCase
{
    use RefreshDatabase;

    private FakeOidcProvider $idp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        config(['app.url' => 'https://prontopa.test']);
        $this->idp = new FakeOidcProvider();
        $this->idp->configura();
        $this->idp->fake();
    }

    public function test_logout_spid_passa_dal_proxy(): void
    {
        $user = User::factory()->create(['auth_source' => 'spid', 'codice_fiscale' => 'RSSMRA80A01G482X', 'password' => null]);

        $risposta = $this->actingAs($user)->withSession(['spid.id_token' => 'tok'])->post('/logout');

        $location = $risposta->headers->get('Location');
        $this->assertStringStartsWith(FakeOidcProvider::ISSUER.'/OIDC/end_session?', $location);
        $this->assertStringContainsString('id_token_hint=tok', $location);
        $this->assertStringContainsString('post_logout_redirect_uri='.rawurlencode('https://prontopa.test/'), $location);
        $this->assertGuest();
    }

    public function test_logout_spid_con_proxy_giu_torna_alla_home(): void
    {
        $this->idp->proxyGiu = true;
        $user = User::factory()->create(['auth_source' => 'spid', 'codice_fiscale' => 'RSSMRA80A01G482X', 'password' => null]);

        $this->actingAs($user)->withSession(['spid.id_token' => 'tok'])->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_logout_non_spid_invariato(): void
    {
        $this->actingAs(User::factory()->create())->post('/logout')->assertRedirect('/');
    }
}
