# ProntoPA

Open-source manutenzione PA. Scuole/comuni/URP segnalano guasti, gestori assegnano imprese/operatori, traccia workflow chiusura. Brand via tabella `impostazioni` (non `.env`). Licenza EUPL-1.2, riuso via `publiccode.yml`.

## Convenzioni Claude Code

- Commit con `/commit` (skill caveman-commit)
- Roadmap: `PIANO-SVILUPPO.md` (piano per release) · `TODO.md` (stato completato/in corso)

## Stack

Laravel 13 · PHP 8.4-FPM · Nginx · MariaDB 11.4 · Redis · Blade+Tailwind4+Alpine.js · Chart.js · Leaflet+OSM · Breeze+Spatie Permission v6 · Sanctum · Docker+compose · GHCR · GitHub Actions

## Setup Dev

```bash
cp .env.example .env
MSYS_NO_PATHCONV=1 docker compose up -d
MSYS_NO_PATHCONV=1 docker compose exec php php artisan key:generate
MSYS_NO_PATHCONV=1 docker compose exec php php artisan migrate --seed
MSYS_NO_PATHCONV=1 docker compose exec php npm run build
```

App: http://localhost | Adminer: :8081 | Mailpit: :8025 (profilo `dev`)  
Dev: `docker compose --profile dev up -d`  
`docker-compose.yml`=prod · `docker-compose.override.yml`=dev (auto, bind mount+Adminer+Mailpit, OPcache hot-reload)  
AI locale opzionale: `docker compose --profile ai up -d` (container Ollama)

Dati demo realistici (istituti/utenti/segnalazioni in tutti gli stati, rilanciabile senza accumulo):
```bash
docker compose exec php php artisan demo
```

```bash
docker compose up -d / down / logs -f php
docker compose exec php sh
docker compose exec php php artisan <cmd>
docker compose exec php composer <cmd>
docker compose exec php npm run build
docker compose exec php composer run analyse   # larastan, baseline in phpstan-baseline.neon
```

**Dopo `git pull`/merge**: se `composer.json` è cambiato, `composer install` — vendor/ non tracciato, "Class not found" spesso è solo questo, non un bug.

**Gotcha Docker dev**: `docker compose restart php` da solo → nginx tiene l'IP upstream vecchio (risolto una volta sola all'avvio) → 502. Riavvia anche `nginx`, o l'intero stack.

**Gotcha versione PHP**: `docker/php/Dockerfile` (dev) e `Dockerfile` (prod, usato anche da CI) possono disallinearsi silenziosamente — verifica `php -v` nel container dev contro `tests.yml` prima di fidarti che riproducano lo stesso ambiente (es. differenze `phpstan`/larastan tra versioni PHP).

**Gotcha build dev**: `pecl install redis` nel build dell'immagine PHP fallisce a volte con "No releases available" (flakiness rete pecl) — retry del build risolve, non è un problema del Dockerfile.

**Gotcha PHPStan CI**: `phpstan.neon` ha `reportUnmatchedIgnoredErrors: false` e `treatPhpDocTypesAsCertain: false` — un run locale pulito non garantisce CI verde, larastan risolve i cast dei model (`casts(): array`) in modo leggermente diverso tra ambienti per motivi mai isolati con certezza. Se tocchi `phpstan-baseline.neon`, verifica sempre su CI (push), non fidarti solo del locale.

## .env

```env
APP_URL=http://localhost  APP_KEY=
DB_HOST=mariadb  DB_PORT=3306  DB_DATABASE=segnalazioni
DB_USERNAME=segnalazioni  DB_PASSWORD=  DB_ROOT_PASSWORD=
REDIS_HOST=redis
MAIL_MAILER=smtp  MAIL_HOST=mailpit  MAIL_PORT=1025
PEC_HOST=mbox.cert.legalmail.it  PEC_USERNAME=  PEC_PASSWORD=
WEBHOOK_CITTADINI_URL=  WEBHOOK_CITTADINI_SECRET=
SETUP_TOKEN=  # wizard primo avvio (/setup); vuoto = wizard disattivato
```

