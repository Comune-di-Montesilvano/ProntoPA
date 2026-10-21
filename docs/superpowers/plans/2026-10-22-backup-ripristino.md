# Backup e ripristino — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** backup notturni/manuali cifrati e portabili di DB + storage, con download, caricamento e ripristino da Admin, senza console.

**Architecture:** dump **logico in PHP** (una tabella = un file JSONL), non `mariadb-dump`: niente binari nuovi nell'immagine, e soprattutto testabile su SQLite (i test girano su `sqlite :memory:`). Il ripristino ricrea lo schema eseguendo **solo le migration elencate nel manifest**, reinserisce i dati, poi `migrate` porta lo schema alla versione corrente. Archivio ZIP AES-256 su un volume `backups` fuori da `storage`; i metadati di ogni backup stanno in un file `<nome>.json` accanto all'archivio (non nel DB, che il ripristino sostituisce).

**Tech Stack:** Laravel 13, PHP 8.4 `ZipArchive` (AES-256 libzip), Blade + Alpine, queue Redis, scheduler esistente.

**Spec:** `docs/superpowers/specs/2026-10-21-backup-ripristino-design.md`

## Global Constraints

- Nessun passo ordinario richiede console Portainer o modifica manuale del compose (stack letto da GitHub).
- `BACKUP_PASSPHRASE` da env, in `x-php-env`; senza passphrase nessun file viene prodotto in chiaro.
- Volume `backups` montato in `/var/www/html/backups` (fuori da `storage`), su `php`, `queue`, `scheduler`; scrivibile da `www` (uid 1000).
- Contenuto: tutte le tabelle tranne `migrations cache cache_locks sessions jobs job_batches`; `storage/app` tranne `quarantena`; mai `storage/logs` e `storage/framework`.
- Nome file: `prontopa-<YYYYMMDD-HHMMSS>-<versione>-<tipo>.zip`, tipo ∈ `notturno` `manuale` `pre-ripristino` `caricato`.
- Retention: ultimo sempre; tutti gli ultimi 7 giorni; oltre, il più recente per settimana ISO fino a 30 giorni; mai pulire dopo un backup fallito; l'ultimo `pre-ripristino` resta finché non esiste un `notturno` più recente.
- Allarme se ultimo backup fallito o più vecchio di **36 ore**.
- Notturno alle **02:00**, `withoutOverlapping`.
- Conferma ripristino: digitare `RIPRISTINA`.
- Installazione "vuota" = 0 segnalazioni e ≤ 1 utente.
- Compatibilità = migration del manifest tutte note localmente (la versione `dev` non conta).
- Parametri passphrase/password sempre `#[\SensitiveParameter]` e aggiunti a `tests/Unit/PasswordNonNeiLogTest.php`.
- Nuove env anche in `docker-compose.yml` `x-php-env` e `.env.example`.
- Commit via `/commit` (caveman-commit) + trailer `Co-Authored-By`. Rilascio 0.9.0: `publiccode.yml` + CHANGELOG nella stessa PR.

## Review Focus

1. **Allegato con nome strano o grande (accenti, spazi, 50 MB)** → finisce nel backup e torna identico (stesso path, stessi byte). Test in Task 3.
2. **Ripristino fallito a metà** (eccezione dopo aver svuotato il DB) → l'app non resta rotta: rollback automatico dal `pre-ripristino`, o installazione pulita se era vuota; esito leggibile. Test in Task 5.
3. **Passphrase sbagliata / file troncato o non-ZIP** → errore chiaro in anteprima, niente viene toccato. Test in Task 3 e 5.
4. **Due backup contemporanei** (notturno + "Backup ora") → il secondo attende o rinuncia, nessun file corrotto. Test in Task 4.
5. **Sessione aperta prima del ripristino** con id utente che nel backup è un'altra persona → logout forzato. Test in Task 6.

---

## File Structure

