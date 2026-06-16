<?php

namespace Tests\Feature;

use App\Models\AllegatoSegnalazione;
use App\Models\Appalto;
use App\Models\Azione;
use App\Models\Impresa;
use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class DitteECanaliTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        Storage::fake('local');
        Storage::fake('public');
        \Illuminate\Support\Facades\Cache::flush();
    }

    private function gestore(): User
    {
        $user = User::factory()->create([
            'gestore_segnalazioni' => true,
            'supervisore_segnalazioni' => true,
        ]);
        $user->assignRole('gestore');
        return $user;
    }

    private function impresaUser(): User
    {
        $impresa = Impresa::create([
            'ragione_sociale' => 'Ditta Test S.r.l.',
            'codice_fiscale' => '12345678901',
            'partita_iva' => '12345678901',
            'email' => 'ditta@example.com',
        ]);

        $user = User::factory()->create([
            'id_impresa' => $impresa->id_impresa,
        ]);
        $user->assignRole('impresa');

        return $user;
    }

    // ── 1. TEST RAPPORTINO DI FINE LAVORO ──────────────────────────────────────

    public function test_proposta_chiusura_richiede_foto_e_campi_rapportino(): void
    {
        $impresaUser = $this->impresaUser();
        $appalto = Appalto::create([
            'id_impresa' => $impresaUser->id_impresa,
            'descrizione' => 'Appalto Test',
            'importo_appalto' => 10000.00,
            'valido' => true,
            'id_gruppo' => 1,
        ]);

        $segnalazione = Segnalazione::factory()->create([
            'id_appalto' => $appalto->id_appalto,
            'id_stato_segnalazione' => 7, // ASSEGNATA AD IMPRESA
        ]);

        // 1. Invio senza dati e senza foto -> fallisce
        $response = $this->actingAs($impresaUser)->post(route('segnalazioni.azione', $segnalazione), [
            'id_azione' => 6, // PROPONE CHIUSURA
        ]);

        $response->assertSessionHasErrors(['ore_lavoro', 'materiali', 'nota', 'allegati']);

        // 2. Invio con dati completi e foto -> ha successo
        $response = $this->actingAs($impresaUser)->post(route('segnalazioni.azione', $segnalazione), [
            'id_azione' => 6,
            'ore_lavoro' => 4.5,
            'materiali' => 'Miscelatore, guarnizioni, canapa',
            'nota' => 'Sostituito il rubinetto che perdeva.',
            'allegati' => [UploadedFile::fake()->image('dopo.jpg')],
        ]);

        $response->assertRedirect();
        
        $segnalazione->refresh();
        $this->assertEquals(10, $segnalazione->id_stato_segnalazione); // ATTESA COLLAUDO

        // Verifica che la foto sia stata salvata con fase 'dopo'
        $allegato = AllegatoSegnalazione::where('id_segnalazione', $segnalazione->id_segnalazione)->first();
        $this->assertNotNull($allegato);
        $this->assertEquals('dopo', $allegato->fase);

        // Verifica che la nota contenga ore e materiali
        $nota = $segnalazione->note()->first();
        $this->assertNotNull($nota);
        $this->assertStringContainsString('Rapportino di fine lavoro:', $nota->testo);
        $this->assertStringContainsString('Ore impiegate: 4.5', $nota->testo);
        $this->assertStringContainsString('Materiali usati: Miscelatore, guarnizioni, canapa', $nota->testo);
        $this->assertStringContainsString('Sostituito il rubinetto che perdeva.', $nota->testo);
    }

    public function test_gestore_puo_rifiutare_chiusura_rapportino(): void
    {
        $gestore = $this->gestore();
        $segnalazione = Segnalazione::factory()->create([
            'id_stato_segnalazione' => 10, // ATTESA COLLAUDO
        ]);

        $response = $this->actingAs($gestore)->post(route('segnalazioni.azione', $segnalazione), [
            'id_azione' => 12, // RIFIUTA CHIUSURA
            'nota' => 'Intervento non eseguito a regola d\'arte. La perdita persiste.',
        ]);

        $response->assertRedirect();

        $segnalazione->refresh();
        $this->assertEquals(7, $segnalazione->id_stato_segnalazione); // ASSEGNATA AD IMPRESA
        
        $nota = $segnalazione->note()->first();
        $this->assertNotNull($nota);
        $this->assertStringContainsString('Intervento non eseguito a regola d\'arte', $nota->testo);
    }

    // ── 2. TEST PREVENTIVO ───────────────────────────────────────────────────

    public function test_presentazione_preventivo_richiede_e_salva_importo(): void
    {
        $impresaUser = $this->impresaUser();
        $appalto = Appalto::create([
            'id_impresa' => $impresaUser->id_impresa,
            'descrizione' => 'Appalto Preventivo',
            'importo_appalto' => 5000.00,
            'valido' => true,
            'id_gruppo' => 1,
        ]);
        $segnalazione = Segnalazione::factory()->create([
            'id_appalto' => $appalto->id_appalto,
            'id_stato_segnalazione' => 7,
        ]);

        // 1. Invio senza importo -> fallisce
        $response = $this->actingAs($impresaUser)->post(route('segnalazioni.azione', $segnalazione), [
            'id_azione' => 4, // PRESENTA PREVENTIVO
        ]);
        $response->assertSessionHasErrors(['importo_preventivo']);

        // 2. Invio con importo -> ha successo e lo salva
        $response = $this->actingAs($impresaUser)->post(route('segnalazioni.azione', $segnalazione), [
            'id_azione' => 4,
            'importo_preventivo' => 450.50,
            'nota' => 'Preventivo per sostituzione infisso.',
        ]);

        $response->assertRedirect();
        
        $segnalazione->refresh();
        $this->assertEquals(9, $segnalazione->id_stato_segnalazione); // IN APPROVAZIONE PREVENTIVO
        $this->assertEquals(450.50, $segnalazione->importo_preventivo);
    }

    // ── 3. TEST MAGIC LINK ───────────────────────────────────────────────────

    public function test_magic_link_mostra_scheda_e_permette_azione_senza_login(): void
    {
        $impresaUser = $this->impresaUser();
        $appalto = Appalto::create([
            'id_impresa' => $impresaUser->id_impresa,
            'descrizione' => 'Appalto Magic',
            'importo_appalto' => 5000.00,
            'valido' => true,
            'id_gruppo' => 1,
        ]);

        $segnalazione = Segnalazione::factory()->create([
            'id_appalto' => $appalto->id_appalto,
            'id_stato_segnalazione' => 7,
        ]);

        // Genera URL firmato temporaneo
        $url = URL::temporarySignedRoute(
            'magic-link.show',
            now()->addHours(1),
            ['segnalazione' => $segnalazione->id_segnalazione]
        );

        // 1. Accesso GET senza login -> successo (200)
        $response = $this->get($url);
        $response->assertOk();
        $response->assertViewHas('segnalazione');

        // 2. Accesso con URL non firmato -> fallisce (403)
        $unsignedUrl = route('magic-link.show', $segnalazione);
        $this->get($unsignedUrl)->assertForbidden();

        // 3. Invio POST azione con URL firmato -> successo
        $postUrl = URL::temporarySignedRoute(
            'magic-link.azione',
            now()->addHours(1),
            ['segnalazione' => $segnalazione->id_segnalazione]
        );

        $response = $this->post($postUrl, [
            'id_azione' => 6, // PROPONE CHIUSURA
            'ore_lavoro' => 3.0,
            'materiali' => 'Sifone',
            'nota' => 'Rapportino caricato via magic link.',
            'allegati' => [UploadedFile::fake()->image('dopo.jpg')],
        ]);

        $response->assertRedirect();
        
        $segnalazione->refresh();
        $this->assertEquals(10, $segnalazione->id_stato_segnalazione); // ATTESA COLLAUDO
        
        $allegato = AllegatoSegnalazione::where('id_segnalazione', $segnalazione->id_segnalazione)->first();
        $this->assertNotNull($allegato);
        $this->assertEquals('dopo', $allegato->fase);
    }

    // ── 4. TEST TELEGRAM OPERATIVO ───────────────────────────────────────────

    public function test_telegram_comando_oggi(): void
    {
        $impresaUser = $this->impresaUser();
        $impresaUser->update(['telegram_chat_id' => '12345678']);

        $appalto = Appalto::create([
            'id_impresa' => $impresaUser->id_impresa,
            'descrizione' => 'Appalto Tel',
            'importo_appalto' => 5000.00,
            'valido' => true,
            'id_gruppo' => 1,
        ]);

        // Crea due segnalazioni aperte sotto questo appalto
        Segnalazione::factory()->create([
            'id_appalto' => $appalto->id_appalto,
            'id_stato_segnalazione' => 7,
            'testo_segnalazione' => 'Lavoro oggi 1',
            'livello_priorita' => 3,
        ]);
        Segnalazione::factory()->create([
            'id_appalto' => $appalto->id_appalto,
            'id_stato_segnalazione' => 7,
            'testo_segnalazione' => 'Lavoro oggi 2',
            'livello_priorita' => 4, // Priorità maggiore, deve venire prima
        ]);

        Impostazione::set('telegram_webhook_secret', 'my-secret');
        Impostazione::set('telegram_bot_token', 'my-token');

        // Mocks
        Http::fake([
            'https://api.telegram.org/botmy-token/sendMessage' => Http::response(['ok' => true]),
        ]);

        $response = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'my-secret')
            ->postJson('/api/telegram/webhook', [
                'message' => [
                    'chat' => ['id' => 12345678],
                    'text' => '/oggi',
                ],
            ]);

        $response->assertOk();

        // Verifica che le segnalazioni siano state inviate, ordinando per priorità
        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), 'sendMessage')) {
                return false;
            }
            return str_contains($request['text'], 'Lavori per priorità:')
                && str_contains($request['text'], 'Priorità: Critica')
                && str_contains($request['text'], 'Priorità: Alta');
        });
    }

    public function test_telegram_carica_foto_con_caption_id(): void
    {
        $impresaUser = $this->impresaUser();
        $impresaUser->update(['telegram_chat_id' => '12345678']);

        $appalto = Appalto::create([
            'id_impresa' => $impresaUser->id_impresa,
            'descrizione' => 'Appalto Tel Photo',
            'importo_appalto' => 5000.00,
            'valido' => true,
            'id_gruppo' => 1,
        ]);

        $segnalazione = Segnalazione::factory()->create([
            'id_appalto' => $appalto->id_appalto,
            'id_stato_segnalazione' => 7,
        ]);

        Impostazione::set('telegram_webhook_secret', 'my-secret');
        Impostazione::set('telegram_bot_token', 'my-token');

        // Mocks delle chiamate API di Telegram
        Http::fake([
            'https://api.telegram.org/botmy-token/getFile*' => Http::response([
                'ok' => true,
                'result' => ['file_path' => 'photos/file_0.jpg'],
            ]),
            'https://api.telegram.org/file/botmy-token/photos/file_0.jpg' => Http::response('fake-image-binary', 200),
            'https://api.telegram.org/botmy-token/sendMessage' => Http::response(['ok' => true]),
        ]);

        $response = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'my-secret')
            ->postJson('/api/telegram/webhook', [
                'message' => [
                    'chat' => ['id' => 12345678],
                    'caption' => 'Risolto problema per #' . $segnalazione->id_segnalazione,
                    'photo' => [
                        ['file_id' => 'photo_id_small', 'file_size' => 100],
                        ['file_id' => 'photo_id_large', 'file_size' => 2000], // Più grande, deve essere scaricato questo
                    ],
                ],
            ]);

        $response->assertOk();

        // Verifica che la foto sia stata scaricata e salvata
        $allegato = AllegatoSegnalazione::where('id_segnalazione', $segnalazione->id_segnalazione)->first();
        $this->assertNotNull($allegato);
        $this->assertEquals('dopo', $allegato->fase); // Impresa -> dopo
        
        Storage::disk('local')->assertExists($allegato->percorso);
        $this->assertEquals('fake-image-binary', Storage::disk('local')->get($allegato->percorso));

        // Verifica che sia stato inviato il messaggio di successo con il magic link
        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), 'sendMessage')) {
                return false;
            }
            return str_contains($request['text'], 'Foto caricata con successo')
                && str_contains($request['text'], '/magic/segnalazione/');
        });
    }
}
