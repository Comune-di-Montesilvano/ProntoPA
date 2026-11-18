<?php

namespace Tests\Feature;

use App\Channels\TelegramChannel;
use App\Models\AllegatoSegnalazione;
use App\Models\Appalto;
use App\Models\Azione;
use App\Models\Impostazione;
use App\Models\Impresa;
use App\Models\Istituto;
use App\Models\CategoriaEnte;
use App\Models\Plesso;
use App\Models\NotaSegnalazione;
use App\Models\Segnalazione;
use App\Models\Squadra;
use App\Models\User;
use App\Notifications\OperatoreAssegnatoNotification;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** 1.0.15: iter normale di ditte e operai dal bot (avviso → apri → foto → fine lavori / preventivo / smista) e chiusura con nota e foto. */
class TelegramIterLavoroTest extends TestCase
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

    private function foto(string $chat): void
    {
        $this->postJson('/api/telegram/webhook', ['message' => ['chat' => ['id' => $chat], 'photo' => [['file_id' => 'x']]]],
            ['X-Telegram-Bot-Api-Secret-Token' => 's'])->assertOk();
    }

    private function premi(string $chat, string $dati): void
    {
        $this->postJson('/api/telegram/webhook', ['callback_query' => ['id' => 'cb', 'data' => $dati, 'message' => ['chat' => ['id' => $chat]]]],
            ['X-Telegram-Bot-Api-Secret-Token' => 's'])->assertOk();
    }

    /** @return \Illuminate\Support\Collection<int, string> */
    private function pulsanti(): \Illuminate\Support\Collection
    {
        return Http::recorded()
            ->filter(fn ($c) => str_ends_with($c[0]->url(), '/sendMessage'))
            ->flatMap(fn ($c) => collect($c[0]['reply_markup']['inline_keyboard'] ?? [])->flatten(1)->pluck('callback_data'))
            ->values();
    }

    private function operaio(string $chat = '200'): User
    {
        $operaio = User::factory()->create(['telegram_chat_id' => $chat]);
        $operaio->assignRole('operaio');

        return $operaio;
    }

    private function ditta(): array
    {
        $impresa = Impresa::create(['ragione_sociale' => 'Ditta Bot', 'codice_fiscale' => '12345678901', 'partita_iva' => '12345678901', 'email' => 'd@example.com']);
        $utente = User::factory()->create(['id_impresa' => $impresa->id_impresa, 'telegram_chat_id' => '300']);
        $utente->assignRole('impresa');
        $appalto = Appalto::create(['id_impresa' => $impresa->id_impresa, 'descrizione' => 'Manutenzione', 'valido' => true]);

        return [$utente, $appalto];
    }

    public function test_avviso_di_assegnazione_ha_il_pulsante_per_aprire_la_segnalazione(): void
    {
        $operaio = $this->operaio();
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 3, 'id_operatore_assegnato' => $operaio->id]);

        app(TelegramChannel::class)->send($operaio, new OperatoreAssegnatoNotification($s, Azione::where('codice', 'assegna_operatore')->first(), $this->gestore));

        $this->assertTrue($this->pulsanti()->contains('apri:'.$s->id_segnalazione));
    }

    public function test_aggiungi_foto_senza_didascalia_dopo_il_pulsante(): void
    {
        $operaio = $this->operaio();
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 3, 'id_operatore_assegnato' => $operaio->id]);
        $id = $s->id_segnalazione;

        $this->premi('200', "apri:$id");
        $this->assertTrue($this->pulsanti()->contains("txt:$id:foto"));

        $this->premi('200', "txt:$id:foto");
        $this->foto('200');
        $this->foto('200');

        $this->assertSame(2, AllegatoSegnalazione::where('id_segnalazione', $id)->count());
    }

    public function test_operaio_fine_lavori_con_foto_chiude(): void
    {
        $operaio = $this->operaio();
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 3, 'id_operatore_assegnato' => $operaio->id]);
        $id = $s->id_segnalazione;

        $this->premi('200', "txt:$id:fl");
        $this->foto('200');
        $this->scrivi('200', 'Riparato il rubinetto');

        $this->assertSame(7, $s->refresh()->statoEnum()->value);
        $this->assertSame(1, AllegatoSegnalazione::where('id_segnalazione', $id)->where('fase', 'dopo')->count());
    }

    public function test_gestore_chiude_con_nota_e_foto(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 2, 'id_operatore_assegnato' => $this->gestore->id]);
        $id = $s->id_segnalazione;

        $this->premi('100', "apri:$id");
        $this->assertTrue($this->pulsanti()->contains("chi:$id"));

        $this->premi('100', "chi:$id");
        $this->assertSame(2, $s->refresh()->statoEnum()->value);
        $this->foto('100');
        $this->scrivi('100', 'Sistemato dal nostro personale');

        $this->assertSame(7, $s->refresh()->statoEnum()->value);
        $this->assertSame(1, AllegatoSegnalazione::where('id_segnalazione', $id)->where('fase', 'dopo')->count());
        $this->assertTrue(NotaSegnalazione::where('id_segnalazione', $id)->where('testo', 'like', '%Sistemato dal nostro personale%')->exists());
    }

    public function test_gestore_chiude_subito_senza_nota(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 2]);

        $this->premi('100', "chi:{$s->id_segnalazione}");
        $this->premi('100', "chn:{$s->id_segnalazione}");

        $this->assertSame(7, $s->refresh()->statoEnum()->value);
    }

    public function test_ditta_presenta_preventivo(): void
    {
        [, $appalto] = $this->ditta();
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 4, 'id_appalto' => $appalto->id_appalto]);
        $id = $s->id_segnalazione;

        $this->premi('300', "apri:$id");
        $this->assertTrue($this->pulsanti()->contains("txt:$id:prev"));

        $this->premi('300', "txt:$id:prev");
        $this->scrivi('300', '1.250,50 sostituzione caldaia e pezzi');

        $s->refresh();
        $this->assertSame(5, $s->statoEnum()->value);
        $this->assertEquals(1250.50, (float) $s->importo_preventivo);
    }

    public function test_preventivo_senza_importo_richiesto_di_nuovo(): void
    {
        [, $appalto] = $this->ditta();
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 4, 'id_appalto' => $appalto->id_appalto]);

        $this->premi('300', "txt:{$s->id_segnalazione}:prev");
        $this->scrivi('300', 'costa parecchio');

        $this->assertSame(4, $s->refresh()->statoEnum()->value);
        $this->scrivi('300', '300 materiali');
        $this->assertSame(5, $s->refresh()->statoEnum()->value);
    }

    public function test_caposquadra_smista_a_un_membro(): void
    {
        Impostazione::set('squadre_enabled', true);
        $capo = $this->operaio('200');
        $membro = $this->operaio('201');
        $squadra = Squadra::create(['nome' => 'Elettricisti', 'id_caposquadra' => $capo->id, 'attiva' => true]);
        $squadra->membri()->attach([$capo->id, $membro->id]);
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 3, 'id_squadra_assegnata' => $squadra->id_squadra, 'id_operatore_assegnato' => $capo->id]);
        $id = $s->id_segnalazione;

        $this->premi('200', "apri:$id");
        $this->assertTrue($this->pulsanti()->contains("smi:$id"));
        $this->premi('200', "smi:$id");
        $this->assertTrue($this->pulsanti()->contains("sms:$id:{$membro->id}"));

        $this->premi('200', "sms:$id:{$membro->id}");

        $this->assertSame($membro->id, (int) $s->refresh()->id_operatore_assegnato);
    }

    public function test_membro_non_caposquadra_non_smista(): void
    {
        Impostazione::set('squadre_enabled', true);
        $capo = $this->operaio('200');
        $membro = $this->operaio('201');
        $squadra = Squadra::create(['nome' => 'Elettricisti', 'id_caposquadra' => $capo->id, 'attiva' => true]);
        $squadra->membri()->attach([$capo->id, $membro->id]);
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 3, 'id_squadra_assegnata' => $squadra->id_squadra, 'id_operatore_assegnato' => $capo->id]);

        $this->premi('201', "sms:{$s->id_segnalazione}:{$membro->id}");

        $this->assertSame($capo->id, (int) $s->refresh()->id_operatore_assegnato);
    }

    /** @return array{0: Segnalazione, 1: string} segnalazione in una sede con referente, testo del dettaglio inviato */
    private function dettaglioDi(string $chat, array $extra = []): array
    {
        $ente = Istituto::create(['descrizione' => 'IC RODARI', 'id_categoria' => CategoriaEnte::factory()->create()->id_categoria, 'attivo' => true]);
        $plesso = Plesso::create(['id_istituto' => $ente->id_istituto, 'nome' => 'Plesso Rodari', 'indirizzo' => 'Via Roma 1',
            'referente' => 'Anna Bianchi', 'recapiti' => '085 1234567; 333 9876543']);
        $autore = User::factory()->create(['name' => 'Luca Verdi', 'email' => 'luca.verdi@scuola.it', 'telefono' => '347 1112223']);
        $s = Segnalazione::factory()->create(array_merge(['id_plesso' => $plesso->id_plesso, 'id_utente_segnalazione' => $autore->id], $extra));

        $this->premi($chat, 'apri:'.$s->id_segnalazione);
        $testo = (string) Http::recorded()->map(fn ($c) => $c[0])
            ->last(fn ($r) => str_ends_with($r->url(), '/sendMessage') && str_contains((string) $r['text'], 'Segnalazione #'))['text'];

        return [$s, $testo];
    }

    public function test_dettaglio_per_il_comune_con_chi_ha_aperto_e_contatti(): void
    {
        [, $testo] = $this->dettaglioDi('100', ['id_stato_segnalazione' => 2]);

        $this->assertStringContainsString('Luca Verdi', $testo);
        $this->assertStringContainsString('347 1112223', $testo);
        $this->assertStringContainsString('luca.verdi@scuola.it', $testo);
        $this->assertStringContainsString('Anna Bianchi', $testo);
        $this->assertStringContainsString('085 1234567; 333 9876543', $testo);
        $this->assertStringContainsString('Via Roma 1', $testo);
    }

    public function test_dettaglio_per_la_ditta_con_referente_ma_senza_dati_di_chi_ha_aperto(): void
    {
        [, $appalto] = $this->ditta();
        [, $testo] = $this->dettaglioDi('300', ['id_stato_segnalazione' => 4, 'id_appalto' => $appalto->id_appalto]);

        $this->assertStringContainsString('Anna Bianchi', $testo);
        $this->assertStringContainsString('085 1234567', $testo);
        $this->assertStringNotContainsString('luca.verdi@scuola.it', $testo);
        $this->assertStringNotContainsString('347 1112223', $testo);
    }

    private function allegato(Segnalazione $s, string $fase, string $stato = 'pulito'): void
    {
        $percorso = 'allegati/'.$s->id_segnalazione.'/'.uniqid().'.jpg';
        Storage::disk('local')->put($percorso, 'jpeg-'.$fase);
        AllegatoSegnalazione::create(['id_segnalazione' => $s->id_segnalazione, 'percorso' => $percorso, 'tipo' => 'image/jpeg',
            'nome_originale' => 'f.jpg', 'dimensione' => 10, 'id_utente_creazione' => $this->gestore->id, 'fase' => $fase, 'stato_scansione' => $stato]);
    }

    public function test_dettaglio_manda_le_foto_in_album(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 2]);
        $this->allegato($s, 'prima');
        $this->allegato($s, 'dopo');
        $this->allegato($s, 'prima', 'infetto');

        // Il dettaglio non le manda da solo: solo il tasto, con il numero delle foto.
        $this->premi('100', 'apri:'.$s->id_segnalazione);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendMediaGroup'));
        $this->assertTrue($this->pulsanti()->contains('fot:'.$s->id_segnalazione));
        $this->assertTrue(Http::recorded()->contains(fn ($c) => str_contains(json_encode($c[0]['reply_markup'] ?? []), 'Vedi foto (2)')));

        $this->premi('100', 'fot:'.$s->id_segnalazione);

        Http::assertSent(function ($r) {
            if (! str_ends_with($r->url(), '/sendMediaGroup')) {
                return false;
            }
            $media = collect($r->data())->firstWhere('name', 'media')['contents'] ?? '[]';

            return count(json_decode($media, true)) === 2;
        });
    }

    public function test_dettaglio_con_una_foto_usa_send_photo(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 2]);
        $this->allegato($s, 'prima');

        $this->premi('100', 'fot:'.$s->id_segnalazione);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendPhoto'));
    }

    public function test_senza_foto_nessun_tasto(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 2]);

        $this->premi('100', 'apri:'.$s->id_segnalazione);

        $this->assertFalse($this->pulsanti()->contains('fot:'.$s->id_segnalazione));
    }

    public function test_lista_con_seconda_riga_di_dettagli(): void
    {
        $ente = Istituto::create(['descrizione' => 'IC RODARI', 'id_categoria' => CategoriaEnte::factory()->create()->id_categoria, 'attivo' => true]);
        $plesso = Plesso::create(['id_istituto' => $ente->id_istituto, 'nome' => 'Plesso Rodari', 'indirizzo' => 'Via Roma 1']);
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 2, 'id_plesso' => $plesso->id_plesso, 'titolo' => 'Neon rotto']);

        $this->scrivi('100', '/lista');

        Http::assertSent(function ($r) use ($s) {
            $testo = (string) ($r['text'] ?? '');

            return str_ends_with($r->url(), '/sendMessage')
                && preg_match('/#'.$s->id_segnalazione.' · Neon rotto\n.*Plesso Rodari.*In carico/u', $testo) === 1;
        });
    }
}
