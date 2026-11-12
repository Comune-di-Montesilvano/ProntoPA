<?php

namespace Tests\Feature\Admin;

use App\Models\Impostazione;
use App\Models\User;
use App\Services\Oidc\OidcConfig;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegrazioniTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ImpostazioniSeeder::class);
        Cache::flush();
        $this->admin = User::factory()->create(['amministratore' => true]);
        $this->admin->assignRole('admin');
        config(['app.url' => 'https://prontopa.ente.it/']);
    }

    public function test_segreti_non_stampati(): void
    {
        Impostazione::set('telegram_bot_token', 'tok-123');
        Impostazione::set('webhook_cittadini_secret', 'whs-456');
        Impostazione::set('telegram_webhook_secret', 'tws-789');
        Impostazione::set('oidc_client_secret', Crypt::encryptString('oidc-999'));

        $this->actingAs($this->admin)->get(route('admin.integrazioni'))
            ->assertOk()
            ->assertDontSee('tok-123')
            ->assertDontSee('whs-456')
            ->assertDontSee('tws-789')
            ->assertDontSee('oidc-999');
    }

    public function test_update_sezione_sconosciuta_404(): void
    {
        $this->actingAs($this->admin)->patch('/admin/integrazioni/pippo', ['impostazioni' => ['x' => '1']])->assertNotFound();
    }

    public function test_prova_sezione_senza_prova_404(): void
    {
        $this->actingAs($this->admin)->post('/admin/integrazioni/webhook/prova')->assertNotFound();
    }

    public function test_update_sezione_salva_solo_le_sue_chiavi(): void
    {
        $this->actingAs($this->admin)->patch(route('admin.integrazioni.update', 'spid'), ['impostazioni' => [
            'oidc_issuer' => 'https://login.ente.it/', 'oidc_client_id' => 'prontopa', 'oidc_client_secret' => 'sec', 'ente_nome' => 'Hack',
        ]])->assertSessionHasNoErrors();

        $this->assertSame('https://login.ente.it', app(OidcConfig::class)->issuer());
        $this->assertSame('sec', app(OidcConfig::class)->clientSecret());
        $this->assertNotSame('Hack', Impostazione::get('ente_nome'));
    }

    public function test_prova_ai_salva_esito_in_cache(): void
    {
        Http::fake(['*/api/tags' => Http::response([], 200)]);
        Impostazione::set('ai_enabled', '1');

        $this->actingAs($this->admin)->post(route('admin.integrazioni.prova', 'ai'))->assertRedirect();

        $this->assertTrue(Cache::get('integrazioni:prova:ai')['ok']);
    }

    public function test_prova_spid_fallita_dice_il_motivo(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        Impostazione::set('oidc_issuer', 'https://login.ente.it');
        Impostazione::set('oidc_client_id', 'x');
        Impostazione::set('oidc_client_secret', Crypt::encryptString('s'));

        $this->actingAs($this->admin)->post(route('admin.integrazioni.prova', 'spid'))->assertRedirect();

        $esito = Cache::get('integrazioni:prova:spid');
        $this->assertFalse($esito['ok']);
        $this->assertNotSame('', $esito['messaggio']);
    }

    public function test_prova_telegram_senza_token_e_telegram_ok(): void
    {
        $this->actingAs($this->admin)->post(route('admin.integrazioni.prova', 'telegram'));
        $this->assertFalse(Cache::get('integrazioni:prova:telegram')['ok']);

        Impostazione::set('telegram_bot_token', 'abc');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['username' => 'prontopa_bot']])]);
        $this->actingAs($this->admin)->post(route('admin.integrazioni.prova', 'telegram'));
        $esito = Cache::get('integrazioni:prova:telegram');
        $this->assertTrue($esito['ok']);
        $this->assertStringContainsString('prontopa_bot', $esito['messaggio']);
    }

    public function test_prova_antivirus_irraggiungibile_non_lancia(): void
    {
        config(['services.clamav.host' => '127.0.0.1', 'services.clamav.port' => 1]);

        $this->actingAs($this->admin)->post(route('admin.integrazioni.prova', 'antivirus'))->assertRedirect();

        $this->assertFalse(Cache::get('integrazioni:prova:antivirus')['ok']);
    }

    public function test_spid_mostra_redirect_uri_e_cosa_manca(): void
    {
        $this->actingAs($this->admin)->get(route('admin.integrazioni'))
            ->assertSee(app(OidcConfig::class)->redirectUri())
            ->assertSee('Issuer mancante.')
            ->assertSee('profilo Docker')
            ->assertSee('Sincronizzazione');
    }
}
