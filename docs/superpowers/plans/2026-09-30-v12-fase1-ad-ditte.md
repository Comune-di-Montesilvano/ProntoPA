# v1.2 Fase 1 — Dipendenti via AD, ditte via email — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** I dipendenti dell'ente entrano con le credenziali Active Directory e ricevono il ruolo dai gruppi AD; le ditte entrano con email + password + 2FA (codice via email di default); il wizard `/setup` sparisce.

**Architecture:** Un'interfaccia `Directory` (autentica username/password e restituisce identità + gruppi) con tre implementazioni: `LdapRecordDirectory` (AD reale), `MockDirectory` (dev, `LDAP_HOST=mock`), `NullDirectory` (non configurata). `LdapLoginService` trasforma un'identità AD in un `User` (`auth_source = ldap`) e ne sincronizza ruolo/flag/provenienza/permesso tramite `MappaGruppiLdap`. `LoginRequest` instrada: input con `@` (che non sia l'UPN del dominio AD) → account locale per email; altrimenti → AD, con fallback transitorio sugli account legacy per username fino al cutover (fase 3). La 2FA via email è un servizio (`CodiceAccessoEmail`) agganciato al challenge esistente.

**Tech Stack:** Laravel 13, PHP 8.4, `directorytree/ldaprecord` v3 (+ `ext-ldap`), Spatie Permission v6, Fortify (solo TOTP), PHPUnit, Dusk.

**Spec:** `docs/superpowers/specs/2026-09-30-v12-identita-accessi-design.md` (sezioni: Modello dati → `users`; Flussi — login; 2FA degli account locali; Mapping gruppi → ruolo; Cosa va in pensione (fase 1); Fasi di consegna → Fase 1).

## Global Constraints

- PHP 8.4 / Laravel 13; stile e idiomi del codice esistente (controller sottili, logica nei service, commenti in italiano).
- Migrazioni solo additive o che allentano vincoli (spec): `password` nullable, `email` perde l'unique DB. Nessuna colonna rimossa.
- Valori `auth_source`: esattamente `locale` · `ldap` · `spid`. Default `locale`.
- Valori `two_factor_metodo`: `email` · `totp` · NULL.
- Nomi gruppi AD default (impostazioni gruppo `ldap`): `PRONTOPA_ADMIN`, `PRONTOPA_SUPERVISORI`, `PRONTOPA_GESTORI`, `PRONTOPA_OPERAI`, `PRONTOPA_URP`, `PRONTOPA_SEGNALATORI`. Precedenza in quest'ordine, un solo ruolo per persona.
- Provenienze (da `TabelleRiferimentoSeeder`): `1` = SEGNALAZIONI INTERNE, `3` = URP.
- 2FA email: codice 6 cifre, validità 10 minuti, max 5 tentativi poi invalidato, reinvio max 1/min.
- `LDAP_HOST=mock` con `APP_ENV=production` → eccezione all'avvio.
- Ogni nuova env var va anche nel blocco `x-php-env` di `docker-compose.yml` (il compose non fa passthrough).
- Mai password, codici OTP o email in chiaro nei log (usare id utente o hash).
- Test: classi PHPUnit, metodi `test_*` in italiano snake_case, `RefreshDatabase`, `withoutMiddleware(PreventRequestForgery::class)` per i POST, `RolesAndPermissionsSeeder` seedato quando servono ruoli.
- Comandi nel container dev: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test --filter=<Nome>`; analisi statica `docker compose exec php composer run analyse` deve restare verde.
- Commit: Conventional Commits in stile caveman (soggetto ≤ 50 caratteri), trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Dipendente che scrive l'UPN (`m.rossi@ente.local`) o `ENTE\m.rossi`** invece dello username nudo: deve entrare via AD, non finire nel ramo "email ditta" → test in Task 6.
2. **Email ditta con maiuscole/spazi** (`  Mario@Ditta.IT `): deve trovare l'account → test in Task 6.
3. **AD irraggiungibile**: i dipendenti vedono "non disponibile" (non "credenziali errate"), le ditte e gli account legacy continuano a entrare → test in Task 6.
4. **Username AD uguale a quello di un altro account esistente** (es. utente demo `gestore`): login rifiutato con messaggio per l'admin, nessun errore 500 da vincolo unique → test in Task 4.
5. **Stessa email su un account `ldap` e su una ditta `locale`**: login e reset password della ditta non devono mai selezionare l'utente AD → test in Task 6 e Task 8.

---

### Task 1: Colonne identità su `users`

**Files:**
- Create: `database/migrations/2026_09_30_000001_add_identita_columns_to_users_table.php`
- Modify: `app/Models/User.php`
- Modify: `database/factories/UserFactory.php`
- Test: `tests/Feature/Auth/UsersIdentitaTest.php`

**Interfaces:**
- Produces: colonne `users.auth_source` (string, default `'locale'`), `users.ldap_guid` (string 36, nullable, unique), `users.two_factor_metodo` (string 5, nullable); `users.password` nullable; nessun unique su `users.email`. `User::isLocale(): bool`, `User::metodoSecondoFattore(): ?string` (`'totp'` | `'email'` | `null`).

- [ ] **Step 1: Scrivere il test (fallisce)**

`tests/Feature/Auth/UsersIdentitaTest.php`:
```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Tests\TestCase;

class UsersIdentitaTest extends TestCase
{
    use RefreshDatabase;

    public function test_nuovo_utente_ha_auth_source_locale_di_default(): void
    {
        $user = User::factory()->create();

        $this->assertSame('locale', $user->fresh()->auth_source);
        $this->assertTrue($user->fresh()->isLocale());
    }

    public function test_due_utenti_possono_avere_la_stessa_email(): void
    {
        User::factory()->create(['email' => 'stessa@ente.it']);
        User::factory()->create(['email' => 'stessa@ente.it']);

        $this->assertSame(2, User::where('email', 'stessa@ente.it')->count());
    }

    public function test_utente_ldap_senza_password_si_salva(): void
    {
        $user = User::factory()->create(['auth_source' => 'ldap', 'password' => null, 'ldap_guid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']);

        $this->assertNull($user->fresh()->password);
    }

    public function test_ldap_guid_e_unico(): void
    {
        User::factory()->create(['auth_source' => 'ldap', 'ldap_guid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']);

        $this->expectException(QueryException::class);
        User::factory()->create(['auth_source' => 'ldap', 'ldap_guid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']);
    }

    public function test_metodo_secondo_fattore(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $locale = User::factory()->create();
        $this->assertNull($locale->metodoSecondoFattore());

        $ditta = User::factory()->create();
        $ditta->assignRole('impresa');
        $this->assertSame('email', $ditta->metodoSecondoFattore());

        $optIn = User::factory()->create(['two_factor_metodo' => 'email']);
        $this->assertSame('email', $optIn->metodoSecondoFattore());

        $totp = User::factory()->create();
        $totp->assignRole('impresa');
        app(EnableTwoFactorAuthentication::class)($totp);
        $totp->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->assertSame('totp', $totp->fresh()->metodoSecondoFattore());

        $ldap = User::factory()->create(['auth_source' => 'ldap', 'two_factor_metodo' => 'email']);
        $ldap->assignRole('impresa');
        $this->assertNull($ldap->metodoSecondoFattore());
    }
}
```

- [ ] **Step 2: Eseguire il test e verificare che fallisca**

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test --filter=UsersIdentitaTest`
Expected: FAIL (colonna `auth_source` inesistente / metodo `isLocale` non definito).

- [ ] **Step 3: Scrivere la migrazione**

`database/migrations/2026_09_30_000001_add_identita_columns_to_users_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1.2 fase 1 — identità per canale di accesso.
 *
 * auth_source: locale (ditte, account legacy) · ldap (dipendenti AD) · spid
 * (scuole, fase 2). email perde l'unique DB: gli account legacy disattivati
 * hanno spesso la stessa email che la persona userà via AD/SPID; le chiavi
 * d'identità vere sono ldap_guid (qui) e codice_fiscale (fase 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('auth_source', 10)->default('locale');
            $table->string('ldap_guid', 36)->nullable()->unique();
            $table->string('two_factor_metodo', 5)->nullable();
            $table->index('auth_source', 'users_auth_source_idx');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');
            $table->index('email', 'users_email_idx');
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_email_idx');
            $table->unique('email');
            $table->string('password')->nullable(false)->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_auth_source_idx');
            $table->dropUnique(['ldap_guid']);
            $table->dropColumn(['auth_source', 'ldap_guid', 'two_factor_metodo']);
        });
    }
};
```

- [ ] **Step 4: Aggiornare il model**

In `app/Models/User.php`, aggiungere a `$fillable` (dopo `'password_legacy',`):
```php
        'auth_source',
        'ldap_guid',
        'two_factor_metodo',
```

E dopo `isSupervisore()` aggiungere:
```php
    public function isLocale(): bool
    {
        return ($this->auth_source ?? 'locale') === 'locale';
    }

    /**
     * Secondo fattore richiesto al login, solo per account locali (ditte e
     * legacy): per ldap/spid l'autenticazione forte è di AD/SPID.
     * TOTP confermato vince; le ditte hanno email come default obbligatorio.
     */
    public function metodoSecondoFattore(): ?string
    {
        if (! $this->isLocale()) {
            return null;
        }

        if ($this->hasEnabledTwoFactorAuthentication()) {
            return 'totp';
        }

        if ($this->two_factor_metodo === 'email' || $this->hasRole('impresa')) {
            return 'email';
        }

        return null;
    }
```

- [ ] **Step 5: Aggiornare la factory**

In `database/factories/UserFactory.php`, in `definition()` aggiungere dopo `'attivo' => true,`:
```php
            'auth_source' => 'locale',
```

- [ ] **Step 6: Eseguire i test**

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test --filter=UsersIdentitaTest`
Expected: PASS (5 test).

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test`
Expected: PASS, nessuna regressione.

- [ ] **Step 7: Verificare la migrazione su MariaDB (up/down/up)**

Run:
```bash
MSYS_NO_PATHCONV=1 docker compose exec php php artisan migrate
MSYS_NO_PATHCONV=1 docker compose exec php php artisan migrate:rollback --step=1
MSYS_NO_PATHCONV=1 docker compose exec php php artisan migrate
```
Expected: nessun errore. Se il rollback fallisce perché esistono email duplicate nel DB dev, è atteso (il `down` ripristina l'unique): segnalarlo nel commit, non forzare.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_09_30_000001_add_identita_columns_to_users_table.php app/Models/User.php database/factories/UserFactory.php tests/Feature/Auth/UsersIdentitaTest.php
git commit -m "feat(auth): colonne auth_source/ldap_guid/2fa metodo

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Astrazione `Directory` + mock dev + guardia produzione

**Files:**
- Create: `config/ldap.php`
- Create: `app/Services/Directory/Directory.php`
- Create: `app/Services/Directory/DirectoryIdentity.php`
- Create: `app/Services/Directory/DirectoryNonDisponibile.php`
- Create: `app/Services/Directory/MockDirectory.php`
- Create: `app/Services/Directory/NullDirectory.php`
- Create: `app/Services/Directory/LdapConfigGuard.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Create: `tests/Support/FakeDirectory.php`
- Test: `tests/Unit/Directory/MockDirectoryTest.php`, `tests/Unit/Directory/LdapConfigGuardTest.php`

**Interfaces:**
- Produces:
  - `interface App\Services\Directory\Directory { public function authenticate(string $username, string $password): ?DirectoryIdentity; }` — `null` = credenziali non valide; lancia `DirectoryNonDisponibile` se la directory non risponde.
  - `final class DirectoryIdentity(string $guid, string $username, string $name, ?string $email, array $groups)` — proprietà `public readonly`; `$groups` = lista dei CN dei gruppi (anche annidati).
  - `class DirectoryNonDisponibile extends \RuntimeException`.
  - `LdapConfigGuard::verifica(string $ambiente, ?string $host): void` — lancia `\RuntimeException` se `$ambiente === 'production'` e `$host === 'mock'`.
  - `config('ldap.host'|'ldap.port'|'ldap.base_dn'|'ldap.user_dn_template'|'ldap.starttls'|'ldap.tls_skip_verify'|'ldap.timeout')`.
  - Binding container: `Directory::class` → `MockDirectory` se host `mock`, `NullDirectory` se host vuoto, `LdapRecordDirectory` altrimenti (classe creata nel Task 5; fino ad allora il ramo non viene mai eseguito nei test).
  - `LdapConfigGuard::avviso(string $ambiente, bool $tlsSkipVerify): ?string` — messaggio di warning se `production` e `tls_skip_verify`, altrimenti `null` (loggato dal provider all'avvio).
  - Test helper `Tests\Support\FakeDirectory` con `aggiungi(string $password, DirectoryIdentity $id): self`, `public bool $nonDisponibile`, e factory statica `FakeDirectory::identita(string $username, array $groups, string|null|false $email = false, ?string $guid = null): DirectoryIdentity` (`false` = email di default `<username>@ente.local`, `null` = senza email).
- Mock users (password = username): `admin` (PRONTOPA_ADMIN), `mock.supervisore`, `mock.gestore`, `mock.operaio`, `mock.urp`, `mock.segnalatore` (gruppi corrispondenti), `mock.nessungruppo` (nessun gruppo). Email `<username>@mock.local`. Prefisso `mock.` per non collidere con gli utenti di `artisan demo` (`gestore`, `supervisore`, …).

- [ ] **Step 1: Scrivere i test (falliscono)**

`tests/Unit/Directory/MockDirectoryTest.php`:
```php
<?php

namespace Tests\Unit\Directory;

use App\Services\Directory\MockDirectory;
use PHPUnit\Framework\TestCase;

class MockDirectoryTest extends TestCase
{
    public function test_credenziali_mock_valide_restituiscono_identita_con_gruppo(): void
    {
        $id = (new MockDirectory())->authenticate('mock.gestore', 'mock.gestore');

        $this->assertNotNull($id);
        $this->assertSame('mock.gestore', $id->username);
        $this->assertSame('mock.gestore@mock.local', $id->email);
        $this->assertSame(['PRONTOPA_GESTORI'], $id->groups);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id->guid);
    }

    public function test_guid_stabile_tra_login(): void
    {
        $dir = new MockDirectory();

        $this->assertSame(
            $dir->authenticate('admin', 'admin')->guid,
            $dir->authenticate('ADMIN', 'admin')->guid,
        );
    }

    public function test_password_errata_o_utente_ignoto_restituiscono_null(): void
    {
        $dir = new MockDirectory();

        $this->assertNull($dir->authenticate('admin', 'sbagliata'));
        $this->assertNull($dir->authenticate('sconosciuto', 'sconosciuto'));
    }

    public function test_utente_senza_gruppi(): void
    {
        $this->assertSame([], (new MockDirectory())->authenticate('mock.nessungruppo', 'mock.nessungruppo')->groups);
    }
}
```

`tests/Unit/Directory/LdapConfigGuardTest.php`:
```php
<?php

namespace Tests\Unit\Directory;

use App\Services\Directory\LdapConfigGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LdapConfigGuardTest extends TestCase
{
    public function test_mock_in_produzione_lancia_eccezione(): void
    {
        $this->expectException(RuntimeException::class);

        LdapConfigGuard::verifica('production', 'mock');
    }

    public function test_mock_fuori_produzione_e_ammesso(): void
    {
        LdapConfigGuard::verifica('local', 'mock');
        LdapConfigGuard::verifica('testing', 'mock');
        LdapConfigGuard::verifica('production', 'ldap://dc.ente.local');
        LdapConfigGuard::verifica('production', null);

        $this->addToAssertionCount(1);
    }

    public function test_avviso_tls_senza_verifica_solo_in_produzione(): void
    {
        $this->assertNotNull(LdapConfigGuard::avviso('production', true));
        $this->assertNull(LdapConfigGuard::avviso('production', false));
        $this->assertNull(LdapConfigGuard::avviso('local', true));
    }
}
```

- [ ] **Step 2: Eseguire i test e verificare che falliscano**

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test --filter="MockDirectoryTest|LdapConfigGuardTest"`
Expected: FAIL (classi inesistenti).

- [ ] **Step 3: Creare config e classi**

`config/ldap.php`:
```php
<?php

// Connessione AD/LDAP dei dipendenti: configurazione di avvio (.env), non
// da UI. I nomi dei gruppi → ruolo invece stanno in Admin → Impostazioni
// (gruppo "ldap"), vedi MappaGruppiLdap.
return [
    // "mock" = simulatore dev (mai in produzione); vuoto = AD non configurato.
    'host' => env('LDAP_HOST'),
    'port' => (int) env('LDAP_PORT', 389),
    'base_dn' => env('LDAP_BASE_DN'),
    // %s = username digitato (sAMAccountName), es. "%s@ente.local" (UPN)
    'user_dn_template' => env('LDAP_USER_DN_TEMPLATE', '%s'),
    'starttls' => (bool) env('LDAP_STARTTLS', false),
    'tls_skip_verify' => (bool) env('LDAP_TLS_SKIP_VERIFY', false),
    'timeout' => (int) env('LDAP_TIMEOUT', 5),
];
```

`app/Services/Directory/Directory.php`:
```php
<?php

namespace App\Services\Directory;

interface Directory
{
    /**
     * Verifica username/password contro la directory dei dipendenti.
     *
     * @return DirectoryIdentity|null null = credenziali non valide
     *
     * @throws DirectoryNonDisponibile directory configurata ma non raggiungibile
     */
    public function authenticate(string $username, string $password): ?DirectoryIdentity;
}
```

`app/Services/Directory/DirectoryIdentity.php`:
```php
<?php

namespace App\Services\Directory;

final class DirectoryIdentity
{
    /**
     * @param  list<string>  $groups  CN dei gruppi di appartenenza (anche annidati)
     */
    public function __construct(
        public readonly string $guid,
        public readonly string $username,
        public readonly string $name,
        public readonly ?string $email,
        public readonly array $groups,
    ) {}
}
```

`app/Services/Directory/DirectoryNonDisponibile.php`:
```php
<?php

namespace App\Services\Directory;

use RuntimeException;

class DirectoryNonDisponibile extends RuntimeException {}
```

`app/Services/Directory/NullDirectory.php`:
```php
<?php

namespace App\Services\Directory;

/**
 * LDAP_HOST vuoto: nessuna directory, nessun dipendente autenticabile.
 * Il login ricade sugli account locali (ditte/legacy) senza errori.
 */
final class NullDirectory implements Directory
{
    public function authenticate(string $username, string $password): ?DirectoryIdentity
    {
        return null;
    }
}
```

`app/Services/Directory/MockDirectory.php`:
```php
<?php

namespace App\Services\Directory;

/**
 * Simulatore AD per sviluppo locale (LDAP_HOST=mock). Password = username.
 * Rifiutato in produzione da LdapConfigGuard. Prefisso "mock." per non
 * collidere con gli utenti locali creati da `artisan demo`.
 */
final class MockDirectory implements Directory
{
    private const UTENTI = [
        'admin' => 'PRONTOPA_ADMIN',
        'mock.supervisore' => 'PRONTOPA_SUPERVISORI',
        'mock.gestore' => 'PRONTOPA_GESTORI',
        'mock.operaio' => 'PRONTOPA_OPERAI',
        'mock.urp' => 'PRONTOPA_URP',
        'mock.segnalatore' => 'PRONTOPA_SEGNALATORI',
        'mock.nessungruppo' => null,
    ];

    public function authenticate(string $username, string $password): ?DirectoryIdentity
    {
        $username = mb_strtolower(trim($username));

        if (! array_key_exists($username, self::UTENTI) || $password !== $username) {
            return null;
        }

        $gruppo = self::UTENTI[$username];

        return new DirectoryIdentity(
            guid: $this->guid($username),
            username: $username,
            name: 'Mock '.ucfirst(str_replace('mock.', '', $username)),
            email: $username.'@mock.local',
            groups: $gruppo !== null ? [$gruppo] : [],
        );
    }

    private function guid(string $username): string
    {
        $h = md5('prontopa-mock-'.$username);

        return sprintf('%s-%s-%s-%s-%s',
            substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20, 12));
    }
}
```

`app/Services/Directory/LdapConfigGuard.php`:
```php
<?php

namespace App\Services\Directory;

use RuntimeException;

final class LdapConfigGuard
{
    public static function verifica(string $ambiente, ?string $host): void
    {
        if ($ambiente === 'production' && $host === 'mock') {
            throw new RuntimeException('LDAP_HOST=mock non è ammesso in produzione: configura il server AD reale.');
        }
    }

    public static function avviso(string $ambiente, bool $tlsSkipVerify): ?string
    {
        return $ambiente === 'production' && $tlsSkipVerify
            ? 'LDAP_TLS_SKIP_VERIFY=true in produzione: il certificato di Active Directory non viene verificato.'
            : null;
    }
}
```

- [ ] **Step 4: Binding e guardia in `AppServiceProvider`**

In `app/Providers/AppServiceProvider.php`, sostituire `register()`:
```php
    public function register(): void
    {
        $this->app->singleton(\App\Services\Directory\Directory::class, function () {
            $host = config('ldap.host');

            return match (true) {
                $host === 'mock' => new \App\Services\Directory\MockDirectory(),
                blank($host) => new \App\Services\Directory\NullDirectory(),
                default => new \App\Services\Directory\LdapRecordDirectory(config('ldap')),
            };
        });
    }
```
E come prime righe di `boot()`:
```php
        \App\Services\Directory\LdapConfigGuard::verifica((string) $this->app->environment(), config('ldap.host'));

        if ($avviso = \App\Services\Directory\LdapConfigGuard::avviso((string) $this->app->environment(), (bool) config('ldap.tls_skip_verify'))) {
            \Illuminate\Support\Facades\Log::warning($avviso);
        }
```

- [ ] **Step 5: Creare il test helper `FakeDirectory`**

`tests/Support/FakeDirectory.php`:
```php
<?php

namespace Tests\Support;

use App\Services\Directory\Directory;
use App\Services\Directory\DirectoryIdentity;
use App\Services\Directory\DirectoryNonDisponibile;

final class FakeDirectory implements Directory
{
    /** @var array<string, array{0: string, 1: DirectoryIdentity}> */
    private array $utenti = [];

    public bool $nonDisponibile = false;

    /**
     * @param  string|null|false  $email  false = default "<username>@ente.local"; null = account AD senza email
     */
    public static function identita(string $username, array $groups, string|null|false $email = false, ?string $guid = null): DirectoryIdentity
    {
        return new DirectoryIdentity(
            guid: $guid ?? substr(md5($username), 0, 8).'-0000-0000-0000-'.substr(md5($username), 0, 12),
            username: $username,
            name: 'Utente '.$username,
            email: $email === false ? $username.'@ente.local' : $email,
            groups: $groups,
        );
    }

    public function aggiungi(string $password, DirectoryIdentity $id): self
    {
        $this->utenti[mb_strtolower($id->username)] = [$password, $id];

        return $this;
    }

    public function authenticate(string $username, string $password): ?DirectoryIdentity
    {
        if ($this->nonDisponibile) {
            throw new DirectoryNonDisponibile('Directory di test non disponibile');
        }

        $riga = $this->utenti[mb_strtolower($username)] ?? null;

        return ($riga !== null && $riga[0] === $password) ? $riga[1] : null;
    }
}
```
Verificare che `composer.json` → `autoload-dev` mappi `"Tests\\": "tests/"` (già così nei progetti Laravel); se no, aggiungerlo e `composer dump-autoload`.

- [ ] **Step 6: Eseguire i test**

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test --filter="MockDirectoryTest|LdapConfigGuardTest"`
Expected: PASS.

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test`
Expected: PASS (in test `LDAP_HOST` è vuoto → `NullDirectory`, il ramo `LdapRecordDirectory` non viene istanziato).

- [ ] **Step 7: Commit**

```bash
git add config/ldap.php app/Services/Directory app/Providers/AppServiceProvider.php tests/Support/FakeDirectory.php tests/Unit/Directory
git commit -m "feat(auth): astrazione Directory + mock AD dev

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Mapping gruppi AD → ruolo

**Files:**
- Create: `app/Services/Auth/RuoloLdap.php`
- Create: `app/Services/Auth/MappaGruppiLdap.php`
- Modify: `database/seeders/ImpostazioniSeeder.php`
- Test: `tests/Feature/Auth/MappaGruppiLdapTest.php`

**Interfaces:**
- Consumes: `Impostazione::get(string $chiave, mixed $default)`.
- Produces:
  - `final class RuoloLdap(string $ruolo, bool $supervisore, bool $perConto, int $idProvenienza)` — `$ruolo` ∈ `admin|gestore|operaio|segnalatore`.
  - `MappaGruppiLdap::risolvi(array $gruppi): ?RuoloLdap` — confronto case-insensitive e trim; `null` se nessun gruppo ProntoPA.
  - Costanti `MappaGruppiLdap::PROVENIENZA_INTERNA = 1`, `MappaGruppiLdap::PROVENIENZA_URP = 3`.

- [ ] **Step 1: Scrivere il test (fallisce)**

`tests/Feature/Auth/MappaGruppiLdapTest.php` (Feature perché legge `impostazioni` dal DB):
```php
<?php

namespace Tests\Feature\Auth;

use App\Models\Impostazione;
use App\Services\Auth\MappaGruppiLdap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MappaGruppiLdapTest extends TestCase
{
    use RefreshDatabase;

    private function mappa(): MappaGruppiLdap
    {
        return app(MappaGruppiLdap::class);
    }

    public function test_ogni_gruppo_default_mappa_il_suo_ruolo(): void
    {
        $casi = [
            'PRONTOPA_ADMIN' => ['admin', false, false, 1],
            'PRONTOPA_SUPERVISORI' => ['gestore', true, false, 1],
            'PRONTOPA_GESTORI' => ['gestore', false, false, 1],
            'PRONTOPA_OPERAI' => ['operaio', false, false, 1],
            'PRONTOPA_URP' => ['segnalatore', false, true, 3],
            'PRONTOPA_SEGNALATORI' => ['segnalatore', false, false, 1],
        ];

        foreach ($casi as $gruppo => [$ruolo, $sup, $perConto, $prov]) {
            $r = $this->mappa()->risolvi([$gruppo]);
            $this->assertSame($ruolo, $r->ruolo, $gruppo);
            $this->assertSame($sup, $r->supervisore, $gruppo);
            $this->assertSame($perConto, $r->perConto, $gruppo);
            $this->assertSame($prov, $r->idProvenienza, $gruppo);
        }
    }

    public function test_precedenza_vince_il_ruolo_piu_alto(): void
    {
        $r = $this->mappa()->risolvi(['PRONTOPA_OPERAI', 'PRONTOPA_GESTORI', 'PRONTOPA_URP']);

        $this->assertSame('gestore', $r->ruolo);
        $this->assertFalse($r->perConto);
    }

    public function test_confronto_case_insensitive_e_gruppi_estranei_ignorati(): void
    {
        $r = $this->mappa()->risolvi(['Domain Users', ' prontopa_operai ']);

        $this->assertSame('operaio', $r->ruolo);
    }

    public function test_nessun_gruppo_prontopa_restituisce_null(): void
    {
        $this->assertNull($this->mappa()->risolvi(['Domain Users', 'VPN']));
        $this->assertNull($this->mappa()->risolvi([]));
    }

    public function test_nome_gruppo_personalizzato_da_impostazioni(): void
    {
        Impostazione::set('ldap_gruppo_gestori', 'MANUTENZIONE_GESTORI');

        $this->assertSame('gestore', $this->mappa()->risolvi(['MANUTENZIONE_GESTORI'])->ruolo);
        $this->assertNull($this->mappa()->risolvi(['PRONTOPA_GESTORI']));
    }
}
```

- [ ] **Step 2: Eseguire il test e verificare che fallisca**

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test --filter=MappaGruppiLdapTest`
Expected: FAIL (classe inesistente).

- [ ] **Step 3: Implementare**

`app/Services/Auth/RuoloLdap.php`:
```php
<?php

namespace App\Services\Auth;

final class RuoloLdap
{
    public function __construct(
        public readonly string $ruolo,
        public readonly bool $supervisore,
        public readonly bool $perConto,
        public readonly int $idProvenienza,
    ) {}
}
```

`app/Services/Auth/MappaGruppiLdap.php`:
```php
<?php

namespace App\Services\Auth;

use App\Models\Impostazione;

/**
 * Gruppi AD → ruolo ProntoPA. Un solo ruolo per persona (vince il più
 * alto): Segnalazione::scopeVisibileA valuta i ruoli in cascata e un
 * doppio ruolo produrrebbe una visibilità sbagliata. Caposquadra non è un
 * gruppo: si decide sulla squadra in ProntoPA.
 */
final class MappaGruppiLdap
{
    public const PROVENIENZA_INTERNA = 1;

    public const PROVENIENZA_URP = 3;

    /** Ordine = precedenza: [chiave impostazione, default, ruolo, supervisore, per-conto, provenienza] */
    private const LIVELLI = [
        ['ldap_gruppo_admin', 'PRONTOPA_ADMIN', 'admin', false, false, self::PROVENIENZA_INTERNA],
        ['ldap_gruppo_supervisori', 'PRONTOPA_SUPERVISORI', 'gestore', true, false, self::PROVENIENZA_INTERNA],
        ['ldap_gruppo_gestori', 'PRONTOPA_GESTORI', 'gestore', false, false, self::PROVENIENZA_INTERNA],
        ['ldap_gruppo_operai', 'PRONTOPA_OPERAI', 'operaio', false, false, self::PROVENIENZA_INTERNA],
        ['ldap_gruppo_urp', 'PRONTOPA_URP', 'segnalatore', false, true, self::PROVENIENZA_URP],
        ['ldap_gruppo_segnalatori', 'PRONTOPA_SEGNALATORI', 'segnalatore', false, false, self::PROVENIENZA_INTERNA],
    ];

    /**
     * @param  list<string>  $gruppi  CN dei gruppi AD dell'utente
     */
    public function risolvi(array $gruppi): ?RuoloLdap
    {
        $utente = array_map(fn (string $g) => mb_strtoupper(trim($g)), $gruppi);

        foreach (self::LIVELLI as [$chiave, $default, $ruolo, $supervisore, $perConto, $provenienza]) {
            $nome = mb_strtoupper(trim((string) Impostazione::get($chiave, $default)));

            if ($nome !== '' && in_array($nome, $utente, true)) {
                return new RuoloLdap($ruolo, $supervisore, $perConto, $provenienza);
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: Aggiungere le impostazioni al seeder**

In `database/seeders/ImpostazioniSeeder.php`, prima della chiusura `];` dell'array `$impostazioni` (dopo il blocco antivirus), aggiungere:
```php

            // v1.2 — Gruppi Active Directory → ruolo ProntoPA
            [
                'chiave'      => 'ldap_gruppo_admin',
                'valore'      => 'PRONTOPA_ADMIN',
                'tipo'        => 'text',
                'gruppo'      => 'ldap',
                'descrizione' => 'Gruppo AD degli amministratori ProntoPA',
            ],
            [
                'chiave'      => 'ldap_gruppo_supervisori',
                'valore'      => 'PRONTOPA_SUPERVISORI',
                'tipo'        => 'text',
                'gruppo'      => 'ldap',
                'descrizione' => 'Gruppo AD dei gestori supervisori (vedono tutte le segnalazioni)',
            ],
            [
                'chiave'      => 'ldap_gruppo_gestori',
                'valore'      => 'PRONTOPA_GESTORI',
                'tipo'        => 'text',
                'gruppo'      => 'ldap',
                'descrizione' => 'Gruppo AD dei gestori (solo segnalazioni assegnate)',
            ],
            [
                'chiave'      => 'ldap_gruppo_operai',
                'valore'      => 'PRONTOPA_OPERAI',
                'tipo'        => 'text',
                'gruppo'      => 'ldap',
                'descrizione' => 'Gruppo AD degli operai',
            ],
            [
                'chiave'      => 'ldap_gruppo_urp',
                'valore'      => 'PRONTOPA_URP',
                'tipo'        => 'text',
                'gruppo'      => 'ldap',
                'descrizione' => 'Gruppo AD URP/centralino (segnalano per conto di terzi)',
            ],
            [
                'chiave'      => 'ldap_gruppo_segnalatori',
                'valore'      => 'PRONTOPA_SEGNALATORI',
                'tipo'        => 'text',
                'gruppo'      => 'ldap',
                'descrizione' => 'Gruppo AD degli uffici interni che segnalano',
            ],
