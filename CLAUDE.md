# ProntoPA

Sistema open-source di pronto intervento e manutenzione per la Pubblica Amministrazione.

Permette a scuole, uffici comunali, URP e cittadini di segnalare problemi di manutenzione
(guasti elettrici, idraulici, strutturali, verde pubblico, strade, ecc.) e agli operatori comunali
di assegnarli ad imprese esterne o operatori interni, tracciando l'intero workflow fino alla chiusura.

Ogni istanza è **brandizzabile**: nome ente, logo, colori e altri parametri si configurano
dall'interfaccia admin (tabella `impostazioni`), non dal file `.env`.

---

## Stack

| Layer | Tecnologia |
|---|---|
| Framework | Laravel 11 |
| PHP | 8.3 (PHP-FPM) |
| Web Server | Nginx |
| Database | MariaDB 10.11 |
| Cache/Sessioni | Redis |
| Frontend | Blade + Tailwind CSS 3 + Alpine.js |
| Grafici | Chart.js |
| Mappe | Leaflet.js + OpenStreetMap/Nominatim |
| Auth | Laravel Breeze + Spatie Laravel Permission v6 |
| API | Laravel Sanctum (integrazione sito web Comune) |
| Container | Docker + docker-compose |
| Registry | GHCR (`ghcr.io/comune-di-montesilvano/pronto-pa`) |
| CI/CD | GitHub Actions — tag `v*.*.*` → build + publish |

---

## Setup Sviluppo

### Prerequisiti
- Docker Desktop (Windows/Mac) o Docker Engine (Linux)
- Git

### Avvio (sviluppo con bind mount)

`docker-compose.yml` è il file di sviluppo locale (bind mount, build locale, Adminer, Mailpit).

```bash
git clone <repo>
cd prontoPA

cp .env.example .env

# Su Windows con Git Bash — evita la traduzione dei path MSYS2
MSYS_NO_PATHCONV=1 docker compose up -d

MSYS_NO_PATHCONV=1 docker compose exec php php artisan key:generate
MSYS_NO_PATHCONV=1 docker compose exec php php artisan migrate --seed
MSYS_NO_PATHCONV=1 docker compose exec php npm run build
```

L'app sarà disponibile su **http://localhost**.
Adminer (DB admin) su **http://localhost:8081** (profilo `dev`).
Mailpit (email dev) su **http://localhost:8025** (profilo `dev`).

Per attivare i profili dev:
```bash
docker compose --profile dev up -d
```

### Avvio produzione (no bind mounts — Portainer / rootless Podman)

In produzione si usa `docker-compose.prod.yml` (GHCR images, named volumes):

```bash
docker compose -f docker-compose.prod.yml up -d
```

L'immagine deve essere pre-buildata e pubblicata su GHCR. Il codice è copiato dentro
l'immagine al momento del build (nessun bind mount).

### Comandi utili

```bash
# Avvia tutti i container (sviluppo)
docker compose up -d

# Ferma i container
docker compose down

# Log in tempo reale
docker compose logs -f php

# Accedi alla shell PHP
docker compose exec php sh

# Esegui artisan dall'host
docker compose exec php php artisan <comando>

# Esegui composer dall'host
docker compose exec php composer <comando>

# Rigenera assets frontend
docker compose exec php npm run build
```

---

## Variabili d'Ambiente (.env)

Copia `.env.example` in `.env` e modifica solo i valori sistemistici (porte, password DB, chiavi).
Le impostazioni applicative (nome ente, logo, colori, coordinate mappa) si configurano
dall'interfaccia admin in **Admin → Impostazioni**.

```env
APP_URL=http://localhost
APP_KEY=            # generato con: php artisan key:generate

DB_HOST=mariadb
DB_PORT=3306
DB_DATABASE=segnalazioni
DB_USERNAME=segnalazioni
DB_PASSWORD=<password>
DB_ROOT_PASSWORD=<root_password>

REDIS_HOST=redis

MAIL_MAILER=smtp
MAIL_HOST=mailpit          # mailpit in dev, SMTP reale in prod
MAIL_PORT=1025

# PEC per notifiche ufficiali (prod)
PEC_HOST=mbox.cert.legalmail.it
PEC_USERNAME=
PEC_PASSWORD=

# Webhook outbound per sito web Comune (configurabile anche da admin UI)
WEBHOOK_CITTADINI_URL=
WEBHOOK_CITTADINI_SECRET=
```

