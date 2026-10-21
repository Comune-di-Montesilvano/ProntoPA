<?php

namespace Tests\Unit;

use App\Http\Requests\Auth\LoginRequest;
use App\Services\Auth\LdapLoginService;
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
    /** @return array<string, array{class-string, string}> */
    public static function metodiConPassword(): array
    {
        return [
            'Directory::authenticate' => [Directory::class, 'authenticate'],
            'LdapRecordDirectory::authenticate' => [LdapRecordDirectory::class, 'authenticate'],
            'MockDirectory::authenticate' => [MockDirectory::class, 'authenticate'],
            'NullDirectory::authenticate' => [NullDirectory::class, 'authenticate'],
            'LdapLoginService::login' => [LdapLoginService::class, 'login'],
            'LoginRequest::tentaDipendente' => [LoginRequest::class, 'tentaDipendente'],
            'LoginRequest::tentaLocale' => [LoginRequest::class, 'tentaLocale'],
        ];
    }

    /** @param class-string $classe */
    #[DataProvider('metodiConPassword')]
    public function test_parametro_password_marcato_sensibile(string $classe, string $metodo): void
    {
        $password = collect((new ReflectionMethod($classe, $metodo))->getParameters())
            ->firstWhere(fn ($p) => $p->getName() === 'password');

        $this->assertNotNull($password, "$classe::$metodo senza parametro \$password");
        $this->assertNotEmpty($password->getAttributes(SensitiveParameter::class), "$classe::$metodo: \$password senza #[\\SensitiveParameter]");
    }
}