```

- [ ] **Step 5: Eseguire il test**

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test --filter=MappaGruppiLdapTest`
Expected: PASS (5 test).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Auth/RuoloLdap.php app/Services/Auth/MappaGruppiLdap.php database/seeders/ImpostazioniSeeder.php tests/Feature/Auth/MappaGruppiLdapTest.php
git commit -m "feat(auth): mapping gruppi AD → ruolo

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: `LdapLoginService` — da identità AD a utente

**Files:**
- Create: `app/Services/Auth/AccessoLdapNegato.php`
- Create: `app/Services/Auth/LdapLoginService.php`
- Test: `tests/Feature/Auth/LdapLoginServiceTest.php`

**Interfaces:**
- Consumes: `Directory`, `DirectoryIdentity`, `DirectoryNonDisponibile` (Task 2); `MappaGruppiLdap::risolvi()`, `RuoloLdap` (Task 3); colonne Task 1.
- Produces:
  - `class AccessoLdapNegato extends \RuntimeException` — messaggio mostrabile all'utente.
  - `LdapLoginService::login(string $username, string $password): ?User` — `null` = credenziali non valide; lancia `AccessoLdapNegato` (nessun gruppo, niente email, username occupato) o `DirectoryNonDisponibile`. Non fa `Auth::login`: restituisce l'utente salvato con ruolo/flag/provenienza/permesso sincronizzati e `last_login` aggiornato.