| File | Responsabilità |
|---|---|
| `config/backup.php` | path, passphrase, tabelle escluse, colonne cifrate, soglie |
| `app/Services/Backup/ArchivioBackup.php` | ZIP AES: scrivi/leggi voci, manifest, verifica checksum |
| `app/Services/Backup/DumpDatabase.php` | tabelle → JSONL e ritorno |
| `app/Services/Backup/SegretiCifrati.php` | decifra/ricifra colonne cifrate con APP_KEY |
| `app/Services/Backup/BackupInfo.php` | value object metadati (sidecar `.json`) |
| `app/Services/Backup/CatalogoBackup.php` | elenco, sidecar, elimina, ultimo, allarme |
| `app/Services/Backup/Retention.php` | calcolo puro dei file da eliminare |
| `app/Services/Backup/BackupService.php` | crea backup completo + verifica + pulizia |
| `app/Services/Backup/RipristinoService.php` | anteprima, esecuzione, rollback |
| `app/Services/Backup/StatoRipristino.php` | stato su file, leggibile anche in manutenzione |
| `app/Jobs/{CreaBackup,EseguiRipristino}.php` | esecuzione fuori dalla richiesta web |
| `app/Console/Commands/{BackupCrea,BackupRipristina,BackupPulisci}.php` | `backup:crea` `backup:ripristina` `backup:pulisci` |
| `app/Http/Controllers/Admin/BackupController.php` + `resources/views/admin/backup/*` | UI |
| `app/Http/Middleware/InvalidaSessioniDopoRipristino.php` | logout dopo ripristino |
| `app/Notifications/BackupFallitoNotification.php` | email admin |

---

### Task 1: Infrastruttura (volume, config, hardening entrypoint/compose)

**Files:**
- Create: `config/backup.php`
- Modify: `docker-compose.yml`, `docker-compose.override.yml`, `Dockerfile` (stage `app`), `docker/php/Dockerfile`, `docker/php/entrypoint.sh`, `.env.example`, `.gitignore`, `phpunit.xml`

**Interfaces:**
- Produces: `config('backup.path')` (string, env `BACKUP_PATH`, default `base_path('backups')`), `config('backup.passphrase')` (env `BACKUP_PASSPHRASE`, `null` se vuota), `config('backup.tabelle_escluse')` (array come Global Constraints), `config('backup.colonne_cifrate')` = `[['tabella'=>'impostazioni','chiave'=>'chiave','valore'=>'oidc_client_secret','colonna'=>'valore'], ['tabella'=>'users','colonna'=>'two_factor_secret'], ['tabella'=>'users','colonna'=>'two_factor_recovery_codes']]`, `config('backup.ore_allarme')`=36, `config('backup.giorni_tutti')`=7, `config('backup.giorni_settimanali')`=30, `config('backup.dimensione_blocco')`=5 MB.

