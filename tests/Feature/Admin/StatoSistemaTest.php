<?php

namespace Tests\Feature\Admin;

use App\Models\Delega;
use App\Models\Impostazione;
use App\Models\User;
use App\Services\Admin\Riquadro;
use App\Services\Admin\StatoSistema;
use App\Services\Auth\EsitoSyncAd;
use App\Services\Backup\CatalogoBackup;
use App\Services\Directory\Directory;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FakeDirectory;
use Tests\TestCase;

/** Home admin: cosa va bene, cosa no, cosa non si può sapere. Mai chiamate di rete. */
class StatoSistemaTest extends TestCase
{
    use RefreshDatabase;

    private FakeDirectory $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        Storage::fake('local');
        Cache::flush();
        $this->dir = new FakeDirectory();
        $this->app->instance(Directory::class, $this->dir);
    }

    private function riquadro(string $chiave): ?Riquadro
    {
        foreach (app(StatoSistema::class)->riquadri() as $r) {
            if ($r->chiave === $chiave) {
                return $r;
            }
        }

        return null;
    }

    private function esito(string $stato, array $extra = [], ?\DateTimeInterface $quando = null): void
    {
        Impostazione::set('ldap_sync_esito', json_encode((new EsitoSyncAd($stato, \Illuminate\Support\Carbon::instance($quando ?? now()), ...$extra))->toArray()));
    }

    public function test_sync_fallita_rossa(): void
    {
        $this->esito(EsitoSyncAd::FALLITA, ['motivo' => 'AD giù']);

        $this->assertSame(Riquadro::PROBLEMA, $this->riquadro('sync')->stato);
        $this->assertStringContainsString('AD giù', $this->riquadro('sync')->messaggio);
    }

    public function test_sync_senza_esito_o_vecchia_rossa(): void
    {
        $this->assertSame(Riquadro::PROBLEMA, $this->riquadro('sync')->stato);

        $this->esito(EsitoSyncAd::OK, [], now()->subHours(40));
        $this->assertSame(Riquadro::PROBLEMA, $this->riquadro('sync')->stato);
    }

    public function test_sync_ok_verde_e_con_errori_gialla(): void
    {
        $this->esito(EsitoSyncAd::OK);
        $this->assertSame(Riquadro::OK, $this->riquadro('sync')->stato);

        $this->esito(EsitoSyncAd::OK, ['senzaEmail' => 2]);
        $this->assertSame(Riquadro::ATTENZIONE, $this->riquadro('sync')->stato);
        $this->assertStringContainsString('2 senza email', $this->riquadro('sync')->messaggio);

        $this->esito(EsitoSyncAd::BLOCCATA);
        $this->assertSame(Riquadro::ATTENZIONE, $this->riquadro('sync')->stato);
    }

    public function test_sync_non_configurata_assente(): void
    {
        $this->dir->sync = false;

        $this->assertNull($this->riquadro('sync'));
    }

    public function test_backup_in_allarme_rosso(): void
    {
        $this->mock(CatalogoBackup::class, fn ($m) => $m->shouldReceive('inAllarme')->andReturn(true));

        $this->assertSame(Riquadro::PROBLEMA, $this->riquadro('backup')->stato);

        $this->mock(CatalogoBackup::class, fn ($m) => $m->shouldReceive('inAllarme')->andReturn(false));
        $this->assertSame(Riquadro::OK, $this->riquadro('backup')->stato);
    }

    public function test_spid_con_problemi_rosso_e_assente_se_mai_configurato(): void
    {
        $this->assertNull($this->riquadro('spid'));

        Impostazione::set('oidc_issuer', 'https://login.ente.it');

        $this->assertSame(Riquadro::PROBLEMA, $this->riquadro('spid')->stato);
        $this->assertStringContainsString('Client ID mancante.', $this->riquadro('spid')->messaggio);
    }

    public function test_email_senza_mittente_rossa(): void
    {
        Impostazione::set('mail_from_address', '');
        $this->assertSame(Riquadro::PROBLEMA, $this->riquadro('email')->stato);

        Impostazione::set('mail_from_address', 'noreply@ente.it');
        $this->assertSame(Riquadro::OK, $this->riquadro('email')->stato);
    }

    public function test_miur_mai_scaricato_o_vecchio_gialla(): void
    {
        $this->assertSame(Riquadro::ATTENZIONE, $this->riquadro('miur')->stato);

        app(\App\Services\Scuole\AnagrafeMiur::class)->salvaStato(['scaricato_at' => now()->subDays(400)->toIso8601String()]);
        $this->assertSame(Riquadro::ATTENZIONE, $this->riquadro('miur')->stato);

        app(\App\Services\Scuole\AnagrafeMiur::class)->salvaStato(['scaricato_at' => now()->subDays(10)->toIso8601String()]);
        $this->assertSame(Riquadro::OK, $this->riquadro('miur')->stato);
    }

    public function test_webhook_non_verificabile_solo_se_configurato(): void
    {
        $this->assertNull($this->riquadro('webhook'));

        Impostazione::set('webhook_cittadini_url', 'https://sito.ente.it/hook');
        $this->assertSame(Riquadro::NON_VERIFICABILE, $this->riquadro('webhook')->stato);
    }

    public function test_orfane_gialla_con_nome(): void
    {
        $nome = \App\Models\TipologiaSegnalazione::orderBy('descrizione')->first()->descrizione;

        $r = $this->riquadro('orfane');

        $this->assertSame(Riquadro::ATTENZIONE, $r->stato);
        $this->assertStringContainsString($nome, $r->messaggio);
    }

    public function test_deleghe_in_attesa_conta(): void
    {
        $this->assertSame(Riquadro::OK, $this->riquadro('deleghe')->stato);

        $istituto = \App\Models\Istituto::create(['id_categoria' => \App\Models\CategoriaEnte::factory()->create()->id_categoria, 'descrizione' => 'IC Prova', 'attivo' => true]);
        $u = User::factory()->create(['auth_source' => 'spid', 'codice_fiscale' => 'RSSMRA80A01G482X']);
        foreach (range(1, 2) as $i) {
            Delega::create(['user_id' => $u->id, 'codice_fiscale' => $u->codice_fiscale, 'id_istituto' => $istituto->id_istituto, 'stato' => Delega::RICHIESTA, 'gruppo_richiesta' => (string) Str::uuid()]);
        }

        $r = $this->riquadro('deleghe');
        $this->assertSame(Riquadro::ATTENZIONE, $r->stato);
        $this->assertStringContainsString('2 richieste', $r->messaggio);
    }

    public function test_ai_spenta_fra_le_spente(): void
    {
        $this->assertNull($this->riquadro('ai'));
        $this->assertContains('Assistente AI', app(StatoSistema::class)->spente());

        Impostazione::set('ai_enabled', '1');
        $this->assertNotContains('Assistente AI', app(StatoSistema::class)->spente());
    }

    public function test_ai_accesa_senza_prova_grigia(): void
    {
        Impostazione::set('ai_enabled', '1');

        $r = $this->riquadro('ai');
        $this->assertSame(Riquadro::NON_VERIFICABILE, $r->stato);
        $this->assertStringContainsString('Verifica dalla pagina Integrazioni', $r->messaggio);
    }

    public function test_esito_della_prova_in_cache_decide_il_colore(): void
    {
        Impostazione::set('telegram_bot_token', 'x');
        Cache::put('integrazioni:prova:telegram', ['ok' => false, 'messaggio' => 'Non risponde', 'quando' => now()->toIso8601String()], 600);
        $this->assertSame(Riquadro::PROBLEMA, $this->riquadro('telegram')->stato);

        Cache::put('integrazioni:prova:telegram', ['ok' => true, 'messaggio' => 'Collegato', 'quando' => now()->toIso8601String()], 600);
        $this->assertSame(Riquadro::OK, $this->riquadro('telegram')->stato);
    }

    public function test_home_non_chiama_la_rete(): void
    {
        Http::fake();
        Impostazione::set('ai_enabled', '1');
        Impostazione::set('antivirus_enabled', '1');
        Impostazione::set('telegram_bot_token', 'x');
        Impostazione::set('oidc_issuer', 'https://login.ente.it');
        Impostazione::set('oidc_client_id', 'prontopa');
        $admin = User::factory()->create(['attivo' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Stato del sistema')
            ->assertSee('Verifica dalla pagina Integrazioni');

        Http::assertNothingSent();
    }

    public function test_home_mostra_le_funzioni_spente_con_link(): void
    {
        $admin = User::factory()->create(['attivo' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Non attive:')
            ->assertSee('Assistente AI')
            ->assertSee(route('admin.integrazioni'));
    }
}
