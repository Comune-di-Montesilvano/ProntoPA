# Changelog

Formato basato su [Keep a Changelog](https://keepachangelog.com/it/1.0.0/).
Per il dettaglio funzionalità per release vedi [TODO.md](TODO.md) (roadmap
completata) e [PIANO-SVILUPPO.md](PIANO-SVILUPPO.md) (piano/motivazioni).

## [0.8.15] - 2026-10-22

### Added
- Controllo settimanale delle LTS di MariaDB: apre una issue quando esce una LTS più
  nuova di quella dello stack (Dependabot non distingue LTS da rolling, su mariadb è
  disattivato).
- `MARIADB_AUTO_UPGRADE` nel compose: al cambio di versione MariaDB aggiorna da sé le
  tabelle di sistema (con backup), senza console.

### Changed
- Immagine web su nginx 1.29.

## [0.8.14] - 2026-10-22

### Security
- Login: dopo 5 password errate l'account resta bloccato 15 minuti da qualunque IP
  (prima: 1 minuto e solo per lo stesso IP). Il blocco scatta prima di interrogare
  Active Directory, così un attacco via ProntoPA non fa bloccare l'utente sul dominio.
  Limite per IP portato a 20 errori/ora. Soglia e durata configurabili
  (`LOGIN_MAX_TENTATIVI`, `LOGIN_BLOCCO_MINUTI`); ogni blocco finisce nel log.

## [0.8.13] - 2026-10-22

### Added
- Rebuild settimanale delle immagini (lunedì 04:30 UTC): `latest` si aggiorna con
  immagini base e patch Alpine fresche solo se la scansione Trivy passa;
  `latest-YYYYMMDD` resta come storico. Saltato se nella settimana è uscito un rilascio.
- Dependabot su tutte le dipendenze (composer, npm, GitHub Actions, immagini Docker,
  servizi del compose): minor e patch raggruppate e integrate da sole a CI verde,
  major e salti di php/mariadb/redis lasciati in PR per revisione manuale.
- Semgrep (SAST) su PR e settimanale; avviso automatico quando esce una nuova
  versione di Trivy.
- Errori su Sentry con tag `build_type`, `commit` e `build_date`.

### Changed
- Versione di Trivy fissata nei workflow invece di `latest`.

## [0.8.12] - 2026-10-22

### Fixed
- Menu laterale fermo a sinistra e alto quanto lo schermo: prima si allungava
  con il contenuto della pagina e scorreva via insieme a lei.

## [0.8.11] - 2026-10-22

### Changed
- Email in italiano e con l'identità dell'ente: intestazione con logo e nome
  (Admin → Impostazioni → brand), pulsante nel colore dell'ente, saluti
  "Cordiali saluti" firmati dall'ente; spariti "Hello!", "Regards" e gli altri
  testi inglesi.
- Conferma email: il pulsante "Invia di nuovo il link" si attiva solo un
  minuto dopo l'ultimo invio, con conto alla rovescia.
- Nuova segnalazione: segnalatori (scuole, URP, interni) non scelgono più il
  "Tipo di intervento richiesto", basta la tipologia; resta facoltativo per
  gestori e admin. Icone delle tipologie più piccole.

## [0.8.10] - 2026-10-22

### Added
- Admin → Impostazioni → Pubblicazione automatica: pulsante "Applica alle
  segnalazioni esistenti". La regola scattava solo ai cambi di stato, quindi
  le segnalazioni importate o precedenti restavano fuori dalla dashboard
  pubblica.

## [0.8.9] - 2026-10-22

### Fixed
- Statistiche in errore (500) dal secondo accesso: i tempi medi per stato
  erano salvati in cache come oggetto, che con Redis tornava illeggibile.

## [0.8.8] - 2026-10-22

### Fixed
- Caricamento di un backup da Admin → Backup rifiutato con errore 413 dietro
  un reverse proxy con limite di 1 MB per richiesta (default nginx): i blocchi
  passano da 5 MB a 512 KB.

## [0.8.7] - 2026-10-22

### Fixed
- `app:import-legacy` pronto per il go-live:
  - stati mappati secondo la tabella reale del vecchio programma (prima 3151
    segnalazioni completate risultavano "assegnate a operatore");
  - istituti e plessi già presenti (es. da anagrafe MIUR) riusati per codice
    meccanografico invece di essere sovrascritti con gli id legacy;
  - utenti legacy importati disattivati e senza ruoli (accesso e ruolo da AD o
    SPID); utenti già presenti con lo stesso username riusati;
  - rifiuta di partire se esistono già segnalazioni.

## [0.8.6] - 2026-10-22

### Changed
- Nuova segnalazione: i segnalatori (scuole SPID, interni, URP) segnalano
  sempre con la propria provenienza, mostrata in sola lettura; gestori e admin
  continuano a sceglierla.
- Per le scuole (SPID o provenienza "Direzioni didattiche") il plesso è
  obbligatorio, la mappa non compare (la posizione è il plesso) e i dettagli
  sono sempre visibili e obbligatori: tipo di intervento e dove si trova il
  problema. Gli allegati restano facoltativi.

## [0.8.5] - 2026-10-22

### Added
- Admin → Impostazioni → SPID/CIE mostra se l'accesso SPID è attivo e, se
  no, cosa manca (issuer, client ID, secret mancante o salvato con un'altra
  `APP_KEY`): prima il tasto al login spariva senza spiegazioni.

## [0.8.4] - 2026-10-22

### Fixed
- Logo dell'ente non visualizzato se ospitato su un altro dominio (es. sito
  istituzionale): la Content Security Policy ora ammette immagini da
  qualsiasi origine `https:`. Script e frame esterni restano bloccati.

