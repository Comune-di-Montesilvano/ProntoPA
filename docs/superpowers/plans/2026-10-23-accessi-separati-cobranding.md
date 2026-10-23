# Accessi separati, ditte via SPID/CIE, co-branding — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Favicon e co-branding ente+ProntoPA (logo ente anche caricabile, loghi inline nelle email), login operatori (solo AD) separato dall'accesso SPID/CIE per scuole e ditte, ditte solo via pre-delega SPID, rimozione registrazione / account locali / magic-link.

**Architecture:** Un helper `App\Support\Brand` è l'unico punto che risolve i loghi (web e email); un componente Blade `x-co-branding` li mostra. Le deleghe ditta stanno in una tabella propria `deleghe_imprese` gestita da `DelegaImpresaService`, che allinea ruolo `impresa`/`id_impresa` dell'utente SPID a ogni login, alla creazione, alla revoca e alla disattivazione della ditta. Tutto il codice che esisteva solo per gli account `locale` (registrazione, reset password, 2FA email, verifica annuale, magic-link) viene rimosso.

**Tech Stack:** Laravel 13, PHP 8.4, Blade+Tailwind4+Alpine, Spatie Permission v6, PHPUnit, Dusk (solo CI).

**Spec:** `docs/superpowers/specs/2026-10-23-accessi-separati-cobranding-design.md`

## Global Constraints

- Test: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test --filter <Nome>`; suite intera prima del commit finale di ogni task: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test`. Analisi: `docker compose exec php composer run analyse`.
- POST web nei Feature test: `$this->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class, \Illuminate\Routing\Middleware\ThrottleRequests::class])`.
- Niente CDN, niente `<img src="http…">` nelle email, CSP nginx invariata.
- `AllineamentoSeeder` idempotente: non tocca mai valori configurati né ruoli utenti.
- Parametri password: `#[\SensitiveParameter]`.
- Codice fiscale: normalizzato maiuscolo, senza prefisso `TINIT-`, 16 caratteri (riusare la normalizzazione già usata da `DelegaService::predelega`).
- Account `locale`: si **disattivano**, mai cancellati.
- Commit con skill `/commit` (caveman-commit), messaggi Conventional Commits.
- Versione: **0.8.19** (patch) — `publiccode.yml` `softwareVersion` + CHANGELOG `## [0.8.19] - <data>` nella stessa PR.
- Magic-link: mai inviati in prod → si rimuovono le rotte e basta (404), nessuna pagina di cortesia.

## Review Focus

1. Logo ente impostato solo come URL esterno irraggiungibile/lento → l'email parte comunque, con solo ProntoPA, entro pochi secondi (timeout 5 s, esito negativo in cache 1 h). Test in Task 2.
2. Persona pre-delegata che entra con SPID **per la prima volta** (Completa profilo, email non ancora verificata) → ruolo `impresa` già assegnato, ma vede solo la verifica email finché non conferma. Test in Task 6.
3. Revoca della delega (o disattivazione della ditta) mentre la persona è collegata → alla richiesta successiva non vede più le segnalazioni della ditta (403 sulla scheda). Test in Task 6 e Task 7.
4. Email della persona delegata uguale all'email aziendale con maiuscole diverse → una sola email. Test in Task 8.
5. Directory AD irraggiungibile al login → messaggio "temporaneamente non disponibile", nessun tentativo su account locali. Test in Task 4.

---

### Task 1: Favicon, helper Brand, upload logo ente, co-branding web

**Files:**
- Create: `public/favicon.svg`, `public/favicon-32.png`, `public/apple-touch-icon.png`, `resources/brand/prontopa-email.png` (192 px, sfondo trasparente) — ricavati da `public/logo.svg`; rigenerare `public/favicon.ico`.
- Create: `resources/views/partials/favicon.blade.php`, `resources/views/components/co-branding.blade.php`
- Create: `app/Support/Brand.php`, `app/Http/Controllers/BrandLogoController.php`
- Modify: `resources/views/layouts/{app,guest,public}.blade.php` (+ viste standalone con `<head>` proprio: `grep -rl "<head" resources/views`)
- Modify: `app/Http/Controllers/Admin/ImpostazioniController.php`, vista impostazioni gruppo brand, `database/seeders/ImpostazioniSeeder.php`, `routes/web.php`
- Test: `tests/Feature/BrandLogoTest.php`