---

## Impostazioni Applicative (Brandizzazione)

Le impostazioni brandizzabili sono nella tabella `impostazioni` del database.
Accedibili da **Admin → Impostazioni** (solo ruolo `admin`).

| Chiave | Gruppo | Descrizione |
|---|---|---|
| `ente_nome` | brand | Nome dell'ente (es. "Comune di Montesilvano") |
| `ente_logo_url` | brand | URL/path del logo |
| `ente_colore_primario` | brand | Colore primario UI (hex) |
| `ente_colore_secondario` | brand | Colore secondario UI (hex) |
| `ente_sito_url` | brand | URL sito web ente |
| `osm_lat` | mappa | Latitudine centro mappa default |
| `osm_lng` | mappa | Longitudine centro mappa default |
| `osm_zoom` | mappa | Zoom default mappa |
| `mail_from_address` | email | Indirizzo mittente email |
| `mail_from_name` | email | Nome mittente email |

Nel codice:
```php
use App\Models\Impostazione;

$nomeEnte = Impostazione::get('ente_nome', 'ProntoPA');
```

---

## Versione App

La versione è iniettata come variabile d'ambiente `APP_VERSION` al momento del build Docker.
Disponibile come `config('app.version')` e mostrata nel footer dell'interfaccia.

In sviluppo locale il valore sarà `dev`.

---

## Architettura

### Directory Laravel (dentro `app/`)

```
app/
├── Http/Controllers/
│   ├── Auth/               # Login, password recovery (Breeze)
│   ├── GestioneController  # Dashboard gestori
│   ├── SegnalazioneController
│   ├── SegnalatoreDashboardController
│   ├── ImpresaController   # Dashboard imprese
│   ├── ImpreseCRUDController
│   ├── AppaltiController
│   ├── StatisticheController
│   ├── Admin/
│   │   ├── AdminController     # Gestione utenti
│   │   └── ImpostazioniController  # Brandizzazione ente
│   └── Api/
│       └── SegnalazioneApiController  # Endpoint per sito Comune
├── Models/
│   ├── Segnalazione.php
│   ├── User.php
│   ├── Impresa.php
│   ├── Appalto.php
│   ├── NotaSegnalazione.php
│   ├── StatoSegnalazione.php
│   ├── Istituto.php
│   ├── Plesso.php
│   ├── Provenienza.php
│   ├── Impostazione.php    # Brandizzazione — helper statico + cache
│   └── ...
├── Policies/
│   └── SegnalazionePolicy.php
├── Services/
│   ├── SegnalazioneWorkflowService.php  # State machine
│   ├── NotificaEmailService.php
│   └── WebhookService.php
└── Http/Middleware/
    └── RoleRedirect.php    # Redirect post-login per ruolo
```

### Ruoli Utente (Spatie Permission)

| Ruolo | Accesso |
|---|---|
| `admin` | Accesso totale — gestione utenti, impostazioni ente, config sistema |
| `gestore` | Gestione segnalazioni. Se `supervisore=true` vede tutto; altrimenti solo quelle assegnate a lui |
| `segnalatore` | Inserimento e visualizzazione proprie segnalazioni. Ha una `provenienza` (scuola, URP, portale, interno) |
| `impresa` | Solo visualizzazione dei lavori assegnati alla propria impresa |

---

## Workflow Segnalazioni

### Stati (14)

| ID | Nome | Colore |
|---|---|---|
| 1 | In attesa di esame | Grigio |
| 2 | In carico | Blu |
| 3 | In gestione | Blu |
| 4 | In valutazione economica | Giallo |
| 5 | Assegnata ad impresa | Azzurro |
| 6 | Accertamento tecnico | Giallo |
| 7 | Proposta chiusura | Verde chiaro |
| 8 | Completata | Verde |
| 9 | Annullata | Rosso |
| 10 | Archiviata (non finanziata) | Grigio scuro |
| 11 | Stimata | Arancio |
| 12 | In appalto | Azzurro |

### Azioni disponibili (11)
- Assegna ad impresa (con appalto)
- Assegna ad operatore
- Chiudi
- Invia preventivo
- Pianifica intervento
- Proponi chiusura
- Accetta preventivo
- Archivia (non finanziata)
- Richiedi accertamento
- Richiedi valutazione tecnico-economica
- Riapri