- [ ] **Step 1: Scrivere il test (fallisce)**

`tests/Feature/Auth/LdapLoginServiceTest.php`:
```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\AccessoLdapNegato;
use App\Services\Auth\LdapLoginService;
use App\Services\Directory\Directory;
use App\Services\Directory\DirectoryNonDisponibile;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeDirectory;
use Tests\TestCase;

class LdapLoginServiceTest extends TestCase
{
    use RefreshDatabase;

    private FakeDirectory $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->dir = new FakeDirectory();
        $this->app->instance(Directory::class, $this->dir);
    }

    private function service(): LdapLoginService
    {
        return app(LdapLoginService::class);
    }

    public function test_primo_login_crea_utente_ldap_con_ruolo(): void
    {
        $this->dir->aggiungi('pw', FakeDirectory::identita('m.rossi', ['PRONTOPA_GESTORI'], 'M.Rossi@Ente.local'));

        $user = $this->service()->login('m.rossi', 'pw');

        $this->assertSame('ldap', $user->auth_source);
        $this->assertSame('m.rossi', $user->username);
        $this->assertSame('m.rossi@ente.local', $user->email);
        $this->assertNull($user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->hasRole('gestore'));
        $this->assertTrue($user->gestore_segnalazioni);
        $this->assertFalse($user->supervisore_segnalazioni);
        $this->assertSame(1, $user->id_provenienza);
        $this->assertNotNull($user->last_login);
    }

    public function test_login_successivo_ritrova_per_guid_e_ricalcola_ruolo(): void
    {
        $this->dir->aggiungi('pw', FakeDirectory::identita('m.rossi', ['PRONTOPA_GESTORI'], guid: 'guid-1'));
        $primo = $this->service()->login('m.rossi', 'pw');

        $this->dir->aggiungi('pw', FakeDirectory::identita('m.rossi', ['PRONTOPA_OPERAI'], guid: 'guid-1'));
        $secondo = $this->service()->login('m.rossi', 'pw');

        $this->assertSame($primo->id, $secondo->id);
        $this->assertTrue($secondo->hasRole('operaio'));
        $this->assertFalse($secondo->hasRole('gestore'));
        $this->assertFalse($secondo->gestore_segnalazioni);
    }

    public function test_urp_riceve_permesso_per_conto_e_lo_perde_se_cambia_gruppo(): void
    {
        $this->dir->aggiungi('pw', FakeDirectory::identita('urp1', ['PRONTOPA_URP'], guid: 'g-urp'));
        $user = $this->service()->login('urp1', 'pw');

        $this->assertTrue($user->can('segnalazioni.per-conto'));
        $this->assertSame(3, $user->id_provenienza);

        $this->dir->aggiungi('pw', FakeDirectory::identita('urp1', ['PRONTOPA_SEGNALATORI'], guid: 'g-urp'));
        $user = $this->service()->login('urp1', 'pw');

        $this->assertFalse($user->fresh()->can('segnalazioni.per-conto'));
    }

    public function test_credenziali_errate_o_password_vuota_restituiscono_null(): void
    {
        $this->dir->aggiungi('pw', FakeDirectory::identita('m.rossi', ['PRONTOPA_GESTORI']));

        $this->assertNull($this->service()->login('m.rossi', 'sbagliata'));
        $this->assertNull($this->service()->login('m.rossi', ''));
        $this->assertSame(0, User::count());
    }

    public function test_nessun_gruppo_prontopa_nega_accesso(): void
    {
        $this->dir->aggiungi('pw', FakeDirectory::identita('esterno', ['Domain Users']));

        $this->expectException(AccessoLdapNegato::class);
        $this->service()->login('esterno', 'pw');
    }

    public function test_account_ad_senza_email_nega_accesso(): void
    {
        $this->dir->aggiungi('pw', FakeDirectory::identita('noemail', ['PRONTOPA_OPERAI'], null));

        $this->expectException(AccessoLdapNegato::class);
        $this->service()->login('noemail', 'pw');
    }

    public function test_aggancia_account_legacy_non_ditta_con_stessa_email(): void
    {
        $legacy = User::factory()->create(['username' => 'vecchio.rossi', 'email' => 'm.rossi@ente.local', 'attivo' => false]);
        $this->dir->aggiungi('pw', FakeDirectory::identita('m.rossi', ['PRONTOPA_OPERAI'], 'M.ROSSI@ente.local'));

        $user = $this->service()->login('m.rossi', 'pw');

        $this->assertSame($legacy->id, $user->id);
        $this->assertSame('ldap', $user->auth_source);
        $this->assertSame('m.rossi', $user->username);
        $this->assertNull($user->password);
        $this->assertTrue($user->attivo);
    }

    public function test_non_aggancia_ditte_ne_email_ambigue(): void
    {
        $ditta = User::factory()->create(['email' => 'info@ditta.it', 'id_impresa' => null]);
        $ditta->assignRole('impresa');
        User::factory()->create(['email' => 'doppia@ente.local']);
        User::factory()->create(['email' => 'doppia@ente.local']);

        $this->dir->aggiungi('pw', FakeDirectory::identita('a.ditta', ['PRONTOPA_OPERAI'], 'info@ditta.it', 'g-a'));
        $this->dir->aggiungi('pw', FakeDirectory::identita('a.doppia', ['PRONTOPA_OPERAI'], 'doppia@ente.local', 'g-b'));

        $this->assertNotSame($ditta->id, $this->service()->login('a.ditta', 'pw')->id);
        $this->assertSame('locale', $ditta->fresh()->auth_source);

        $this->service()->login('a.doppia', 'pw');
        $this->assertSame(2, User::where('email', 'doppia@ente.local')->where('auth_source', 'locale')->count());
        $this->assertSame(1, User::where('email', 'doppia@ente.local')->where('auth_source', 'ldap')->count());
    }

    public function test_username_occupato_da_altro_account_nega_accesso_senza_errore_db(): void
    {
        User::factory()->create(['username' => 'gestore', 'email' => 'gestore@demo.local']);
        $this->dir->aggiungi('pw', FakeDirectory::identita('gestore', ['PRONTOPA_GESTORI'], 'gestore@ente.local'));

        $this->expectException(AccessoLdapNegato::class);
        $this->service()->login('gestore', 'pw');
    }

    public function test_directory_non_disponibile_propaga_eccezione(): void
    {
        $this->dir->nonDisponibile = true;

        $this->expectException(DirectoryNonDisponibile::class);
        $this->service()->login('m.rossi', 'pw');
    }
}
```

