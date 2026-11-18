<?php

namespace Tests\Feature;

use App\Models\Impostazione;
use App\Models\Impresa;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 1.0.9: Telegram solo per chi lavora (gestori, operai, ditte; admin per provarlo) e invito in cima agli elenchi. */
class InvitoTelegramTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        Impostazione::set('telegram_bot_token', '123:abc');
        Impostazione::set('telegram_bot_username', 'prontopa_bot');
    }

    private function utente(string $ruolo, array $attributi = []): User
    {
        $utente = User::factory()->create($attributi + ['supervisore_segnalazioni' => $ruolo === 'gestore']);
        $utente->assignRole($ruolo);

        return $utente;
    }

    private function ditta(): User
    {
        $impresa = Impresa::create(['ragione_sociale' => 'Edil Adriatica']);

        return $this->utente('impresa', ['auth_source' => 'spid', 'id_impresa' => $impresa->id_impresa, 'email_verified_at' => now()]);
    }

    public function test_chi_usa_telegram(): void
    {
        $this->assertTrue($this->utente('gestore')->usaTelegram());
        $this->assertTrue($this->utente('operaio')->usaTelegram());
        $this->assertTrue($this->ditta()->usaTelegram());
        $this->assertTrue($this->utente('admin')->usaTelegram());
        $this->assertFalse($this->utente('segnalatore')->usaTelegram());
    }

    public function test_invito_negli_elenchi_di_gestore_operaio_e_ditta(): void
    {
        $this->actingAs($this->utente('gestore'))->get(route('gestione.dashboard'))->assertOk()
            ->assertSee('Ricevi gli avvisi su Telegram')->assertSee(route('profile.telegram.ignora'), false);
        $this->actingAs($this->utente('operaio'))->get(route('operaio.dashboard'))->assertOk()
            ->assertSee('Ricevi gli avvisi su Telegram');
        $this->actingAs($this->ditta())->get(route('imprese.dashboard'))->assertOk()
            ->assertSee('Ricevi gli avvisi su Telegram');
    }

    public function test_niente_invito_se_collegato_o_bot_non_configurato(): void
    {
        $this->actingAs($this->utente('gestore', ['telegram_chat_id' => '123']))->get(route('gestione.dashboard'))
            ->assertOk()->assertDontSee('Ricevi gli avvisi su Telegram');

        Impostazione::set('telegram_bot_token', null);
        $this->actingAs($this->utente('gestore'))->get(route('gestione.dashboard'))
            ->assertOk()->assertDontSee('Ricevi gli avvisi su Telegram');
    }

    public function test_ignora_nasconde_l_invito_per_30_giorni(): void
    {
        $gestore = $this->utente('gestore');
        $this->actingAs($gestore)->from(route('gestione.dashboard'))->post(route('profile.telegram.ignora'))
            ->assertRedirect(route('gestione.dashboard'));

        $this->actingAs($gestore->fresh())->get(route('gestione.dashboard'))->assertDontSee('Ricevi gli avvisi su Telegram');

        $this->travel(31)->days();
        $this->actingAs($gestore->fresh())->get(route('gestione.dashboard'))->assertSee('Ricevi gli avvisi su Telegram');
    }

    public function test_collega_apre_direttamente_il_bot(): void
    {
        $gestore = $this->utente('gestore');
        $risposta = $this->actingAs($gestore)->post(route('profile.telegram.store'), ['apri' => 1]);

        $token = $gestore->fresh()->telegram_link_token;
        $this->assertNotNull($token);
        $risposta->assertRedirect('https://t.me/prontopa_bot?start='.$token);
    }

    public function test_segnalatore_non_ha_telegram(): void
    {
        $segnalatore = $this->utente('segnalatore', ['telegram_chat_id' => '555']);

        $this->actingAs($segnalatore)->get(route('profile.edit'))->assertOk()->assertDontSee('Genera token Telegram');
        $this->actingAs($segnalatore)->post(route('profile.telegram.store'))->assertForbidden();
        $this->assertNull($segnalatore->routeNotificationForTelegram());
    }

    public function test_bot_non_risponde_ai_comandi_di_un_segnalatore_collegato(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        Impostazione::set('telegram_webhook_secret', 's');
        $segnalatore = $this->utente('segnalatore', ['telegram_chat_id' => '555']);
        Segnalazione::factory()->create(['id_utente_segnalazione' => $segnalatore->id, 'id_stato_segnalazione' => 2, 'testo_segnalazione' => 'Lampione spento']);

        $this->postJson('/api/telegram/webhook', ['message' => ['chat' => ['id' => '555'], 'text' => '/lista']],
            ['X-Telegram-Bot-Api-Secret-Token' => 's'])->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage'));
        Http::assertNotSent(fn ($r) => str_contains((string) ($r['text'] ?? ''), 'Lampione spento'));
    }

    public function test_bot_non_collega_un_segnalatore(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        Impostazione::set('telegram_webhook_secret', 's');
        $segnalatore = $this->utente('segnalatore', ['telegram_link_token' => 'TOK', 'telegram_link_expires_at' => now()->addHour()]);

        $this->postJson('/api/telegram/webhook', ['message' => ['chat' => ['id' => '777'], 'text' => '/start TOK']],
            ['X-Telegram-Bot-Api-Secret-Token' => 's'])->assertOk();

        $this->assertNull($segnalatore->fresh()->telegram_chat_id);
    }

    public function test_profilo_non_stampa_codice_blade(): void
    {
        $this->actingAs($this->utente('gestore'))->get(route('profile.edit'))->assertOk()
            ->assertDontSee('ltrim($botUsername', false)
            ->assertSee('@prontopa_bot');
    }
}