**Interfaces:**
- Produces:
  - `Brand::logoEnteUrl(): ?string` — file caricato → `route('brand.logo-ente', ['v' => <mtime>])`; altrimenti `ente_logo_url` (relativo → `url()`); altrimenti `null`.
  - `Brand::logoEnteFile(): ?string` — path assoluto del file caricato, o `null`.
  - `Brand::logoProntoPaEmail(): string` — `resource_path('brand/prontopa-email.png')`.
  - Impostazione `ente_logo_file` (path relativo sul disco `local`, es. `brand/logo-ente.png`, default `''`).
  - Rotta pubblica `GET /brand/logo-ente` → `brand.logo-ente` (404 se nessun file; `Cache-Control: public, max-age=86400`; `Content-Type` dal file; header `X-Content-Type-Options: nosniff`).
  - Componente `<x-co-branding size="sm|md|lg" />`: logo ente (o nome ente in testo se manca) + separatore verticale + `x-application-logo`.

Decisione del piano: upload solo **PNG, JPG, WebP** (niente SVG caricati: elimina il rischio XSS e il problema SVG nelle email). L'URL può restare qualsiasi immagine.

- [ ] **Step 1: Test che falliscono** in `tests/Feature/BrandLogoTest.php`:
  - `test_admin_carica_logo_png_e_viene_servito`: POST `admin.impostazioni.update` multipart con `ente_logo_upload` = `UploadedFile::fake()->image('logo.png', 200, 80)` → `Impostazione::get('ente_logo_file')` = `'brand/logo-ente.png'`; GET `/brand/logo-ente` → 200, `Content-Type` `image/png`.
  - `test_logo_caricato_ha_precedenza_su_url`: con `ente_logo_url='https://x.test/a.png'` e file caricato → `Brand::logoEnteUrl()` inizia con `route('brand.logo-ente')`.
  - `test_upload_rifiuta_svg_e_file_oltre_1mb`: `.svg` → errore di validazione su `ente_logo_upload`; `UploadedFile::fake()->image('x.png')->size(1025)` → errore.
  - `test_rimuovi_logo_cancella_file_e_impostazione`: POST con `rimuovi_logo_file=1` → `ente_logo_file` vuoto, file assente, GET `/brand/logo-ente` → 404.
  - `test_login_e_home_mostrano_favicon_e_co_branding`: GET `/login` e `/` contengono `favicon.svg` e `logo-ente` quando il file c'è.
- [ ] **Step 2:** Esegui `--filter BrandLogoTest` → FAIL.
- [ ] **Step 3: Implementa.** Validazione upload: `['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024']`; salva come `brand/logo-ente.<ext>` sul disco `local` cancellando il file precedente; form impostazioni `enctype="multipart/form-data"`, anteprima del logo attuale, checkbox "rimuovi". `ente_logo_file` va in `ImpostazioniSeeder` (gruppo `brand`, valore vuoto, `firstOrCreate`) e **non** deve essere sovrascrivibile dall'array `impostazioni[...]` (aggiungerla a `Impostazione::CHIAVI_INTERNE` o escluderla esplicitamente). Sostituisci ogni `Impostazione::get('ente_logo_url')` nelle viste web con `Brand::logoEnteUrl()` e l'header logo con `<x-co-branding>`. `@include('partials.favicon')` in ogni `<head>`.
- [ ] **Step 4:** Verifica che il backup includa il file: `--filter Backup` verde, e nel test `BrandLogoTest` aggiungi `test_logo_caricato_sta_sotto_storage_app` (path assoluto inizia con `storage_path('app')`).
- [ ] **Step 5:** Esegui `--filter BrandLogoTest` → PASS; suite intera verde.
- [ ] **Step 6:** Commit `feat(brand): favicon, upload logo ente, co-branding`.

### Task 2: Loghi inline nelle email

**Files:**
- Modify: `resources/views/vendor/mail/html/message.blade.php` (e `vendor/mail/html/themes/*.css` per la classe del logo se serve)
- Modify: `app/Support/Brand.php`
- Test: `tests/Feature/EmailLoghiInlineTest.php`

