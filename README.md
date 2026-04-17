# ProntoPA

**Sistema open-source di pronto intervento e manutenzione per la Pubblica Amministrazione**

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](https://www.gnu.org/licenses/agpl-3.0)
[![PHP](https://img.shields.io/badge/PHP-8.3-purple.svg)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-11-red.svg)](https://laravel.com)
[![Docker](https://img.shields.io/badge/Docker-ready-blue.svg)](https://docker.com)

ProntoPA permette a scuole, uffici comunali, URP e cittadini di segnalare problemi di manutenzione
(guasti elettrici, idraulici, strutturali, verde pubblico, strade, ecc.) e agli operatori comunali
di assegnarli ad imprese esterne o operatori interni, tracciando l'intero workflow fino alla chiusura.

Ogni istanza è **completamente brandizzabile** dall'interfaccia di amministrazione:
nessuna modifica al codice richiesta per adattarlo al proprio ente.

---

## Funzionalità principali

- **Gestione segnalazioni** con workflow a 14 stati e 11 azioni
- **Ruoli utente**: admin, gestore (supervisore/non), segnalatore, impresa
- **Segnalatori agnostici**: ogni utente ha una provenienza configurabile (scuola, URP, portale cittadino, ufficio interno…)
- **Geolocalizzazione** con Leaflet.js + OpenStreetMap
- **Notifiche email** al cambio di stato (SMTP / PEC)
- **API REST + webhook** per integrazione con portali cittadini esterni (Laravel Sanctum)
- **Brandizzabile**: nome ente, logo, colori, coordinate mappa configurabili da admin UI
- **Versionato**: tag Git → immagine Docker pubblicata su GHCR via GitHub Actions

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
| Container | Docker + docker-compose |

---

## Quick Start (sviluppo locale)

### Prerequisiti

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (Windows/Mac) o Docker Engine + Compose Plugin (Linux)
- Git

### Avvio

```bash
git clone https://github.com/Comune-di-Montesilvano/prontoPA.git
cd prontoPA

cp .env.example .env

# Su Windows con Git Bash — necessario per evitare la traduzione dei path MSYS2
MSYS_NO_PATHCONV=1 docker compose up -d

MSYS_NO_PATHCONV=1 docker compose exec php php artisan key:generate
MSYS_NO_PATHCONV=1 docker compose exec php php artisan migrate --seed
MSYS_NO_PATHCONV=1 docker compose exec php npm run build
```

> `docker-compose.yml` è il file di **produzione** (immagini GHCR, named volumes, no bind mount).
> `docker-compose.override.yml` viene caricato automaticamente in sviluppo (bind mount, build locale, Adminer, Mailpit).

L'app è disponibile su **http://localhost**.

### Tool sviluppo (opzionali)

```bash
# Avvia con Adminer (http://localhost:8081) e Mailpit (http://localhost:8025)
docker compose --profile dev up -d
```

### Comandi utili

```bash
# Shell del container PHP
docker compose exec php sh

# Artisan
docker compose exec php php artisan <comando>

# Composer
docker compose exec php composer <comando>

# Build assets (Vite)
docker compose exec php npm run build

# Watch assets in sviluppo
docker compose exec php npm run dev
```

---

## Configurazione

### Variabili d'ambiente

Copia `.env.example` in `.env` e imposta i valori sistemistici:

| Variabile | Descrizione |
|---|---|
| `APP_KEY` | Chiave crittografica — genera con `php artisan key:generate` |
| `APP_URL` | URL pubblico dell'istanza |
| `DB_PASSWORD` | Password del database MariaDB |
| `DB_ROOT_PASSWORD` | Password root MariaDB (per l'init del container) |
| `MAIL_HOST` / `MAIL_PORT` | Server SMTP per le email |
| `PEC_*` | Credenziali PEC (solo produzione) |

> **Le impostazioni applicative** (nome ente, logo, colori, coordinate mappa)
> si configurano dall'interfaccia admin in **Admin → Impostazioni** — non dal file `.env`.

### Brandizzazione

Dopo il primo login con l'account admin:

1. Vai su **Admin → Impostazioni**
2. Configura il gruppo **Brand**: nome ente, logo, colori
3. Configura il gruppo **Mappa**: coordinate e zoom del territorio
4. Configura il gruppo **Email**: mittente notifiche

### Telegram

La v0.3 include un bot Telegram opzionale per notifiche push e comandi rapidi operativi.

Configurazione minima:

1. Vai su **Admin → Impostazioni** e compila il gruppo **Bot Telegram**:
	- `telegram_bot_token`
	- `telegram_bot_username`
	- `telegram_webhook_secret` opzionale, ma raccomandato
2. Espone l'istanza su HTTPS pubblico.
3. Registra il webhook:

```bash
docker compose exec php php artisan telegram:set-webhook --url="https://tuo-dominio.example"
```

Collegamento utente:

1. L'utente autenticato apre **Profilo**.
2. Genera un token Telegram.
3. Avvia il bot con `/start <token>`.

Comandi disponibili:

- `/lista` mostra le segnalazioni aperte visibili all'utente.
- `/apri <id>` mostra il dettaglio e, se consentito, i bottoni inline per le azioni rapide.

---

## Deployment Produzione (Portainer / rootless Podman)

ProntoPA usa due immagini Docker pre-compilate, pubblicate su GHCR dopo ogni release:

| Immagine | Descrizione |
|---|---|
| `ghcr.io/comune-di-montesilvano/pronto-pa:TAG` | PHP-FPM con tutto il codice Laravel |
| `ghcr.io/comune-di-montesilvano/pronto-pa-web:TAG` | Nginx con asset statici compilati |

### Stack Portainer

1. In Portainer crea un nuovo **Stack**
2. Punta al repository Git e usa `docker-compose.yml` (il file di default è già prod)
3. Imposta le variabili d'ambiente nello stack (APP_KEY, DB_PASSWORD, ecc.)
4. Scegli la versione: imposta `APP_VERSION=v1.0.0` (o `latest`)
5. Deploy

### Prima installazione

Dopo il primo avvio:

```bash
docker compose exec php php artisan migrate --seed
```

Poi accedi e configura il tuo ente in **Admin → Impostazioni**.

### Aggiornamento

```bash
# Aggiorna le immagini
docker compose pull

# Riavvia
docker compose up -d

# Esegui eventuali nuove migrations
docker compose exec php php artisan migrate
```

> Nessun bind mount in produzione. Tutto il codice è copiato dentro le immagini.
> I dati persistenti (DB, storage, upload) sono in named volumes Docker.

---

## Rilascio di una nuova versione

Il workflow CI/CD si attiva automaticamente al push di un tag Git:

```bash
git tag v1.2.0
git push origin v1.2.0
```

GitHub Actions:
1. Compila le dipendenze PHP e gli asset frontend
2. Crea le immagini multi-arch (`linux/amd64`, `linux/arm64`)
3. Pubblica su GHCR con i tag `v1.2.0`, `1.2` e `latest`

La versione è visibile nel footer dell'interfaccia.

---

## Struttura del progetto

```
prontoPA/
├── app/
│   ├── Http/Controllers/    # Controller per ruolo
│   ├── Models/              # Eloquent models (incl. Impostazione)
│   ├── Policies/            # Autorizzazione per ruolo
│   └── Services/            # Workflow, email, webhook
├── database/
│   ├── migrations/          # Schema DB (da legacy export.sql)
│   └── seeders/             # Dati iniziali e di riferimento
├── docker/
│   ├── nginx/               # Config Nginx
│   └── php/                 # Dockerfile dev + php.ini
├── legacy/                  # Codice originale (solo riferimento)
├── resources/views/         # Blade templates
├── Dockerfile               # Multi-stage: app (php-fpm) + web (nginx)
├── docker-compose.yml       # Produzione (no bind mounts)
└── docker-compose.override.yml  # Sviluppo (bind mounts + Adminer + Mailpit)
```

---

## Contribuire

Contributi benvenuti! Apri una issue o una pull request su
[Comune-di-Montesilvano/prontoPA](https://github.com/Comune-di-Montesilvano/prontoPA).

---

## Licenza

ProntoPA è distribuito sotto licenza **GNU Affero General Public License v3.0 (AGPL-3.0)**.

Questo significa che anche le versioni distribuite come servizio web (SaaS) devono rendere
disponibile il codice sorgente. Vedi [LICENSE](LICENSE) per i dettagli.