- [ ] **Step 1: verifica AES** — nel container dev ed in CI (`tests.yml`, setup-php) `php -r 'var_dump(ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256));'` deve dare `true`. Se false in uno dei due: fermati e segnala (piano B: archivio ZIP non cifrato dentro un file `openssl_encrypt` AES-256-GCM a blocchi — decisione da riportare al committente).
- [ ] **Step 2: grep colonne cifrate** — `grep -rn "Crypt::\|encrypt(\|'encrypted'" app config` ; ogni colonna cifrata con APP_KEY oltre alle tre sopra va aggiunta a `colonne_cifrate`.
- [ ] **Step 3: compose** — volume `backups:` in `volumes`, montato `backups:/var/www/html/backups` su `php` `queue` `scheduler` (aggiungilo all'anchor `x-php-volumes`); `BACKUP_PASSPHRASE: ${BACKUP_PASSPHRASE:-}` in `x-php-env`. Password DB: `DB_PASSWORD: ${DB_PASSWORD:?DB_PASSWORD obbligatoria}` in `x-php-env` e `MARIADB_PASSWORD: ${DB_PASSWORD:?DB_PASSWORD obbligatoria}`; `MARIADB_ROOT_PASSWORD: ${DB_ROOT_PASSWORD:-}` (niente `root_secret`: su volume esistente è ignorata, su volume nuovo mariadb rifiuta di inizializzare con messaggio esplicito → mai rottura su "Update the stack" di prod esistente). Commento sopra il servizio: le password MariaDB valgono solo alla creazione del volume.
- [ ] **Step 4: override dev** — bind `./backups:/var/www/html/backups` sugli stessi servizi; `/backups/` in `.gitignore`.
- [ ] **Step 5: Dockerfile** — in entrambi i Dockerfile `mkdir -p backups` prima del `chown -R www:www storage bootstrap/cache backups` (Podman inizializza il named volume con l'ownership della directory dell'immagine).
- [ ] **Step 6: entrypoint** — nel loop di attesa conta i tentativi; al 15° (≈30 s) e poi ogni 30 s stampa una riga `==> DB non raggiungibile: <messaggio PDO>`; se il messaggio contiene `Access denied` aggiungi `==> DB_PASSWORD diversa da quella con cui è stato creato il volume mariadb_data (vale solo alla prima creazione)`. Il messaggio PDO non contiene la password; non stampare variabili d'ambiente.
- [ ] **Step 7: verifica** — `MSYS_NO_PATHCONV=1 docker compose config >/dev/null` con `.env` di dev → nessun errore; con `DB_PASSWORD` rimossa da un env temporaneo → errore `DB_PASSWORD obbligatoria`. Stack dev su con DB_PASSWORD errata → nel log `php` compare la riga Access denied entro 35 s; poi ripristina `.env`.
- [ ] **Step 8: Commit** — `feat(backup): volume, config e diagnosi avvio DB`

### Task 2: Dump logico del database

**Files:**
- Create: `app/Services/Backup/DumpDatabase.php`
- Test: `tests/Feature/Backup/DumpDatabaseTest.php`

**Interfaces:**
- Produces: `DumpDatabase::esporta(string $dir): array<string,int>` (scrive `$dir/<tabella>.jsonl`, una riga JSON per record, ritorna conteggi per tabella); `DumpDatabase::importa(string $dir, array $conteggi): void` (inserisce a blocchi di 500 con vincoli FK disattivati, lancia `RuntimeException` se le righe lette ≠ `$conteggi`); `DumpDatabase::tabelle(): list<string>` (`Schema::getTableListing(schemaQualified: false)` meno `tabelle_escluse`); `DumpDatabase::migrazioniApplicate(): list<string>`.

- [ ] **Step 1: test failing**

```php
public function test_esporta_e_reimporta_identico(): void
{
    User::factory()->count(3)->create();
    Segnalazione::factory()->create(['descrizione' => "Perdita d'acqua — aula 3 ☂"]);
    $dir = $this->tmpDir();
    $utenti = DB::table('users')->count();
    $conteggi = app(DumpDatabase::class)->esporta($dir);
    $prima = DB::table('segnalazioni')->get()->toArray();

    $this->assertSame($utenti, $conteggi['users']);
    $this->assertArrayNotHasKey('migrations', $conteggi);
    $this->assertArrayNotHasKey('sessions', $conteggi);

    Schema::disableForeignKeyConstraints();
    foreach (array_keys($conteggi) as $t) DB::table($t)->delete();
    app(DumpDatabase::class)->importa($dir, $conteggi);

    $this->assertEquals($prima, DB::table('segnalazioni')->get()->toArray());
}

public function test_importa_rifiuta_conteggi_diversi(): void { /* manifest dice 5, file ne ha 3 → RuntimeException */ }
```
(se `Segnalazione::factory` non esiste usa l'helper già usato in `tests/Feature/DedupTest.php`.)
- [ ] **Step 2:** `php artisan test --filter=DumpDatabaseTest` → FAIL (classe mancante)
- [ ] **Step 3:** implementa. Lettura con `DB::table($t)->orderBy(primo indice/colonna)->lazy(1000)`; tabelle senza `id` (pivot Spatie) → `cursor()`; `json_encode(..., JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)`.
- [ ] **Step 4:** test PASS
- [ ] **Step 5: Commit** — `feat(backup): dump logico tabelle in JSONL`

### Task 3: Archivio cifrato e segreti

**Files:**
- Create: `app/Services/Backup/ArchivioBackup.php`, `app/Services/Backup/SegretiCifrati.php`
- Test: `tests/Feature/Backup/ArchivioBackupTest.php`, `tests/Feature/Backup/SegretiCifratiTest.php`

**Interfaces:**
- Produces:
  - `ArchivioBackup::scrivi(string $zip, #[\SensitiveParameter] string $passphrase, array $manifest, string $dirDb, string $dirStorage, array $segreti): void` — voci: `manifest.json`, `segreti.json`, `db/*.jsonl`, `storage/app/**`; tutte AES-256 (`setEncryptionName`). `manifest` arricchito con `checksum` = sha256 di ogni voce `db/*` e `segreti.json`.
  - `ArchivioBackup::manifest(string $zip, #[\SensitiveParameter] string $passphrase): array` — lancia `BackupNonLeggibile` (nuova eccezione in `app/Services/Backup/`) con messaggio italiano per passphrase errata / file non ZIP / manifest assente.
  - `ArchivioBackup::verifica(string $zip, #[\SensitiveParameter] string $passphrase): void` — ricalcola checksum e conta righe JSONL vs `manifest['conteggi']`; `BackupNonLeggibile` se differiscono.
  - `ArchivioBackup::estrai(string $zip, #[\SensitiveParameter] string $passphrase, string $dest): void`
  - `ArchivioBackup::ricifra(string $zip, #[\SensitiveParameter] string $da, #[\SensitiveParameter] string $a): void`
  - `SegretiCifrati::estrai(): array` — per ogni voce di `colonne_cifrate`: `Crypt::decryptString` sul valore grezzo (copre anche `encrypt()` di Fortify: il valore decifrato è la stringa serializzata) → `[['tabella','colonna','id'|'chiave','valore'], ...]`; valori non decifrabili saltati con `Log::warning` senza il valore.
  - `SegretiCifrati::applica(array $segreti): void` — `Crypt::encryptString` con la APP_KEY corrente e update della riga.

- [ ] **Step 1: test failing**
  - `test_roundtrip_con_allegato_nome_strano`: file `storage/app/public/allegati/Aula 3 – più.jpg` da 50 MB (`random_bytes` a blocchi, scrittura in streaming) → `scrivi` + `estrai` → stesso path, `hash_file('sha256')` identico.
  - `test_passphrase_errata`: `manifest($zip, 'sbagliata')` → `BackupNonLeggibile`, messaggio contiene `passphrase`.
  - `test_file_troncato_o_non_zip`: file di testo rinominato `.zip` e zip troncato a metà → `BackupNonLeggibile`.
  - `test_verifica_rileva_jsonl_alterato`: riscrivi `db/users.jsonl` dentro l'archivio con una riga in meno → `verifica` lancia.
  - `test_segreti_sopravvivono_al_cambio_app_key`: imposta `oidc_client_secret` cifrato e un utente con `two_factor_secret = encrypt('ABC')`; `estrai()`; `config(['app.key' => 'base64:'.base64_encode(random_bytes(32))])` + `app()->forgetInstance('encrypter')`; `applica()`; `Crypt::decryptString(Impostazione oidc)` = valore originale, `decrypt($user->fresh()->getRawOriginal('two_factor_secret'))` = `'ABC'`.
- [ ] **Step 2:** FAIL
- [ ] **Step 3:** implementa. `ZipArchive::addFile` è lazy (nessun file intero in RAM); aggiungi `ArchivioBackup::scrivi`, `manifest`, `verifica`, `estrai`, `ricifra` a `PasswordNonNeiLogTest` (parametro `$passphrase` / `$da` / `$a` → estendi il test per accettare il nome del parametro come terzo elemento del data provider).
- [ ] **Step 4:** PASS (+ `PasswordNonNeiLogTest`)
- [ ] **Step 5: Commit** — `feat(backup): archivio zip AES-256 e segreti portabili`

### Task 4: Catalogo, retention, BackupService, comandi, scheduler

**Files:**
- Create: `app/Services/Backup/{BackupInfo,CatalogoBackup,Retention,BackupService}.php`, `app/Jobs/CreaBackup.php`, `app/Console/Commands/{BackupCrea,BackupPulisci}.php`, `app/Notifications/BackupFallitoNotification.php`
- Modify: `routes/console.php`
- Test: `tests/Unit/Backup/RetentionTest.php`, `tests/Feature/Backup/BackupServiceTest.php`

**Interfaces:**
- Produces:
  - `BackupInfo` readonly: `string $nome, string $tipo, CarbonImmutable $creatoIl, string $versione, int $dimensione, bool $verificato, ?string $errore, ?int $durataSecondi`; `toArray()` / `fromArray()` per il sidecar `<nome>.json`.
  - `CatalogoBackup::elenco(): Collection<BackupInfo>` (ordinati dal più recente; legge i sidecar; zip senza sidecar → `verificato=false`, tipo dal nome) · `percorso(string $nome): string` (rifiuta nomi con `/`, `..` o non `prontopa-*.zip` → `InvalidArgumentException`) · `salvaInfo(BackupInfo)` · `elimina(string $nome)` (rifiuta l'ultimo `pre-ripristino` protetto) · `ultimo(): ?BackupInfo` · `inAllarme(CarbonImmutable $ora): bool` (nessun backup, ultimo fallito o più vecchio di `ore_allarme`).
  - `Retention::daEliminare(Collection<BackupInfo> $backup, CarbonImmutable $ora): list<string>` (funzione pura).
  - `BackupService::crea(string $tipo): BackupInfo` — `Cache::lock('backup', 3600)->block(0)`: se occupato lancia `BackupInCorso`; senza passphrase lancia `BackupNonConfigurato`; scrive in `backups/tmp/<uuid>/`, poi `ArchivioBackup::scrivi`, `verifica`, sidecar; pulisce `tmp`; se riuscito applica `Retention` (solo per tipo `notturno` e `manuale`); in caso d'errore: sidecar con `verificato=false` ed `errore`, `report($e)`, notifica `BackupFallitoNotification` a `User::role('admin')->where('attivo', true)`, rilancia.
  - `manifest` scritto da `crea`: `versione` (`config('app.version')`), `creato_il` ISO, `app_url`, `app_key_impronta` (= primi 12 hex di `sha256(config('app.key'))`, mai la chiave), `migrazioni` (`DumpDatabase::migrazioniApplicate()`), `conteggi`, `file_storage` (numero, byte), `tipo`.
  - `CreaBackup` job (`tipo`), `backup:crea {--tipo=manuale}`, `backup:pulisci`.
  - Scheduler: `Schedule::command('backup:crea --tipo=notturno')->dailyAt('02:00')->withoutOverlapping()->runInBackground();`

- [ ] **Step 1: test retention** (`RetentionTest`, ora = 2026-10-22 03:00)
  - 40 notturni giornalieri (uno al giorno alle 02:00, da 2026-09-13 a 2026-10-22) → conservati: i 7 degli ultimi 7 giorni + il più recente di ciascuna settimana ISO con backup tra 8 e 30 giorni fa; tutto ciò che è più vecchio di 30 giorni eliminato; asserisci l'elenco esatto dei nomi eliminati.
  - un solo backup di 90 giorni fa → non eliminato (ultimo sempre).
  - `pre-ripristino` di 45 giorni fa senza notturni più recenti → conservato; con un notturno più recente → eliminato.
  - backup falliti (`verificato=false`) non contano come "ultimo" ma si eliminano con le stesse regole d'età.
- [ ] **Step 2: test service** (`BackupServiceTest`, `config(['backup.path' => tmp, 'backup.passphrase' => 'test-pass'])`)
  - `test_crea_backup_verificato`: file `prontopa-*-dev-manuale.zip` + sidecar con `verificato=true`; `manifest` ha `migrazioni` non vuote e nessuna chiave `app_key`.
  - `test_senza_passphrase_niente_file`: `passphrase=null` → `BackupNonConfigurato`, nessun `.zip` nella cartella.
  - `test_secondo_backup_concorrente_rinuncia`: acquisisci `Cache::lock('backup')` nel test → `crea` lancia `BackupInCorso`, nessun file nuovo.
  - `test_fallimento_notifica_admin_e_non_pulisce`: `DumpDatabase` mockato che lancia → `Notification::assertSentTo($admin, BackupFallitoNotification::class)`, sidecar con `errore`, i backup vecchi preesistenti ancora presenti.
  - `test_storage_esclude_quarantena_e_log`: file in `storage/app/quarantena/x` e `storage/logs/y.log` → assenti dall'archivio.
- [ ] **Step 3:** FAIL
- [ ] **Step 4:** implementa. Per `storage/app` nei test usa `config('backup.storage_root')` (default `storage_path('app')`) così i test puntano a una cartella temporanea.
- [ ] **Step 5:** PASS
- [ ] **Step 6: Commit** — `feat(backup): backup notturni con retention 1/7/30`

### Task 5: Ripristino

**Files:**
- Create: `app/Services/Backup/{RipristinoService,StatoRipristino,Anteprima}.php`, `app/Jobs/EseguiRipristino.php`, `app/Console/Commands/BackupRipristina.php`
- Test: `tests/Feature/Backup/RipristinoServiceTest.php`

**Interfaces:**
- Consumes: Task 2-4 (`DumpDatabase`, `ArchivioBackup`, `SegretiCifrati`, `BackupService::crea`, `CatalogoBackup`).
- Produces:
  - `Anteprima` readonly: `array $manifest, bool $compatibile, list<string> $migrazioniSconosciute, bool $installazioneVuota, bool $stessaAppKey, bool $stessoAppUrl, ?string $bloccante` (motivo in italiano se non si può procedere: incompatibile, oppure non vuota e `BACKUP_PASSPHRASE` assente).
  - `RipristinoService::anteprima(string $zip, #[\SensitiveParameter] string $passphrase): Anteprima` (chiama `ArchivioBackup::verifica`).
  - `RipristinoService::esegui(string $zip, #[\SensitiveParameter] string $passphrase, string $token): void` — sequenza: lock `backup` → `StatoRipristino::fase($token, ...)` → `pre-ripristino` via `BackupService::crea` se non vuota → `Artisan::call('down', ['--retry' => 30])` → estrai in `tmp` → `Schema::dropAllTables()` → `Migrator::runPending()` sui soli file in `manifest['migrazioni']` (ordinati come in manifest) → `DumpDatabase::importa` → sostituisci contenuto di `storage/app` (tranne `quarantena`, senza rimuovere la directory `public` che è un mountpoint) → `Artisan::call('migrate', ['--force' => true])` → seed `AllineamentoSeeder` → `SegretiCifrati::applica` → `Impostazione::set('ripristino_id', (string) Str::uuid())` → `Cache::flush()` → `Artisan::call('up')` → `StatoRipristino::fine($token, true, ...)`.
  - Errore dopo `dropAllTables`: se esiste il `pre-ripristino` appena creato, ripeti la sequenza su quello (una sola volta, niente ricorsione); altrimenti `migrate:fresh --force` + `AllineamentoSeeder`; in entrambi i casi `up` e `StatoRipristino::fine($token, false, messaggio con nome del pre-ripristino)`; `report($e)`.
  - `StatoRipristino` scrive `backups/stato/<token>.json` `{fase, esito: null|true|false, messaggio, aggiornato_il}`; `leggi(string $token): ?array`; token = 40 caratteri casuali, validato `^[A-Za-z0-9]{40}$`.
  - `backup:ripristina {file} {--passphrase=}` (default `config('backup.passphrase')`).

- [ ] **Step 1: test failing**
  - `test_ripristino_su_installazione_vuota`: crea dati (3 utenti, 2 segnalazioni, impostazione `ente_nome=Comune A`, file storage) → backup → `migrate:fresh` + `AllineamentoSeeder` + 1 admin → `anteprima` dice `installazioneVuota=true`, `bloccante=null` → `esegui` → conteggi per tabella identici al manifest, `ente_nome=Comune A`, file presente, nessun nuovo `pre-ripristino`, `StatoRipristino::leggi` esito `true`, app non in manutenzione (`app()->isDownForMaintenance()` false).
  - `test_rifiuta_migrazioni_sconosciute`: manipola il manifest con `2099_01_01_000000_futura` → `compatibile=false`, `bloccante` contiene `più recente`.
  - `test_installazione_non_vuota_senza_passphrase_bloccata`: 2 segnalazioni presenti, `backup.passphrase=null`, passphrase digitata corretta → `bloccante` non nullo.
  - `test_backup_vecchio_migra_allo_schema_attuale`: costruisci un archivio il cui manifest omette l'ultima migration esistente (e i cui JSONL non hanno le colonne che essa aggiunge) → dopo `esegui` la migration risulta applicata e i dati presenti.
  - `test_fallimento_a_meta_fa_rollback`: installazione non vuota con `ente_nome=Comune B`; bind di un `DumpDatabase` che lancia in `importa` solo alla prima chiamata → esito `false`, messaggio contiene il nome del `pre-ripristino`, `ente_nome` torna `Comune B`, app non in manutenzione.
  - `test_avvisa_app_key_e_url_diverse`: backup con `app_url=http://localhost` e impronta di un'altra chiave → `stessaAppKey=false`, `stessoAppUrl=false`.
- [ ] **Step 2:** FAIL
- [ ] **Step 3:** implementa (aggiungi `anteprima`/`esegui` a `PasswordNonNeiLogTest`).
- [ ] **Step 4:** PASS
- [ ] **Step 5: Commit** — `feat(backup): ripristino con rollback automatico`

### Task 6: UI Admin → Backup + logout post-ripristino + allarme dashboard

**Files:**
- Create: `app/Http/Controllers/Admin/BackupController.php`, `resources/views/admin/backup/index.blade.php`, `resources/views/admin/backup/anteprima.blade.php`, `app/Http/Middleware/InvalidaSessioniDopoRipristino.php`
- Modify: `routes/web.php` (gruppo admin), `bootstrap/app.php` (middleware web + `preventRequestsDuringMaintenance(except: ['ripristino/stato/*'])`), `resources/views/layouts/app.blade.php` (sidebar admin, icona coerente con le altre), `app/Http/Controllers/Admin/AdminDashboardController.php` + `resources/views/admin/dashboard.blade.php`
- Test: `tests/Feature/Admin/BackupControllerTest.php`, `tests/Feature/Backup/InvalidaSessioniTest.php`

**Interfaces:**
- Rotte (`admin.backup.*`, solo `role:admin`): `GET admin/backup` index · `POST admin/backup` crea (dispatch `CreaBackup('manuale')`) · `GET admin/backup/{nome}/scarica` (`response()->download`, streaming) · `DELETE admin/backup/{nome}` · `POST admin/backup/carica` (blocco: `upload_id` uuid, `indice`, `totale`, `blocco` file ≤ `dimensione_blocco`; append in `backups/tmp/upload-<upload_id>.part`; all'ultimo blocco: `ArchivioBackup::verifica` con `passphrase` del form o locale, `ricifra` verso la locale se diversa, rinomina in `prontopa-<data creazione dal manifest>-<versione>-caricato.zip`, sidecar; risposta JSON `{nome}` o `{errore}` 422) · `GET admin/backup/{nome}/ripristina` anteprima (campo passphrase se il file non si apre con quella locale) · `POST admin/backup/{nome}/ripristina` (richiede `conferma === 'RIPRISTINA'`, genera token, dispatch `EseguiRipristino`, redirect a pagina di attesa con token) · `GET ripristino/stato/{token}` **fuori** dal gruppo auth e dalla manutenzione, ritorna `StatoRipristino::leggi` come JSON (404 se token ignoto).
- Middleware `InvalidaSessioniDopoRipristino` (gruppo `web`, dopo `StartSession`): se l'utente è autenticato e `session('ripristino_id') !== Impostazione::get('ripristino_id')` e la sessione ne ha già uno salvato (o l'impostazione esiste e la sessione no) → `Auth::logout()`, `invalidate()`, redirect `login` con flash "Sistema ripristinato da backup, accedi di nuovo"; al login salva `ripristino_id` corrente in sessione.
- Dashboard: banner rosso se `CatalogoBackup::inAllarme(now())`, con link alla pagina Backup; pagina Backup: banner se `backup.passphrase` vuota ("Imposta BACKUP_PASSPHRASE nella stack").

- [ ] **Step 1: test failing** (`withoutMiddleware([PreventRequestForgery, ThrottleRequests])` come da CLAUDE.md)
  - `test_solo_admin`: gestore → 403 su tutte le rotte `admin.backup.*`.
  - `test_backup_ora_accoda_job`: `Queue::fake()` → `CreaBackup` con tipo `manuale`.
  - `test_scarica_rifiuta_path_traversal`: `admin/backup/..%2F.env/scarica` → 404.
  - `test_upload_a_blocchi`: backup reale diviso in 3 blocchi da `dimensione_blocco` ridotto a 1 KB nel test → file `*-caricato.zip` in elenco, verificato.
  - `test_upload_passphrase_diversa_ricifrato`: archivio creato con `altra-pass`, caricato con `passphrase=altra-pass` → si apre con la passphrase locale, non con `altra-pass`.
  - `test_ripristino_richiede_conferma`: `conferma=ripristina` (minuscolo) → errore di validazione, nessun job.
  - `test_stato_raggiungibile_in_manutenzione`: `Artisan::call('down')` → `GET ripristino/stato/{token}` 200 JSON.
  - `test_dashboard_allarme_36_ore`: ultimo backup di 37 ore fa → testo allarme presente; di 2 ore fa → assente.
  - `InvalidaSessioniTest::test_sessione_aperta_prima_del_ripristino_fa_logout`: login, poi `Impostazione::set('ripristino_id', nuovo uuid)` → richiesta successiva redirect `login`, `assertGuest()`.
- [ ] **Step 2:** FAIL
- [ ] **Step 3:** implementa. Pagine con stesso layout/stile di `resources/views/admin/anagrafe-miur/index.blade.php`; upload a blocchi con Alpine + `fetch` (barra di avanzamento, `X-CSRF-TOKEN`); pagina di attesa: polling ogni 3 s di `ripristino/stato/{token}`, al termine link "Accedi" (sessione invalidata).
- [ ] **Step 4:** PASS
- [ ] **Step 5: smoke manuale dev** — `docker compose up -d`, login `mock.admin` → Backup ora → Scarica → Ripristina lo stesso file → rientra; Dashboard senza allarme.
- [ ] **Step 6: Commit** — `feat(backup): pagina admin backup, upload e ripristino`

### Task 7: Documentazione e rilascio 0.9.0

**Files:**
- Modify: `CLAUDE.md`, `CHANGELOG.md`, `publiccode.yml`, `docs/` se esiste una guida deploy

- [ ] **Step 1:** CLAUDE.md: sezione "Backup" (architettura dump logico, volume `backups`, `BACKUP_PASSPHRASE`, sidecar, `ripristino_id`, comandi `backup:*`) + in "Deploy Prod": password MariaDB valide solo alla creazione del volume; procedura reset senza console (container temporaneo `mariadb:11.4` `--skip-grant-tables` sullo stesso volume, con `mariadb` dello stack fermo); disaster recovery = stessa `APP_KEY`, `APP_URL`, `BACKUP_PASSPHRASE`.
- [ ] **Step 2:** CHANGELOG `## [0.9.0] - <data>` (Added: backup/ripristino; Changed: `DB_PASSWORD` obbligatoria, niente `root_secret`; Fixed: entrypoint mostra errore DB); `publiccode.yml` `softwareVersion: "0.9.0"`.
- [ ] **Step 3:** `php artisan test` completo + `composer run analyse` → verdi.
- [ ] **Step 4: Commit** — `docs: backup e ripristino, release 0.9.0`