Brand/mappa/email → **Admin → Impostazioni**.

## Brandizzazione (`impostazioni`)

| Chiave | Gruppo |
|---|---|
| `ente_nome` `ente_logo_url` `ente_colore_primario` `ente_colore_secondario` `ente_sito_url` | brand |
| `osm_lat` `osm_lng` `osm_zoom` | mappa |
| `mail_from_address` `mail_from_name` | email |

```php
$val = Impostazione::get('ente_nome', 'ProntoPA');
```

`APP_VERSION` iniettato build Docker → `config('app.version')`. Dev=`dev`.

## Architettura

```
app/Http/Controllers/
  Auth/  SetupController  GestioneController  SegnalazioneController
  SegnalatoreDashboardController  OperaioDashboardController  RoleDashboardController
  ImpreseDashboardController  ImpreseCRUDController  AppaltiController
  StatisticheController  ReportController  FascicoloPdfController
  AdesioniSegnalazioniController  AllegatiSegnalazioniController  MagicLinkController
  AiTriageController  PublicHomeController  ProfileController  TelegramAccountController
  Admin/{ImpostazioniController,UtentiController,ProfiliController,ProvenienzaController,
         SediController,SlaController,SquadreController,OrganizzazioniController,AdminDashboardController}
  Api/{SegnalazioneApiController,TelegramWebhookController}
app/Models/
  Segnalazione  User  Impresa  Appalto  NotaSegnalazione  AllegatoSegnalazione
  StatoSegnalazione  StoricoStatoSegnalazione  Squadra  AdesioneSegnalazione
  SlaConfigurazione  Specializzazione  TipologiaSegnalazione  Profilo  Azione  ApiLog
  Istituto  Plesso  Provenienza  GruppoSegnalazione  Impostazione (helper statico+cache)
app/Enums/SegnalazioneStato.php    # fonte di verità sugli stati, vedi sotto
app/Policies/SegnalazionePolicy.php
app/Services/
  SegnalazioneWorkflowService  WebhookService  SlaService
  DedupService     # anti-duplicato: simili per tipologia/plesso/vicinanza + embeddings
  OllamaService    # LLM locale opzionale (titolo auto, triage suggerito, embeddings)
  TelegramBotService
app/Jobs/           CalcolaEmbeddingSegnalazione  GeneraTitoloSegnalazione  SuggerisciTriageSegnalazione
app/Http/Middleware/EnsureSetupComplete.php  EnsureUserIsActive.php
app/Console/Commands/PopulateDemoData.php (artisan demo)  InviaDigestGestori  CheckSlaViolazioni
```

## Ruoli (Spatie)

| Ruolo | Accesso |
|---|---|
| `admin` | Totale: utenti, impostazioni, sistema |
| `gestore` | Segnalazioni. `supervisore_segnalazioni=true`→tutto; altrimenti solo assegnate |
| `operaio` | Lavori assegnati a sé o alla propria squadra (`Squadra`, caposquadra riassegna ai membri) |
| `segnalatore` | Proprie segnalazioni. Ha `id_provenienza` (scuola/URP/portale/interno) |
| `impresa` | Solo lavori propria impresa. Ditte non registrate operano via magic-link firmato (no login) |

