# Changelog

Formato basato su [Keep a Changelog](https://keepachangelog.com/it/1.0.0/).
Per il dettaglio funzionalità per release vedi [TODO.md](TODO.md) (roadmap
completata) e [PIANO-SVILUPPO.md](PIANO-SVILUPPO.md) (piano/motivazioni).

## [Unreleased]

### Added
- Wizard di primo avvio (`/setup`): token `.env` + email + password → OTP via
  email → crea l'account amministratore, senza password in chiaro nelle
  variabili d'ambiente
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
- Security header e CSP su nginx; rate limit su wizard OTP e API pubblica

### Fixed
- Licenza incoerente tra README/footer login (AGPL-3.0) e LICENSE/publiccode.yml
  (EUPL-1.2) — allineata a EUPL-1.2 ovunque
- Wizard di setup irraggiungibile in produzione: l'entrypoint creava sempre
  un admin da `.env` prima che il wizard potesse partire
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
| v0.6.x | Adozione: form compatto, anti-duplicato, digest, squadre |
| v0.5.0 | Integrazioni esterne: API REST, webhook outbound |
| v0.3 | UX e comunicazioni: landing page, notifiche email, bot Telegram |
| v0.2.0, v0.1.0 | Prime release |

Changelog dettagliato per singolo tag non ricostruito retroattivamente
per evitare voci imprecise; da qui in avanti ogni tag aggiorna questo file.