- [ ] **Step 2: Eseguire il test e verificare che fallisca**

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test --filter=LdapLoginServiceTest`
Expected: FAIL (classe inesistente).

- [ ] **Step 3: Implementare**

`app/Services/Auth/AccessoLdapNegato.php`:
```php
<?php

namespace App\Services\Auth;

use RuntimeException;

/** Credenziali AD valide ma accesso non consentito: il messaggio è per l'utente. */
class AccessoLdapNegato extends RuntimeException {}
```

`app/Services/Auth/LdapLoginService.php`:
```php
<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Directory\Directory;
use App\Services\Directory\DirectoryIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Login dipendenti: AD è la fonte di verità. A ogni login ruolo, flag,
 * provenienza e permesso per-conto vengono ricalcolati dai gruppi.
 */
final class LdapLoginService
{
    public function __construct(
        private readonly Directory $directory,
        private readonly MappaGruppiLdap $mappa,
    ) {}

    /**
     * @return User|null null = credenziali non valide
     *
     * @throws AccessoLdapNegato
     * @throws \App\Services\Directory\DirectoryNonDisponibile
     */
    public function login(string $username, string $password): ?User
    {
        // Su AD un bind con password vuota è un bind anonimo che "riesce".
        if ($password === '') {
            return null;
        }

        $identita = $this->directory->authenticate($username, $password);
        if ($identita === null) {
            return null;
        }

        $ruolo = $this->mappa->risolvi($identita->groups);
        if ($ruolo === null) {
            throw new AccessoLdapNegato('Non sei abilitato a ProntoPA. Contatta l\'amministratore.');
        }

        if (blank($identita->email)) {
            throw new AccessoLdapNegato('Il tuo account di dominio non ha un indirizzo email: contatta l\'amministratore.');
        }

        return DB::transaction(fn () => $this->sincronizza($identita, $ruolo));
    }

    private function sincronizza(DirectoryIdentity $identita, RuoloLdap $ruolo): User
    {
        $email = mb_strtolower(trim((string) $identita->email));

        $user = User::where('ldap_guid', $identita->guid)->first()
            ?? $this->legacyDaAgganciare($email)
            ?? new User();

        $usernameOccupato = User::where('username', $identita->username)
            ->when($user->exists, fn ($q) => $q->whereKeyNot($user->getKey()))
            ->exists();

        if ($usernameOccupato) {
            Log::warning('Login AD rifiutato: username già usato da un altro account ProntoPA', [
                'username' => $identita->username,
                'user_id' => $user->id,
            ]);

            throw new AccessoLdapNegato('Il tuo nome utente è già usato da un altro account ProntoPA: contatta l\'amministratore.');
        }

        $user->forceFill([
            'auth_source' => 'ldap',
            'ldap_guid' => $identita->guid,
            'username' => $identita->username,
            'name' => $identita->name,
            'email' => $email,
            'email_verified_at' => $user->email_verified_at ?? now(),
            'password' => null,
            'password_legacy' => null,
            'amministratore' => $ruolo->ruolo === 'admin',
            'gestore_segnalazioni' => $ruolo->ruolo === 'gestore',
            'supervisore_segnalazioni' => $ruolo->supervisore,
            'id_provenienza' => $ruolo->idProvenienza,
            'attivo' => true,
            'approval_status' => 'approved',
            'last_login' => now(),
        ])->save();

        $user->syncRoles([$ruolo->ruolo]);

        if ($ruolo->perConto) {
            $user->givePermissionTo('segnalazioni.per-conto');
        } else {
            $user->revokePermissionTo('segnalazioni.per-conto');
        }

        return $user;
    }

    /**
     * Primo accesso AD di un dipendente già presente come account locale
     * legacy: aggancio per email (quella di AD è affidabile), così lo
     * storico assegnazioni resta suo. Mai le ditte; mai se ambiguo.
     */
    private function legacyDaAgganciare(string $email): ?User
    {
        $candidati = User::where('auth_source', 'locale')
            ->whereNull('id_impresa')
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'impresa'))
            ->whereRaw('LOWER(email) = ?', [$email])
            ->limit(2)
            ->get();

        if ($candidati->count() > 1) {
            Log::warning('Login AD: più account legacy con la stessa email, nessun aggancio automatico', [
                'email_sha256' => hash('sha256', $email),
            ]);

            return null;
        }

        return $candidati->first();
    }
}
```

- [ ] **Step 4: Eseguire il test**

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test --filter=LdapLoginServiceTest`
Expected: PASS (10 test).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Auth/AccessoLdapNegato.php app/Services/Auth/LdapLoginService.php tests/Feature/Auth/LdapLoginServiceTest.php
git commit -m "feat(auth): login AD con sync ruolo e aggancio legacy

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Client AD reale (LdapRecord) + infrastruttura

**Files:**
- Modify: `docker/php/Dockerfile` (dev), `Dockerfile` (prod)
- Modify: `.github/workflows/tests.yml`, `.github/workflows/dusk.yml` (estensione `ldap`)
- Modify: `composer.json`, `composer.lock` (via `composer require`)
- Create: `app/Services/Directory/LdapRecordDirectory.php`
- Create: `app/Console/Commands/ProvaLdap.php`
- Modify: `docker-compose.yml` (blocco `x-php-env`), `.env.example`
- Test: `tests/Unit/Directory/LdapRecordDirectoryTest.php`

**Interfaces:**
- Consumes: `Directory`, `DirectoryIdentity`, `DirectoryNonDisponibile` (Task 2); `MappaGruppiLdap` (Task 3); `config('ldap.*')`.
- Produces:
  - `LdapRecordDirectory::__construct(array $config)` (array di `config/ldap.php`), implementa `Directory`.
  - `LdapRecordDirectory::endpoint(string $host, int $portaDefault): array{host: string, port: int, ssl: bool}` (static, pubblico per i test).
  - Comando `php artisan ldap:prova {username}`: chiede la password (nascosta), stampa GUID/email/gruppi e il ruolo risolto. Nessuna scrittura su DB.

- [ ] **Step 1: Aggiungere `ext-ldap` ai Dockerfile**

`docker/php/Dockerfile`: nella lista `apk add --no-cache` aggiungere `openldap-dev \` dopo `icu-dev \`; nella riga `docker-php-ext-install` aggiungere `ldap \` dopo `intl \`.

`Dockerfile` (prod):
- Stage `php-extensions`, Layer 1 runtime: aggiungere `libldap` a `libpng libjpeg-turbo libwebp freetype libzip oniguruma icu-libs`.
- Layer 2: aggiungere `openldap-dev` sia in `apk add` sia in `apk del`; aggiungere `ldap` alla lista `docker-php-ext-install`.
- Stage `app`: aggiungere `libldap` a `apk add --no-cache libpng libjpeg-turbo libwebp freetype libzip oniguruma icu-libs`.

- [ ] **Step 2: Ricostruire il container dev e verificare l'estensione**

Run:
```bash
MSYS_NO_PATHCONV=1 docker compose build php
MSYS_NO_PATHCONV=1 docker compose up -d php nginx
MSYS_NO_PATHCONV=1 docker compose exec php php -m | grep -i ldap
```
Expected: `ldap`. (Riavviare anche `nginx`: con il solo `php` ricreato nginx tiene l'IP vecchio → 502. Se `pecl install redis` fallisce con "No releases available", ripetere il build: flakiness di rete pecl.)

- [ ] **Step 3: Installare LdapRecord e aggiornare la CI**

Run: `MSYS_NO_PATHCONV=1 docker compose exec php composer require directorytree/ldaprecord:^3`
Expected: pacchetto installato, `composer.lock` aggiornato.

In `.github/workflows/tests.yml` e `.github/workflows/dusk.yml`, riga `extensions:` → aggiungere `, ldap`:
```yaml
          extensions: mbstring, pdo_sqlite, bcmath, gd, zip, intl, redis, ldap
```

- [ ] **Step 4: Scrivere il test (fallisce)**

`tests/Unit/Directory/LdapRecordDirectoryTest.php`:
```php
<?php

namespace Tests\Unit\Directory;

use App\Services\Directory\LdapRecordDirectory;
use PHPUnit\Framework\TestCase;

class LdapRecordDirectoryTest extends TestCase
{
    public function test_endpoint_da_url_ldap_con_porta(): void
    {
        $this->assertSame(
            ['host' => 'dc.ente.local', 'port' => 3268, 'ssl' => false],
            LdapRecordDirectory::endpoint('ldap://dc.ente.local:3268', 389),
        );
    }

    public function test_endpoint_ldaps_senza_porta_usa_636(): void
    {
        $this->assertSame(
            ['host' => 'dc.ente.local', 'port' => 636, 'ssl' => true],
            LdapRecordDirectory::endpoint('ldaps://dc.ente.local', 389),
        );
    }

    public function test_endpoint_host_nudo_usa_porta_di_default(): void
    {
        $this->assertSame(
            ['host' => '10.0.0.5', 'port' => 389, 'ssl' => false],
            LdapRecordDirectory::endpoint('10.0.0.5', 389),
        );
    }

    public function test_password_vuota_non_contatta_il_server(): void
    {
        $dir = new LdapRecordDirectory(['host' => 'ldap://host.inesistente.invalid', 'port' => 389, 'base_dn' => 'DC=x',
            'user_dn_template' => '%s', 'starttls' => false, 'tls_skip_verify' => false, 'timeout' => 1]);

        $this->assertNull($dir->authenticate('qualcuno', ''));
    }
}
```

- [ ] **Step 5: Eseguire il test e verificare che fallisca**

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test --filter=LdapRecordDirectoryTest`
Expected: FAIL (classe inesistente).

- [ ] **Step 6: Implementare `LdapRecordDirectory`**

Prima di scrivere, verificare contro `vendor/directorytree/ldaprecord/src` (v3) le firme di: `LdapRecord\Connection::__construct(array)`, `Connection::auth()->attempt(string $username, string $password, bool $stayBound)`, `Connection::query()->where()/rawFilter()/select()/first()/get()`, `LdapRecord\Models\Attributes\Guid::getValue()`, `LdapRecord\LdapRecordException`. Se una firma differisce, adattare mantenendo il comportamento sotto.

`app/Services/Directory/LdapRecordDirectory.php`:
```php
<?php

namespace App\Services\Directory;

use LdapRecord\Connection;
use LdapRecord\LdapRecordException;
use LdapRecord\Models\Attributes\Guid;

/**
 * Active Directory reale. Bind con le credenziali dell'utente (nessun
 * account di servizio), poi lettura attributi e gruppi annidati via
 * LDAP_MATCHING_RULE_IN_CHAIN (1.2.840.113556.1.4.1941).
 */
final class LdapRecordDirectory implements Directory
{
    /**
     * @param  array{host: string, port: int, base_dn: ?string, user_dn_template: string, starttls: bool, tls_skip_verify: bool, timeout: int}  $config
     */
    public function __construct(private readonly array $config) {}

    /**
     * @return array{host: string, port: int, ssl: bool}
     */
    public static function endpoint(string $host, int $portaDefault): array
    {
        $ssl = str_starts_with(strtolower($host), 'ldaps://');
        $senzaSchema = preg_replace('#^ldaps?://#i', '', $host);
        [$nome, $porta] = array_pad(explode(':', $senzaSchema, 2), 2, null);

        return [
            'host' => $nome,
            'port' => $porta !== null ? (int) $porta : ($ssl ? 636 : $portaDefault),
            'ssl' => $ssl,
        ];
    }

    public function authenticate(string $username, string $password): ?DirectoryIdentity
    {
        if ($password === '' || trim($username) === '') {
            return null;
        }

        $endpoint = self::endpoint($this->config['host'], $this->config['port']);

        $connection = new Connection([
            'hosts' => [$endpoint['host']],
            'port' => $endpoint['port'],
            'base_dn' => $this->config['base_dn'],
            'use_ssl' => $endpoint['ssl'],
            'use_tls' => $this->config['starttls'],
            'timeout' => $this->config['timeout'],
            'options' => [
                LDAP_OPT_X_TLS_REQUIRE_CERT => $this->config['tls_skip_verify'] ? LDAP_OPT_X_TLS_NEVER : LDAP_OPT_X_TLS_HARD,
                LDAP_OPT_REFERRALS => 0,
            ],
        ]);

        $bindDn = sprintf($this->config['user_dn_template'], $username);

        try {
            if (! $connection->auth()->attempt($bindDn, $password, true)) {
                return null;
            }

            $entry = $connection->query()
                ->where('samaccountname', '=', $username)
                ->select(['objectguid', 'samaccountname', 'displayname', 'mail', 'distinguishedname'])
                ->first();

            if (! $entry) {
                return null;
            }

            $dn = $entry['distinguishedname'][0];

            $gruppi = $connection->query()
                ->rawFilter('(&(objectClass=group)(member:1.2.840.113556.1.4.1941:='.ldap_escape($dn, '', LDAP_ESCAPE_FILTER).'))')
                ->select(['cn'])
                ->get();
        } catch (LdapRecordException $e) {
            throw new DirectoryNonDisponibile('Active Directory non raggiungibile: '.$e->getMessage(), 0, $e);
        } finally {
            $connection->disconnect();
        }

        return new DirectoryIdentity(
            guid: (new Guid($entry['objectguid'][0]))->getValue(),
            username: $entry['samaccountname'][0],
            name: $entry['displayname'][0] ?? $entry['samaccountname'][0],
            email: $entry['mail'][0] ?? null,
            groups: array_values(array_filter(array_map(fn ($g) => $g['cn'][0] ?? null, $gruppi))),
        );
    }
}
```