Login: campo form si chiama `username` (accetta lo username, non l'email) — `AuthenticatedSessionController`/`LoginRequest`.

## Workflow Stati

Fonte di verità: `app/Enums/SegnalazioneStato.php` (int-backed enum, **non** una tabella di riferimento).

1=Nuova 2=In carico 3=Assegnata a operatore 4=Assegnata a impresa 5=Preventivo in attesa 6=Sospesa 7=Completata† 8=Duplicata† 9=Annullata† 10=Archiviata† († = `isTerminale()`)

Azioni: assegna impresa/operatore/squadra · chiudi · invia/accetta preventivo · proponi chiusura (con rapportino fotografico) · archivia · sospendi · riapri · unisci a duplicato

Transizioni: `app/Services/SegnalazioneWorkflowService.php` · storico: tabella `stati_segnalazioni` (model `StoricoStatoSegnalazione`, alimenta i KPI)

## Funzionalità v0.6+

- **Anti-duplicato**: adesioni multiple a una segnalazione esistente + merge a posteriori (`DedupService`, `AdesioneSegnalazione`)
- **Squadre**: assegnazione a operatore singolo o squadra, notifica al solo caposquadra
- **Assistente AI locale opzionale** (profilo Docker `ai`, Ollama): titolo auto-generato, triage suggerito, dedup semantico via embeddings — sempre asincrono (queue), mai sul path sincrono; degrada in silenzio se Ollama non è raggiungibile (`Impostazione::get('ai_enabled')`)
- **Digest mattutino gestori**: comando `digest:invia` / `InviaDigestGestori`
- **Rendicontazione**: export XLSX (report mensile gestore, riepilogo impresa) e fascicolo PDF per segnalazione (richiede `composer install` per `phpoffice/phpspreadsheet` e `barryvdh/laravel-dompdf`)
- **Wizard primo avvio** (`/setup`): gate su `User::query()->exists()`, attivo solo se `SETUP_TOKEN` è valorizzato in `.env`; token + email + password → OTP via email → crea admin (`SetupController`, `EnsureSetupComplete`)
- **2FA opzionale** (TOTP + recovery codes): Fortify usato solo come libreria (`Fortify::ignoreRoutes()` in `App\Providers\FortifyServiceProvider::register()`) — login/registrazione/reset restano ai controller custom in `routes/auth.php`, zero rotte Fortify attive. **Va registrato a mano in `bootstrap/providers.php`** (non auto-discovered come le altre integrazioni Laravel). Self-service da profilo, dietro `password.confirm`.
- **Scan antimalware allegati** (opzionale, profilo Docker `security` + `Impostazione::get('antivirus_enabled')`): `ClamAvService` parla INSTREAM via socket raw a `clamav/clamav:stable` (no dipendenza composer), job `ScansionaAllegato` dispatchato da un unico hook su `AllegatoSegnalazione::booted()` (created event) — copre tutti i path di upload senza doverli aggiornare uno per uno. Infetto → spostato (non cancellato) su disco `quarantena`, mai servito dalla route di download. Degrada in silenzio se disattivato/clamd irraggiungibile.

## Database

Migrations `database/migrations/` (27 file, naming datato `YYYY_MM_DD_......`), schema base da `legacy/export.sql`, evoluto per release additiva (v0.5→v1.0). Non fare affidamento sui nomi legacy `000000..000005`, ormai storici.

Seeders (`DatabaseSeeder`): `TabelleRiferimentoSeeder` · `IstitutiPlessiSeeder` (vuoto by design, no dati demo in prod — usa `artisan demo`) · `ImpostazioniSeeder` · `RolesAndPermissionsSeeder` · `AdminUserSeeder` (dev/CI, marca `setup_completato`)

Import prod: `docker compose exec -T mariadb mariadb -u segnalazioni -p segnalazioni < legacy/export.sql`

## API

Spec completa: [`docs/API.md`](docs/API.md) · [`docs/openapi.yaml`](docs/openapi.yaml) (OpenAPI 3.1, importabile Swagger/Postman).

```
POST /api/segnalazioni              # crea da sito Comune (Sanctum), 409 se simili aperte (anti-dup)
GET  /api/segnalazioni/{id}/stato   # legge stato
```

Webhook outbound: HTTP POST HMAC-firmato al cambio stato → Admin → Impostazioni → Webhook.

## CI/CD

Tag `v*.*.*` → `.github/workflows/release.yml` → build **amd64 only** (arm64 droppato, niente QEMU) → push GHCR `:tag`+`:latest`.

```bash
git tag v1.2.0 && git push origin v1.2.0
```

**Dependabot**: PR con conflitto `composer.lock`/`package-lock.json` (tipico se ne mergi più di una in sequenza) → commenta `@dependabot rebase`, aspetta, ricontrolla `gh pr checks`. Se lento/bloccato, applicare il bump a mano (`composer require pkg:^X` o `npm install pkg@X`) è più veloce che aspettare — poi chiudi la PR come superata (`gh pr close N --comment "..." --delete-branch`).
**Gotcha peer-dep**: `vite` e `laravel-vite-plugin` sono accoppiati (`laravel-vite-plugin` fissa la major di vite richiesta) — dependabot le propone come PR separate ma vanno bumpate insieme o falliscono con `ERESOLVE`.

## Convenzioni

- Controller: Resource Controllers, autorizzazione via Policy (no ruoli nel controller)
- Models: Eloquent+relazioni esplicite, `scopeVisibileA(User $user)`, cast date+bool
- Views: layout `layouts/app.blade.php`, componenti `components/`, sezioni `gestione/ segnalatore/ imprese/ admin/`
- Email: Laravel Notifications → Mailpit dev, SMTP/PEC prod

## Test

CSRF/throttle non auto-bypassati nei Feature test nonostante `APP_ENV=testing` (`app()->runningUnitTests()` risulta `false` qui). Sui POST a rotte `web`: `$this->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class, \Illuminate\Routing\Middleware\ThrottleRequests::class])`.

Stesso sintomo si estende agli `<env>` di `phpunit.xml` in generale, non solo `APP_ENV`: `QUEUE_CONNECTION=sync` e `CACHE_STORE=array` dichiarati lì **non vengono applicati** — a runtime restano i valori reali (redis). Conseguenze pratiche: (1) un job dispatchato in un test NON gira sincrono — se devi verificarne l'esito, chiama `->handle()` a mano invece di fidarti del dispatch, oppure usa `Queue::fake()` + `assertPushed` per verificare solo che sia stato accodato; (2) `Impostazione` (cache `rememberForever`) non si resetta tra test — `Cache::flush()` esplicito nel `setUp()`, altrimenti un test eredita valori settati da un altro.

## Deploy Prod (Portainer/Podman rootless)

1. `git push tag` → Actions builda GHCR
2. Portainer stack → `docker-compose.yml`, env vars (APP_KEY, DB_PASSWORD, `SETUP_TOKEN`…)
3. **Non usare `migrate --seed`**: `DatabaseSeeder` include sempre `AdminUserSeeder`, che crea un admin con password da `.env` (default `password` se `ADMIN_PASSWORD` non è settata) e disabilita per sempre il wizard (gate su `User::exists()`). Seed selettivo:
   ```bash
   docker compose exec php php artisan migrate
   docker compose exec php php artisan db:seed --class=TabelleRiferimentoSeeder
   docker compose exec php php artisan db:seed --class=ImpostazioniSeeder
   docker compose exec php php artisan db:seed --class=RolesAndPermissionsSeeder
   ```
4. Vai su `/setup`, inserisci `SETUP_TOKEN` → crea admin → Admin → Impostazioni per configurare ente

Rootless: no bind mount, named volumes `mariadb_data` `redis_data` `app_storage` → `/var/www/html/storage`

Servizi `queue` (`queue:work`) e `scheduler` (loop `schedule:run` ogni 60s) obbligatori: senza, webhook/job AI restano in coda per sempre e i comandi schedulati (`sla:check`, `digest:invia`, verifica annuale) non partono mai. Stessa immagine di `php`, `entrypoint: []` (niente migrate/seed duplicato).

Error tracking: `sentry/sentry-laravel` (compatibile Glitchtip self-hosted, stesso protocollo). `SENTRY_LARAVEL_DSN` vuoto in `.env` = disattivato, nessuna configurazione aggiuntiva richiesta. `release`/`environment` derivati da `APP_VERSION`/`APP_ENV`.
