# Changelog

Formato basato su [Keep a Changelog](https://keepachangelog.com/it/1.0.0/).
Per il dettaglio funzionalità per release vedi [TODO.md](TODO.md) (roadmap
completata) e [PIANO-SVILUPPO.md](PIANO-SVILUPPO.md) (piano/motivazioni).

## [Unreleased]

### Changed
- Dipendenze: `directorytree/ldaprecord` 3 → 4 (opzioni connessione
  rinominate: `use_tls` = ldaps://, `use_starttls` = STARTTLS),
  `firebase/php-jwt` 6 → 7, `vite` 8.3.2, `codeql-action/upload-sarif` 4.38.2

## [0.7.2] - 2026-10-01

### Changed
- Nomi dei gruppi Active Directory → ruolo spostati da Admin → Impostazioni
  alle variabili `LDAP_GRUPPO_ADMIN`, `LDAP_GRUPPO_SUPERVISORI`,
  `LDAP_GRUPPO_GESTORI`, `LDAP_GRUPPO_OPERAI`, `LDAP_GRUPPO_URP`,
  `LDAP_GRUPPO_SEGNALATORI` (stessi default): servono già al primo accesso,
  quando non esiste ancora un amministratore che possa cambiarli da UI
- `php artisan ldap:prova` mostra la configurazione letta e il motivo esatto
  di un rifiuto (messaggio diagnostico di AD sul bind, oppure bind riuscito
  ma utente non trovato con base DN/template indicati)

### Fixed
- Messaggi di accesso e reset password in inglese ("These credentials do not
  match our records"): aggiunte le traduzioni italiane e lingua predefinita
  `it`

## [0.7.1] - 2026-10-01

### Fixed
- La versione mostrata dall'app (e inviata a Sentry come release) era
  sempre `latest`: il compose di produzione riscriveva `APP_VERSION` a
  runtime sopra quella iniettata nel build dal tag git. Ora la versione
  viene solo dal build; il tag dell'immagine da scaricare si sceglie con
  `IMAGE_TAG` (`0.7.1`, `0.7` o `latest`)

## [0.7.0] - 2026-10-01

### Added
- Login dipendenti con Active Directory: ruolo, flag supervisore,
  provenienza e permesso "per conto di" derivati dai gruppi AD a ogni
  accesso; aggancio automatico degli account legacy con la stessa email;
  comando `ldap:prova` per la diagnosi
- Login ditte con email + password e verifica in due passaggi con codice
  via email (obbligatoria), in alternativa all'app TOTP
- Login delle scuole con SPID/CIE tramite pa-sso-proxy (OIDC + PKCE):
  primo accesso con conferma dell'email, configurazione da Admin →
  Impostazioni con secret cifrato, logout anche sul proxy
- Comando `artisan demo`: dati realistici (istituti, utenti, ~50 segnalazioni
  in tutti gli stati) per chi valuta il riuso, rilanciabile senza accumulo
- `publiccode.yml` + `LICENSE` (EUPL-1.2) per il riuso via Developers Italia
- Documentazione API OpenAPI 3.1 (`docs/openapi.yaml`)
- Servizi `queue` e `scheduler` in produzione (in precedenza assenti: job in
  coda — webhook, AI — e comandi schedulati — SLA, digest, verifica annuale —
  non venivano mai eseguiti)
- Integrazione error tracking Sentry/Glitchtip (disattivata finché non si
  configura `SENTRY_LARAVEL_DSN`)
- Analisi statica PHP (Larastan) e workflow CI per test automatici e
  validazione `publiccode.yml`
- Security header e CSP su nginx; rate limit su API pubblica

### Changed
- Reset password e unicità email limitati agli account locali (ditte)

### Removed
- Creazione dell'amministratore da variabili `ADMIN_*` (password in chiaro
  nelle env vars): il primo amministratore è chi appartiene al gruppo AD
  `PRONTOPA_ADMIN`

### Fixed
- Licenza incoerente tra README/footer login (AGPL-3.0) e LICENSE/publiccode.yml
  (EUPL-1.2) — allineata a EUPL-1.2 ovunque
- 42 vulnerabilità nelle dipendenze Composer (11 high, incluso Laravel
  framework) risolte con aggiornamento entro i vincoli esistenti
- Fallback `.env` del webhook outbound si rompeva silenziosamente con
  `config:cache` attivo (usava `env()` invece di `config()`)
- Contrasto colore e landmark mancanti sulle pagine pubbliche (audit
  accessibilità AGID, portate a 100/100 Lighthouse)
- Hot-reload PHP in sviluppo: OPcache non rileggeva mai i file modificati

## Storico release

| Versione | Tema |
|---|---|
| v0.7.2 | Gruppi AD in env, diagnosi `ldap:prova`, messaggi di accesso in italiano |
| v0.7.1 | Fix versione applicativa da build (IMAGE_TAG) |
| v0.7.0 | Ditte, assistente AI locale, rendicontazione, hardening, accessi con AD (dipendenti), email+2FA (ditte), SPID/CIE (scuole) |
| v0.6.x | Adozione: form compatto, anti-duplicato, digest, squadre |
| v0.5.0 | Integrazioni esterne: API REST, webhook outbound |
| v0.3 | UX e comunicazioni: landing page, notifiche email, bot Telegram |
| v0.2.0, v0.1.0 | Prime release |

Changelog dettagliato per singolo tag non ricostruito retroattivamente
per evitare voci imprecise; da qui in avanti ogni tag aggiorna questo file.