- [ ] **Step 7: Comando di prova per il sistemista**

`app/Console/Commands/ProvaLdap.php`:
```php
<?php

namespace App\Console\Commands;

use App\Services\Auth\MappaGruppiLdap;
use App\Services\Directory\Directory;
use App\Services\Directory\DirectoryNonDisponibile;
use Illuminate\Console\Command;

class ProvaLdap extends Command
{
    protected $signature = 'ldap:prova {username : sAMAccountName da provare}';

    protected $description = 'Verifica login AD e ruolo risolto per un utente, senza scrivere sul DB';

    public function handle(Directory $directory, MappaGruppiLdap $mappa): int
    {
        $password = (string) $this->secret('Password');

        try {
            $identita = $directory->authenticate($this->argument('username'), $password);
        } catch (DirectoryNonDisponibile $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($identita === null) {
            $this->error('Credenziali non valide (o LDAP_HOST vuoto).');

            return self::FAILURE;
        }

        $ruolo = $mappa->risolvi($identita->groups);

        $this->table(['Campo', 'Valore'], [
            ['GUID', $identita->guid],
            ['Username', $identita->username],
            ['Nome', $identita->name],
            ['Email', $identita->email ?? '— (manca: login rifiutato)'],
            ['Gruppi', implode(', ', $identita->groups) ?: '—'],
            ['Ruolo ProntoPA', $ruolo ? $ruolo->ruolo.($ruolo->supervisore ? ' (supervisore)' : '').($ruolo->perConto ? ' + per-conto' : '') : '— (nessun gruppo: login rifiutato)'],
        ]);

        return self::SUCCESS;
    }
}
```

- [ ] **Step 8: Env in compose ed `.env.example`**

In `docker-compose.yml`, blocco `x-php-env`, dopo `SENTRY_TRACES_SAMPLE_RATE: ...` aggiungere:
```yaml
  LDAP_HOST: ${LDAP_HOST:-}
  LDAP_PORT: ${LDAP_PORT:-389}
  LDAP_BASE_DN: ${LDAP_BASE_DN:-}
  LDAP_USER_DN_TEMPLATE: ${LDAP_USER_DN_TEMPLATE:-%s}
  LDAP_STARTTLS: ${LDAP_STARTTLS:-false}
  LDAP_TLS_SKIP_VERIFY: ${LDAP_TLS_SKIP_VERIFY:-false}
  LDAP_TIMEOUT: ${LDAP_TIMEOUT:-5}
```

In `.env.example`, in fondo:
```env

# ── Active Directory (login dipendenti) ─────────────────────────────────────
# Sviluppo locale senza AD: LDAP_HOST=mock → utenti simulati (password =
# username): admin, mock.supervisore, mock.gestore, mock.operaio, mock.urp,
# mock.segnalatore, mock.nessungruppo. MAI in produzione (l'app non parte).
# Vuoto = nessun login dipendenti (solo ditte e account locali legacy).
LDAP_HOST=mock
LDAP_PORT=389
LDAP_BASE_DN=DC=ente,DC=local
# %s = username digitato. UPN (consigliato): %s@ente.local
LDAP_USER_DN_TEMPLATE=%s@ente.local
LDAP_STARTTLS=false
# true ammesso solo in sviluppo (certificato AD self-signed)
LDAP_TLS_SKIP_VERIFY=false
LDAP_TIMEOUT=5
# Verifica rapida: docker compose exec php php artisan ldap:prova <username>
```

- [ ] **Step 9: Eseguire test e analisi statica**

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test --filter=LdapRecordDirectoryTest`
Expected: PASS (4 test).

Run: `MSYS_NO_PATHCONV=1 docker compose exec php composer run analyse`
Expected: nessun errore nuovo. Se Larastan segnala tipi dell'array `$entry` di LdapRecord, tipizzare con `@var array<string, array<int, string>> $entry` invece di aggiungere righe alla baseline.

- [ ] **Step 10: Commit**

```bash
git add docker/php/Dockerfile Dockerfile .github/workflows/tests.yml .github/workflows/dusk.yml composer.json composer.lock app/Services/Directory/LdapRecordDirectory.php app/Console/Commands/ProvaLdap.php docker-compose.yml .env.example tests/Unit/Directory/LdapRecordDirectoryTest.php
git commit -m "feat(auth): client AD LdapRecord + ldap:prova

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Instradamento del form di login

**Files:**
- Create: `app/Services/Auth/NormalizzaUsernameAd.php`
- Modify: `app/Http/Requests/Auth/LoginRequest.php`
- Modify: `resources/views/auth/login.blade.php`
- Test: `tests/Unit/Auth/NormalizzaUsernameAdTest.php`, `tests/Feature/Auth/LoginInstradamentoTest.php`

**Interfaces:**
- Consumes: `LdapLoginService::login()`, `AccessoLdapNegato` (Task 4); `DirectoryNonDisponibile`, `FakeDirectory` (Task 2); `User::metodoSecondoFattore()` (Task 1). Il servizio `CodiceAccessoEmail` arriva nel Task 7: in questo task `LoginRequest` mette in sessione `login.metodo` ma **non** invia il codice (lo farà il Task 7).
- Produces:
  - `NormalizzaUsernameAd::èUpn(string $login, string $template): bool`, `NormalizzaUsernameAd::normalizza(string $login, string $template): string`.
  - Sessione dopo credenziali valide con secondo fattore: `login.id`, `login.remember`, `login.metodo` (`'totp'`|`'email'`).
  - Regola d'instradamento: contiene `@` e non è UPN del dominio → account `locale` per email (lower/trim); altrimenti → AD, poi fallback su account `locale` per username (**transitorio fino al cutover, fase 3**).

- [ ] **Step 1: Scrivere i test (falliscono)**

`tests/Unit/Auth/NormalizzaUsernameAdTest.php`:
```php
<?php

namespace Tests\Unit\Auth;

use App\Services\Auth\NormalizzaUsernameAd;
use PHPUnit\Framework\TestCase;

class NormalizzaUsernameAdTest extends TestCase
{
    public function test_riconosce_upn_del_dominio(): void
    {
        $this->assertTrue(NormalizzaUsernameAd::èUpn('M.Rossi@Ente.Local', '%s@ente.local'));
        $this->assertFalse(NormalizzaUsernameAd::èUpn('info@ditta.it', '%s@ente.local'));
        $this->assertFalse(NormalizzaUsernameAd::èUpn('m.rossi@ente.local', 'CN=%s,OU=Utenti,DC=ente,DC=local'));
    }

    public function test_normalizza_upn_dominio_e_nudo(): void
    {
        $t = '%s@ente.local';

        $this->assertSame('m.rossi', NormalizzaUsernameAd::normalizza(' m.rossi@ente.local ', $t));
        $this->assertSame('m.rossi', NormalizzaUsernameAd::normalizza('ENTE\\m.rossi', $t));
        $this->assertSame('m.rossi', NormalizzaUsernameAd::normalizza('m.rossi', $t));
    }
}
```

`tests/Feature/Auth/LoginInstradamentoTest.php`:
```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Directory\Directory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeDirectory;
use Tests\TestCase;

class LoginInstradamentoTest extends TestCase
{
    use RefreshDatabase;

    private FakeDirectory $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['ldap.user_dn_template' => '%s@ente.local']);
        $this->dir = new FakeDirectory();
        $this->app->instance(Directory::class, $this->dir);
    }

    public function test_dipendente_entra_con_username_ad(): void
    {
        $this->dir->aggiungi('pw', FakeDirectory::identita('m.rossi', ['PRONTOPA_GESTORI']));

        $response = $this->post('/login', ['username' => 'm.rossi', 'password' => 'pw']);

        $this->assertAuthenticated();
        $this->assertSame('ldap', auth()->user()->auth_source);
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_dipendente_entra_con_upn_o_dominio_barra(): void
    {
        $this->dir->aggiungi('pw', FakeDirectory::identita('m.rossi', ['PRONTOPA_OPERAI']));

        $this->post('/login', ['username' => 'M.Rossi@ente.local', 'password' => 'pw']);
        $this->assertAuthenticated();

        auth()->logout();

        $this->post('/login', ['username' => 'ENTE\\m.rossi', 'password' => 'pw']);
        $this->assertAuthenticated();
    }

    public function test_account_locale_entra_con_email_maiuscole_e_spazi(): void
    {
        $user = User::factory()->create(['email' => 'mario@ditta.it']);

        $this->post('/login', ['username' => '  Mario@Ditta.IT ', 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_email_non_seleziona_mai_un_utente_ldap(): void
    {
        User::factory()->create(['email' => 'stessa@ente.it', 'auth_source' => 'ldap', 'ldap_guid' => 'g-1', 'password' => bcrypt('password')]);

        $this->post('/login', ['username' => 'stessa@ente.it', 'password' => 'password']);

        $this->assertGuest();
    }

    public function test_email_con_account_locale_e_ldap_uguali_entra_la_ditta(): void
    {
        User::factory()->create(['email' => 'stessa@ente.it', 'auth_source' => 'ldap', 'ldap_guid' => 'g-1', 'password' => null]);
        $ditta = User::factory()->create(['email' => 'stessa@ente.it']);

        $this->post('/login', ['username' => 'stessa@ente.it', 'password' => 'password']);

        $this->assertAuthenticatedAs($ditta);
    }

    public function test_ad_valido_senza_gruppo_mostra_messaggio_dedicato(): void
    {
        $this->dir->aggiungi('pw', FakeDirectory::identita('esterno', ['Domain Users']));

        $response = $this->from('/login')->post('/login', ['username' => 'esterno', 'password' => 'pw']);

        $this->assertGuest();
        $response->assertSessionHasErrors(['username' => 'Non sei abilitato a ProntoPA. Contatta l\'amministratore.']);
    }

    public function test_legacy_con_username_entra_se_ad_non_lo_conosce(): void
    {
        $legacy = User::factory()->create(['username' => 'vecchio.utente']);

        $this->post('/login', ['username' => 'vecchio.utente', 'password' => 'password']);

        $this->assertAuthenticatedAs($legacy);
    }

    public function test_ad_giu_legacy_entra_comunque(): void
    {
        $this->dir->nonDisponibile = true;
        $legacy = User::factory()->create(['username' => 'vecchio.utente']);

        $this->post('/login', ['username' => 'vecchio.utente', 'password' => 'password']);

        $this->assertAuthenticatedAs($legacy);
    }

    public function test_ad_giu_dipendente_vede_non_disponibile(): void
    {
        $this->dir->nonDisponibile = true;

        $response = $this->from('/login')->post('/login', ['username' => 'm.rossi', 'password' => 'pw']);

        $this->assertGuest();
        $response->assertSessionHasErrors(['username' => 'Autenticazione dei dipendenti temporaneamente non disponibile. Riprova più tardi.']);
    }

    public function test_ad_giu_ditta_via_email_entra(): void
    {
        $this->dir->nonDisponibile = true;
        $user = User::factory()->create(['email' => 'mario@ditta.it']);

        $this->post('/login', ['username' => 'mario@ditta.it', 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_credenziali_errate_messaggio_generico(): void
    {
        $response = $this->from('/login')->post('/login', ['username' => 'nessuno', 'password' => 'x']);

        $this->assertGuest();
        $response->assertSessionHasErrors(['username' => trans('auth.failed')]);
    }

    public function test_ditta_con_credenziali_valide_va_al_challenge_email(): void
    {
        $ditta = User::factory()->create(['email' => 'mario@ditta.it']);
        $ditta->assignRole('impresa');

        $response = $this->post('/login', ['username' => 'mario@ditta.it', 'password' => 'password']);

        $this->assertGuest();
        $response->assertRedirect(route('two-factor.login'));
        $this->assertSame('email', session('login.metodo'));
        $this->assertSame($ditta->id, session('login.id'));
    }
}
```

