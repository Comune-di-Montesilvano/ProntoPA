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

`migrate --seed` non crea utenti: con `LDAP_HOST=mock` (default `.env.example`) si entra con `mock.admin`/`mock.admin`. Altri utenti AD simulati (password = username): `mock.supervisore` `mock.gestore` `mock.operaio` `mock.urp` `mock.segnalatore` `mock.nessungruppo`. Prefisso `mock.` voluto: `admin`/`gestore` collidono con admin legacy e utenti `artisan demo` (login rifiutato "username già usato").

**Smoke login via curl** (porta = `HTTP_PORT` del `.env`): `T=$(curl -s -c c.txt -b c.txt localhost:$PORT/login | grep -o 'name="_token" value="[^"]*"' | sed 's/.*value="//;s/"//')` poi `curl -b c.txt -c c.txt --data-urlencode "_token=$T" --data-urlencode username=mock.admin --data-urlencode password=mock.admin localhost:$PORT/login -w '%{redirect_url}'`. Stesso schema per `/auth/spid/mock` (`OIDC_MOCK=true`).

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
LDAP_HOST=mock  LDAP_BASE_DN=  LDAP_USER_DN_TEMPLATE=%s@ente.local  # mock vietato in prod (app non parte)
OIDC_MOCK=true  # simulatore SPID dev (/auth/spid/mock), vietato in prod
BACKUP_PASSPHRASE=  # passphrase AES-256 dei backup, uguale tra dev e prod per migrare
```

Nuova env var → anche nel blocco `x-php-env` di `docker-compose.yml` (niente passthrough automatico).

Brand/mappa/email → **Admin → Impostazioni**.

## Brandizzazione (`impostazioni`)

| Chiave | Gruppo |
|---|---|
| `ente_nome` `ente_logo_url` `ente_colore_primario` `ente_colore_secondario` `ente_sito_url` | brand |
| `osm_lat` `osm_lng` `osm_zoom` | mappa |
| `mail_from_address` `mail_from_name` | email |
| `miur_anagrafe_url` `miur_comune_default` | scuole |
| `deleghe_max_pendenti` `deleghe_giorni_stop_rifiuto` `deleghe_giorni_scadenza_richiesta` `deleghe_email_giorno_istituto` `deleghe_mesi_validita` `deleghe_giorni_avviso_delegato` | deleghe |

```php
$val = Impostazione::get('ente_nome', 'ProntoPA');
```

`APP_VERSION` iniettato SOLO nel build Docker (`release.yml` → build-arg dal tag git → `ENV` nell'immagine) → `config('app.version')`. Dev=`dev` (default config). **Mai** nell'env del container né in `.env`: lo riscriverebbe (bug v0.7.0: il compose lo forzava a `latest`). Il tag dell'immagine da scaricare è `IMAGE_TAG` (stesso schema di ComunicaPA).

## Architettura

```
app/Http/Controllers/
  Auth/  GestioneController  SegnalazioneController
  SegnalatoreDashboardController  OperaioDashboardController  RoleDashboardController
  ImpreseDashboardController  ImpreseCRUDController  AppaltiController
  StatisticheController  ReportController  FascicoloPdfController
  AdesioniSegnalazioniController  AllegatiSegnalazioniController  MagicLinkController
  AiTriageController  PublicHomeController  ProfileController  TelegramAccountController
  Admin/{ImpostazioniController,UtentiController,ProfiliController,ProvenienzaController,
         SediController,SlaController,SquadreController,OrganizzazioniController,AdminDashboardController,
         AnagrafeMiurController,DelegheController,BackupController}
  Deleghe/{DecisioneDelegaController,RinnovoDelegheController}  DelegheScuolaController
  Api/{SegnalazioneApiController,TelegramWebhookController}
