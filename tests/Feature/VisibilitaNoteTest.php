<?php

namespace Tests\Feature;

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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Le note interne restano al comune: la ditta vede solo le note "visibile impresa"
 * (più quelle scritte dalla propria ditta), il segnalante solo quelle "visibile web"
 * (più le proprie). Vale per scheda, stampa, fascicolo PDF e Telegram.
 */
class VisibilitaNoteTest extends TestCase
{
    use RefreshDatabase;

    private Segnalazione $segnalazione;

    private User $segnalatore;

    private User $ditta;

    private User $collegaDitta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);

        $impresa = Impresa::create(['ragione_sociale' => 'Ditta']);
        $appalto = Appalto::create(['id_gruppo' => 1, 'descrizione' => 'A', 'id_impresa' => $impresa->id_impresa, 'valido' => true]);

        $this->segnalatore = User::factory()->create();
        $this->segnalatore->assignRole('segnalatore');
        $this->ditta = User::factory()->create(['auth_source' => 'spid', 'id_impresa' => $impresa->id_impresa, 'email_verified_at' => now()]);
        $this->ditta->assignRole('impresa');
        $this->collegaDitta = User::factory()->create(['auth_source' => 'spid', 'id_impresa' => $impresa->id_impresa, 'email_verified_at' => now()]);
        $this->collegaDitta->assignRole('impresa');
        $gestore = User::factory()->create(['supervisore_segnalazioni' => true]);
        $gestore->assignRole('gestore');

        $this->segnalazione = Segnalazione::factory()->create([
            'id_utente_segnalazione' => $this->segnalatore->id,
            'id_appalto' => $appalto->id_appalto,
            'id_stato_segnalazione' => 4,
        ]);

        $nota = fn (string $testo, User $autore, bool $web, bool $impresa) => NotaSegnalazione::create([
            'id_segnalazione' => $this->segnalazione->id_segnalazione, 'testo' => $testo,
            'id_utente' => $autore->id, 'visibile_web' => $web, 'visibile_impresa' => $impresa,
        ]);
        $nota('NOTA-INTERNA', $gestore, false, false);
        $nota('NOTA-PER-DITTA', $gestore, false, true);
        $nota('NOTA-PER-SEGNALANTE', $gestore, true, false);
        $nota('NOTA-DELLA-DITTA', $this->collegaDitta, false, false);
        $nota('NOTA-DEL-SEGNALANTE', $this->segnalatore, false, false);
    }

    public function test_ditta_vede_solo_note_per_ditta_e_della_propria_ditta(): void
    {
        $this->actingAs($this->ditta)->get(route('segnalazioni.show', $this->segnalazione))
            ->assertOk()
            ->assertSee('NOTA-PER-DITTA')
            ->assertSee('NOTA-DELLA-DITTA')
            ->assertDontSee('NOTA-INTERNA')
            ->assertDontSee('NOTA-PER-SEGNALANTE')
            ->assertDontSee('NOTA-DEL-SEGNALANTE');
    }

    public function test_segnalante_vede_solo_note_pubbliche_e_proprie(): void
    {
        $this->actingAs($this->segnalatore)->get(route('segnalazioni.show', $this->segnalazione))
            ->assertOk()
            ->assertSee('NOTA-PER-SEGNALANTE')
            ->assertSee('NOTA-DEL-SEGNALANTE')
            ->assertDontSee('NOTA-INTERNA')
            ->assertDontSee('NOTA-PER-DITTA')
            ->assertDontSee('NOTA-DELLA-DITTA');
    }

    public function test_personale_del_comune_vede_tutte_le_note(): void
    {
        $gestore = User::role('gestore')->firstOrFail();

        $risposta = $this->actingAs($gestore)->get(route('segnalazioni.show', $this->segnalazione))->assertOk();
        foreach (['NOTA-INTERNA', 'NOTA-PER-DITTA', 'NOTA-PER-SEGNALANTE', 'NOTA-DELLA-DITTA', 'NOTA-DEL-SEGNALANTE'] as $testo) {
            $risposta->assertSee($testo);
        }
    }

    public function test_stampa_scheda_della_ditta_esclude_note_interne(): void
    {
        $this->actingAs($this->ditta)->get(route('segnalazioni.stampa', $this->segnalazione))
            ->assertOk()
            ->assertSee('NOTA-PER-DITTA')
            ->assertDontSee('NOTA-INTERNA');
    }

    public function test_fascicolo_pdf_della_ditta_esclude_note_interne(): void
    {
        $note = null;
        Event::listen('composing: segnalazioni.fascicolo-pdf', function ($view) use (&$note) {
            $note = $view->getData()['segnalazione']->note->pluck('testo')->all();
        });

        $this->actingAs($this->ditta)->get(route('segnalazioni.fascicolo-pdf', $this->segnalazione))->assertOk();

        $this->assertContains('NOTA-PER-DITTA', $note);
        $this->assertNotContains('NOTA-INTERNA', $note);
    }

    public function test_telegram_note_del_segnalante_esclude_note_interne(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        Impostazione::set('telegram_bot_token', '123456:telegram-test-token');
        Impostazione::set('telegram_webhook_secret', 'secret-token');
        $this->segnalatore->update(['telegram_chat_id' => '445566', 'telegram_verified_at' => now()]);

        $this->postJson('/api/telegram/webhook', [
            'message' => ['chat' => ['id' => '445566'], 'text' => '/note ' . $this->segnalazione->id_segnalazione],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'secret-token'])->assertOk();

        Http::assertSent(fn ($request) => Str::contains($request->url(), 'sendMessage')
            && Str::contains((string) $request['text'], 'NOTA-PER-SEGNALANTE')
            && ! Str::contains((string) $request['text'], 'NOTA-INTERNA'));
    }
}