- [ ] **Step 2: Eseguire i test e verificare che falliscano**

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test --filter="NormalizzaUsernameAdTest|LoginInstradamentoTest"`
Expected: FAIL.

- [ ] **Step 3: Implementare `NormalizzaUsernameAd`**

`app/Services/Auth/NormalizzaUsernameAd.php`:
```php
<?php

namespace App\Services\Auth;

/**
 * I dipendenti scrivono lo username in tre modi: "m.rossi", l'UPN
 * "m.rossi@ente.local" (che contiene @ ma NON è un'email ditta) o
 * "ENTE\m.rossi". Tutti diventano "m.rossi".
 */
final class NormalizzaUsernameAd
{
    public static function èUpn(string $login, string $template): bool
    {
        if (! str_starts_with($template, '%s@')) {
            return false;
        }

        $suffisso = mb_strtolower(substr($template, 3));

        return str_ends_with(mb_strtolower(trim($login)), '@'.$suffisso);
    }

    public static function normalizza(string $login, string $template): string
    {
        $login = trim($login);

        if (str_contains($login, '\\')) {
            $login = substr($login, strrpos($login, '\\') + 1);
        }

        if (self::èUpn($login, $template)) {
            $login = substr($login, 0, strrpos($login, '@'));
        }

        return $login;
    }
}
```

- [ ] **Step 4: Riscrivere `LoginRequest::authenticate()`**

In `app/Http/Requests/Auth/LoginRequest.php`:

Sostituire gli `use` in testa con:
```php
use App\Models\User;
use App\Services\Auth\AccessoLdapNegato;
use App\Services\Auth\LdapLoginService;
use App\Services\Auth\NormalizzaUsernameAd;
use App\Services\Directory\DirectoryNonDisponibile;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
```

Sostituire il metodo `authenticate()` (docblock incluso) con:
```php
    /**
     * Instrada per contenuto del campo: con "@" (che non sia l'UPN del
     * dominio AD) → account locale per email (ditte); altrimenti → Active
     * Directory. Le credenziali vengono solo verificate: il login vero
     * avviene qui se non serve secondo fattore, altrimenti dopo il
     * challenge (sessione 'login.*', stesso pattern di Fortify).
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $login = trim((string) $this->input('username'));
        $password = (string) $this->input('password');
        $template = (string) config('ldap.user_dn_template', '%s');

        $user = str_contains($login, '@') && ! NormalizzaUsernameAd::èUpn($login, $template)
            ? $this->tentaLocale('email', mb_strtolower($login), $password)
            : $this->tentaDipendente(NormalizzaUsernameAd::normalizza($login, $template), $password);

        if ($user === null) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'username' => trans('auth.failed'),
            ]);
        }

        $this->ensureUserIsActive($user);
        RateLimiter::clear($this->throttleKey());

        $metodo = $user->metodoSecondoFattore();

        if ($metodo !== null) {
            $this->session()->put([
                'login.id'       => $user->getKey(),
                'login.remember' => $this->boolean('remember'),
                'login.metodo'   => $metodo,
            ]);

            return;
        }

        Auth::login($user, $this->boolean('remember'));
    }

    /**
     * @throws ValidationException
     */
    private function tentaDipendente(string $username, string $password): ?User
    {
        try {
            $user = app(LdapLoginService::class)->login($username, $password);
        } catch (AccessoLdapNegato $e) {
            throw ValidationException::withMessages(['username' => $e->getMessage()]);
        } catch (DirectoryNonDisponibile $e) {
            report($e);

            return $this->tentaLocale('username', $username, $password)
                ?? throw ValidationException::withMessages([
                    'username' => 'Autenticazione dei dipendenti temporaneamente non disponibile. Riprova più tardi.',
                ]);
        }

        // TRANSITORIO fino al cutover (fase 3): account locali legacy che
        // entrano ancora con username. Da rimuovere in utenze:cutover.
        return $user ?? $this->tentaLocale('username', $username, $password);
    }

    /**
     * Solo account locali: un utente ldap/spid non entra mai con password,
     * anche se ha la stessa email di una ditta.
     */
    private function tentaLocale(string $campo, string $valore, string $password): ?User
    {
        $user = User::where('auth_source', 'locale')
            ->when(
                $campo === 'email',
                fn ($q) => $q->whereRaw('LOWER(email) = ?', [$valore]),
                fn ($q) => $q->where('username', $valore),
            )
            ->orderByDesc('attivo')
            ->first();

        if ($user === null || $user->password === null || ! Hash::check($password, $user->password)) {
            return null;
        }

        return $user;
    }
```

Il metodo `ensureUserIsActive(?User $user)` resta invariato (chiama `Auth::logout()` solo se c'è un utente autenticato: ora nessuno lo è a quel punto, quindi resta innocuo).

- [ ] **Step 5: Aggiornare la vista di login**

In `resources/views/auth/login.blade.php`, sostituire il blocco del campo username:
```blade
            <div class="pa-field">
                <label class="pa-field-label" for="username">Username</label>
                <input id="username" type="text" name="username" class="pa-input"
                       value="{{ old('username') }}" required autofocus autocomplete="username">
            </div>
```
con:
```blade
            <div class="pa-field">
                <label class="pa-field-label" for="username">Utente di dominio (dipendenti) o email (ditte)</label>
                <input id="username" type="text" name="username" class="pa-input"
                       value="{{ old('username') }}" required autofocus autocomplete="username"
                       aria-describedby="username-aiuto">
                <span id="username-aiuto" style="font-size:12px; color:var(--slate-500);">
                    Dipendenti: le credenziali del PC dell'ufficio. Ditte: l'email registrata.
                </span>
            </div>
```

- [ ] **Step 6: Eseguire i test**

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test --filter="NormalizzaUsernameAdTest|LoginInstradamentoTest|AuthenticationTest|TwoFactorAuthenticationTest"`
Expected: PASS. (`AuthenticationTest` e `TwoFactorAuthenticationTest` esistenti entrano con username locale: passano grazie al fallback legacy, con `NullDirectory` perché `LDAP_HOST` è vuoto in test.)

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Auth/NormalizzaUsernameAd.php app/Http/Requests/Auth/LoginRequest.php resources/views/auth/login.blade.php tests/Unit/Auth/NormalizzaUsernameAdTest.php tests/Feature/Auth/LoginInstradamentoTest.php
git commit -m "feat(auth): login instrada AD vs email ditta

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: 2FA con codice via email

**Files:**
- Create: `app/Services/Auth/CodiceAccessoEmail.php`
- Create: `app/Notifications/CodiceAccessoNotification.php`
- Modify: `app/Http/Requests/Auth/LoginRequest.php` (invio codice)
- Modify: `app/Http/Controllers/Auth/TwoFactorChallengeController.php`
- Modify: `resources/views/auth/two-factor-challenge.blade.php`
- Modify: `routes/auth.php`
- Test: `tests/Feature/Auth/CodiceAccessoEmailTest.php`

**Interfaces:**
- Consumes: sessione `login.id`/`login.remember`/`login.metodo` (Task 6); `User::metodoSecondoFattore()` (Task 1).
- Produces:
  - `CodiceAccessoEmail::invia(User $user): void`, `CodiceAccessoEmail::verifica(User $user, string $codice): bool` — codice 6 cifre, hash SHA-256 in cache `2fa-email:{user_id}`, 10 minuti dalla generazione, max 5 tentativi errati poi invalidato, codice consumato al successo.
  - `CodiceAccessoNotification(string $codice, int $minuti)` (canale `mail`).
  - Route `POST /two-factor-challenge/reinvia` → name `two-factor.reinvia`, middleware `guest` + `throttle:1,1`.

- [ ] **Step 1: Scrivere il test (fallisce)**

`tests/Feature/Auth/CodiceAccessoEmailTest.php`:
```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\CodiceAccessoNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CodiceAccessoEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $ditta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        Notification::fake();

        $this->ditta = User::factory()->create(['email' => 'mario@ditta.it']);
        $this->ditta->assignRole('impresa');
    }

    private function loginEPrendiCodice(): string
    {
        $this->post('/login', ['username' => 'mario@ditta.it', 'password' => 'password']);

        $codice = null;
        Notification::assertSentTo($this->ditta, CodiceAccessoNotification::class, function ($n) use (&$codice) {
            $codice = $n->codice;

            return true;
        });

        return $codice;
    }

    public function test_login_ditta_invia_codice_e_mostra_challenge_email(): void
    {
        $codice = $this->loginEPrendiCodice();

        $this->assertMatchesRegularExpression('/^\d{6}$/', $codice);
        $this->assertGuest();
        $this->get('/two-factor-challenge')->assertOk()->assertSee('Abbiamo inviato un codice');
    }

    public function test_codice_corretto_completa_il_login_e_pulisce_la_sessione(): void
    {
        $codice = $this->loginEPrendiCodice();

        $response = $this->post('/two-factor-challenge', ['code' => $codice]);

        $this->assertAuthenticatedAs($this->ditta);
        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertNull(session('login.id'));
        $this->assertNull(session('login.metodo'));
    }

    public function test_codice_errato_non_autentica(): void
    {
        $this->loginEPrendiCodice();

        $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_dopo_cinque_errori_anche_il_codice_giusto_non_vale(): void
    {
        $codice = $this->loginEPrendiCodice();
        $sbagliato = $codice === '111111' ? '222222' : '111111';

        for ($i = 0; $i < 5; $i++) {
            $this->post('/two-factor-challenge', ['code' => $sbagliato]);
        }
        $this->post('/two-factor-challenge', ['code' => $codice]);

        $this->assertGuest();
    }

    public function test_codice_scaduto_dopo_dieci_minuti(): void
    {
        $codice = $this->loginEPrendiCodice();

        $this->travel(11)->minutes();
        $this->post('/two-factor-challenge', ['code' => $codice]);

        $this->assertGuest();
    }

    public function test_reinvio_genera_nuovo_codice_e_invalida_il_vecchio(): void
    {
        $vecchio = $this->loginEPrendiCodice();
        Notification::fake();

        $this->post('/two-factor-challenge/reinvia')->assertRedirect(route('two-factor.login'));

        $nuovo = null;
        Notification::assertSentTo($this->ditta, CodiceAccessoNotification::class, function ($n) use (&$nuovo) {
            $nuovo = $n->codice;

            return true;
        });

        if ($nuovo !== $vecchio) {
            $this->post('/two-factor-challenge', ['code' => $vecchio]);
            $this->assertGuest();
        }

        $this->post('/two-factor-challenge', ['code' => $nuovo]);
        $this->assertAuthenticatedAs($this->ditta);
    }

    public function test_reinvio_senza_login_in_corso_torna_al_login(): void
    {
        $this->post('/two-factor-challenge/reinvia')->assertRedirect(route('login'));

        Notification::assertNothingSent();
    }
}
```

- [ ] **Step 2: Eseguire il test e verificare che fallisca**

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test --filter=CodiceAccessoEmailTest`
Expected: FAIL.

- [ ] **Step 3: Servizio e notifica**

`app/Services/Auth/CodiceAccessoEmail.php`:
```php
<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Notifications\CodiceAccessoNotification;
use Illuminate\Support\Facades\Cache;

/**
 * Secondo fattore via email per gli account locali (ditte): nessuna app da
 * installare. In cache solo l'hash; scadenza fissa dalla generazione (gli
 * errori non la allungano).
 */
final class CodiceAccessoEmail
{
    public const MINUTI_VALIDITA = 10;

    public const MAX_TENTATIVI = 5;

    public function invia(User $user): void
    {
        $codice = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $scade = now()->addMinutes(self::MINUTI_VALIDITA);

        Cache::put($this->chiave($user), [
            'hash' => hash('sha256', $codice),
            'tentativi' => 0,
            'scade' => $scade->getTimestamp(),
        ], $scade);

        $user->notify(new CodiceAccessoNotification($codice, self::MINUTI_VALIDITA));
    }

    public function verifica(User $user, string $codice): bool
    {
        $chiave = $this->chiave($user);
        $dati = Cache::get($chiave);

        if (! is_array($dati) || $dati['scade'] <= now()->getTimestamp()) {
            Cache::forget($chiave);

            return false;
        }

        if (hash_equals($dati['hash'], hash('sha256', trim($codice)))) {
            Cache::forget($chiave);

            return true;
        }

        $dati['tentativi']++;

        if ($dati['tentativi'] >= self::MAX_TENTATIVI) {
            Cache::forget($chiave);
        } else {
            Cache::put($chiave, $dati, now()->setTimestamp($dati['scade']));
        }

        return false;
    }

    private function chiave(User $user): string
    {
        return '2fa-email:'.$user->getKey();
    }
}
```

`app/Notifications/CodiceAccessoNotification.php`:
```php
<?php

namespace App\Notifications;

