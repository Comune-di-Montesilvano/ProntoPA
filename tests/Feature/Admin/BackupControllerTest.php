<?php

namespace Tests\Feature\Admin;

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
        }

        return $r;
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
        $r = $this->inviaBlocchi($blocchi);

        $r->assertOk()->assertJsonStructure(['nome']);
        $nome = $r->json('nome');
        $this->assertStringEndsWith('-caricato.zip', $nome);
        $voce = app(CatalogoBackup::class)->elenco()->firstWhere('nome', $nome);
        $this->assertNotNull($voce);
        $this->assertTrue($voce->verificato);
        $this->assertSame('caricato', $voce->tipo);
        $this->assertSame([], glob($this->base.'/backups/tmp/upload-*.part') ?: []);
    }

    public function test_upload_passphrase_diversa_ricifrato(): void
    {
        $blocchi = $this->blocchiDi($this->backupReale(), 'test-pass', 'altra-pass');

        $this->actingAs($this->utente('admin'));
        $r = $this->inviaBlocchi($blocchi, 'altra-pass');

        $r->assertOk();
        $nuovo = $this->base.'/backups/'.$r->json('nome');
        app(ArchivioBackup::class)->verifica($nuovo, 'test-pass');
        $this->expectException(BackupNonLeggibile::class);
        app(ArchivioBackup::class)->verifica($nuovo, 'altra-pass');
    }

    public function test_upload_senza_passphrase_locale_rifiutato(): void
    {
        $blocchi = $this->blocchiDi($this->backupReale());
        config(['backup.passphrase' => null]);

        $this->actingAs($this->utente('admin'));
        $r = $this->inviaBlocchi($blocchi, 'test-pass');

        $r->assertStatus(422)->assertSee('BACKUP_PASSPHRASE');
        $this->assertSame([], glob($this->base.'/backups/*.zip') ?: []);
        $this->assertSame([], glob($this->base.'/backups/tmp/upload-*.part') ?: []);
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
        $r = $this->inviaBlocchi(['non sono uno zip']);
        $r->assertStatus(422)->assertJsonStructure(['errore']);
        $this->assertSame([], glob($this->base.'/backups/tmp/upload-*.part') ?: []);
    }

    public function test_ripristino_richiede_conferma(): void
    {
        Queue::fake();
        $info = $this->backupReale();
        $this->actingAs($this->utente('admin'))
            ->post(route('admin.backup.ripristina', $info->nome), ['conferma' => 'ripristina'])
            ->assertSessionHasErrors('conferma');
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
        $r->assertRedirect(route('admin.backup.attesa', $token));
        $this->assertSame('in coda', StatoRipristino::leggi($token)['fase']);
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
