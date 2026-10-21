<?php

namespace Tests\Feature\Backup;

use App\Services\Backup\ArchivioBackup;
use App\Services\Backup\BackupNonLeggibile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class ArchivioBackupTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir().'/arch-'.uniqid('', true);
        mkdir($this->base.'/db', 0777, true);
        mkdir($this->base.'/storage/public/allegati', 0777, true);
        file_put_contents($this->base.'/db/users.jsonl', "{\"id\":1}\n{\"id\":2}\n{\"id\":3}\n");
        file_put_contents($this->base.'/db/segnalazioni.jsonl', "{\"id\":1}\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    private function crea(string $pass = 'segreta-123'): string
    {
        $zip = $this->base.'/b.zip';
        (new ArchivioBackup)->scrivi(
            $zip, $pass,
            ['versione' => 1, 'conteggi' => ['users' => 3, 'segnalazioni' => 1]],
            $this->base.'/db', $this->base.'/storage',
            [['tabella' => 'users', 'colonna' => 'two_factor_secret', 'id' => 1, 'valore' => 'x']],
        );

        return $zip;
    }

    public function test_roundtrip_con_allegato_nome_strano(): void
    {
        $nome = $this->base.'/storage/public/allegati/Aula 3 – più.jpg';
        $f = fopen($nome, 'wb');
        for ($i = 0; $i < 50; $i++) {
            fwrite($f, random_bytes(1024 * 1024));
        }
        fclose($f);
        $hash = hash_file('sha256', $nome);

        $zip = $this->crea();
        $dest = $this->base.'/out';
        (new ArchivioBackup)->estrai($zip, 'segreta-123', $dest);

        $this->assertSame($hash, hash_file('sha256', $dest.'/storage/app/public/allegati/Aula 3 – più.jpg'));
        $this->assertFileExists($dest.'/manifest.json');
        $this->assertFileExists($dest.'/segreti.json');
        $this->assertFileExists($dest.'/db/users.jsonl');
        (new ArchivioBackup)->verifica($zip, 'segreta-123');
    }

    public function test_quarantena_esclusa(): void
    {
        mkdir($this->base.'/storage/quarantena', 0777, true);
        file_put_contents($this->base.'/storage/quarantena/virus.bin', 'x');
        $zip = $this->crea();
        (new ArchivioBackup)->estrai($zip, 'segreta-123', $this->base.'/out');

        $this->assertFileDoesNotExist($this->base.'/out/storage/app/quarantena/virus.bin');
    }

    public function test_passphrase_errata(): void
    {
        $zip = $this->crea();

        try {
            (new ArchivioBackup)->manifest($zip, 'sbagliata');
            $this->fail('Attesa BackupNonLeggibile');
        } catch (BackupNonLeggibile $e) {
            $this->assertStringContainsString('passphrase', $e->getMessage());
        }
        $this->assertSame(3, (new ArchivioBackup)->manifest($zip, 'segreta-123')['conteggi']['users']);
    }

    public function test_file_troncato_o_non_zip(): void
    {
        $testo = $this->base.'/finto.zip';
        file_put_contents($testo, 'non sono uno zip');
        try {
            (new ArchivioBackup)->manifest($testo, 'x');
            $this->fail('Attesa BackupNonLeggibile');
        } catch (BackupNonLeggibile $e) {
            $this->assertStringNotContainsString('passphrase', $e->getMessage());
        }

        $zip = $this->crea();
        $troncato = $this->base.'/tronco.zip';
        file_put_contents($troncato, substr((string) file_get_contents($zip), 0, (int) (filesize($zip) / 2)));
        $this->expectException(BackupNonLeggibile::class);
        (new ArchivioBackup)->manifest($troncato, 'segreta-123');
    }

    public function test_verifica_rileva_jsonl_alterato(): void
    {
        $zip = $this->crea();
        $a = new ArchivioBackup;
        $a->verifica($zip, 'segreta-123');

        // Riscrive db/users.jsonl con una riga in meno tenendo i checksum coerenti:
        // resta solo la discordanza con manifest['conteggi'].
        $a->estrai($zip, 'segreta-123', $this->base.'/x');
        file_put_contents($this->base.'/x/db/users.jsonl', "{\"id\":1}\n{\"id\":2}\n");
        $manifest = $a->manifest($zip, 'segreta-123');
        $segreti = json_decode((string) file_get_contents($this->base.'/x/segreti.json'), true);
        $a->scrivi($zip, 'segreta-123', $manifest, $this->base.'/x/db', $this->base.'/storage', $segreti);

        $this->expectException(BackupNonLeggibile::class);
        $this->expectExceptionMessage('users');
        $a->verifica($zip, 'segreta-123');
    }

    public function test_verifica_rileva_checksum_alterato(): void
    {
        $zip = $this->crea();

        // Stesso numero di righe, contenuto diverso, dentro l'archivio.
        $za = new ZipArchive;
        $za->open($zip);
        $za->setPassword('segreta-123');
        $za->deleteName('db/users.jsonl');
        $za->addFromString('db/users.jsonl', "{\"id\":9}\n{\"id\":2}\n{\"id\":3}\n");
        $za->setEncryptionName('db/users.jsonl', ZipArchive::EM_AES_256, 'segreta-123');
        $za->close();

        $this->expectException(BackupNonLeggibile::class);
        $this->expectExceptionMessage('Checksum');
        (new ArchivioBackup)->verifica($zip, 'segreta-123');
    }

    public function test_estrai_rifiuta_zip_slip(): void
    {
        $zip = $this->base.'/slip.zip';
        $za = new ZipArchive;
        $za->open($zip, ZipArchive::CREATE);
        $za->addFromString('../fuori.txt', 'x');
        $za->setEncryptionName('../fuori.txt', ZipArchive::EM_AES_256, 'p');
        $za->close();

        try {
            (new ArchivioBackup)->estrai($zip, 'p', $this->base.'/out');
            $this->fail('Attesa BackupNonLeggibile');
        } catch (BackupNonLeggibile) {
        }
        $this->assertFileDoesNotExist($this->base.'/fuori.txt');
    }

    public function test_ricifra(): void
    {
        $zip = $this->crea('vecchia-pass');
        (new ArchivioBackup)->ricifra($zip, 'vecchia-pass', 'nuova-pass');

        $a = new ArchivioBackup;
        $a->verifica($zip, 'nuova-pass');
        $this->assertSame([], glob($this->base.'/.ricifra-*') ?: []);
        $this->expectException(BackupNonLeggibile::class);
        $a->manifest($zip, 'vecchia-pass');
    }
}