use App\Models\Impostazione;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CodiceAccessoNotification extends Notification
{
    public function __construct(
        public readonly string $codice,
        public readonly int $minuti,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ente = Impostazione::get('ente_nome', 'ProntoPA');

        return (new MailMessage)
            ->subject("Codice di accesso — {$ente}")
            ->line('Il tuo codice di accesso è:')
            ->line("**{$this->codice}**")
            ->line("Scade tra {$this->minuti} minuti.")
            ->line("Se non hai appena tentato di accedere a ProntoPA, ignora questa email e avvisa {$ente}.");
    }
}
```

- [ ] **Step 4: Invio del codice al login**

In `app/Http/Requests/Auth/LoginRequest.php`, nel blocco `if ($metodo !== null) { ... }` di `authenticate()`, subito prima di `return;` aggiungere:
```php
            if ($metodo === 'email') {
                app(\App\Services\Auth\CodiceAccessoEmail::class)->invia($user);
            }
```

- [ ] **Step 5: Challenge controller**

Sostituire il contenuto di `app/Http/Controllers/Auth/TwoFactorChallengeController.php` con:
```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\CodiceAccessoEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;

class TwoFactorChallengeController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('login.id')) {
            return redirect()->route('login');
        }

        $metodo = $request->session()->get('login.metodo', 'totp');
        $emailMascherata = null;

        if ($metodo === 'email') {
            $email = (string) User::find($request->session()->get('login.id'))?->email;
            [$locale, $dominio] = array_pad(explode('@', $email, 2), 2, '');
            $emailMascherata = Str::substr($locale, 0, 1).'***@'.$dominio;
        }

        return view('auth.two-factor-challenge', compact('metodo', 'emailMascherata'));
    }

    public function store(TwoFactorLoginRequest $request, CodiceAccessoEmail $codici): RedirectResponse
    {
        if (! $request->hasChallengedUser()) {
            return redirect()->route('login');
        }

        /** @var User $user */
        $user = $request->challengedUser();

        if ($request->session()->get('login.metodo') === 'email') {
            if (! $codici->verifica($user, (string) $request->input('code', ''))) {
                throw ValidationException::withMessages(['code' => 'Codice non valido o scaduto.']);
            }
        } elseif (! $request->hasValidCode()) {
            $validCode = $request->validRecoveryCode();

            if (! $validCode) {
                throw ValidationException::withMessages(['code' => 'Codice non valido.']);
            }

            $user->replaceRecoveryCode($validCode);
        }

        Auth::login($user, $request->remember());

        $request->session()->forget(['login.id', 'login.remember', 'login.metodo']);
        $request->session()->regenerate();
        $user->update(['last_login' => now()]);

        return redirect()->intended(route('dashboard'));
    }

    public function reinvia(Request $request, CodiceAccessoEmail $codici): RedirectResponse
    {
        $user = User::find($request->session()->get('login.id'));

        if ($user === null || $request->session()->get('login.metodo') !== 'email') {
            return redirect()->route('login');
        }

        $codici->invia($user);

        return redirect()->route('two-factor.login')->with('status', 'Ti abbiamo inviato un nuovo codice.');
    }
}
```
Nota: `$request->remember()` di Fortify legge `login.remember` dalla sessione, quindi va chiamato prima del `forget` (lo è).

- [ ] **Step 6: Route**

In `routes/auth.php`, dentro il gruppo `guest`, dopo la route `POST two-factor-challenge`:
```php
    Route::post('two-factor-challenge/reinvia', [TwoFactorChallengeController::class, 'reinvia'])
        ->middleware('throttle:1,1')
        ->name('two-factor.reinvia');