**Interfaces:**
- Consumes: `Brand::logoEnteFile()`, `Brand::logoProntoPaEmail()` (Task 1).
- Produces: `Brand::logoEntePerEmail(): ?string` — path assoluto locale: file caricato; altrimenti, se `ente_logo_url` è valorizzato, copia in cache su `storage_path('app/brand/cache/' . sha1($url) . '.<ext>')`; `null` se non c'è o il download fallisce.

Regole del download (`logoEntePerEmail`): URL relativo → risolto su `public_path()` senza HTTP; URL assoluto → `Http::timeout(5)->get()`, accettato solo se 200, ≤ 1 MB, `getimagesizefromstring()` riconosce PNG/JPEG/GIF/WebP; fallimento → `Cache::put('brand:logo-ente-ko:' . sha1($url), true, 3600)` e `null`; cache riusata finché il file esiste (l'hash cambia se cambia l'URL).

- [ ] **Step 1: Test che falliscono** (mailer `array`: `config(['mail.default' => 'array'])`, invio reale di una notifica esistente, es. `SegnalazioneStatoCambiato` a un utente; messaggi da `app('mailer')->getSymfonyTransport()->messages()`):
  - `test_email_contiene_loghi_inline_e_nessuna_immagine_esterna`: con logo caricato → l'HTML non contiene `src="http`, contiene ≥ 2 `src="cid:`, il messaggio ha ≥ 2 parti inline.
  - `test_logo_da_url_viene_scaricato_e_incorporato`: `Http::fake(['x.test/*' => Http::response(<png bytes>, 200, ['Content-Type' => 'image/png'])])`, `ente_logo_url='https://x.test/l.png'` → 2 `cid:`, nessun `src="http`.
  - `test_url_irraggiungibile_email_parte_con_solo_prontopa`: `Http::fake(fn () => throw new ConnectionException())` → email inviata, 1 `cid:`, nessun `src="http`; seconda email nella stessa ora → nessuna nuova richiesta HTTP (`Http::assertSentCount(1)`).
  - `test_url_che_non_e_immagine_viene_ignorato`: risposta `text/html` → 1 `cid:`.
