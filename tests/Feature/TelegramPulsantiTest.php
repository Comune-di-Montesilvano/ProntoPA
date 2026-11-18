<?php

namespace Tests\Feature;

use App\Models\Impostazione;
use App\Models\NotaSegnalazione;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 1.0.11: bot usabile a pulsanti, senza scrivere comandi. */
class TelegramPulsantiTest extends TestCase
{
    use RefreshDatabase;

    private User $gestore;

    private Segnalazione $segnalazione;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        Impostazione::set('telegram_bot_token', '123:abc');
        Impostazione::set('telegram_webhook_secret', 's');
        Impostazione::set('telegram_bot_username', 'prontopa_bot');
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->gestore = User::factory()->create(['telegram_chat_id' => '100', 'supervisore_segnalazioni' => true]);
        $this->gestore->assignRole('gestore');
        $this->segnalazione = Segnalazione::factory()->create([
            'id_stato_segnalazione' => 2,
            'titolo' => 'SI SEGNALANO TOMBINI OSTRUITI',
            'testo_segnalazione' => 'Tombini ostruiti davanti alla scuola',
        ]);
    }

    private function scrivi(string $testo): void
    {
        $this->postJson('/api/telegram/webhook', ['message' => ['chat' => ['id' => '100'], 'text' => $testo]],
            ['X-Telegram-Bot-Api-Secret-Token' => 's'])->assertOk();
    }

    private function premi(string $dati): void
    {
        $this->postJson('/api/telegram/webhook', ['callback_query' => ['id' => 'cb1', 'data' => $dati, 'message' => ['chat' => ['id' => '100']]]],
            ['X-Telegram-Bot-Api-Secret-Token' => 's'])->assertOk();
    }

    /** @return list<array<string, mixed>> pulsanti inline dei messaggi inviati */
    private function pulsantiInviati(): array
    {
        return Http::recorded()
            ->filter(fn ($coppia) => str_ends_with($coppia[0]->url(), '/sendMessage'))
            ->flatMap(fn ($coppia) => collect($coppia[0]['reply_markup']['inline_keyboard'] ?? [])->flatten(1))
            ->values()->all();
    }

    public function test_lista_mostra_un_pulsante_per_segnalazione_con_titolo_leggibile(): void
    {
        $this->scrivi('/lista');

        $pulsante = collect($this->pulsantiInviati())->firstWhere('callback_data', 'apri:'.$this->segnalazione->id_segnalazione);
        $this->assertNotNull($pulsante);
        $this->assertStringContainsString('#'.$this->segnalazione->id_segnalazione, $pulsante['text']);
        $this->assertStringContainsString('Si segnalano tombini', $pulsante['text']);
    }

    public function test_testo_della_tastiera_vale_come_comando(): void
    {
        $this->scrivi('📋 Lista');

        $this->assertNotNull(collect($this->pulsantiInviati())->firstWhere('callback_data', 'apri:'.$this->segnalazione->id_segnalazione));
    }

    public function test_pulsante_apri_mostra_il_dettaglio_con_note_e_ritorno_alla_lista(): void
    {
        $this->premi('apri:'.$this->segnalazione->id_segnalazione);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains((string) $r['text'], '#'.$this->segnalazione->id_segnalazione)
            && str_contains((string) $r['text'], 'Tombini ostruiti davanti alla scuola'));
        $dati = collect($this->pulsantiInviati())->pluck('callback_data');
        $this->assertContains('note:'.$this->segnalazione->id_segnalazione, $dati);
        $this->assertContains('lista', $dati);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/answerCallbackQuery'));
    }

    public function test_pulsante_note_mostra_le_note(): void
    {
        NotaSegnalazione::create([
            'id_segnalazione' => $this->segnalazione->id_segnalazione, 'id_utente' => $this->gestore->id,
            'testo' => 'Ditta avvisata', 'data' => now(), 'visibile_web' => false, 'visibile_impresa' => false,
        ]);

        $this->premi('note:'.$this->segnalazione->id_segnalazione);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && str_contains((string) $r['text'], 'Ditta avvisata'));
    }

    public function test_pulsante_su_segnalazione_non_visibile_non_mostra_nulla(): void
    {
        $operaio = User::factory()->create(['telegram_chat_id' => '200']);
        $operaio->assignRole('operaio');

        $this->postJson('/api/telegram/webhook', ['callback_query' => ['id' => 'cb2', 'data' => 'apri:'.$this->segnalazione->id_segnalazione, 'message' => ['chat' => ['id' => '200']]]],
            ['X-Telegram-Bot-Api-Secret-Token' => 's'])->assertOk();

        Http::assertNotSent(fn ($r) => str_contains((string) ($r['text'] ?? ''), 'Tombini ostruiti davanti alla scuola'));
    }

    public function test_collegamento_mostra_la_tastiera_fissa(): void
    {
        $nuovo = User::factory()->create(['telegram_link_token' => 'TOK', 'telegram_link_expires_at' => now()->addHour()]);
        $nuovo->assignRole('operaio');

        $this->postJson('/api/telegram/webhook', ['message' => ['chat' => ['id' => '300'], 'text' => '/start TOK']],
            ['X-Telegram-Bot-Api-Secret-Token' => 's'])->assertOk();

        Http::assertSent(function ($r) {
            $tasti = collect($r['reply_markup']['keyboard'] ?? [])->flatten(1)->map(fn ($t) => is_array($t) ? $t['text'] : $t);

            return str_ends_with($r->url(), '/sendMessage') && $tasti->contains('📋 Lista') && $tasti->contains('📅 Oggi');
        });
    }

    public function test_tastiera_fissa_su_due_righe_da_tre(): void
    {
        $this->scrivi('ciao');

        Http::assertSent(function ($r) {
            $righe = $r['reply_markup']['keyboard'] ?? [];
            $testi = collect($righe)->flatten(1)->pluck('text');

            return count($righe) === 2 && count($righe[0]) === 3 && count($righe[1]) === 3
                && $testi->contains('⏰ Scadenze') && $testi->contains('🔍 Cerca n°') && $testi->contains('❓ Aiuto');
        });
    }

    public function test_scadenze_mostra_scadute_ed_entro_24_ore_non_le_altre(): void
    {
        $this->segnalazione->update(['data_scadenza_sla' => now()->subHour()]);
        $vicina = Segnalazione::factory()->create(['id_stato_segnalazione' => 2, 'data_scadenza_sla' => now()->addHours(5)]);
        $lontana = Segnalazione::factory()->create(['id_stato_segnalazione' => 2, 'data_scadenza_sla' => now()->addDays(5)]);

        $this->scrivi('⏰ Scadenze');

        $dati = collect($this->pulsantiInviati())->pluck('callback_data')->values();
        $this->assertSame(['apri:'.$this->segnalazione->id_segnalazione, 'apri:'.$vicina->id_segnalazione], $dati->all());
        $this->assertNotContains('apri:'.$lontana->id_segnalazione, $dati);
    }

    public function test_cerca_chiede_il_numero_e_il_numero_apre_la_segnalazione(): void
    {
        $this->scrivi('🔍 Cerca n°');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && str_contains((string) $r['text'], 'numero'));

        $this->scrivi('#'.$this->segnalazione->id_segnalazione);
        Http::assertSent(fn ($r) => str_contains((string) ($r['text'] ?? ''), 'Tombini ostruiti davanti alla scuola'));
    }

    public function test_aiuto_spiega_i_tasti(): void
    {
        $this->scrivi('❓ Aiuto');

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains((string) $r['text'], 'Scadenze') && str_contains((string) $r['text'], 'foto'));
    }

    public function test_start_da_account_gia_collegato_manda_la_tastiera(): void
    {
        $this->scrivi('/start');

        Http::assertNotSent(fn ($r) => str_contains((string) ($r['text'] ?? ''), 'non collegato'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && isset($r['reply_markup']['keyboard']));
    }
}