```

- [ ] **Step 7: Vista challenge**

In `resources/views/auth/two-factor-challenge.blade.php`, sostituire dal `<div>` con `<h2>` fino alla fine del `</form>` (righe del titolo, errori e form) con:
```blade
    <div>
        <h2 style="font-family:var(--font-ui); font-size:24px; font-weight:700;
                   color:var(--ink); margin:0; letter-spacing:-.01em;">Verifica in due passaggi</h2>
        <p style="font-size:14px; color:var(--slate-600); margin:6px 0 0;">
            @if(($metodo ?? 'totp') === 'email')
                Abbiamo inviato un codice a 6 cifre a <strong>{{ $emailMascherata }}</strong>. Scade tra 10 minuti.
            @else
                Inserisci il codice generato dall'app di autenticazione, oppure uno dei tuoi codici di recupero.
            @endif
        </p>
    </div>

    @if(session('status'))
        <div role="status" style="background:var(--emerald-100); color:var(--emerald); border-radius:var(--radius-sm);
                    padding:10px 14px; font-size:13px;">
            {{ session('status') }}
        </div>
    @endif

    @if($errors->any())
        <div role="alert" style="background:var(--rose-100); color:var(--rose); border-radius:var(--radius-sm);
                    padding:10px 14px; font-size:13px; border:1px solid color-mix(in srgb,var(--rose) 25%,#fff);">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('two-factor.login') }}" style="display:flex; flex-direction:column; gap:20px;">
        @csrf

        <div class="pa-field">
            <label class="pa-field-label" for="code">Codice a 6 cifre</label>
            <input id="code" type="text" name="code" class="pa-input" inputmode="numeric"
                   autocomplete="one-time-code" autofocus placeholder="000000">
        </div>

        @if(($metodo ?? 'totp') !== 'email')
            <div class="pa-field">
                <label class="pa-field-label" for="recovery_code">Oppure codice di recupero</label>
                <input id="recovery_code" type="text" name="recovery_code" class="pa-input" autocomplete="off">
            </div>
        @endif

        <button type="submit" class="pa-btn pa-btn-primary pa-btn-lg" style="width:100%;">
            Verifica e accedi
        </button>
    </form>

    @if(($metodo ?? 'totp') === 'email')
        <form method="POST" action="{{ route('two-factor.reinvia') }}" style="text-align:center;">
            @csrf
            <button type="submit" style="background:none; border:none; cursor:pointer; font-size:13px;
                           font-weight:600; color:var(--ente-primary);">Non è arrivato? Invia un nuovo codice</button>
        </form>
    @endif
```

- [ ] **Step 8: Eseguire i test**

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test --filter="CodiceAccessoEmailTest|TwoFactorAuthenticationTest|LoginInstradamentoTest"`
Expected: PASS.

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Services/Auth/CodiceAccessoEmail.php app/Notifications/CodiceAccessoNotification.php app/Http/Requests/Auth/LoginRequest.php app/Http/Controllers/Auth/TwoFactorChallengeController.php resources/views/auth/two-factor-challenge.blade.php routes/auth.php tests/Feature/Auth/CodiceAccessoEmailTest.php
git commit -m "feat(auth): 2FA con codice via email per ditte

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Reset password e unicità email solo per account locali

**Files:**
- Modify: `app/Http/Controllers/Auth/PasswordResetLinkController.php`
- Modify: `app/Http/Controllers/Auth/NewPasswordController.php`
- Modify: `app/Http/Controllers/Admin/UtentiController.php`
- Test: `tests/Feature/Auth/PasswordResetLocaleTest.php`, `tests/Feature/Admin/UtentiEmailUnicaTest.php`

**Interfaces:**
- Consumes: colonna `auth_source` (Task 1).
- Produces: il broker password riceve sempre `auth_source = 'locale'` e `attivo = true` tra le credenziali (l'`EloquentUserProvider` li trasforma in `where`); validazione email in `UtentiController` unica solo tra utenti `locale` attivi.

- [ ] **Step 1: Scrivere i test (falliscono)**

`tests/Feature/Auth/PasswordResetLocaleTest.php`:
```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        Notification::fake();
    }

    public function test_reset_va_solo_alla_ditta_se_email_condivisa_con_utente_ad(): void
    {
        $ad = User::factory()->create(['email' => 'stessa@ente.it', 'auth_source' => 'ldap', 'ldap_guid' => 'g-1', 'password' => null]);
        $ditta = User::factory()->create(['email' => 'stessa@ente.it']);

        $this->post('/forgot-password', ['email' => 'stessa@ente.it']);

        Notification::assertSentTo($ditta, ResetPassword::class);
        Notification::assertNotSentTo($ad, ResetPassword::class);
    }

    public function test_nessun_reset_per_utente_solo_ad(): void
    {
        $ad = User::factory()->create(['email' => 'solo@ente.it', 'auth_source' => 'ldap', 'ldap_guid' => 'g-2', 'password' => null]);

        $this->post('/forgot-password', ['email' => 'solo@ente.it']);

        Notification::assertNotSentTo($ad, ResetPassword::class);
    }

    public function test_nessun_reset_per_account_locale_disattivato(): void
    {
        $vecchio = User::factory()->create(['email' => 'vecchio@ente.it', 'attivo' => false]);

        $this->post('/forgot-password', ['email' => 'vecchio@ente.it']);

        Notification::assertNotSentTo($vecchio, ResetPassword::class);
    }
}
```

`tests/Feature/Admin/UtentiEmailUnicaTest.php`:
```php
<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UtentiEmailUnicaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create(['amministratore' => true]);
        $this->admin->assignRole('admin');
    }

    private function dati(string $email, string $username): array
    {
        return ['name' => 'Ditta Test', 'username' => $username, 'email' => $email,
            'password' => 'Password123', 'ruolo' => 'impresa', 'attivo' => true];
    }

    public function test_ditta_puo_usare_email_gia_usata_da_utente_ad(): void
    {
        User::factory()->create(['email' => 'stessa@ente.it', 'auth_source' => 'ldap', 'ldap_guid' => 'g-1']);

        $this->actingAs($this->admin)->post(route('admin.utenti.store'), $this->dati('stessa@ente.it', 'ditta1'))
            ->assertSessionHasNoErrors();
    }

    public function test_ditta_non_puo_usare_email_di_altro_account_locale_attivo(): void
    {
        User::factory()->create(['email' => 'presa@ditta.it']);

        $this->actingAs($this->admin)->post(route('admin.utenti.store'), $this->dati('presa@ditta.it', 'ditta2'))
            ->assertSessionHasErrors('email');
    }
}
```
Prima di eseguire, verificare in `routes/web.php` il nome esatto della route di creazione utenti admin (atteso `admin.utenti.store`, coerente con `admin.utenti.index` usato in `UtentiController`) e il middleware che la protegge; se il nome differisce, adattare il test.

- [ ] **Step 2: Eseguire i test e verificare che falliscano**

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test --filter="PasswordResetLocaleTest|UtentiEmailUnicaTest"`
Expected: FAIL (il reset parte anche verso l'utente AD / la creazione con email di utente AD viene rifiutata).

- [ ] **Step 3: Implementare**

`app/Http/Controllers/Auth/PasswordResetLinkController.php`, in `store()` sostituire:
```php
        $status = Password::sendResetLink(
            $request->only('email')
        );
```
con:
```php
        // Solo account locali attivi (ditte): ldap/spid non hanno password
        // ProntoPA, e l'email non è più unica tra tipi di account diversi.
        $status = Password::sendResetLink([
            'email' => mb_strtolower(trim((string) $request->input('email'))),
            'auth_source' => 'locale',
            'attivo' => true,
        ]);
```

`app/Http/Controllers/Auth/NewPasswordController.php`, in `store()` sostituire:
```php
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
```
con:
```php
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token') + [
                'auth_source' => 'locale',
                'attivo' => true,
            ],
```

`app/Http/Controllers/Admin/UtentiController.php`:
- in `store()` la regola `'email' => ['required', 'email', 'max:100', 'unique:users,email'],` diventa:
```php
            'email'      => ['required', 'email', 'max:100', Rule::unique('users', 'email')
                ->where(fn ($q) => $q->where('auth_source', 'locale')->where('attivo', true))],
```
- in `update()` la regola `'email' => ['required', 'email', 'max:100', Rule::unique('users', 'email')->ignore($utente->id)],` diventa:
```php
            'email'      => ['required', 'email', 'max:100', Rule::unique('users', 'email')
                ->where(fn ($q) => $q->where('auth_source', 'locale')->where('attivo', true))
                ->ignore($utente->id)],
```

- [ ] **Step 4: Eseguire i test**

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test --filter="PasswordResetLocaleTest|UtentiEmailUnicaTest|PasswordResetTest"`
Expected: PASS (incluso il `PasswordResetTest` esistente: gli utenti factory sono `locale` e attivi).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Auth/PasswordResetLinkController.php app/Http/Controllers/Auth/NewPasswordController.php app/Http/Controllers/Admin/UtentiController.php tests/Feature/Auth/PasswordResetLocaleTest.php tests/Feature/Admin/UtentiEmailUnicaTest.php
git commit -m "fix(auth): reset e email unica solo account locali

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Rimozione wizard `/setup` e admin da `.env`

**Files:**
- Delete: `app/Http/Controllers/SetupController.php`, `app/Http/Middleware/EnsureSetupComplete.php`, `app/Notifications/SetupOtpNotification.php`, `resources/views/setup/index.blade.php`, `resources/views/setup/verify.blade.php`, `tests/Feature/SetupWizardTest.php`, `tests/Unit/EnsureSetupCompleteTest.php`, `tests/Browser/SetupWizardTest.php`, `database/seeders/AdminUserSeeder.php`
- Modify: `routes/web.php`, `bootstrap/app.php`, `config/app.php`, `database/seeders/DatabaseSeeder.php`, `docker/php/entrypoint.sh`, `docker-compose.yml`, `.env.example`, `.github/workflows/dusk.yml`, `app/Providers/AppServiceProvider.php` (solo commento)

**Interfaces:**
- Consumes: login AD (Task 6): il primo admin di un'installazione è chi sta in `PRONTOPA_ADMIN` (in dev: `admin`/`admin` con `LDAP_HOST=mock`).
- Produces: nessuna route `/setup`, nessun `SETUP_TOKEN`, nessun `ADMIN_*`; `DatabaseSeeder` non crea utenti.

- [ ] **Step 1: Cancellare i file del wizard**

Run:
```bash
git rm app/Http/Controllers/SetupController.php app/Http/Middleware/EnsureSetupComplete.php app/Notifications/SetupOtpNotification.php resources/views/setup/index.blade.php resources/views/setup/verify.blade.php tests/Feature/SetupWizardTest.php tests/Unit/EnsureSetupCompleteTest.php tests/Browser/SetupWizardTest.php database/seeders/AdminUserSeeder.php
```

- [ ] **Step 2: Togliere i riferimenti**

- `routes/web.php`: rimuovere `use App\Http\Controllers\SetupController;` e il blocco commento + 4 route `/setup` (righe "Setup wizard (primo avvio …)" fino a `->name('setup.conferma');`), incluso il commento sul throttle OTP che le precede.
- `bootstrap/app.php`: rimuovere
```php
        $middleware->web(prepend: [
            \App\Http\Middleware\EnsureSetupComplete::class,
        ]);

```
- `config/app.php`: rimuovere il commento e la chiave `'setup_token' => env('SETUP_TOKEN'),`.
- `database/seeders/DatabaseSeeder.php`: rimuovere il blocco `if (blank(config('app.setup_token'))) { $this->call(AdminUserSeeder::class); }` e il commento sopra; al suo posto:
```php
        // Nessun utente creato qui: i dipendenti (admin compreso) entrano da
        // Active Directory, il primo admin è chi sta in PRONTOPA_ADMIN. In
        // sviluppo: LDAP_HOST=mock → admin/admin.
```
- `docker/php/entrypoint.sh`: rimuovere l'intero blocco `if [ -z "$SETUP_TOKEN" ]; then … fi` (righe 25-32).
- `docker-compose.yml`, blocco `x-php-env`: rimuovere `ADMIN_USERNAME`, `ADMIN_EMAIL`, `ADMIN_PASSWORD`, `ADMIN_NAME`, `SETUP_TOKEN`.
- `.env.example`: rimuovere il blocco `SETUP_TOKEN` (commento incluso) e le variabili `ADMIN_*` con il loro commento.
- `.github/workflows/dusk.yml`: rimuovere la riga `echo "SETUP_TOKEN=dusk-e2e-setup-token"` e le 4 righe di commento su `SETUP_TOKEN`/`SetupWizardTest` (lasciare il resto del commento su sqlite).
- `app/Providers/AppServiceProvider.php`: nel commento sulla password policy, sostituire "prima d'ora solo il wizard di setup imponeva min 10 + complessità, registrazione e cambio password usavano" con "prima d'ora registrazione e cambio password usavano".

- [ ] **Step 3: Verificare che non restino riferimenti**

Usare Grep (non Bash) con pattern `(?i)setup_token|SetupController|EnsureSetupComplete|SetupOtp|AdminUserSeeder|ADMIN_PASSWORD|setup\.show|/setup` su `app routes config database docker bootstrap resources tests .github .env.example docker-compose.yml`.
Expected: nessun risultato (ammessi solo `docs/` storici, `CHANGELOG.md`, `TODO.md`, `PIANO-SVILUPPO.md`, `README.md`, `CLAUDE.md`, aggiornati nel Task 11).

- [ ] **Step 4: Eseguire la suite completa e l'analisi statica**

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test`
Expected: PASS.

Run: `MSYS_NO_PATHCONV=1 docker compose exec php composer run analyse`
Expected: nessun errore. Se la baseline contiene voci per i file cancellati, Larastan le ignora (`reportUnmatchedIgnoredErrors: false`): rimuoverle comunque da `phpstan-baseline.neon` cercando `SetupController`/`EnsureSetupComplete`.

- [ ] **Step 5: Verificare il primo avvio da zero in dev**

Run:
```bash
MSYS_NO_PATHCONV=1 docker compose exec php php artisan migrate:fresh --seed
MSYS_NO_PATHCONV=1 docker compose exec php php artisan tinker --execute="echo App\Models\User::count();"
```
Expected: `0`. Poi aprire http://localhost/login, entrare con `admin` / `admin` (`LDAP_HOST=mock` nel `.env` dev) → dashboard admin. (Richiede `LDAP_HOST=mock` nel `.env` locale: aggiungerlo se manca e fare `docker compose up -d php nginx`.)

- [ ] **Step 6: Commit**

```bash
git add -A app routes config database docker bootstrap resources tests .github .env.example docker-compose.yml phpstan-baseline.neon
git commit -m "feat(auth)!: rimuovi wizard setup, admin da AD

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Test E2E login AD (Dusk, mock)

**Files:**
- Create: `tests/Browser/LdapLoginTest.php`
- Modify: `.github/workflows/dusk.yml`

**Interfaces:**
- Consumes: `MockDirectory` (Task 2) con `LDAP_HOST=mock`; instradamento login (Task 6).

- [ ] **Step 1: Abilitare il mock AD nella CI Dusk**

In `.github/workflows/dusk.yml`, nel blocco `Configure env for Dusk`, aggiungere accanto alle altre `echo`:
```yaml
            echo "LDAP_HOST=mock"
            echo "LDAP_USER_DN_TEMPLATE=%s@ente.local"
```

- [ ] **Step 2: Scrivere il test**

`tests/Browser/LdapLoginTest.php`:
```php
<?php

namespace Tests\Browser;

use App\Models\User;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class LdapLoginTest extends DuskTestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(ImpostazioniSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_dipendente_ad_entra_e_riceve_il_ruolo_dal_gruppo(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/login')
                ->type('username', 'mock.gestore')
                ->type('password', 'mock.gestore')
                ->press('Accedi')
                ->waitUntilMissing('#password')
                ->assertPathIsNot('/login');
        });

        $user = User::where('username', 'mock.gestore')->firstOrFail();
        $this->assertSame('ldap', $user->auth_source);
        $this->assertTrue($user->hasRole('gestore'));
    }

    public function test_dipendente_con_upn_entra(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/login')
                ->type('username', 'mock.operaio@ente.local')
                ->type('password', 'mock.operaio')
                ->press('Accedi')
                ->waitUntilMissing('#password')
                ->assertPathIsNot('/login');
        });
    }

    public function test_utente_ad_senza_gruppo_resta_sul_login_con_messaggio(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/login')
                ->type('username', 'mock.nessungruppo')
                ->type('password', 'mock.nessungruppo')
                ->press('Accedi')
                ->waitForText('Non sei abilitato a ProntoPA')
                ->assertPathIs('/login');
        });
    }
}
```

- [ ] **Step 3: Eseguire Dusk in locale (se l'ambiente Dusk locale è disponibile) oppure affidarsi alla CI**

Se Dusk gira in locale: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan dusk --filter=LdapLoginTest` → Expected: PASS.
Altrimenti: push del branch e verifica del job `Dusk (E2E)` verde; in caso di fallimento leggere screenshot/console artifact prima di correggere.

- [ ] **Step 4: Commit**

```bash
git add tests/Browser/LdapLoginTest.php .github/workflows/dusk.yml
git commit -m "test(dusk): login dipendenti AD con mock

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: Documentazione e smoke test contro l'AD reale

**Files:**
- Modify: `CLAUDE.md`, `README.md`, `CHANGELOG.md`, `TODO.md`
- Modify: `docs/superpowers/specs/2026-09-30-v12-identita-accessi-design.md` (nota username occupato)

**Interfaces:**
- Consumes: tutto quanto sopra; comando `ldap:prova` (Task 5).

- [ ] **Step 1: Aggiornare la spec**

In `docs/superpowers/specs/2026-09-30-v12-identita-accessi-design.md`, sezione "Login dipendenti (LDAP)", dopo il punto 6 aggiungere:
```markdown
7. Username AD già usato da un altro account ProntoPA (es. utente locale
   legacy o demo con lo stesso username, non agganciato per email) →
   login rifiutato con messaggio "contatta l'amministratore" e log warning;
   l'admin rinomina o disattiva l'account locale. Nessun errore di vincolo.
8. Transitorio fino al cutover: input senza `@` non riconosciuto da AD →
   tentativo sugli account `locale` per username (segnalatori legacy).
   Rimosso da `utenze:cutover` (fase 3).
```

- [ ] **Step 2: Aggiornare `CLAUDE.md`**

- Sezione "Setup Dev": sostituire la riga `migrate --seed` con la nota che dopo `migrate --seed` non esistono utenti e si entra con `admin`/`admin` grazie a `LDAP_HOST=mock` (altri utenti mock: `mock.supervisore`, `mock.gestore`, `mock.operaio`, `mock.urp`, `mock.segnalatore`, password = username).
- Sezione ".env": aggiungere `LDAP_HOST=mock  LDAP_BASE_DN=  LDAP_USER_DN_TEMPLATE=%s@ente.local`; rimuovere `SETUP_TOKEN`.
- Sezione "Ruoli (Spatie)": aggiungere la tabella gruppi AD → ruolo (nomi default, precedenza, URP = segnalatore + `segnalazioni.per-conto`, provenienza 3) e "ditte: email + password + 2FA email obbligatoria".
- Sezione "Funzionalità v0.6+": rimuovere la voce "Wizard primo avvio"; aggiungere "Login: AD per i dipendenti (`LdapLoginService`, `ldap:prova <username>` per diagnosi), email+2FA per le ditte".
- Sezione "Architettura": aggiungere `app/Services/Directory/` e `app/Services/Auth/`; togliere `SetupController` e `EnsureSetupComplete`.

- [ ] **Step 3: Aggiornare `README.md`, `CHANGELOG.md`, `TODO.md`**

- `README.md`: nei prerequisiti di installazione aggiungere "Active Directory/LDAP dell'ente (obbligatorio: i dipendenti, amministratore compreso, entrano con le credenziali di dominio)" e la tabella dei 6 gruppi; rimuovere le istruzioni del wizard `/setup` e di `ADMIN_*`/`SETUP_TOKEN`.
- `CHANGELOG.md`, sotto `[Unreleased]`:
```markdown
### Added
- Login dipendenti con Active Directory: ruolo, flag supervisore,
  provenienza e permesso "per conto di" derivati dai gruppi AD a ogni
  accesso; aggancio automatico degli account legacy con la stessa email;
  comando `ldap:prova` per la diagnosi
- Login ditte con email + password e verifica in due passaggi con codice
  via email (obbligatoria), in alternativa all'app TOTP

### Removed
- Wizard di primo avvio `/setup` e creazione admin da `ADMIN_*`/`SETUP_TOKEN`:
  il primo amministratore è chi appartiene al gruppo AD `PRONTOPA_ADMIN`

### Changed
- Reset password e unicità email limitati agli account locali (ditte)
```
- `TODO.md`: aggiungere sezione `## v1.2 — Identità e accessi 🚧 IN CORSO` con link alla spec e "Fase 1 (AD + ditte via email) ✅", "Fase 2 (SPID/CIE + deleghe) 📋", "Fase 3 (cutover) 📋".

- [ ] **Step 4: Smoke test contro l'AD reale (manuale, con il sistemista)**

Prerequisito: gruppi `PRONTOPA_*` creati in AD con almeno un utente di prova in ciascuno, `.env` dev con `LDAP_HOST`, `LDAP_BASE_DN`, `LDAP_USER_DN_TEMPLATE` reali, `docker compose up -d php nginx`.

Checklist, spuntare ogni voce:
- `docker compose exec php php artisan ldap:prova <utente-gestore>` → GUID, email, gruppi (inclusi gli annidati), ruolo `gestore`.
- Utente in gruppo annidato (membro di un gruppo che è membro di `PRONTOPA_OPERAI`) → `ldap:prova` mostra `PRONTOPA_OPERAI` e ruolo `operaio`.
- Login web con `m.rossi`, con `m.rossi@<dominio>` e con `<DOMINIO>\m.rossi` → entra in tutti e tre i casi.
- Password errata → "credenziali non valide"; utente disabilitato in AD → "credenziali non valide".
- Utente AD senza alcun gruppo `PRONTOPA_*` → "Non sei abilitato a ProntoPA".
- Spostare un utente da `PRONTOPA_GESTORI` a `PRONTOPA_OPERAI`, rifare login → ruolo `operaio`.
- `LDAP_HOST` su un IP irraggiungibile → "temporaneamente non disponibile" entro ~`LDAP_TIMEOUT` secondi; una ditta via email entra comunque.
- Con `LDAPS`/StartTLS e certificato dell'ente: login riuscito con `LDAP_TLS_SKIP_VERIFY=false`.
Annotare nel CHANGELOG o nella spec ogni scostamento trovato (in `palestre` gli smoke test reali hanno trovato bug che i test automatici non vedevano).

- [ ] **Step 5: Commit**

```bash
git add CLAUDE.md README.md CHANGELOG.md TODO.md docs/superpowers/specs/2026-09-30-v12-identita-accessi-design.md
git commit -m "docs: v1.2 fase 1 login AD e ditte

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