- [ ] **Step 2:** `--filter EmailLoghiInlineTest` → FAIL.
- [ ] **Step 3: Implementa.** In `message.blade.php`, dentro `<x-mail::header>`, `<img src="{{ $message->embed($path) }}">` per ogni logo disponibile, protetto da `@if(isset($message) && method_exists($message, 'embed'))` (l'anteprima mail in browser non ha `$message`: lì solo il nome ente in testo). Rimuovi la lettura di `ente_logo_url`.
- [ ] **Step 4:** `--filter EmailLoghiInlineTest` → PASS; suite intera verde.
- [ ] **Step 5:** Commit `feat(mail): loghi ente e ProntoPA inline nelle email`.

### Task 3: Rimozione registrazione, reset password, approvazione e verifica annuale

**Files:**
- Delete: `app/Http/Controllers/Auth/{RegisteredUserController,PasswordResetLinkController,NewPasswordController,AnnualAccountVerificationController}.php`, `resources/views/auth/{register,forgot-password,reset-password}.blade.php`, viste della verifica annuale, `app/Console/Commands/CheckAnnualAccountVerification.php`, `app/Notifications/AnnualVerification{Reminder,Suspended}Notification.php`, `tests/Feature/Auth/{RegistrationTest,PasswordResetTest,PasswordResetLocaleTest,AnnualAccountVerificationControllerTest}.php`, `tests/Feature/CheckAnnualAccountVerificationTest.php`
- Modify: `routes/auth.php`, `routes/web.php`, `routes/console.php` (schedule), `app/Http/Controllers/Admin/UtentiController.php`, `app/Models/User.php`, `app/Http/Middleware/EnsureUserIsActive.php`, `app/Http/Requests/Auth/LoginRequest.php` (solo rami `approval_status`), `database/seeders/ImpostazioniSeeder.php` (chiavi verifica annuale), viste con link a `register`/`password.request`, eventuali notifiche di reset in `app/Notifications`
- Modify: `resources/views/profile/edit.blade.php` + partial cambio password, `PasswordController@update`, rotta `password.update` (PUT `password`), `tests/Feature/Auth/PasswordUpdateTest.php` (delete)
- Test: `tests/Feature/Auth/RotteRimosseTest.php`

Colonne DB (`approval_status`, `annual_verification_*`, `prossima_verifica_annuale_at`) **restano**: nessuna migration di drop. Il rinnovo deleghe scuole (`deleghe:rinnovi`) non si tocca.

- [ ] **Step 1: Test che falliscono** in `RotteRimosseTest`: `Route::has()` è `false` per `register`, `password.request`, `password.email`, `password.reset`, `password.store`, `password.update` e per la rotta della verifica annuale (nome attuale da `routes/web.php`); `GET /register` → 404; `Artisan::all()` non contiene il comando della verifica annuale; GET `/login` non contiene `register` né `forgot-password`.
- [ ] **Step 2:** `--filter RotteRimosseTest` → FAIL.
- [ ] **Step 3:** Rimuovi file, rotte, schedule, riferimenti (`grep -rn "register\|password.request\|password.reset\|AnnualVerification\|annual_verification\|approval_status\|pending" app resources routes`). In `EnsureUserIsActive`/`LoginRequest` restano solo i controlli `attivo`/`bloccato`. `creaDaProfilo` può smettere di scrivere `approval_status` solo se la colonna ha default compatibile, altrimenti lascialo.
- [ ] **Step 4:** `--filter RotteRimosseTest` → PASS; suite intera verde; `composer run analyse` pulito.
- [ ] **Step 5:** Commit `refactor(auth): via registrazione, reset password, verifica annuale`.

### Task 4: Login solo AD, via 2FA email, disattivazione account locali

**Files:**
- Modify: `app/Http/Requests/Auth/LoginRequest.php`, `app/Models/User.php` (`metodoSecondoFattore`), `app/Http/Controllers/Auth/TwoFactorChallengeController.php`, `routes/auth.php` (`two-factor-challenge/reinvia`), `app/Http/Controllers/Admin/UtentiController.php` + viste `admin/utenti/*`, `routes/web.php`
- Delete: `app/Services/Auth/CodiceAccessoEmail.php` + la sua notifica, `tests/Feature/Auth/CodiceAccessoEmailTest.php`
- Create: `database/migrations/2026_10_23_000001_disattiva_account_locali.php`
- Modify: `tests/Feature/Auth/{LoginInstradamentoTest,AuthenticationTest,EmailCondivisaTest,TwoFactorAuthenticationTest,V072Test}.php` dove assumono login locale; `tests/Unit/PasswordNonNeiLogTest.php` (togli metodi rimossi)
- Test: `tests/Feature/Auth/LoginSoloAdTest.php`

**Interfaces:**
- Produces: `LoginRequest::authenticate()` passa solo da `LdapLoginService::login()`. `User::metodoSecondoFattore(): ?string` restituisce solo `'totp'` o `null` (TOTP resta, nessun ramo `'email'`).

- [ ] **Step 1: Test che falliscono** in `LoginSoloAdTest` (AD simulato con `tests/Support/FakeDirectory`):
  - `test_account_locale_attivo_con_password_giusta_non_entra`: utente `auth_source='locale'`, `attivo=true`, password nota; POST `/login` con username e con email → sessione guest, errore `auth.failed`.
  - `test_utente_ad_entra`: `FakeDirectory` riconosce `m.rossi` → redirect dashboard.
  - `test_directory_irraggiungibile_mostra_errore_e_non_prova_locale`: `FakeDirectory` lancia `DirectoryNonDisponibile`, esiste un `locale` con stesse credenziali → errore `"Autenticazione dei dipendenti temporaneamente non disponibile. Riprova più tardi."`, guest.
  - `test_migration_disattiva_tutti_i_locali`: crea 3 `locale` attivi (ruoli `impresa`, `segnalatore`, `admin`) + 1 `ldap` + 1 `spid`; esegui `(require database_path('migrations/2026_10_23_000001_disattiva_account_locali.php'))->up()` → i 3 `locale` hanno `attivo=false`, gli altri invariati.
  - `test_utenti_non_si_creano_da_admin`: `Route::has('admin.utenti.create')` e `admin.utenti.store` `false`; la form di modifica non offre il ruolo `impresa` né il campo `id_impresa`.
- [ ] **Step 2:** `--filter LoginSoloAdTest` → FAIL.
- [ ] **Step 3: Implementa.** Togli `tentaLocale` e il canale `email` di `identitaLogin()` (il blocco tentativi continua a usare l'identità normalizzata); `DirectoryNonDisponibile` → `report()` + `fallito(<messaggio sopra>)`. Togli ramo/rotta `reinvia` e il caso `'email'` dalla challenge. `UtentiController`: via `create`/`store` (utenti arrivano solo da AD o SPID), via ruolo `impresa` e `id_impresa` da `update` (il ruolo ditta lo decide solo `DelegaImpresaService`, Task 6). Migration: `DB::table('users')->where('auth_source', 'locale')->update(['attivo' => false])`, `down()` vuoto. Aggiorna il commento in `AppServiceProvider.php:40` (niente più locale/magic link).
- [ ] **Step 4:** `--filter LoginSoloAdTest` → PASS; suite intera verde (adegua i test elencati: login via `FakeDirectory` o `actingAs`).
- [ ] **Step 5:** Commit `feat(auth): login operatori solo AD, account locali disattivati`.

### Task 5: Due porte d'accesso

**Files:**
- Create: `resources/views/auth/accesso.blade.php`, rotta `GET /accesso` → `accesso` (middleware `guest`) in `routes/auth.php`
- Modify: `resources/views/auth/login.blade.php` (togli blocco SPID ~righe 100-115, aggiungi link "Scuole e ditte: accedi con SPID/CIE" → `route('accesso')`), `resources/views/public/home.blade.php`, `resources/views/layouts/public.blade.php:82`, `app/Http/Controllers/Auth/SpidController.php` (`errore()` → redirect `accesso`), `app/Http/Middleware/LimitaAccessoSpid.php` (redirect di sessione chiusa → `accesso`), logout SPID se torna su `login`
- Modify: `tests/Browser/{LoginTest,SpidLoginTest,DelegaScuolaTest}.php` e `tests/Browser/Pages/*` (SPID parte da `/accesso`)
- Test: `tests/Feature/Auth/PorteAccessoTest.php`, aggiorna `tests/Feature/PublicHomeTest.php`

Testi (copy):
- `/accesso`: titolo "Accesso scuole e ditte", bottone "Entra con SPID o CIE"; in dev con `OIDC_MOCK` anche il link al simulatore come oggi in login; in fondo link piccolo "Personale del comune? Accedi con le credenziali di dominio" → `login`.
- `/login`: titolo "Accesso personale del comune", nota "Usa le credenziali di dominio (le stesse del PC dell'ufficio)".
- Home: bottone primario "Scuole e ditte — Entra con SPID/CIE" → `accesso`; sotto, link secondario "Personale del comune — accesso con credenziali di dominio" → `login`. Header pubblico "Accedi" → `accesso`.

- [ ] **Step 1: Test che falliscono** in `PorteAccessoTest`: GET `/accesso` → 200, contiene `route('spid.start')` e `route('login')`, non contiene `name="password"`; GET `/login` → contiene `name="password"` e `route('accesso')`, **non** contiene `route('spid.start')`; GET `/` contiene sia `route('accesso')` sia `route('login')`, e `route('accesso')` compare prima; utente autenticato su `/accesso` → redirect dashboard; errore SPID (callback con `state` errato) → redirect `/accesso` con messaggio.
- [ ] **Step 2:** `--filter PorteAccessoTest` → FAIL.
- [ ] **Step 3:** Implementa viste/rotte; layout `guest` + `<x-co-branding size="lg">` su entrambe le pagine.
- [ ] **Step 4:** `--filter "PorteAccessoTest|PublicHomeTest|Spid"` → PASS; suite intera verde.
- [ ] **Step 5:** Commit `feat(auth): accesso SPID separato dal login operatori`.

### Task 6: Deleghe ditta — modello, servizio, aggancio SPID

**Files:**
- Create: `database/migrations/2026_10_23_000002_create_deleghe_imprese_table.php` (anche `imprese.attiva` boolean default true)
- Create: `app/Models/DelegaImpresa.php`, `app/Services/Deleghe/DelegaImpresaService.php`
- Modify: `app/Models/Impresa.php` (`attiva` in fillable + cast bool, `deleghe(): HasMany`), `app/Services/Auth/SpidLoginService.php`, `app/Http/Middleware/LimitaAccessoSpid.php`
- Test: `tests/Feature/Deleghe/DelegaImpresaTest.php`

**Interfaces:**
- Produces:
  - Tabella `deleghe_imprese`: `id`, `codice_fiscale` char(16), `user_id` nullable FK users nullOnDelete, `id_impresa` FK `imprese.id_impresa`, `stato` string(12) (`attiva`·`revocata`), `motivo` string(255) nullable, `creata_da` FK users nullOnDelete, `revocata_da` nullable FK users nullOnDelete, `revocata_at` timestamp nullable, timestamps; indici (`codice_fiscale`,`stato`), (`user_id`,`stato`), (`id_impresa`,`stato`).
  - `DelegaImpresa` (`$table = 'deleghe_imprese'`): const `ATTIVA='attiva'`, `REVOCATA='revocata'`; `scopeAttive(Builder)`; relazioni `user()`, `impresa()`, `creataDa()`, `revocataDa()`.
  - `DelegaImpresaService::predelega(string $codiceFiscale, Impresa $impresa, ?string $motivo, User $operatore): DelegaImpresa` — `ValidationException` su chiave `codice_fiscale` se CF non valido, se esiste già una delega attiva per quel CF (anche su altra ditta: "Questa persona gestisce già <ragione sociale>") o se `! $impresa->attiva`. Se esiste già un utente con quel CF → aggancia subito e chiama `allinea()`.
  - `DelegaImpresaService::revoca(DelegaImpresa $delega, User $operatore, ?string $motivo = null): void` → stato `revocata`, `revocata_da/at`, poi `allinea($delega->user)` se c'è.
  - `DelegaImpresaService::disattivaImpresa(Impresa $impresa, User $operatore): void` → `attiva=false`, revoca tutte le attive con motivo `'Ditta disattivata'`. `riattivaImpresa(Impresa $impresa): void` → `attiva=true`, nessuna delega ripristinata.
  - `DelegaImpresaService::allinea(User $user): void` — solo per `isSpid()`: aggancia le attive con `user_id NULL` e stesso CF; se esiste una delega attiva → `syncRoles(['impresa'])` + `id_impresa`; altrimenti, se ha ruolo `impresa` → `syncRoles(['segnalatore'])` + `id_impresa = null`. Idempotente.

- [ ] **Step 1: Test che falliscono** in `DelegaImpresaTest`:
  - `test_predelega_e_primo_accesso_spid_danno_ruolo_impresa`: predelega CF `RSSMRA80A01H501U` su impresa A; `SpidLoginService::creaDaProfilo(<identità con quel CF>, 'm@x.it')` → ruolo `impresa`, `id_impresa` = A, delega con `user_id` valorizzato; GET dashboard con email **non verificata** → redirect `verification.notice`; dopo `markEmailAsVerified()` → GET `route('imprese.dashboard')` 200.
  - `test_utente_spid_esistente_viene_agganciato_al_login`: utente spid senza ruolo ditta, predelega creata dopo → `accedi()` → ruolo `impresa`.
  - `test_predelega_su_utente_gia_esistente_aggancia_subito`.
  - `test_persona_vede_storico_della_ditta`: segnalazione su vecchio appalto (scaduto) dell'impresa A → visibile via `Segnalazione::visibileA($user)`; segnalazione di impresa B → non visibile.
  - `test_revoca_toglie_accesso_alla_richiesta_successiva`: utente collegato; `revoca()` → GET `segnalazioni.show` della segnalazione di A → 403 (o redirect fuori dal portale imprese); ruolo tornato `segnalatore`, `id_impresa` null.
  - `test_cf_con_delega_attiva_non_si_rideleaga`: seconda predelega stesso CF (anche altra impresa) → `ValidationException` su `codice_fiscale`.
  - `test_impresa_disattivata_revoca_tutte_e_blocca_nuove`: 2 deleghe attive → `disattivaImpresa()` → entrambe `revocata` con motivo `Ditta disattivata`; `predelega()` su quella ditta → `ValidationException`; `riattivaImpresa()` → deleghe ancora revocate.
  - `test_cf_con_prefisso_tinit_viene_normalizzato`: `'TINIT-rssmra80a01h501u'` → salvato `RSSMRA80A01H501U`.
- [ ] **Step 2:** `--filter DelegaImpresaTest` → FAIL.
- [ ] **Step 3: Implementa** migration, model, servizio. In `SpidLoginService::accedi()` chiama `allinea($user)` dopo il salvataggio (prima di `agganciaPredeleghe`); in `creaDaProfilo()` dopo `syncRoles(['segnalatore'])`. In `LimitaAccessoSpid`: dopo il controllo email verificata, `if ($user->hasRole('impresa')) return $next($request);` (prima del controllo deleghe scuola). Verifica che `RoleDashboardController`/`dashboard` mandi il ruolo `impresa` su `imprese.dashboard` (già così per i locali; aggiungi al test se serve).
- [ ] **Step 4:** `--filter "DelegaImpresaTest|LimitaAccessoSpid|Spid|Deleghe"` → PASS; suite intera verde.
- [ ] **Step 5:** Commit `feat(deleghe): deleghe ditta via SPID con pre-delega`.

### Task 7: UI deleghe ditta e disattivazione ditta

**Files:**
- Create: `app/Http/Controllers/DelegheImpresaController.php`, `resources/views/imprese/_deleghe.blade.php`
- Modify: `routes/web.php` (gruppo `auth`, `role:admin|gestore`), `resources/views/imprese/{edit,index}.blade.php`, `app/Http/Controllers/ImpreseCRUDController.php`
- Test: `tests/Feature/DelegheImpresaUiTest.php`

**Interfaces:**
- Consumes: `DelegaImpresaService` (Task 6).
- Produces rotte: `POST imprese/{impresa}/deleghe` → `imprese.deleghe.store` (`codice_fiscale` required, `motivo` nullable max:255); `DELETE imprese/{impresa}/deleghe/{delega}` → `imprese.deleghe.destroy` (revoca, 404 se la delega non è di quell'impresa); `POST imprese/{impresa}/disattiva` → `imprese.disattiva`; `POST imprese/{impresa}/riattiva` → `imprese.riattiva`.

UI: nella pagina modifica impresa, sezione "Persone che gestiscono la ditta (SPID/CIE)": elenco (CF, nome se agganciata altrimenti "in attesa del primo accesso", creata da/il, bottone Revoca con conferma), form "Aggiungi persona" (CF + motivo), storico revocate compresso. Bottone "Disattiva ditta" (conferma: "Le persone delegate perderanno subito l'accesso") / "Riattiva". Elenco imprese: badge "Disattivata", conteggio persone delegate. Testo d'aiuto: "Scegli la ditta già presente in anagrafica: la persona vedrà anche le segnalazioni dei vecchi appalti".

- [ ] **Step 1: Test che falliscono**: gestore crea delega → 302 + riga attiva con `creata_da` = gestore; CF già delegato → errore di validazione mostrato; revoca → `revocata`; revoca di delega di altra impresa → 404; `operaio` e `impresa` → 403 su tutte le rotte; disattiva → `imprese.attiva=false` e deleghe revocate; pagina edit mostra CF e "in attesa del primo accesso".
- [ ] **Step 2:** `--filter DelegheImpresaUiTest` → FAIL.
- [ ] **Step 3:** Implementa controller (sottile, delega al servizio) e viste.
- [ ] **Step 4:** `--filter DelegheImpresaUiTest` → PASS; suite intera verde.
- [ ] **Step 5:** Commit `feat(imprese): gestione persone delegate e disattivazione ditta`.

### Task 8: Notifiche ditta e rimozione magic-link

**Files:**
- Modify: `app/Services/SegnalazioneWorkflowService.php` (~righe 353-372), `app/Notifications/ImpresaAssegnataNotification.php`, `app/Http/Controllers/Api/TelegramWebhookController.php` (~righe 474-481), `app/Models/AllegatoSegnalazione.php:42` (commento), `routes/web.php:250-255`
- Delete: `app/Http/Controllers/MagicLinkController.php`, `resources/views/imprese/magic-show.blade.php`, `DitteECanaliTest::test_magic_link_mostra_scheda_e_permette_azione_senza_login`
- Test: `tests/Feature/NotificheDittaTest.php`, aggiorna `tests/Feature/SegnalazioneWorkflowNotificationsTest.php`, `tests/Feature/TelegramWebhookTest.php`

Regola destinatari (assegnazione a impresa): utenti `impresa` attivi con `id_impresa` = ditta **più** `imprese.email` se valorizzata e non uguale (case-insensitive, trim) all'email di uno di quegli utenti. Ditta disattivata: nessuna notifica (`attiva=false` → return). Azione dell'email: "Accedi con SPID/CIE" → `route('accesso')`, testo con numero e titolo della segnalazione.

- [ ] **Step 1: Test che falliscono** (`Notification::fake()`):
  - `test_notifica_a_persone_delegate_e_email_aziendale`: 1 delegato `a@ditta.it`, `imprese.email='info@ditta.it'` → delegato notificato + `AnonymousNotifiable` con `info@ditta.it`.
  - `test_email_uguale_una_sola_notifica`: delegato `Info@Ditta.it`, impresa `info@ditta.it ` → solo il delegato.
  - `test_senza_delegati_solo_email_aziendale`.
  - `test_ditta_disattivata_nessuna_notifica`.
  - `test_email_ditta_punta_ad_accesso_spid`: `toMail()` → `actionUrl` = `route('accesso')`, nessun `signature=` nell'output.
  - `test_magic_link_rimosso`: `Route::has('magic-link.show')` `false`.
  - Telegram: foto caricata da utente `impresa` → risposta senza `signature=`.
- [ ] **Step 2:** `--filter "NotificheDittaTest"` → FAIL.
- [ ] **Step 3:** Implementa; `grep -rn "magic" app resources routes tests` deve restituire solo occorrenze non legate al magic-link.
- [ ] **Step 4:** `--filter "NotificheDittaTest|SegnalazioneWorkflowNotifications|TelegramWebhook|DitteECanali"` → PASS; suite intera verde.
- [ ] **Step 5:** Commit `feat(notifiche): email ditta a delegati e azienda, via magic-link`.

### Task 9: Dati demo, Dusk, documentazione, versione

**Files:**
- Modify: `app/Console/Commands/PopulateDemoData.php` (ditte demo → utenti `spid` con CF fittizi validi + `DelegaImpresa` attive invece degli account `locale` `impresa1`/`impresa2`; nessun nuovo account `locale` che pretenda di poter entrare), `tests/Feature/PopulateDemoDataTest.php`
- Modify: `database/seeders/AdminUserSeeder.php` solo se serve a CI/Dusk (verificare che Dusk entri via LDAP mock)
- Modify: `tests/Browser/*` rimasti (es. login ditta), `CLAUDE.md` (sezioni Accesso v1.2, Ruoli, Brandizzazione: `ente_logo_file`, Architettura: nuovi file, rimozione magic-link/2FA email/verifica annuale/registrazione, `artisan demo` ditte via SPID mock), `docs/` utente (`grep -rln "registra\|magic\|password dimenticata\|ditta" docs`), `CHANGELOG.md`, `publiccode.yml` (`softwareVersion: 0.8.19`)
- Test: `tests/Feature/PopulateDemoDataTest.php`

- [ ] **Step 1: Test che fallisce**: dopo `artisan demo`, esistono ≥ 2 `DelegaImpresa` attive con `user_id` di utenti `spid` con ruolo `impresa`; nessun utente `locale` con ruolo `impresa`; rilanciato due volte non duplica.
- [ ] **Step 2:** `--filter PopulateDemoDataTest` → FAIL.
- [ ] **Step 3:** Implementa demo; aggiorna Dusk (non eseguibile in locale: Alpine/musl, vedi CLAUDE.md — gira in CI); aggiorna docs, CHANGELOG `## [0.8.19] - 2026-10-23` con sezioni Aggiunto/Modificato/Rimosso (nota operativa: account locali disattivati, ditte da pre-delegare con CF della persona fisica).
- [ ] **Step 4:** Suite intera verde, `composer run analyse` pulito, `npm run build` ok; smoke manuale: `/login` (mock.admin), `/accesso` → simulatore SPID con CF della ditta demo → portale imprese; email in Mailpit con loghi inline.
- [ ] **Step 5:** Commit `chore(release): 0.8.19 accessi separati e ditte via SPID`.
- [ ] **Step 6:** PR verso `main`, CI verde (`test`+`dusk`+`semgrep`), squash merge, tag `v0.8.19`, `gh run watch` sul workflow release.