app/Models/
  Segnalazione  User  Impresa  Appalto  NotaSegnalazione  AllegatoSegnalazione
  StatoSegnalazione  StoricoStatoSegnalazione  Squadra  AdesioneSegnalazione
  SlaConfigurazione  Specializzazione  TipologiaSegnalazione  Profilo  Azione  ApiLog
  Delega  DelegaStorico  Istituto  Plesso  Provenienza  GruppoSegnalazione  Impostazione (helper statico+cache)
app/Enums/SegnalazioneStato.php    # fonte di verità sugli stati, vedi sotto
app/Policies/SegnalazionePolicy.php
app/Services/
  SegnalazioneWorkflowService  WebhookService  SlaService
  DedupService     # anti-duplicato: simili per tipologia/plesso/vicinanza + embeddings
  OllamaService    # LLM locale opzionale (titolo auto, triage suggerito, embeddings)
  TelegramBotService
  Backup/     dump logico JSONL · archivio ZIP AES-256 · BackupService (crea/retention) · RipristinoService (con rollback)
  Directory/  Directory (interfaccia) · LdapRecordDirectory (AD reale) · MockDirectory · NullDirectory
  Auth/       LdapLoginService · MappaGruppiLdap · NormalizzaUsernameAd · CodiceAccessoEmail (2FA email) · SpidLoginService
  Deleghe/    DelegaService (richiesta, decisione segreteria, pre-delega, rinnovo, scadenze)
  Scuole/     AnagrafeMiur (indice open data MIUR su disco local, miur/*.json) · SincronizzaScuole (selezione admin + riallineamento, fonte_dati=miur)
  Oidc/       OidcClient (discovery/PKCE/token/id_token/userinfo) · OidcConfig · ClaimsSpid · IdentitaSpid
app/Jobs/           ScaricaAnagrafeMiur  CreaBackup  EseguiRipristino  CompletaCaricamento  CalcolaEmbeddingSegnalazione  GeneraTitoloSegnalazione  SuggerisciTriageSegnalazione
app/Http/Middleware/EnsureUserIsActive.php  LimitaAccessoSpid.php
app/Console/Commands/PopulateDemoData.php (artisan demo)  InviaDigestGestori  CheckSlaViolazioni  ProvaLdap (ldap:prova)  DelegheScadenze (deleghe:scadenze)  DelegheRinnovi (deleghe:rinnovi)  backup:crea · backup:ripristina · backup:pulisci
```

## Ruoli (Spatie)

| Ruolo | Accesso |
|---|---|
| `admin` | Totale: utenti, impostazioni, sistema |
| `gestore` | Segnalazioni. `supervisore_segnalazioni=true`→tutto; altrimenti solo assegnate |
| `operaio` | Lavori assegnati a sé o alla propria squadra (`Squadra`, caposquadra riassegna ai membri) |
| `segnalatore` | Proprie segnalazioni. Ha `id_provenienza` (scuola/URP/portale/interno) |
| `impresa` | Solo lavori propria impresa. Ditte non registrate operano via magic-link firmato (no login) |

**Accesso (v1.2, spec `docs/superpowers/specs/2026-09-30-v12-identita-accessi-design.md`)** — campo form `username`, instradato da `LoginRequest`:
- contiene `@` e non è l'UPN AD (suffisso di `LDAP_USER_DN_TEMPLATE`) → account `locale` per email (ditte) → 2FA (`User::metodoSecondoFattore()`: TOTP se attivo, altrimenti email obbligatoria per ruolo `impresa`)
- altrimenti (`m.rossi`, `m.rossi@ente.local`, `ENTE\m.rossi`) → AD via `LdapLoginService`; **transitorio fino al cutover (fase 3)**: se AD non riconosce, fallback account `locale` per username (segnalatori legacy)
- **Username per tipo di account** (regola del committente, non derogabile): dominio = sAMAccountName (`mario.rossi`), SPID = codice fiscale, ditta = email (forzato in `UtentiController` per ruolo `impresa`). I tre spazi non collidono: niente generatori `nome.cognome`.
- `users.auth_source` = `locale`·`ldap`·`spid`; `ldap_guid` chiave identità AD; `email` NON unique a DB (unicità applicativa solo tra `locale` attivi); reset password solo `locale` attivi

**Scuole via SPID/CIE** (`/auth/spid` → pa-sso-proxy): OIDC Authorization Code + PKCE, solo `client_secret_basic`, id_token verificato via JWKS (senza `kid` ok se JWKS ha una chiave) + claim persona da userinfo (`sub` deve coincidere). Identità = `users.codice_fiscale` (`TINIT-` rimosso), **mai** il `sub`. Primo accesso → "Completa profilo" (utente creato SOLO con l'email) → verifica email (`verification.*`) → `LimitaAccessoSpid`: senza delega attiva gli utenti `spid` vedono solo verifica email e "Le mie deleghe" (`scuola.deleghe.*`); visibilità segnalazioni = `Delega::plessiCopertiDa()` (scope, policy, form). Deleghe decise dalla segreteria via link firmato senza login (`deleghe/decidi|rinnovo/{token}`, solo hash SHA-256 a DB, firma legata ad `APP_URL`). `state`/`nonce`/`verifier` in sessione Laravel (monouso). Config in Admin → Impostazioni → SPID: issuer (radice, senza `/OIDC`), client id, secret **cifrato con `APP_KEY`** (cambiare `APP_KEY` = reinserire il secret); redirect URI `{APP_URL}/auth/spid/callback` mostrato in sola lettura. Logout SPID → `end_session_endpoint` del proxy.

Gruppi AD → ruolo (nomi in env `LDAP_GRUPPO_*` → `config('ldap.gruppi')`, NON in Impostazioni: servono al primo login, prima che esista un admin), precedenza in quest'ordine, un solo ruolo, ricalcolato a ogni login:

| Gruppo default | Ruolo | Note |
|---|---|---|
| `PRONTOPA_ADMIN` | `admin` | |
| `PRONTOPA_SUPERVISORI` | `gestore` | `supervisore_segnalazioni=true` |
| `PRONTOPA_GESTORI` | `gestore` | |
| `PRONTOPA_OPERAI` | `operaio` | caposquadra NON da AD: si decide sulla squadra |
| `PRONTOPA_URP` | `segnalatore` | + permesso `segnalazioni.per-conto`, provenienza 3 |
| `PRONTOPA_SEGNALATORI` | `segnalatore` | provenienza 1 |

Primo login AD: aggancio account legacy `locale` non-ditta con stessa email (se unico). Username AD già usato da altro account → login rifiutato ("contatta l'amministratore"). Diagnosi: `php artisan ldap:prova <username>` (nessuna scrittura DB): stampa la config letta e `Directory::motivoUltimoRifiuto()` (bind 49 con messaggio AD data 52e/533/775…, oppure bind ok ma utente non trovato → base DN/template). Il form di login mostra sempre il generico `auth.failed` (`lang/it/auth.php`).
**Blocco tentativi** (`LoginRequest`, `config('auth.login')`): chiave account = identità normalizzata **senza IP** (`LOGIN_MAX_TENTATIVI`=5 in `LOGIN_BLOCCO_MINUTI`=15, da tenere sotto soglia/finestra di lockout AD), chiave IP 20/60 min. Il controllo precede il bind AD; ogni blocco → `Log::warning`. IP reale da `trustProxies('*')` = solo il peer diretto (reverse proxy) è fidato.
**Gotcha LdapRecord**: `Guard::attempt()` restituisce `false` per QUALSIASI errore di bind (anche server irraggiungibile) — `LdapRecordDirectory` usa `auth()->bind()` e solo codice 49 = credenziali errate.

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
- **Nessun wizard/admin da `.env`** (rimossi in v1.2): il primo admin è chi sta nel gruppo AD `PRONTOPA_ADMIN`
- **2FA** (TOTP + recovery codes, oppure codice via email per le ditte: 6 cifre, 10 min, 5 tentativi, cache `2fa-email:{id}`): Fortify usato solo come libreria (`Fortify::ignoreRoutes()` in `App\Providers\FortifyServiceProvider::register()`) — login/registrazione/reset restano ai controller custom in `routes/auth.php`, zero rotte Fortify attive. **Va registrato a mano in `bootstrap/providers.php`** (non auto-discovered come le altre integrazioni Laravel). Self-service da profilo, dietro `password.confirm`.
- **Scan antimalware allegati** (opzionale, profilo Docker `security` + `Impostazione::get('antivirus_enabled')`): `ClamAvService` parla INSTREAM via socket raw a `clamav/clamav:stable` (no dipendenza composer), job `ScansionaAllegato` dispatchato da un unico hook su `AllegatoSegnalazione::booted()` (created event) — copre tutti i path di upload senza doverli aggiornare uno per uno. Infetto → spostato (non cancellato) su disco `quarantena`, mai servito dalla route di download. Degrada in silenzio se disattivato/clamd irraggiungibile.

## Backup e ripristino (v0.8.2)

Spec: `docs/superpowers/specs/2026-10-21-backup-ripristino-design.md`. Admin → Backup: Backup ora, Scarica, Elimina, Carica a blocchi da 512 KB (sotto il limite 1 MB dei reverse proxy) (l'ultimo blocco accoda `CompletaCaricamento` su `lunghi`: verifica+ricifratura fuori dalla richiesta, la pagina interroga lo stesso `ripristino/stato/{token}`), Ripristina (conferma digitando `RIPRISTINA`, pagina d'attesa che interroga `ripristino/stato/{token}`).

- **Formato**: dump logico in PHP (una tabella = un JSONL, snapshot coerente su MariaDB) + `storage` → ZIP AES-256 (apribile con 7-Zip data la passphrase; **nomi e dimensioni dei file NON sono cifrati**). Accanto a ogni zip un sidecar `<nome>.json`. Volume `backups` → `/var/www/html/backups` (dev: bind mount `./backups`; su host Linux `mkdir backups` prima del primo `up`, altrimenti nasce root).
- **`segreti.json`** (secret SPID ecc.) è ricifrato con l'`APP_KEY` di destinazione. Compatibilità giudicata dalle migrations del manifest, non dalla versione.
- **Ripristino** (job su coda `lunghi`): verifica+estrae TUTTO prima di toccare qualcosa → maintenance → backup `pre-ripristino` (sempre se c'è `BACKUP_PASSPHRASE`; saltato solo su installazione vuota — 0 segnalazioni e ≤1 utente — senza passphrase locale, dove basta quella digitata) → drop tabelle → migrations del manifest → import → storage → `migrate` → `AllineamentoSeeder` → segreti → `ripristino_id` (invalida tutte le sessioni) → `up`. Errore → rollback automatico dal `pre-ripristino`.
- **Env** (`BACKUP_PASSPHRASE` e `BACKUP_MAX_UPLOAD_MB` anche in `x-php-env`): `BACKUP_PASSPHRASE` (senza, nessun backup; dev e prod devono averla uguale per migrare, oppure si digita quella del file in upload/ripristino) · `BACKUP_MAX_UPLOAD_MB` (10240) · `BACKUP_QUEUE_CONNECTION` è solo un default interno di config (`redis-lunghi`, `sync` forzato in phpunit): non passata dal compose, non impostarla in prod.
- **Notturno 02:00**, retention: sempre l'ultimo; tutti quelli degli ultimi 7 giorni; il più recente per settimana ISO fino a 30 giorni; l'ultimo `pre-ripristino` resta finché non c'è un notturno successivo verificato. Allarme in dashboard admin e pagina Backup se l'ultimo (esclusi i `caricato`) è fallito o più vecchio di 36 h; fallimento → Sentry + email agli admin attivi.
- **Temporanei**: `backups/tmp/` (cartelle 0700: dump/estrazioni/ricifrature in chiaro) e `backups/stato/` (stati dei job, marcatore `backup-in-corso.json` per la pagina) → `CatalogoBackup::pulisciTemporanei()` solo per età (>3 h, oltre ogni timeout/TTL lock; stati >30 gg), a ogni retention e upload. Niente controllo del lock: prenderlo farebbe rinunciare un notturno concorrente.
- **Comandi**: `backup:crea --tipo=` · `backup:ripristina {file} --passphrase=` · `backup:pulisci` (dev/CI, in prod non serve).
- **Gotcha**: il worker `queue-lunghi` (`--force`, `retry_after` 7300) è obbligatorio: senza, backup/ripristini da UI restano in coda per sempre. `--force` serve a recuperare un ripristino interrotto da un worker ucciso (l'app torna su).

## Database

Migrations `database/migrations/` (27 file, naming datato `YYYY_MM_DD_......`), schema base da `legacy/export.sql`, evoluto per release additiva (v0.5→v1.0). Non fare affidamento sui nomi legacy `000000..000005`, ormai storici.

Seeders (`DatabaseSeeder`): `AllineamentoSeeder` (= `TabelleRiferimentoSeeder` · `RolesAndPermissionsSeeder` · `ImpostazioniSeeder`, gira a ogni avvio) · `IstitutiPlessiSeeder` (vuoto by design, no dati demo in prod — usa `artisan demo`) · `AdminUserSeeder` (dev/CI, marca `setup_completato`). **Tutto ciò che sta in `AllineamentoSeeder` deve essere idempotente e non toccare mai dati configurati né ruoli degli utenti** (bug 0.8.1: permesso `segnalazioni.per-conto` mai arrivato in prod perché il seed ruoli girava solo con `users` vuota → 500 a ogni login AD). Nuovo ruolo/permesso/riga di riferimento → basta aggiungerlo al seeder, arriva al prossimo avvio. Mai far fare all'admin interventi da console.

Parametri password: sempre `#[\SensitiveParameter]` (con backslash, siamo in un namespace) — altrimenti gli stacktrace in `laravel.log`/Sentry li scrivono in chiaro. Coperto da `tests/Unit/PasswordNonNeiLogTest.php`: nuovo metodo con password → aggiungilo lì.

Import prod: `docker compose exec -T mariadb mariadb -u segnalazioni -p segnalazioni < legacy/export.sql`

## API

Spec completa: [`docs/API.md`](docs/API.md) · [`docs/openapi.yaml`](docs/openapi.yaml) (OpenAPI 3.1, importabile Swagger/Postman).

```
POST /api/segnalazioni              # crea da sito Comune (Sanctum), 409 se simili aperte (anti-dup)
GET  /api/segnalazioni/{id}/stato   # legge stato
```

Webhook outbound: HTTP POST HMAC-firmato al cambio stato → Admin → Impostazioni → Webhook.

## Test E2E (Dusk)

`tests/Browser/` (login, login AD mock, creazione segnalazione, cambio stato) gira solo in CI (con `LDAP_HOST=mock`) (`.github/workflows/dusk.yml`, `ubuntu-latest` ha Chrome già installato) — **non nell'immagine dev**, Alpine/musl non è compatibile col chromedriver glibc di Dusk (serve `gcompat`, non vale la pena). Se serve debuggare un test Dusk localmente, usa un ambiente Linux glibc (o WSL), non il container `php`.

## CI/CD

Tag `v*.*.*` → `.github/workflows/release.yml` → build **amd64 only** (arm64 droppato, niente QEMU) → push GHCR `:X.Y.Z`+`:X.Y`+`:latest` (**senza `v`**; `APP_VERSION=vX.Y.Z` cotta nell'immagine).

**Rebuild settimanale** (lunedì 04:30 UTC, o dispatch `rebuild_latest`): rebuild di `main` con base/patch Alpine fresche (`APK_REFRESH` = settimana ISO invalida il layer `apk upgrade`) → push `:latest-YYYYMMDD` → Trivy **bloccante** → solo se passa `latest` promosso. `:X.Y.Z`/`:X.Y` mai toccati; saltato se nella settimana è uscito un tag. `APP_VERSION` = ultimo tag, `APP_BUILD_TYPE=auto` (tag Sentry `build_type`/`commit`/`build_date`). Le patch arrivano in prod solo con `IMAGE_TAG=latest` (default).

**Rilascio**: bump `publiccode.yml` `softwareVersion` + CHANGELOG `## [X.Y.Z] - data` **nella stessa PR della modifica** (preferenza committente, niente PR di rilascio separata) → `main` protetto: PR squash con CI verde (`test`+`dusk`+`publiccode.yml validation`) → `git switch main && git pull` → `git tag -a vX.Y.Z -m "..." && git push origin vX.Y.Z` → `gh run watch <id> --exit-status` sul workflow release.

```bash
git tag v1.2.0 && git push origin v1.2.0
```

**Dependabot**: composer, npm, actions, docker (Dockerfile prod+dev), docker-compose; minor+patch raggruppate (`minor-patch`) → `dependabot-automerge.yml` abilita auto-merge squash a CI verde e aggiorna le PR behind una alla volta. Major e immagini php/mariadb/redis: manuali. Serve la GitHub App `comune-montesilvano-bot` (stessa di GovPay; l'installazione su ProntoPA la aggiunge solo un owner dell'org) con secret `BOT_CLIENT_ID`/`BOT_PRIVATE_KEY` salvati **sia Actions sia Dependabot** (permessi Contents/PR/Workflows RW); senza secret il workflow non fa nulla. Altri workflow: `semgrep.yml` (PR diff-aware bloccante, scan completo settimanale), `trivy-version-check.yml` (issue quando esce un Trivy più nuovo di `v0.75.0` pinnato in tests/release).

PR dependabot con conflitto `composer.lock`/`package-lock.json` (tipico se ne mergi più di una in sequenza) → commenta `@dependabot rebase`, aspetta, ricontrolla `gh pr checks`. Se lento/bloccato, applicare il bump a mano (`composer require pkg:^X` o `npm install pkg@X`) è più veloce che aspettare — poi chiudi la PR come superata (`gh pr close N --comment "..." --delete-branch`).
**Tante PR Dependabot insieme** → un'unica PR: cherry-pick dei commit actions/npm (file distinti, niente conflitti); per composer rigenera il lock con `composer update <pkg...> -w --with symfony/<x>:^7.4 ... --with guzzlehttp/guzzle:^7 --no-install` su `php:8.4-cli` (l'immagine `composer:2` è PHP 8.5) per non tirare major non richiesti. `Closes #N` nel body NON chiude le PR: chiuderle con `gh pr close N --comment "Consolidata in #M" --delete-branch`.
**Gotcha peer-dep**: `vite` e `laravel-vite-plugin` sono accoppiati (`laravel-vite-plugin` fissa la major di vite richiesta) — dependabot le propone come PR separate ma vanno bumpate insieme o falliscono con `ERESOLVE`.

**Baseline sicurezza (dal 2026-09-07)**: `tests.yml` scansiona con Trivy (`scan-type: fs`) le dipendenze composer/npm ad ogni push/PR, **bloccante** su CRITICAL/HIGH (`.trivyignore` a root per i falsi positivi verificati); `release.yml` scansiona entrambe le immagini pubblicate (app+web), **report-only**, risultati su tab Security via SARIF. Tutte le Action nei 4 workflow pinnate per commit SHA (`# vX` a commento). `dependabot.yml` ha `cooldown` (7gg default, 14gg sui major) su tutti e 3 gli ecosistemi. `main` è protetto (anche per gli admin): required check `test`+`dusk`+`semgrep`, strict (branch aggiornato), no force-push, no delete branch.

**`aquasecurity/trivy-action` — il binario Trivy pinnato di default da certe release dell'action può non esistere/non installare** (visto dal vivo: v0.34.0 dell'action prova a scaricare Trivy `0.69.1`, fallisce silenziosamente con solo "Process completed with exit code 1", nessun dettaglio). Fix: `version: latest` esplicito nell'input dello step — stesso gotcha già preso una volta su ComunicaPA.

**Gotcha `release.yml` bake/trivy (risolti 2026-09-08, 4 bug in cascata su `docker/metadata-action@v6`+`bake-action`+trivy+codeql, ognuno emerso solo dopo il fix del precedente — verificabili solo su un vero tag push, non in locale)**:
- `docker/metadata-action@v6` non setta più l'output combinato `bake-file` (rimosso, nonostante il README lo elenchi ancora) — usare `bake-file-tags`+`bake-file-labels`.
- `docker/bake-action` risolve quei path solo se prefissati `cwd://` (`cwd://${{ steps.meta.outputs.bake-file-tags }}`), altrimenti "no such file or directory" anche se il file esiste.
- Il tag pushato da bake è quello risolto da metadata-action (`type=semver,pattern={{version}}` strippa la "v": `0.6.3`, non `v0.6.3`) — uno step successivo che referenzia il ref git grezzo (`v0.6.3`, es. per lo scan Trivy) punta a un tag inesistente (`MANIFEST_UNKNOWN`). Usare `steps.meta-*.outputs.version`.
- `upload-sarif` con più file (app+web) sotto la stessa `category` di default viene rifiutato da CodeQL dal 2025-07-21 ("multiple SARIF runs with the same category") — uno step `upload-sarif` per file, `category` esplicita e distinta.

**Rilanciare la build su un tag già esistente**: spostare il tag (`git tag -f vX.Y.Z <commit> && git push origin vX.Y.Z --force`) ritriggera `release.yml` — utile per iterare su fix del workflow senza bumpare versione. Force-push su tag già pubblicato è distruttivo (sovrascrive ref remoto, immagini GHCR già pushate restano finché non arriva il prossimo push): conferma con l'utente prima.

**gh CLI come fallback quando l'MCP GitHub è down**: `gh run list`/`gh run view --log-failed`/`gh pr create`/`gh pr checks --watch`/`gh pr merge` coprono l'intero giro PR senza bisogno dell'MCP.

**Gotcha ambiente Windows**: la Bash tool (MSYS) può crashare a metà sessione con `fatal error - add_item (...) failed, errno 1` su comandi altrimenti innocui (git, gh log a file) — non è un errore del comando, è l'MSYS runtime. Fix: retry con PowerShell tool sullo stesso comando, poi si può tornare a Bash (era transiente qui).

## Convenzioni

- Controller: Resource Controllers, autorizzazione via Policy (no ruoli nel controller)
- Models: Eloquent+relazioni esplicite, `scopeVisibileA(User $user)`, cast date+bool
- Views: layout `layouts/app.blade.php`, componenti `components/`, sezioni `gestione/ segnalatore/ imprese/ admin/`
- Email: Laravel Notifications → Mailpit dev, SMTP/PEC prod

## Test

CSRF/throttle non auto-bypassati nei Feature test nonostante `APP_ENV=testing` (`app()->runningUnitTests()` risulta `false` qui). Sui POST a rotte `web`: `$this->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class, \Illuminate\Routing\Middleware\ThrottleRequests::class])`.

Causa trovata (v1.2): nel container dev `CACHE_STORE`/`SESSION_DRIVER`/`QUEUE_CONNECTION`/`MAIL_MAILER` arrivano dall'env del compose (redis/smtp) e Laravel legge `$_SERVER` prima di `$_ENV` — un `<env>` senza `force` non li sovrascrive, e i test scrivevano nella cache Redis del dev (test flaky + impostazioni dev inquinate). `phpunit.xml` ora li forza con `<env … force="true"/>` **e** `<server … force="true"/>` (idem `LDAP_HOST` vuoto). Nuova variabile che il compose passa al container e che i test devono controllare → stessa coppia env+server forzata.

Test SPID: `tests/Support/FakeOidcProvider` simula pa-sso-proxy con `Http::fake` e chiave RSA vera (discovery, JWKS con/senza `kid`, token solo `client_secret_basic`, userinfo); `configura()` scrive le impostazioni OIDC, `nonce` va impostato dopo `GET /auth/spid` (`session('spid.nonce')`). Test AD: `tests/Support/FakeDirectory`. `APP_ENV` resta non forzato.

## Deploy Prod (Portainer/Podman rootless)

1. `git push tag` → Actions builda GHCR
2. Portainer stack → `docker-compose.yml`, env vars (APP_KEY, DB_PASSWORD, `LDAP_HOST` `LDAP_BASE_DN` `LDAP_USER_DN_TEMPLATE`…). **Mai `LDAP_HOST=mock`**
3. L'entrypoint a OGNI avvio esegue migrate + `AllineamentoSeeder` (dati di riferimento, ruoli/permessi, impostazioni nuove; mai valori configurati né ruoli utenti; nessun utente creato)
4. Crea in AD i gruppi `PRONTOPA_*`, verifica con `php artisan ldap:prova <utente>`, entra con un utente di `PRONTOPA_ADMIN` → Admin → Impostazioni per configurare ente

Rootless: no bind mount, named volumes `mariadb_data` `redis_data` `app_storage` → `/var/www/html/storage`

Servizi `queue` (`queue:work`), `queue-lunghi` (connessione `redis-lunghi`, coda `lunghi`: backup e ripristini) e `scheduler` (loop `schedule:run` ogni 60s) obbligatori: senza, webhook/job AI/backup restano in coda per sempre e i comandi schedulati (`sla:check`, `digest:invia`, `deleghe:scadenze`, `deleghe:rinnovi`, verifica annuale, backup notturno) non partono mai. Stessa immagine di `php`, `entrypoint: []` (niente migrate/seed duplicato). `queue-lunghi` compare da solo con "Update the stack" (lo stack si legge da GitHub), nessun passo manuale.

**Password MariaDB** (v0.8.2): `DB_PASSWORD` obbligatoria (`:?`, stesso valore per `php` e `mariadb`), nessun default `segnalazioni_secret`/`root_secret`. Le password valgono SOLO alla creazione del volume `mariadb_data`: cambiarle nello stack dopo non ha effetto. `DB_ROOT_PASSWORD` è ignorata su volume esistente ma su un volume NUOVO mariadb non si inizializza senza → nuove installazioni: impostarla. Se il DB rifiuta l'accesso l'entrypoint, dopo ~30 s, stampa l'errore PDO reale con suggerimento "Access denied".
**Reset password DB senza shell host** (emergenza, non operatività ordinaria): ferma `mariadb` dello stack → da Portainer crea un container temporaneo `mariadb:11.4` con command `--skip-grant-tables` sullo stesso volume `mariadb_data` → dalla sua console Portainer: `FLUSH PRIVILEGES; ALTER USER 'segnalazioni'@'%' IDENTIFIED BY '<nuova>';` → rimuovi il container temporaneo → imposta la stessa `<nuova>` in `DB_PASSWORD` e riavvia lo stack.
**Disaster recovery**: ripristina su stack nuovo con stessa `APP_KEY`, `APP_URL`, `BACKUP_PASSPHRASE`. Con `APP_KEY`/`APP_URL` diversi i link firmati già inviati (magic-link ditte, deleghe, verifica annuale) smettono di funzionare e le impostazioni legate all'host (es. `ente_logo_url`) vanno ricontrollate; il secret SPID è ricifrato in automatico.

Error tracking: `sentry/sentry-laravel` (compatibile Glitchtip self-hosted, stesso protocollo). `SENTRY_LARAVEL_DSN` vuoto in `.env` = disattivato, nessuna configurazione aggiuntiva richiesta. `release`/`environment` derivati da `APP_VERSION`/`APP_ENV`.
