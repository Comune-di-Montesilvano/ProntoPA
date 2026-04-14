# ProntoPA

Open-source manutenzione PA. Scuole/comuni/URP segnalano guasti, gestori assegnano ad imprese/operatori, traccia workflow chiusura. Brandizzabile via tabella `impostazioni` (non `.env`).

## Convenzioni Claude Code

- Commit sempre con `/commit` (skill caveman-commit)

## Stack

Laravel 11 · PHP 8.3-FPM · Nginx · MariaDB 10.11 · Redis · Blade+Tailwind3+Alpine.js · Chart.js · Leaflet+OSM · Breeze+Spatie Permission v6 · Sanctum · Docker+compose · GHCR · GitHub Actions

## Setup Dev

```bash
cp .env.example .env
MSYS_NO_PATHCONV=1 docker compose up -d
MSYS_NO_PATHCONV=1 docker compose exec php php artisan key:generate
MSYS_NO_PATHCONV=1 docker compose exec php php artisan migrate --seed
MSYS_NO_PATHCONV=1 docker compose exec php npm run build
```

- App: http://localhost | Adminer: :8081 | Mailpit: :8025 (profilo `dev`)
- Dev profili: `docker compose --profile dev up -d`
- `docker-compose.yml` = **prod** (GHCR images, named volumes, no bind mount)
- `docker-compose.override.yml` = **dev** (caricato auto, bind mount + Adminer + Mailpit)

### Comandi utili
```bash
docker compose up -d / down
docker compose logs -f php
docker compose exec php sh
docker compose exec php php artisan <cmd>
docker compose exec php composer <cmd>
docker compose exec php npm run build
```

## .env (solo sistemistico)

```env
APP_URL=http://localhost
APP_KEY=              # php artisan key:generate
DB_HOST=mariadb  DB_PORT=3306  DB_DATABASE=segnalazioni
DB_USERNAME=segnalazioni  DB_PASSWORD=  DB_ROOT_PASSWORD=
REDIS_HOST=redis
MAIL_MAILER=smtp  MAIL_HOST=mailpit  MAIL_PORT=1025
PEC_HOST=mbox.cert.legalmail.it  PEC_USERNAME=  PEC_PASSWORD=
WEBHOOK_CITTADINI_URL=  WEBHOOK_CITTADINI_SECRET=
```

Impostazioni app (brand, mappa, email) → **Admin → Impostazioni**.

## Brandizzazione (`impostazioni`)

| Chiave | Gruppo |
|---|---|
| `ente_nome` `ente_logo_url` `ente_colore_primario` `ente_colore_secondario` `ente_sito_url` | brand |
| `osm_lat` `osm_lng` `osm_zoom` | mappa |
| `mail_from_address` `mail_from_name` | email |

```php
$val = Impostazione::get('ente_nome', 'ProntoPA');
```

`APP_VERSION` iniettato al build Docker → `config('app.version')`. Dev = `dev`.

## Architettura

```
app/Http/Controllers/
  Auth/  GestioneController  SegnalazioneController  SegnalatoreDashboardController
  ImpresaController  ImpreseCRUDController  AppaltiController  StatisticheController
  Admin/{AdminController, ImpostazioniController}
  Api/SegnalazioneApiController
app/Models/
  Segnalazione  User  Impresa  Appalto  NotaSegnalazione  StatoSegnalazione
  Istituto  Plesso  Provenienza  Impostazione (helper statico+cache)
app/Policies/SegnalazionePolicy.php
app/Services/SegnalazioneWorkflowService.php  NotificaEmailService  WebhookService
app/Http/Middleware/RoleRedirect.php
```

## Ruoli (Spatie)

| Ruolo | Accesso |
|---|---|
| `admin` | Totale: utenti, impostazioni, sistema |
| `gestore` | Segnalazioni. `supervisore=true` → tutto; altrimenti solo assegnate |
| `segnalatore` | Proprie segnalazioni. Ha `provenienza` (scuola/URP/portale/interno) |
| `impresa` | Solo lavori propria impresa |

## Workflow Stati

1=Attesa esame 2=In carico 3=In gestione 4=Val.economica 5=Ass.impresa 6=Acc.tecnico 7=Prop.chiusura 8=Completata 9=Annullata 10=Archiviata 11=Stimata 12=In appalto

Azioni: assegna impresa/operatore · chiudi · invia/accetta preventivo · pianifica · proponi chiusura · archivia · richiedi accertamento/valutazione · riapri

Logica transizioni: `app/Services/SegnalazioneWorkflowService.php`

## Database

Migrations `database/migrations/` da `legacy/export.sql`:
- `000000` users · `000001` reference tables · `000002` imprese · `000003` segnalazioni · `000004` webhook_logs · `000005` impostazioni

Seeders: `TabelleRiferimentoSeeder` · `IstitutiPlessiSeeder` · `ImpostazioniSeeder` · `RolesAndPermissionsSeeder`

Import prod: `docker compose exec -T mariadb mysql -u segnalazioni -p segnalazioni < legacy/export.sql`

## API

```
POST /api/segnalazioni              # crea da sito Comune (Sanctum)
GET  /api/segnalazioni/{id}/stato   # legge stato
```
Webhook outbound: HTTP POST HMAC-firmato al cambio stato → config Admin → Impostazioni → Webhook.

## CI/CD

Tag `v*.*.*` → `.github/workflows/release.yml` → build multi-arch (amd64+arm64) → push GHCR `:tag` + `:latest` con `APP_VERSION=<tag>`.

```bash
git tag v1.2.0 && git push origin v1.2.0
```

## Convenzioni

- Controller: Resource Controllers, autorizzazione via Policy (no ruoli nel controller)
- Models: Eloquent+relazioni esplicite, `scopeVisibileA(User $user)`, cast date+bool
- Views: layout `layouts/app.blade.php`, componenti `components/`, sezioni `gestione/ segnalatore/ imprese/ admin/`
- Email: Laravel Notifications → Mailpit dev, SMTP/PEC prod

## Deploy Prod (Portainer/Podman rootless)

1. `git push tag` → Actions builda GHCR
2. Portainer stack punta a `docker-compose.yml` (prod di default), env vars (APP_KEY, DB_PASSWORD…)
3. `docker compose exec php php artisan migrate --seed`
4. Admin → Impostazioni → configura ente

Rootless: no bind mount (codice in immagine), named volumes `mariadb_data` `redis_data` `app_storage` → `/var/www/html/storage`
