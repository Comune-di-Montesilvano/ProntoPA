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
        Http::fake([
            'api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'prontopa_bot']]),
            'api.telegram.org/*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => 'https://prontopa.ente.it/api/telegram/webhook']]),
        ]);
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

    public function test_salvataggio_cancella_l_esito_della_prova(): void
    {
        Cache::put('integrazioni:prova:ai', ['ok' => false, 'messaggio' => 'x', 'quando' => now()->toIso8601String()], 600);
        Cache::put('integrazioni:prova:spid', ['ok' => true, 'messaggio' => 'y', 'quando' => now()->toIso8601String()], 600);

        $this->actingAs($this->admin)->patch(route('admin.integrazioni.update', 'ai'), ['impostazioni' => ['ai_modello' => 'nuovo']])
            ->assertSessionHasNoErrors();

        $this->assertNull(Cache::get('integrazioni:prova:ai'));
        $this->assertNotNull(Cache::get('integrazioni:prova:spid'));
    }

    public function test_prova_telegram_dice_se_il_bot_non_e_collegato(): void
    {
        Impostazione::set('telegram_bot_token', 'abc');
        Http::fake([
            'api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'prontopa_bot']]),
            'api.telegram.org/*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => '']]),
        ]);
        $this->actingAs($this->admin)->post(route('admin.integrazioni.prova', 'telegram'));
        $esito = Cache::get('integrazioni:prova:telegram');
        $this->assertFalse($esito['ok']);
        $this->assertStringContainsString('Salva', $esito['messaggio']);
    }

    public function test_prova_telegram_riporta_l_errore_di_consegna(): void
    {
        Impostazione::set('telegram_bot_token', 'abc');
        Http::fake([
            'api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'prontopa_bot']]),
            'api.telegram.org/*/getWebhookInfo' => Http::response(['ok' => true, 'result' => [
                'url' => 'https://prontopa.ente.it/api/telegram/webhook', 'last_error_message' => 'Connection timed out',
            ]]),
        ]);
        $this->actingAs($this->admin)->post(route('admin.integrazioni.prova', 'telegram'));
        $esito = Cache::get('integrazioni:prova:telegram');
        $this->assertFalse($esito['ok']);
        $this->assertStringContainsString('Connection timed out', $esito['messaggio']);
    }

    private function fakeTelegram(array $getMe = ['ok' => true, 'result' => ['username' => 'prontopa_bot']], int $statoGetMe = 200): void
    {
        Http::fake([
            'api.telegram.org/*/getMe' => Http::response($getMe, $statoGetMe),
            'api.telegram.org/*/setWebhook' => Http::response(['ok' => true, 'result' => true]),
            'api.telegram.org/*/setMyCommands' => Http::response(['ok' => true, 'result' => true]),
            'api.telegram.org/*/setMyName' => Http::response(['ok' => true, 'result' => true]),
            'api.telegram.org/*/setMyDescription' => Http::response(['ok' => true, 'result' => true]),
            'api.telegram.org/*/setMyShortDescription' => Http::response(['ok' => true, 'result' => true]),
            'api.telegram.org/*/setMyProfilePhoto' => Http::response(['ok' => true, 'result' => true]),
            'api.telegram.org/*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => 'https://prontopa.ente.it/api/telegram/webhook']]),
        ]);
    }

    private function salvaTelegram(): void
    {
        $this->actingAs($this->admin)->patch(route('admin.integrazioni.update', 'telegram'), ['impostazioni' => ['telegram_bot_token' => '123:abc']])
            ->assertRedirect()->assertSessionHas('success');
    }

    public function test_salva_telegram_imposta_nome_descrizioni_e_foto_dell_ente(): void
    {
        Impostazione::set('ente_nome', 'Comune di Montesilvano');
        $this->fakeTelegram();

        $this->salvaTelegram();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/setMyName') && $r['name'] === 'ProntoPA · Comune di Montesilvano');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/setMyShortDescription')
            && str_contains($r['short_description'], 'Comune di Montesilvano') && mb_strlen($r['short_description']) <= 120);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/setMyDescription')
            && str_contains($r['description'], 'Collega Telegram') && mb_strlen($r['description']) <= 512);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/setMyProfilePhoto') && $r->isMultipart());
        $this->assertStringContainsString('foto', session('success'));
    }

    public function test_foto_del_bot_ricaricata_solo_se_il_logo_cambia(): void
    {
        $this->fakeTelegram();
        $this->salvaTelegram();
        $this->salvaTelegram();

        Http::assertSentCount(2 * 8 + 1); // 2 giri completi (getMe, setWebhook, comandi, nome, 2 descrizioni, getMe+info della prova) + 1 sola foto
        $this->assertCount(1, Http::recorded(fn ($r) => str_ends_with($r->url(), '/setMyProfilePhoto')));
    }

    public function test_salva_telegram_collega_tutto_in_un_colpo(): void
    {
        $this->fakeTelegram();

        $this->actingAs($this->admin)->patch(route('admin.integrazioni.update', 'telegram'), ['impostazioni' => ['telegram_bot_token' => '123:abc']])
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame('prontopa_bot', Impostazione::get('telegram_bot_username'));
        $segreto = Impostazione::get('telegram_webhook_secret');
        $this->assertNotEmpty($segreto);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/setWebhook')
            && $r['url'] === 'https://prontopa.ente.it/api/telegram/webhook'
            && $r['secret_token'] === $segreto);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/setMyCommands')
            && collect($r['commands'])->pluck('command')->all() === ['lista', 'oggi', 'apri', 'chiudi', 'note', 'priorita']);
        $this->assertTrue(Cache::get('integrazioni:prova:telegram')['ok']);
    }

    public function test_salva_telegram_con_token_non_valido_non_collega(): void
    {
        $this->fakeTelegram(['ok' => false, 'description' => 'Unauthorized'], 401);

        $this->actingAs($this->admin)->patch(route('admin.integrazioni.update', 'telegram'), ['impostazioni' => ['telegram_bot_token' => 'sbagliato']])
            ->assertRedirect()->assertSessionHas('error');

        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/setWebhook'));
        $this->assertFalse(Cache::get('integrazioni:prova:telegram')['ok']);
    }

    public function test_salva_telegram_senza_token_non_chiama_telegram(): void
    {
        Http::fake();
        $this->actingAs($this->admin)->patch(route('admin.integrazioni.update', 'telegram'), ['impostazioni' => ['telegram_bot_token' => '']])
            ->assertRedirect();
        Http::assertNothingSent();
    }

    public function test_pagina_mostra_guida_telegram_e_indirizzo_webhook(): void
    {
        $this->actingAs($this->admin)->get(route('admin.integrazioni'))->assertOk()
            ->assertSee('@BotFather')
            ->assertSee('https://prontopa.ente.it/api/telegram/webhook')
            ->assertSee('menu dei comandi')
            ->assertDontSee('name="impostazioni[telegram_bot_username]"', false)
            ->assertDontSee('name="impostazioni[telegram_webhook_secret]"', false);
    }
}
