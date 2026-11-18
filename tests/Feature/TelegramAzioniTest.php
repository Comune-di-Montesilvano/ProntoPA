<?php

namespace Tests\Feature;

use App\Models\AllegatoSegnalazione;
use App\Models\Appalto;
use App\Models\Impostazione;
use App\Models\Impresa;
use App\Models\NotaSegnalazione;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** 1.0.14: dal bot le stesse azioni della scheda (assegna, chiudi, senza intervento, fine lavori, rimanda, nota). */
class TelegramAzioniTest extends TestCase
{
    use RefreshDatabase;

    private User $gestore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        Impostazione::set('telegram_bot_token', '123:abc');
        Impostazione::set('telegram_webhook_secret', 's');
        Http::fake([
            'https://api.telegram.org/bot123:abc/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/f.jpg']]),
            'https://api.telegram.org/file/bot123:abc/photos/f.jpg' => Http::response('jpeg-finto', 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);
        Storage::fake('local');
        Notification::fake();
        Cache::flush();

        $this->gestore = User::factory()->create(['telegram_chat_id' => '100', 'supervisore_segnalazioni' => true, 'gestore_segnalazioni' => true]);
        $this->gestore->assignRole('gestore');
    }

    private function scrivi(string $chat, string $testo): void
    {
        $this->postJson('/api/telegram/webhook', ['message' => ['chat' => ['id' => $chat], 'text' => $testo]],
            ['X-Telegram-Bot-Api-Secret-Token' => 's'])->assertOk();
    }

    private function premi(string $chat, string $dati): void
    {
        $this->postJson('/api/telegram/webhook', ['callback_query' => ['id' => 'cb', 'data' => $dati, 'message' => ['chat' => ['id' => $chat]]]],
            ['X-Telegram-Bot-Api-Secret-Token' => 's'])->assertOk();
    }

    /** @return \Illuminate\Support\Collection<int, string> callback_data dei pulsanti inviati */
    private function pulsanti(): \Illuminate\Support\Collection
    {
        return Http::recorded()
            ->filter(fn ($c) => str_ends_with($c[0]->url(), '/sendMessage'))
            ->flatMap(fn ($c) => collect($c[0]['reply_markup']['inline_keyboard'] ?? [])->flatten(1)->pluck('callback_data'))
            ->values();
    }

    private function ditta(): array
    {
        $impresa = Impresa::create(['ragione_sociale' => 'Ditta Bot', 'codice_fiscale' => '12345678901', 'partita_iva' => '12345678901', 'email' => 'd@example.com']);
        $utente = User::factory()->create(['id_impresa' => $impresa->id_impresa, 'telegram_chat_id' => '300']);
        $utente->assignRole('impresa');
        $appalto = Appalto::create(['id_impresa' => $impresa->id_impresa, 'descrizione' => 'Manutenzione', 'valido' => true]);

        return [$utente, $appalto];
    }

    public function test_segnalazione_nuova_mostra_le_azioni_del_comune(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 1]);

        $this->premi('100', 'apri:'.$s->id_segnalazione);

        $id = $s->id_segnalazione;
        $this->assertTrue($this->pulsanti()->contains("me:$id"));
        $this->assertTrue($this->pulsanti()->contains("asg:$id"));
        $this->assertTrue($this->pulsanti()->contains("si:$id"));
        $this->assertTrue($this->pulsanti()->contains("txt:$id:nota"));
    }

    public function test_assegna_a_me(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 1]);

        $this->premi('100', 'me:'.$s->id_segnalazione);

        $s->refresh();
        $this->assertSame(2, $s->statoEnum()->value);
        $this->assertSame($this->gestore->id, (int) $s->id_operatore_assegnato);
    }

    public function test_assegna_a_operaio_scegliendo_dall_elenco(): void
    {
        $operaio = User::factory()->create(['name' => 'Mario Operaio']);
        $operaio->assignRole('operaio');
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 2]);
        $id = $s->id_segnalazione;

        $this->premi('100', "asg:$id");
        $this->assertTrue($this->pulsanti()->contains("asl:$id:o:0"));
        $this->premi('100', "asl:$id:o:0");
        $this->assertTrue($this->pulsanti()->contains("ass:$id:o:{$operaio->id}"));

        $this->premi('100', "ass:$id:o:{$operaio->id}");

        $s->refresh();
        $this->assertSame(3, $s->statoEnum()->value);
        $this->assertSame($operaio->id, (int) $s->id_operatore_assegnato);
    }

    public function test_assegna_a_ditta(): void
    {
        [, $appalto] = $this->ditta();
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 2]);
        $id = $s->id_segnalazione;

        $this->premi('100', "asl:$id:d:0");
        $this->assertTrue($this->pulsanti()->contains("ass:$id:d:{$appalto->id_appalto}"));
        $this->premi('100', "ass:$id:d:{$appalto->id_appalto}");

        $s->refresh();
        $this->assertSame(4, $s->statoEnum()->value);
        $this->assertSame($appalto->id_appalto, (int) $s->id_appalto);
    }

    public function test_assegna_a_persona_non_assegnabile_rifiutata(): void
    {
        $segnalatore = User::factory()->create();
        $segnalatore->assignRole('segnalatore');
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 2]);

        $this->premi('100', "ass:{$s->id_segnalazione}:o:{$segnalatore->id}");

        $this->assertSame(2, $s->refresh()->statoEnum()->value);
    }

    public function test_chiudi_senza_intervento_con_motivo(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 1]);
        $id = $s->id_segnalazione;

        $this->premi('100', "si:$id");
        $this->assertTrue($this->pulsanti()->contains("sim:$id:gia_risolta"));
        $this->premi('100', "sim:$id:gia_risolta");

        $this->assertSame(9, $s->refresh()->statoEnum()->value);
    }

    public function test_senza_intervento_altro_chiede_il_motivo_scritto(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 1]);
        $id = $s->id_segnalazione;

        $this->premi('100', "sim:$id:altro");
        $this->assertSame(1, $s->refresh()->statoEnum()->value);

        $this->scrivi('100', 'Doppione di una segnalazione telefonica');

        $this->assertSame(9, $s->refresh()->statoEnum()->value);
        $this->assertTrue(NotaSegnalazione::where('id_segnalazione', $id)->where('testo', 'like', '%Doppione di una segnalazione telefonica%')->exists());
    }

    public function test_scrivi_nota_dal_pulsante(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 2]);
        $id = $s->id_segnalazione;

        $this->premi('100', "txt:$id:nota");
        $this->scrivi('100', 'Passato a vedere, serve scala');

        $this->assertTrue(NotaSegnalazione::where('id_segnalazione', $id)->where('testo', 'Passato a vedere, serve scala')->exists());
    }

    public function test_annulla_toglie_l_attesa_del_testo(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 2]);
        $id = $s->id_segnalazione;

        $this->premi('100', "txt:$id:nota");
        $this->premi('100', 'ann');
        $this->scrivi('100', 'testo qualsiasi');

        $this->assertFalse(NotaSegnalazione::where('id_segnalazione', $id)->where('testo', 'testo qualsiasi')->exists());
    }

    public function test_ditta_fine_lavori_con_foto_e_descrizione(): void
    {
        [$ditta, $appalto] = $this->ditta();
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 4, 'id_appalto' => $appalto->id_appalto]);
        $id = $s->id_segnalazione;

        $this->premi('300', "apri:$id");
        $this->assertTrue($this->pulsanti()->contains("txt:$id:fl"));
        $this->assertTrue($this->pulsanti()->contains("txt:$id:rim"));

        $this->premi('300', "txt:$id:fl");
        // Foto senza didascalia: va alla segnalazione di cui si sta comunicando la fine lavori.
        $this->postJson('/api/telegram/webhook', ['message' => ['chat' => ['id' => '300'], 'photo' => [['file_id' => 'x']]]],
            ['X-Telegram-Bot-Api-Secret-Token' => 's'])->assertOk();
        $this->scrivi('300', 'Sostituito il neon');

        $s->refresh();
        $this->assertSame(7, $s->statoEnum()->value);
        $this->assertNotNull($s->fine_lavori_at);
        $this->assertSame(1, AllegatoSegnalazione::where('id_segnalazione', $id)->where('fase', 'dopo')->count());
    }

    public function test_ditta_rimanda_al_comune(): void
    {
        [, $appalto] = $this->ditta();
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 4, 'id_appalto' => $appalto->id_appalto]);
        $id = $s->id_segnalazione;

        $this->premi('300', "txt:$id:rim");
        $this->scrivi('300', 'Non è di nostra competenza');

        $this->assertContains($s->refresh()->statoEnum()->value, [1, 2]);
    }

    public function test_ditta_non_puo_assegnare(): void
    {
        [, $appalto] = $this->ditta();
        $operaio = User::factory()->create();
        $operaio->assignRole('operaio');
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 4, 'id_appalto' => $appalto->id_appalto]);

        $this->premi('300', "ass:{$s->id_segnalazione}:o:{$operaio->id}");
        $this->premi('300', "me:{$s->id_segnalazione}");

        $s->refresh();
        $this->assertSame(4, $s->statoEnum()->value);
        $this->assertSame($appalto->id_appalto, (int) $s->id_appalto);
    }

    public function test_sollecita_chi_ha_il_lavoro(): void
    {
        [, $appalto] = $this->ditta();
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 4, 'id_appalto' => $appalto->id_appalto]);

        $this->premi('100', "apri:{$s->id_segnalazione}");
        $this->assertTrue($this->pulsanti()->contains("sol:{$s->id_segnalazione}"));
        $this->premi('100', "sol:{$s->id_segnalazione}");

        $this->assertTrue(NotaSegnalazione::where('id_segnalazione', $s->id_segnalazione)->where('origine', NotaSegnalazione::ORIGINE_SOLLECITO)->exists());
    }
}
