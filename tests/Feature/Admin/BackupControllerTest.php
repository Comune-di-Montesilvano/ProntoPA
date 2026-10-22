<?php

namespace Tests\Feature\Admin;

use App\Jobs\CompletaCaricamento;
use App\Jobs\CreaBackup;
use App\Jobs\EseguiRipristino;
use App\Models\User;
use App\Services\Backup\ArchivioBackup;
use App\Services\Backup\BackupInfo;
use App\Services\Backup\BackupNonLeggibile;
use App\Services\Backup\BackupService;
use App\Services\Backup\CatalogoBackup;
use App\Services\Backup\StatoRipristino;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BackupControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir().'/bkc-'.uniqid('', true);
        mkdir($this->base.'/backups', 0777, true);
        mkdir($this->base.'/app/public', 0777, true);
        file_put_contents($this->base.'/app/public/a.txt', 'allegato');
        config([
            'backup.path' => $this->base.'/backups',
            'backup.storage_root' => $this->base.'/app',
            'backup.passphrase' => 'test-pass',
            'backup.dimensione_blocco' => 1024,
        ]);
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
            ThrottleRequests::class,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    private function utente(string $ruolo): User
    {
        Role::firstOrCreate(['name' => $ruolo, 'guard_name' => 'web']);
        $u = User::factory()->create(['attivo' => true, 'approval_status' => 'approved']);
        $u->syncRoles([$ruolo]);

        return $u;
    }

    private function backupReale(): BackupInfo
    {
        return app(BackupService::class)->crea('manuale');
    }

    /** @return list<string> blocchi da 1 KB (almeno 3) del backup, che viene tolto dal disco */
    private function blocchiDi(BackupInfo $info, ?string $da = null, ?string $a = null): array
    {
        $zip = $this->base.'/backups/'.$info->nome;
        if ($da !== null && $a !== null) {
            app(ArchivioBackup::class)->ricifra($zip, $da, $a);
        }
        $blocchi = str_split((string) file_get_contents($zip), 1024);
        $this->assertGreaterThanOrEqual(3, count($blocchi));
        File::delete([$zip, $zip.'.json']);

        return $blocchi;
    }

    /** @param list<string> $blocchi */
    private function inviaBlocchi(array $blocchi, ?string $passphrase = null): TestResponse
    {
        $id = (string) Str::uuid();
        $r = null;
        foreach ($blocchi as $i => $dati) {
            $r = $this->postJson(route('admin.backup.carica'), [
                'upload_id' => $id, 'indice' => $i, 'totale' => count($blocchi),
                'blocco' => UploadedFile::fake()->createWithContent('b', $dati),
                'passphrase' => $passphrase,
            ]);
            if (! $r->isOk()) {
                break;
            }
        }

        return $r;
    }

    /**
     * Esito del completamento (job sync nei test) per la risposta dell'ultimo blocco.
     *
     * @return array<string,mixed>
     */
    private function esitoCaricamento(TestResponse $r): array
    {
        $r->assertOk()->assertJsonStructure(['token']);
        $stato = StatoRipristino::leggi((string) $r->json('token'));
        $this->assertNotNull($stato);

        return $stato;
    }

    private function caricato(): BackupInfo
    {
        $voce = app(CatalogoBackup::class)->elenco()->firstWhere('tipo', 'caricato');
        $this->assertNotNull($voce);

        return $voce;
    }

    public function test_solo_admin(): void
    {
        $this->actingAs($this->utente('gestore'));
        $n = 'prontopa-20260101-000000-dev-manuale.zip';

        $this->get(route('admin.backup.index'))->assertForbidden();
        $this->post(route('admin.backup.crea'))->assertForbidden();
        $this->get(route('admin.backup.scarica', $n))->assertForbidden();
        $this->delete(route('admin.backup.elimina', $n))->assertForbidden();
        $this->post(route('admin.backup.carica'))->assertForbidden();
        $this->get(route('admin.backup.anteprima', $n))->assertForbidden();
        $this->post(route('admin.backup.anteprima-passphrase', $n))->assertForbidden();
        $this->post(route('admin.backup.ripristina', $n))->assertForbidden();
        $this->get(route('admin.backup.attesa', Str::random(40)))->assertForbidden();
    }

    public function test_backup_ora_accoda_job(): void
    {
        Queue::fake();
        $this->actingAs($this->utente('admin'))->post(route('admin.backup.crea'))
            ->assertRedirect(route('admin.backup.index'));
        Queue::assertPushed(CreaBackup::class, fn (CreaBackup $j) => $j->tipo === 'manuale');
        $this->assertSame('in coda', app(CatalogoBackup::class)->inCorso()['fase'] ?? null);
    }

    public function test_backup_ora_senza_passphrase_rifiutato(): void
    {
        Queue::fake();
        config(['backup.passphrase' => null]);
        $this->actingAs($this->utente('admin'))->post(route('admin.backup.crea'))
            ->assertRedirect(route('admin.backup.index'))->assertSessionHas('errore');
        Queue::assertNotPushed(CreaBackup::class);
    }

    public function test_index_mostra_backup_in_corso_e_blocca_backup_ora(): void
    {
        Queue::fake();
        app(CatalogoBackup::class)->segnaInCorso('notturno', 'in corso');
        $admin = $this->utente('admin');

        $this->actingAs($admin)->get(route('admin.backup.index'))
            ->assertOk()->assertSee('Backup notturno in corso dal')->assertSee('location.reload', false);
        $this->post(route('admin.backup.crea'))->assertSessionHas('errore');
        Queue::assertNotPushed(CreaBackup::class);

        app(CatalogoBackup::class)->fineInCorso();
        $this->get(route('admin.backup.index'))->assertOk()->assertDontSee('in corso dal');
    }

    public function test_pagina_backup_allarme_36_ore(): void
    {
        $quando = CarbonImmutable::now()->subHours(37);
        $nome = 'prontopa-'.$quando->format('Ymd-His').'-dev-notturno.zip';
        file_put_contents($this->base.'/backups/'.$nome, 'x');
        app(CatalogoBackup::class)->salvaInfo(new BackupInfo($nome, 'notturno', $quando, 'dev', 1, true, null, 1));

        $this->actingAs($this->utente('admin'))->get(route('admin.backup.index'))
            ->assertOk()->assertSee('Backup non aggiornato');
    }

    public function test_index_elenca_backup(): void
    {
        $info = $this->backupReale();
        $this->actingAs($this->utente('admin'))->get(route('admin.backup.index'))
            ->assertOk()->assertSee($info->nome);
    }

    public function test_index_avvisa_se_passphrase_mancante(): void
    {
        config(['backup.passphrase' => null]);
        $this->actingAs($this->utente('admin'))->get(route('admin.backup.index'))
            ->assertOk()->assertSee('Imposta BACKUP_PASSPHRASE');
    }

    public function test_scarica_rifiuta_path_traversal(): void
    {
        $this->actingAs($this->utente('admin'))->get('/admin/backup/..%2F.env/scarica')->assertNotFound();
    }

    public function test_scarica_file_esistente(): void
    {
        $info = $this->backupReale();
        $this->actingAs($this->utente('admin'))->get(route('admin.backup.scarica', $info->nome))
            ->assertOk()->assertDownload($info->nome);
    }

    public function test_elimina_backup(): void
    {
        $info = $this->backupReale();
        $this->actingAs($this->utente('admin'))->delete(route('admin.backup.elimina', $info->nome))
            ->assertRedirect(route('admin.backup.index'));
        $this->assertFileDoesNotExist($this->base.'/backups/'.$info->nome);
    }

    public function test_elimina_pre_ripristino_protetto_non_da_500(): void
    {
        $nome = 'prontopa-20260101-000000-dev-pre-ripristino.zip';
        file_put_contents($this->base.'/backups/'.$nome, 'x');
        app(CatalogoBackup::class)->salvaInfo(new BackupInfo($nome, 'pre-ripristino', CarbonImmutable::parse('2026-01-01'), 'dev', 1, true, null, 1));

        $this->actingAs($this->utente('admin'))->delete(route('admin.backup.elimina', $nome))
            ->assertRedirect(route('admin.backup.index'))->assertSessionHas('errore');
        $this->assertFileExists($this->base.'/backups/'.$nome);
    }

    public function test_upload_a_blocchi(): void
    {
        $blocchi = $this->blocchiDi($this->backupReale());

        $this->actingAs($this->utente('admin'));
        $stato = $this->esitoCaricamento($this->inviaBlocchi($blocchi));

        $this->assertTrue($stato['esito'], $stato['messaggio']);
        $voce = $this->caricato();
        $this->assertStringEndsWith('-caricato.zip', $voce->nome);
        $this->assertStringContainsString($voce->nome, $stato['messaggio']);
        $this->assertTrue($voce->verificato);
        $this->assertSame([], glob($this->base.'/backups/tmp/*') ?: []);
    }

    public function test_upload_completato_da_job_cifrato_sulla_coda_lunga(): void
    {
        $blocchi = $this->blocchiDi($this->backupReale());
        Queue::fake();
        config(['backup.connessione_coda' => 'redis-lunghi']);

        $this->actingAs($this->utente('admin'));
        $r = $this->inviaBlocchi($blocchi, 'una-passphrase');

        $r->assertOk()->assertJsonStructure(['token']);
        Queue::assertPushed(CompletaCaricamento::class, fn (CompletaCaricamento $j) => $j->connection === 'redis-lunghi'
            && $j->queue === 'lunghi' && $j->token === $r->json('token') && $j->timeout === 7200 && $j->tries === 1);
        $this->assertInstanceOf(ShouldBeEncrypted::class, Queue::pushed(CompletaCaricamento::class)->first());
        $this->assertSame('in coda', StatoRipristino::leggi($r->json('token'))['fase']);
        $this->assertSame([], glob($this->base.'/backups/tmp/upload-*') ?: []);
    }

    public function test_upload_passphrase_diversa_ricifrato(): void
    {
        $blocchi = $this->blocchiDi($this->backupReale(), 'test-pass', 'altra-pass');

        $this->actingAs($this->utente('admin'));
        $stato = $this->esitoCaricamento($this->inviaBlocchi($blocchi, 'altra-pass'));

        $this->assertTrue($stato['esito'], $stato['messaggio']);
        $nuovo = $this->base.'/backups/'.$this->caricato()->nome;
        app(ArchivioBackup::class)->verifica($nuovo, 'test-pass');
        $this->assertSame([], glob($this->base.'/backups/tmp/*') ?: []);
        $this->expectException(BackupNonLeggibile::class);
        app(ArchivioBackup::class)->verifica($nuovo, 'altra-pass');
    }

    public function test_upload_gia_presente_fallisce_e_pulisce(): void
    {
        $blocchi = $this->blocchiDi($this->backupReale());
        $this->actingAs($this->utente('admin'));
        $this->assertTrue($this->esitoCaricamento($this->inviaBlocchi($blocchi))['esito']);

        $stato = $this->esitoCaricamento($this->inviaBlocchi($blocchi));

        $this->assertFalse($stato['esito']);
        $this->assertStringContainsString('Esiste già', $stato['messaggio']);
        $this->assertSame([], glob($this->base.'/backups/tmp/*') ?: []);
    }

    public function test_upload_senza_passphrase_locale_rifiutato_al_primo_blocco(): void
    {
        $blocchi = $this->blocchiDi($this->backupReale());
        config(['backup.passphrase' => null]);

        $this->actingAs($this->utente('admin'));
        $r = $this->inviaBlocchi($blocchi, 'test-pass');

        $r->assertStatus(422)->assertSee('BACKUP_PASSPHRASE');
        $this->assertSame([], glob($this->base.'/backups/*.zip') ?: []);
        $this->assertSame([], glob($this->base.'/backups/tmp/upload-*') ?: []);
    }

    public function test_upload_blocchi_successivi_legati_al_primo(): void
    {
        $admin = $this->utente('admin');
        $altro = $this->utente('admin');
        $id = (string) Str::uuid();
        $blocco = fn () => UploadedFile::fake()->createWithContent('b', str_repeat('a', 1024));

        $this->actingAs($admin)->postJson(route('admin.backup.carica'), ['upload_id' => $id, 'indice' => 0, 'totale' => 3, 'blocco' => $blocco()])->assertOk();
        $this->postJson(route('admin.backup.carica'), ['upload_id' => $id, 'indice' => 1, 'totale' => 2, 'blocco' => $blocco()])->assertStatus(422);
        $this->actingAs($altro)->postJson(route('admin.backup.carica'), ['upload_id' => $id, 'indice' => 1, 'totale' => 3, 'blocco' => $blocco()])->assertStatus(422);
        $this->actingAs($admin)->postJson(route('admin.backup.carica'), ['upload_id' => $id, 'indice' => 1, 'totale' => 3, 'blocco' => $blocco()])->assertOk();
    }

    public function test_upload_blocco_fuori_ordine_rifiutato(): void
    {
        $this->actingAs($this->utente('admin'));
        $this->postJson(route('admin.backup.carica'), [
            'upload_id' => (string) Str::uuid(), 'indice' => 1, 'totale' => 3,
            'blocco' => UploadedFile::fake()->createWithContent('b', str_repeat('a', 1024)),
        ])->assertStatus(422)->assertJsonStructure(['errore']);
    }

    public function test_upload_blocco_duplicato_rifiutato(): void
    {
        $this->actingAs($this->utente('admin'));
        $dati = ['upload_id' => (string) Str::uuid(), 'indice' => 0, 'totale' => 3];
        $this->postJson(route('admin.backup.carica'), $dati + ['blocco' => UploadedFile::fake()->createWithContent('b', str_repeat('a', 1024))])->assertOk();
        $this->postJson(route('admin.backup.carica'), $dati + ['blocco' => UploadedFile::fake()->createWithContent('b', str_repeat('a', 1024))])->assertStatus(422);
    }

    public function test_upload_blocco_troppo_grande_rifiutato(): void
    {
        $this->actingAs($this->utente('admin'));
        $this->postJson(route('admin.backup.carica'), [
            'upload_id' => (string) Str::uuid(), 'indice' => 0, 'totale' => 2,
            'blocco' => UploadedFile::fake()->createWithContent('b', str_repeat('a', 2048)),
        ])->assertStatus(422);
    }

    public function test_upload_file_non_backup_rifiutato_e_pulito(): void
    {
        $this->actingAs($this->utente('admin'));
        $stato = $this->esitoCaricamento($this->inviaBlocchi(['non sono uno zip']));

        $this->assertFalse($stato['esito']);
        $this->assertNotSame('', $stato['messaggio']);
        $this->assertSame([], glob($this->base.'/backups/tmp/*') ?: []);
        $this->assertSame([], glob($this->base.'/backups/*.zip') ?: []);
    }

    public function test_ripristino_richiede_conferma(): void
    {
        Queue::fake();
        $info = $this->backupReale();
        $this->actingAs($this->utente('admin'))
            ->post(route('admin.backup.ripristina', $info->nome), ['conferma' => 'ripristina'])
            ->assertOk()->assertSee('scrivi esattamente RIPRISTINA');
        Queue::assertNotPushed(EseguiRipristino::class);
    }

    public function test_ripristino_accoda_e_porta_all_attesa(): void
    {
        Queue::fake();
        $info = $this->backupReale();
        $r = $this->actingAs($this->utente('admin'))
            ->post(route('admin.backup.ripristina', $info->nome), ['conferma' => 'RIPRISTINA']);
        Queue::assertPushed(EseguiRipristino::class, fn (EseguiRipristino $j) => $j->zip === $this->base.'/backups/'.$info->nome);
        $token = Queue::pushed(EseguiRipristino::class)->first()->token;
        // Niente redirect: il worker potrebbe mettere in manutenzione prima della GET.
        $r->assertOk()->assertSee($token, false);
        $this->assertSame('in coda', StatoRipristino::leggi($token)['fase']);
    }

    public function test_conferma_errata_non_flasha_la_passphrase(): void
    {
        Queue::fake();
        $info = $this->backupReale();
        app(ArchivioBackup::class)->ricifra($this->base.'/backups/'.$info->nome, 'test-pass', 'altra-pass');

        $this->actingAs($this->utente('admin'))
            ->post(route('admin.backup.ripristina', $info->nome), ['conferma' => 'no', 'passphrase' => 'altra-pass'])
            ->assertOk()->assertSee('RIPRISTINA');
        $this->assertNull(session()->getOldInput('passphrase'));
        Queue::assertNotPushed(EseguiRipristino::class);
    }

    public function test_stato_e_stateless_anche_se_ripristino_id_cambia(): void
    {
        $token = StatoRipristino::nuovoToken();
        StatoRipristino::fase($token, 'import', 'x');
        $this->actingAs($this->utente('admin'));
        \App\Models\Impostazione::set('ripristino_id', (string) Str::uuid());

        $this->getJson('/ripristino/stato/'.$token)->assertOk()->assertJsonPath('fase', 'import');
    }

    public function test_upload_troppo_grande_rifiutato_al_primo_blocco(): void
    {
        config(['backup.max_upload_mb' => 1, 'backup.dimensione_blocco' => 1024]);
        $this->actingAs($this->utente('admin'));
        $this->postJson(route('admin.backup.carica'), [
            'upload_id' => (string) Str::uuid(), 'indice' => 0, 'totale' => 5000,
            'blocco' => UploadedFile::fake()->createWithContent('b', str_repeat('a', 1024)),
        ])->assertStatus(422)->assertSee('troppo grande');
    }

    public function test_upload_scaduti_rimossi(): void
    {
        mkdir($this->base.'/backups/tmp', 0777, true);
        $vecchia = $this->base.'/backups/tmp/upload-'.Str::uuid().'.part';
        $recente = $this->base.'/backups/tmp/upload-'.Str::uuid().'.part';
        file_put_contents($vecchia, 'x');
        file_put_contents($recente, 'x');
        touch($vecchia, time() - 25 * 3600);

        $this->actingAs($this->utente('admin'));
        $this->postJson(route('admin.backup.carica'), [
            'upload_id' => (string) Str::uuid(), 'indice' => 0, 'totale' => 2,
            'blocco' => UploadedFile::fake()->createWithContent('b', str_repeat('a', 1024)),
        ])->assertOk();

        $this->assertFileDoesNotExist($vecchia);
        $this->assertFileExists($recente);
    }

    public function test_anteprima_mostra_riepilogo(): void
    {
        $info = $this->backupReale();
        $this->actingAs($this->utente('admin'))->get(route('admin.backup.anteprima', $info->nome))
            ->assertOk()->assertSee('RIPRISTINA');
    }

    public function test_anteprima_chiede_passphrase_se_non_si_apre(): void
    {
        $info = $this->backupReale();
        app(ArchivioBackup::class)->ricifra($this->base.'/backups/'.$info->nome, 'test-pass', 'altra-pass');
        $this->actingAs($this->utente('admin'))->get(route('admin.backup.anteprima', $info->nome))
            ->assertOk()->assertSee('name="passphrase"', false);
        $this->post(route('admin.backup.anteprima-passphrase', $info->nome), ['passphrase' => 'altra-pass'])
            ->assertOk()->assertSee('RIPRISTINA');
    }

    public function test_stato_raggiungibile_in_manutenzione(): void
    {
        $token = StatoRipristino::nuovoToken();
        StatoRipristino::fase($token, 'import', 'x');
        Artisan::call('down');
        try {
            $this->getJson('/ripristino/stato/'.$token)->assertOk()->assertJsonPath('fase', 'import');
        } finally {
            Artisan::call('up');
        }
    }

    public function test_stato_token_ignoto_o_non_valido_404(): void
    {
        $this->getJson('/ripristino/stato/'.Str::random(40))->assertNotFound();
        $this->getJson('/ripristino/stato/zz')->assertNotFound();
    }

    public function test_dashboard_allarme_36_ore(): void
    {
        $admin = $this->utente('admin');
        $cat = app(CatalogoBackup::class);
        $mk = function (CarbonImmutable $quando) use ($cat) {
            $nome = 'prontopa-'.$quando->format('Ymd-His').'-dev-notturno.zip';
            file_put_contents($this->base.'/backups/'.$nome, 'x');
            $cat->salvaInfo(new BackupInfo($nome, 'notturno', $quando, 'dev', 1, true, null, 1));

            return $nome;
        };

        $vecchio = $mk(CarbonImmutable::now()->subHours(37));
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertSee('Backup non aggiornato');

        File::delete([$this->base.'/backups/'.$vecchio, $this->base.'/backups/'.$vecchio.'.json']);
        $mk(CarbonImmutable::now()->subHours(2));
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertDontSee('Backup non aggiornato');
    }
}