La logica delle transizioni è in `app/Services/SegnalazioneWorkflowService.php`.

---

## Database

### Migrations
Le migrations si trovano in `database/migrations/` — derivate dallo schema `legacy/export.sql`.

| File | Contenuto |
|---|---|
| `000000_create_users_table.php` | Users + campi custom (username, ruoli boolean, provenienza) |
| `000001_create_reference_tables.php` | Tabelle di riferimento: stati, azioni, tipologie, gruppi, provenienze |
| `000002_create_imprese_tables.php` | Imprese, specializzazioni, appalti |
| `000003_create_segnalazioni_table.php` | Segnalazioni, note, storico stati |
| `000004_create_webhook_logs_table.php` | Log API/webhook |
| `000005_create_impostazioni_table.php` | Impostazioni brandizzazione |

### Seeders
- `DatabaseSeeder` → lancia tutti i seeder
- `TabelleRiferimentoSeeder` → stati, azioni, tipologie, gruppi, provenienze, specializzazioni, parametri
- `IstitutiPlessiSeeder` → istituti, plessi, profili, imprese di esempio
- `ImpostazioniSeeder` → valori default impostazioni brandizzazione
- `RolesAndPermissionsSeeder` → ruoli Spatie (da abilitare dopo setup Breeze)

### Import dati produzione
```bash
docker compose exec -T mariadb mysql -u segnalazioni -p segnalazioni < legacy/export.sql
```

---

## API (Integrazione Sito Web Comune)

Il sistema espone endpoint REST protetti da **Laravel Sanctum** per l'integrazione col gestionale
segnalazioni del sito web del Comune (segnalazioni dei cittadini).

```
POST   /api/segnalazioni            → Crea segnalazione dal sito Comune
GET    /api/segnalazioni/{id}/stato → Legge stato corrente
```

**Webhook outbound**: al cambio di stato, il sistema notifica il sito Comune via HTTP POST
con payload JSON firmato HMAC (configurabile in Admin → Impostazioni → Webhook).

---

## CI/CD

Il workflow `.github/workflows/release.yml` si attiva al push di un tag `v*.*.*`:
1. Build immagine multi-arch (`linux/amd64`, `linux/arm64`)
2. Push su `ghcr.io/comune-di-montesilvano/pronto-pa:<tag>` e `:latest`
3. La versione è iniettata come build arg `APP_VERSION=<tag>`

```bash
# Rilasciare una nuova versione
git tag v1.2.0
git push origin v1.2.0
```

---

## Convenzioni

### Controller
- Un controller per modulo principale
- Usa Laravel Resource Controllers (`index`, `create`, `store`, `show`, `edit`, `update`, `destroy`)
- Autorizzazione tramite Policy — NON fare controlli ruolo direttamente nel controller

### Models
- Usa Eloquent con relazioni esplicite
- Scopes per filtrare per ruolo: `scopeVisibileA(User $user)`
- Cast per date e boolean

### Views (Blade)
- Layout base in `resources/views/layouts/app.blade.php`
- Componenti in `resources/views/components/`
- Sezioni per ruolo: `gestione/`, `segnalatore/`, `imprese/`, `admin/`

### Notifiche email
- Usa Laravel Notifications (`php artisan make:notification`)
- In dev: catturate da **Mailpit** su localhost:8025
- In prod: configurare SMTP/PEC in `.env`

---

## Deployment Produzione (Portainer / rootless Podman)

1. Pusha un tag Git → GitHub Actions builda e pubblica l'immagine su GHCR
2. In Portainer: crea uno stack con `docker-compose.yml` (senza override)
3. Imposta le variabili d'ambiente nello stack (APP_KEY, DB_PASSWORD, ecc.)
4. Esegui le migrations dopo il primo deploy:
   ```bash
   docker compose exec php php artisan migrate --seed
   ```
5. Accedi come admin e configura l'ente in **Admin → Impostazioni**

### Note rootless Podman
- Nessun bind mount — tutto il codice è dentro l'immagine (`COPY` nel Dockerfile)
- Named volumes per dati persistenti: `mariadb_data`, `redis_data`, `app_storage`
- `app_storage` è montato in `/var/www/html/storage` per log, file caricati, cache
