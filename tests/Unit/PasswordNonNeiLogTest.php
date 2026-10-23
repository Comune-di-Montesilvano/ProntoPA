<?php

namespace Tests\Unit;

use App\Http\Controllers\Admin\BackupController;
use App\Http\Requests\Auth\LoginRequest;
use App\Jobs\CompletaCaricamento;
use App\Jobs\EseguiRipristino;
use App\Services\Auth\LdapLoginService;
use App\Services\Backup\ArchivioBackup;
use App\Services\Backup\BackupService;
use App\Services\Backup\RipristinoService;
use App\Services\Backup\SegretiCifrati;
use App\Services\Directory\Directory;
use App\Services\Directory\LdapRecordDirectory;
use App\Services\Directory\MockDirectory;
use App\Services\Directory\NullDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SensitiveParameter;

/**
 * Gli stacktrace (laravel.log, Sentry) riportano gli argomenti delle
 * funzioni: senza #[\SensitiveParameter] un'eccezione durante il login
 * scrive la password in chiaro nel log.
 */
class PasswordNonNeiLogTest extends TestCase
{
    /** @return array<string, array{class-string, string}|array{class-string, string, string}> */
    public static function metodiConPassword(): array
    {
        return [
            'Directory::authenticate' => [Directory::class, 'authenticate'],
            'LdapRecordDirectory::authenticate' => [LdapRecordDirectory::class, 'authenticate'],
            'MockDirectory::authenticate' => [MockDirectory::class, 'authenticate'],
            'NullDirectory::authenticate' => [NullDirectory::class, 'authenticate'],
            'LdapLoginService::login' => [LdapLoginService::class, 'login'],
            'LoginRequest::tentaDipendente' => [LoginRequest::class, 'tentaDipendente'],
            'ArchivioBackup::scrivi' => [ArchivioBackup::class, 'scrivi', 'passphrase'],
            'ArchivioBackup::manifest' => [ArchivioBackup::class, 'manifest', 'passphrase'],
            'ArchivioBackup::verifica' => [ArchivioBackup::class, 'verifica', 'passphrase'],
            'ArchivioBackup::estrai' => [ArchivioBackup::class, 'estrai', 'passphrase'],
            'ArchivioBackup::ricifra (da)' => [ArchivioBackup::class, 'ricifra', 'da'],
            'ArchivioBackup::ricifra (a)' => [ArchivioBackup::class, 'ricifra', 'a'],
            'RipristinoService::anteprima' => [RipristinoService::class, 'anteprima', 'passphrase'],
            'RipristinoService::esegui' => [RipristinoService::class, 'esegui', 'passphrase'],
            'BackupController::vistaAnteprima' => [BackupController::class, 'vistaAnteprima', 'passphraseForm'],
            'BackupService::registraCaricato' => [BackupService::class, 'registraCaricato', 'passphraseFile'],
            'CompletaCaricamento::__construct' => [CompletaCaricamento::class, '__construct', 'passphrase'],
            'ArchivioBackup::scrivi (segreti)' => [ArchivioBackup::class, 'scrivi', 'segreti'],
            'ArchivioBackup::scriviVoci (segreti)' => [ArchivioBackup::class, 'scriviVoci', 'segretiJson'],
            'SegretiCifrati::applica' => [SegretiCifrati::class, 'applica', 'segreti'],
            'RipristinoService::sostituisci' => [RipristinoService::class, 'sostituisci', 'estratto'],
            'RipristinoService::sostituisciConRollback' => [RipristinoService::class, 'sostituisciConRollback', 'estratto'],
            'EseguiRipristino::__construct' => [EseguiRipristino::class, '__construct', 'passphrase'],
        ];
    }

    /** @param class-string $classe */
    #[DataProvider('metodiConPassword')]
    public function test_parametro_password_marcato_sensibile(string $classe, string $metodo, string $parametro = 'password'): void
    {
        $password = collect((new ReflectionMethod($classe, $metodo))->getParameters())
            ->firstWhere(fn ($p) => $p->getName() === $parametro);

        $this->assertNotNull($password, "$classe::$metodo senza parametro $$parametro");
        $this->assertNotEmpty($password->getAttributes(SensitiveParameter::class), "$classe::$metodo: $$parametro senza #[\\SensitiveParameter]");
    }
}