## [0.8.3] - 2026-10-22

### Fixed
- Footer e pagina di login mostravano la versione con la "v" doppia
  (`vv0.8.2`): `APP_VERSION` contiene già la "v" del tag.

## [0.8.2] - 2026-10-22

### Added
- Backup e ripristino: Admin → Backup per creare, scaricare, eliminare,
  caricare (a blocchi da 5 MB, verifica del file in background) e
  ripristinare un backup, con conferma digitando `RIPRISTINA` e pagina
  d'attesa. La pagina mostra il backup in corso e si aggiorna da sola. Il backup (database e file
  allegati) è un archivio ZIP cifrato AES-256 con la passphrase
  `BACKUP_PASSPHRASE`; i segreti (es. SPID) vengono ricifrati con la chiave
  dell'installazione di destinazione, quindi si può migrare da dev a prod
- Backup notturno alle 02:00 con conservazione automatica (ultimo, 7 giorni,
  uno per settimana fino a 30 giorni). Allarme in dashboard admin e nella
  pagina Backup se l'ultimo è fallito o più vecchio di 36 ore; in caso di errore notifica a
  Sentry e via email agli amministratori
- Ripristino sicuro: verifica prima di toccare i dati, copia
  "pre-ripristino" automatica (sempre, se è impostata `BACKUP_PASSPHRASE`)
  e rollback automatico in caso di errore. I file temporanei restano
  leggibili solo dal processo e vengono ripuliti se un job si interrompe. A
  fine ripristino tutte le sessioni vengono chiuse
- Comandi `backup:crea`, `backup:ripristina`, `backup:pulisci`
- Nuovo servizio `queue-lunghi` (worker dedicato a backup e ripristini):
  compare con "Update the stack" ed è obbligatorio come `queue` e `scheduler`

### Changed
- `DB_PASSWORD` è obbligatoria e uguale per `php` e `mariadb`: eliminati i
  valori predefiniti `segnalazioni_secret` e `root_secret`.
  `DB_ROOT_PASSWORD` serve solo alla prima creazione del volume del database
- Le password del database valgono solo alla creazione del volume
  `mariadb_data` (procedura di reset documentata in CLAUDE.md)
- Nuove variabili `BACKUP_PASSPHRASE` e `BACKUP_MAX_UPLOAD_MB` (vedi
  `.env.example`)

### Fixed
- Se il database rifiuta l'accesso, l'avvio del container mostra dopo circa
  30 secondi l'errore reale (con suggerimento "Access denied") invece di
  restare in attesa senza messaggi

## [0.8.1] - 2026-10-21

### Fixed
- Login con Active Directory in errore (500) sulle installazioni nate con
  una versione precedente: ruoli e permessi introdotti dagli aggiornamenti
  (es. `segnalazioni.per-conto`) non venivano mai creati, perché il seed
  girava solo al primo avvio. Ora a ogni avvio il container allinea dati di
  riferimento, ruoli/permessi e impostazioni, senza toccare valori già
  configurati né i ruoli degli utenti. Nessun intervento manuale richiesto
- Le password non compaiono più negli stacktrace di `laravel.log` e Sentry
  quando un login va in errore
- Avvio del container con una password del database contenente apici o `$`

### Changed
- L'avvio non riassegna più i ruoli agli utenti in base ai vecchi flag
  `amministratore`/`gestore_segnalazioni`

## [0.8.0] - 2026-10-08

### Added
- Deleghe scuole: chi entra con SPID/CIE chiede una delega per un istituto
  (intero o per plessi); la segreteria approva o rifiuta da un link nella
  casella istituzionale, senza registrarsi. Con una delega attiva si
  segnala e si vedono le segnalazioni dei plessi coperti, fatte da chiunque
- Rinnovo annuale confermato dalla segreteria persona per persona
  (`deleghe:rinnovi`, il 1° del mese) e chiusura automatica di deleghe e
  richieste scadute (`deleghe:scadenze`, ogni mattina)
- Admin → Deleghe: elenco con filtri, pre-deleghe per codice fiscale
  agganciate al primo accesso SPID, attivazione d'ufficio, revoca, reinvio
  dell'email, sblocco delle persone segnalate dalle segreterie
- Admin → Anagrafe MIUR: ricerca nell'open data "Anagrafe scuole statali",
  selezione di istituti e sedi da gestire; nome, indirizzo ed email dei
  record selezionati arrivano dal MIUR e si riallineano a ogni nuovo
  download (link in Impostazioni → scuole). Nessuna cancellazione
  automatica: le scuole sparite dal dataset vengono segnalate

### Changed
- La pagina d'attesa degli utenti SPID è diventata "Le mie deleghe"
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
