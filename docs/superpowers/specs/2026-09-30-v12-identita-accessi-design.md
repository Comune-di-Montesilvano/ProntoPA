# ProntoPA v1.2 — "Identità e accessi" — Design

**Data**: 2026-09-30
**Stato**: approvata — fasi 1-2 implementate in 0.7.0/0.8.0; modifiche in `2026-10-08-v080-anagrafe-miur-deleghe-design.md`
**Branch**: `feature/v12-identita-accessi` (da `main` @ `b1a8c79`)

## Obiettivo

Eliminare il lavoro manuale sulle utenze che nel vecchio software è la
difficoltà principale: creazione account, gestione di chi può segnalare
per quale scuola, account di persone che non lavorano più lì e restano
attivi.

Ogni tipologia di utente entra con un solo canale, e il ciclo di vita
dell'accesso è governato da chi ha titolo a governarlo:

| Chi | Canale | Chi governa l'accesso |
|---|---|---|
| Segnalatori scuole | SPID/CIE via `pa-sso-proxy` | Segreteria della scuola (delega + rinnovo annuale) |
| Dipendenti ente (gestori, operai, admin, URP, uffici) | LDAP/Active Directory | Gruppi AD (sistemista) |
| Ditte | email + password locale + 2FA (codice via email o TOTP), magic link per non registrate | Admin ProntoPA |

## Stato del progetto e readiness produzione

Roadmap v0.3 → v1.0 completata (vedi `TODO.md`). v1.1 Hardening
completata tranne due voci che dipendono dall'ente:

- Retention/cancellazione dati GDPR — serve decisione dell'ente sui tempi.
- Ambiente di staging — serve infrastruttura.

CI verde (PHPUnit, Dusk, Larastan, Trivy), dipendenze aggiornate al
2026-09-29 (#63).

Bloccante per il go-live, emerso in questa analisi: la gestione utenze.
Oggi esistono registrazione self-service con password + approvazione
admin manuale + verifica annuale email (`accounts:annual-check`), ma:

- la verifica del dominio email non identifica la scuola: il personale
  delle scuole statali usa `@istruzione.it`, dominio condiviso da tutte le
  scuole d'Italia;
- l'approvazione admin manuale è il collo di bottiglia del vecchio sistema;
- i dipendenti dell'ente hanno password locali da gestire a mano.

Questa v1.2 chiude quel gap. Dopo v1.2 restano solo GDPR retention e
staging, entrambi decisioni/risorse dell'ente, non sviluppo.

## Decisioni prese (brainstorm 2026-09-29/30)

1. **Delega = legame persona → scuola** ("sono autorizzato a segnalare per
   questo istituto/plesso"). Nessuna gerarchia dirigente → delegati.
2. **SPID/CIE solo per i segnalatori delle scuole.** Dipendenti via AD,
   ditte locali sotto pieno controllo admin.
3. **Ruoli dipendenti dai gruppi AD**, riletti a ogni login.
4. **Chi approva la delega è la casella istituzionale della segreteria**
   (`istituti.email`, tipicamente `<codmecc>@istruzione.it`), mai un altro
   delegato e mai un admin in via ordinaria.
5. **Una persona può avere N deleghe** (docenti su più istituti, DSGA
   reggenti).
6. **Delega per istituto intero o per plessi specifici** (`id_plesso`
   nullable).
7. **Il segnalatore vede tutte le segnalazioni dei plessi coperti dalle sue
   deleghe attive**, fatte da chiunque: la segnalazione appartiene alla
   scuola, non alla persona.
8. **Taglio netto al go-live** per i segnalatori legacy: disattivati, nessun
   aggancio automatico al nuovo account SPID.
9. **Anti-spam senza seconda email di verifica appartenenza**: limiti per
   codice fiscale, tetto giornaliero per casella, blocco su segnalazione
   della segreteria.
10. **Email del segnalatore sempre presente e verificata** (link di
    conferma), anche se arriva dal claim SPID.
11. **Rinnovo ogni 12 mesi confermato dalla segreteria**, persona per
    persona.
12. **Email alla segreteria con un solo link** a una pagina con due
    pulsanti Approva/Rifiuta. Nessuna pagina di conferma intermedia.

## Architettura

```
                ┌──────────── pa-sso-proxy (SATOSA) ── SPID / CIE / eIDAS
                │  OIDC Authorization Code + PKCE
Scuole ──► /auth/spid ──► OidcClient ──► SpidLoginService ──► users (auth_source=spid)
                                                                 │
                                                                 ▼
                                               DelegaService ──► deleghe ──► email segreteria
                                                                 │               │
                                                                 │     /deleghe/decidi/{token}
                                                                 ▼
                                               Segnalazione::scopeVisibileA (per deleghe attive)

Dipendenti ─► /login ──► LoginRequest ──┬─► locale (solo ditte)
Ditte ──────►                           └─► LdapLoginService ──► AD (bind + gruppi)
                                                    │
                                                    ▼
                                         users (auth_source=ldap) + ruolo da gruppi
```

### Componenti nuovi

| Unità | Responsabilità | Dipende da |
|---|---|---|
| `app/Services/Oidc/OidcClient.php` | Discovery (cache 10 min), URL autorizzazione PKCE, scambio code (`client_secret_basic`), verifica id_token via JWKS, userinfo con controllo `sub` | `firebase/php-jwt`, `Http`, `Impostazione` |
| `app/Services/Oidc/ClaimsSpid.php` | Estrazione codice fiscale (`TINIT-`, varianti eIDAS), nome/cognome/email da claim eterogenei | — |
| `app/Services/Auth/SpidLoginService.php` | Da claim verificati a utente: blocco, lookup per CF, completamento profilo | `ClaimsSpid`, `User` |
| `app/Services/Auth/LdapLoginService.php` | Bind, lettura GUID/mail/gruppi (annidati), mapping ruolo, upsert utente, aggancio legacy per email | `directorytree/ldaprecord-laravel`, `MappaGruppiLdap` |
| `app/Services/Auth/MappaGruppiLdap.php` | Gruppi AD → ruolo/flag/provenienza/permesso con precedenze | `Impostazione` |
| `app/Services/DelegaService.php` | Richiesta (controlli, copertura plessi, tetto), decisione, revoca, rinnovo, scadenze, pre-delega | `Delega`, `DelegaStorico`, notifiche |
| `app/Models/Delega.php`, `DelegaStorico.php` | Eloquent, `scopeAttive`, `scopeCoprePlesso` | — |
| Controller: `Auth/SpidController`, `Auth/CompletaProfiloController`, `DelegheController` (delegato), `DecisioneDelegaController` (segreteria, no login), `Admin/DelegheController` | HTTP sottile, logica nei service | service sopra |
| Comandi: `deleghe:rinnovi` (1° del mese), `deleghe:scadenze` (giornaliero), `utenze:cutover` | Scheduler | `DelegaService` |
| Mock dev: `Auth/SpidMockController`, `LdapMock` | Simulatori, rifiutati con `APP_ENV=production` | — |

Nessun SAML in ProntoPA: tutta la complessità SPID/CIE resta nel proxy.

## Modello dati

Migrazioni additive o che allentano vincoli; nessuna colonna rimossa.

### `users` — colonne nuove

| Colonna | Tipo | Scopo |
|---|---|---|
| `auth_source` | `varchar(10)` default `'locale'` | `locale` · `ldap` · `spid`. Il form password autentica in locale solo `locale` |
| `codice_fiscale` | `char(16)` nullable, **unique** | Chiave identità SPID/CIE |
| `oidc_subject` | `varchar(255)` nullable | Tracciabilità, mai usato come chiave (stessa persona può avere `sub` diversi tra SPID e CIE) |
| `ldap_guid` | `varchar(36)` nullable, **unique** | `objectGUID` AD, stabile ai rename |
| `bloccato_at` | timestamp nullable | Blocco su segnalazione segreteria o admin |
| `motivo_blocco` | `varchar(255)` nullable | |
| `two_factor_metodo` | `varchar(5)` nullable | `email` · `totp` · NULL — solo account `locale` |

Vincoli modificati:

- `email` resta NOT NULL, **perde l'indice unique** a livello DB. Motivo:
  gli account legacy disattivati hanno spesso la stessa email personale
  che la persona userà con SPID. Unicità applicativa (validazione) solo tra
  utenti attivi `locale` e `spid`. Chiavi d'identità vere: `codice_fiscale`,
  `ldap_guid` (unique DB).
- `password` diventa nullable (utenti `ldap`/`spid` non ne hanno).
- Conseguenza del non-unique su `email`: il reset password (broker
  Laravel, che cerca per email) va limitato agli utenti `locale` attivi —
  provider/query del broker filtrata su `auth_source = 'locale'`, così non
  può mai selezionare un utente `ldap`/`spid` o legacy disattivato con la
  stessa email.

### Tabella `deleghe`

| Colonna | Note |
|---|---|
| `id` | |
| `user_id` → users, nullable | NULL solo per pre-delega admin non ancora agganciata |
| `codice_fiscale` | `char(16)`, sempre valorizzato: chiave di aggancio della pre-delega e snapshot |
| `id_istituto` → istituti | |
| `id_plesso` → plessi, nullable | NULL = tutto l'istituto |
| `gruppo_richiesta` | UUID: le N righe di una stessa richiesta multi-plesso, decise in blocco |
| `stato` | `richiesta` · `attiva` · `rifiutata` · `revocata` · `scaduta` |
| `email_destinatario` | Snapshot di `istituti.email` all'invio |
| `richiesta_inviata_at` | NULL = in coda per tetto giornaliero |
| `decisa_at` | |
| `decisa_via` | `segreteria` · `admin` · `sistema` |
| `decisa_da` → users, nullable | Admin, per `decisa_via = admin` |
| `motivo` | `varchar(255)` nullable — revoca/attivazione d'ufficio admin |
| `valida_fino_at` | Approvazione + `deleghe_mesi_validita` |
| `rinnovo_inviato_at` | Evita doppio invio nella email mensile |
| `token_hash` | SHA-256 dell'unico token valido per l'azione pendente |
| `token_scadenza_at` | |
| `created_at`, `updated_at` | |

Indici: `(user_id, stato)`, `(codice_fiscale, stato)`, `(id_istituto, stato)`,
`(stato, valida_fino_at)`, `gruppo_richiesta`, `token_hash`.

Il token è per **gruppo di richiesta** (stesso valore sulle righe del
gruppo) in fase di richiesta, e per **istituto** in fase di rinnovo (stesso
valore sulle deleghe dell'istituto incluse nell'email del mese).

Invarianti applicative (in `DelegaService`, testate):

- al massimo un gruppo in stato `richiesta` per (persona, istituto);
- nessuna delega `attiva` duplicata per (persona, istituto, plesso);
- una delega `attiva` su plesso NULL rende superflue quelle sui singoli
  plessi dello stesso istituto: in approvazione le righe per-plesso della
  stessa persona/istituto vengono chiuse come `revocata` con
  `decisa_via = sistema`, `motivo = 'assorbita da delega istituto'`.

### Tabella `deleghe_storico`

Audit di ogni transizione (stesso pattern di `stati_segnalazioni`):

| Colonna | Note |
|---|---|
| `id`, `id_delega` → deleghe | |
| `evento` | `richiesta` · `inviata` · `approvata` · `rifiutata` · `bloccata` · `revocata` · `rinnovata` · `scaduta` · `predelega` · `agganciata` · `reinviata` |
| `via` | `utente` · `segreteria` · `admin` · `sistema` |
| `id_utente` → users, nullable | Chi, se loggato |
| `ip` | `varchar(45)` nullable — per le azioni della segreteria (senza login) |
| `created_at` | |

### `impostazioni` — chiavi nuove

- OIDC (gruppo `spid`): `oidc_issuer`, `oidc_client_id`,
  `oidc_client_secret` (cifrato `Crypt`, legato ad `APP_KEY`: cambiarlo =
  reinserire il secret da UI; mai restituito in chiaro, la UI mostra solo
  "configurato: sì/no"). Redirect URI **calcolato** `APP_URL/auth/spid/callback`,
  mostrato in sola lettura con pulsante copia.
- Gruppi AD (gruppo `ldap`), default:
  `ldap_gruppo_admin=PRONTOPA_ADMIN`, `ldap_gruppo_supervisori=PRONTOPA_SUPERVISORI`,
  `ldap_gruppo_gestori=PRONTOPA_GESTORI`, `ldap_gruppo_operai=PRONTOPA_OPERAI`,
  `ldap_gruppo_urp=PRONTOPA_URP`, `ldap_gruppo_segnalatori=PRONTOPA_SEGNALATORI`.
- Deleghe (gruppo `deleghe`), default: `deleghe_max_pendenti=3`,
  `deleghe_giorni_stop_rifiuto=30`, `deleghe_giorni_scadenza_richiesta=30`,
  `deleghe_email_giorno_istituto=10`, `deleghe_mesi_validita=12`,
  `deleghe_giorni_avviso_delegato=7`.

### `.env` — nuove variabili (bootstrap)

```env
LDAP_HOST=ldap://dc.ente.local:389     # "mock" in dev
LDAP_PORT=389
LDAP_BASE_DN=DC=ente,DC=local
LDAP_USER_DN_TEMPLATE=%s@ente.local
LDAP_STARTTLS=false
LDAP_TLS_SKIP_VERIFY=false             # true ammesso solo fuori produzione
OIDC_MOCK=false                        # true = simulatore SPID, solo dev
```

Ogni variabile va aggiunta anche al blocco `environment:` dei servizi in
`docker-compose.yml` (lezione `comunicapa`: il compose non fa passthrough).
`ext-ldap` va aggiunta a entrambi i Dockerfile (dev e prod).

### Cosa va in pensione (fase 1)

- Wizard di primo avvio `/setup`: `SetupController`, `EnsureSetupComplete`,
  view `setup/*`, `SetupOtpNotification`, route, `SETUP_TOKEN` in
  `.env.example`/compose, test Feature/Unit/Dusk collegati. Creava solo
  l'admin: con LDAP fonte di verità il primo admin è chi sta in
  `PRONTOPA_ADMIN`. Aggiornare README, `CLAUDE.md`, `publiccode.yml`
  (se lo cita), `CHANGELOG.md`.

### Cosa va in pensione (fase 3)

- Route `/register` e `RegisteredUserController` (registrazione con password).
- Comando `accounts:annual-check` rimosso dallo scheduler; colonne
  `annual_verification_*` restano (regola migrazioni additive), rimozione
  in una release successiva.
- `profilo.limita_istituto` non più usato per i segnalatori `spid`; resta
  valido per eventuali utenti locali residui.

## Flussi — login

### Pagina di login

Due blocchi: **"Scuole: entra con SPID/CIE"** (pulsante) e **"Personale
dell'ente e ditte"** (un campo "Username dipendente o email ditta" +
password).

Il form instrada sul contenuto del campo:

1. contiene `@` → account `locale` (solo ditte) cercato per
   **email** tra gli utenti `locale` attivi → password → 2FA;
2. altrimenti → username AD → bind LDAP.

I due spazi di nomi non si sovrappongono (uno username AD non contiene
`@`), quindi nessuna collisione possibile. Gli account `locale` si
identificano solo per email: unicità dell'email tra utenti `locale`
attivi garantita da validazione applicativa (creazione/modifica ditta da
admin).

Rate limit e messaggio generico (`auth.failed`) come oggi in `LoginRequest`:
il messaggio non rivela se l'account esiste né dove.

### 2FA degli account locali (ditte)

- Nuova colonna `users.two_factor_metodo`: `email` · `totp` · NULL.
- **Ditte: 2FA obbligatoria**, default `email` (nessuna app da installare).
  Dopo la password: codice di 6 cifre inviato all'email dell'account,
  hash in cache Redis (chiave per user id), validità 10 minuti, max 5
  tentativi poi codice invalidato, reinvio con throttle (1/min). La ditta
  può passare a `totp` dal profilo (Fortify esistente, con recovery code).
- Utenti `ldap`/`spid`: nessuna 2FA ProntoPA (l'autenticazione forte è di
  AD/SPID); la 2FA TOTP self-service esistente resta disponibile solo agli
  account `locale`.
- `TwoFactorChallengeController` esteso: se `two_factor_metodo = email`
  mostra il form codice email invece di quello TOTP.

### Login dipendenti (LDAP)

1. Password vuota → rifiutata **prima** del bind (su AD un bind con
   password vuota è un bind anonimo che "riesce").
2. Bind con `LDAP_USER_DN_TEMPLATE` + password utente. Fallito (password
   errata, account AD disabilitato/scaduto) → credenziali non valide.
3. Lettura `objectGUID`, `mail`, `displayName`, gruppi (annidati via
   `LDAP_MATCHING_RULE_IN_CHAIN`).
4. Nessun gruppo `PRONTOPA_*` → "Non sei abilitato a ProntoPA".
   `mail` assente → "Account AD senza email: contatta l'amministratore".
5. Lookup per `ldap_guid`. Se assente (primo accesso): cerca utente
   `locale`, attivo o no, **non ditta** (`id_impresa` NULL), stessa email
   (case-insensitive). Esattamente un risultato → aggancio: diventa `ldap`,
   `password` = NULL, storico assegnazioni preservato. Zero o più di uno →
   nuovo utente (più di uno: log warning per l'admin).
6. Sync `name`, `email` (`email_verified_at = now`), `username` =
   sAMAccountName, ruolo/flag/provenienza/permesso da `MappaGruppiLdap`,
   `attivo = true`, `last_login`. Login, sessione rigenerata.
7. Username AD già usato da un altro account ProntoPA (es. admin locale
   legacy o utente demo con lo stesso username, non agganciato per email) →
   login rifiutato con messaggio "contatta l'amministratore" e log warning;
   l'admin rinomina o disattiva l'account locale. Nessun errore di vincolo.
8. Transitorio fino al cutover: input senza `@` non riconosciuto da AD (o AD
   irraggiungibile) → tentativo sugli account `locale` per username
   (segnalatori legacy). Rimosso da `utenze:cutover` (fase 3).
9. Solo il codice LDAP 49 (credenziali non valide; su AD anche account
   disabilitato/scaduto) vale "credenziali errate"; ogni altro errore di
   bind → "non disponibile".

### Mapping gruppi → ruolo

Precedenza: ADMIN > SUPERVISORI > GESTORI > OPERAI > URP > SEGNALATORI. Un
solo ruolo Spatie per persona (vince il più alto): `scopeVisibileA` valuta
i ruoli in cascata e un doppio ruolo produrrebbe visibilità sbagliata.

| Gruppo | Ruolo | `supervisore_segnalazioni` | `segnalazioni.per-conto` | Provenienza |
|---|---|---|---|---|
| ADMIN | `admin` | — | da ruolo | interno |
| SUPERVISORI | `gestore` | true | da ruolo | interno |
| GESTORI | `gestore` | false | da ruolo | interno |
| OPERAI | `operaio` | false | — | interno |
| URP | `segnalatore` | false | diretto all'utente | URP |
| SEGNALATORI | `segnalatore` | false | — | interno |

Caposquadra non è un gruppo: si decide sulla squadra in ProntoPA.
Utente rimosso da tutti i gruppi → al login successivo rifiutato; ruolo e
permessi non vengono toccati fino a un login riuscito (un utente AD
disabilitato non riesce comunque a fare bind).

### Login scuole (SPID/CIE)

1. `GET /auth/spid` → discovery (cache 10 min) → `state`, `nonce`,
   `code_verifier` in sessione → redirect all'`authorization_endpoint`
   (PKCE S256, scope `openid profile email`).
2. `GET /auth/spid/callback?code&state`:
   1. `state` confrontato con la sessione (monouso, rimosso subito);
      mancante/diverso → errore, nessuna chiamata al proxy;
   2. token endpoint con `Authorization: Basic` (mai secret nel body);
   3. id_token: firma via JWKS (senza `kid` ammesso solo se JWKS ha una
      chiave), `iss`, `aud`, `exp`, `iat`, `nonce`, leeway 60 s;
   4. `userinfo` con Bearer; `sub` diverso da quello dell'id_token → claim
      userinfo scartati; endpoint assente/in errore → solo claim id_token;
   5. codice fiscale mancante → errore "identità incompleta".
3. CF bloccato → "Accesso non consentito".
4. Utente esistente per CF → aggiorna nome/cognome/`oidc_subject`, aggancia
   eventuali pre-deleghe per quel CF, rigenera sessione, login.
5. CF nuovo → claim in sessione (non si crea ancora l'utente) → pagina
   **Completa profilo**: email precompilata dal claim se presente →
   creazione utente `spid` con `email_verified_at = NULL`, ruolo
   `segnalatore`, provenienza scuola → invio link di verifica → login.
6. Instradamento post-login:
   - email non verificata → pagina verifica (reinvio link);
   - nessuna delega attiva → "Le mie deleghe" con form di richiesta;
   - altrimenti → dashboard segnalatore.
7. Cambio email da profilo → `email_verified_at = NULL`, nuova verifica;
   deleghe invariate, notifiche sospese fino a conferma.
8. Logout → logout locale, poi redirect a `end_session_endpoint` del proxy
   se esposto dalla discovery.

## Flussi — deleghe

### Richiesta (utente SPID, email verificata)

1. "Richiedi delega": ricerca istituto (nome / codice meccanografico),
   poi "Tutto l'istituto" oppure selezione di uno o più plessi.
2. Controlli, in ordine, ognuno con messaggio esplicito:
   1. utente bloccato → no;
   2. istituto senza `email` → "La scuola non è ancora configurata,
      abbiamo avvisato l'ente" + notifica admin;
   3. gruppo `richiesta` già pendente per quell'istituto → "Hai già una
      richiesta in attesa, inviata il gg/mm";
   4. gruppi pendenti totali ≥ `deleghe_max_pendenti` → stop;
   5. rifiuto dallo stesso istituto negli ultimi `deleghe_giorni_stop_rifiuto`
      → "Potrai ripresentare la richiesta dal gg/mm";
   6. plessi già coperti da una delega attiva della persona → esclusi con
      avviso; se non resta nulla → nessuna richiesta.
3. Creazione righe `richiesta` (stesso `gruppo_richiesta`, stesso token,
   scadenza `deleghe_giorni_scadenza_richiesta`), storico `richiesta`.
4. Invio:
   - **almeno un plesso richiesto non coperto** (nessuna delega attiva di
     nessuno sul plesso o sull'intero istituto; per "tutto l'istituto":
     almeno un plesso dell'istituto non coperto) → email **subito**, senza
     tetto;
   - altrimenti, se l'istituto è sotto `deleghe_email_giorno_istituto`
     email inviate oggi → email subito;
   - altrimenti → `richiesta_inviata_at` NULL, invio da `deleghe:scadenze`
     il mattino dopo, avviso admin ("IC X: superato il tetto giornaliero").
5. Il richiedente vede "In attesa della segreteria".

### Email alla segreteria

Oggetto: `ProntoPA — Richiesta di delega a segnalare guasti — <Nome Cognome>`

> **Mario Rossi** (codice fiscale RSSMRA80A01G482X, email m.rossi@gmail.com),
> identificato con SPID/CIE, chiede di poter segnalare guasti e richieste
> di manutenzione all'ente per conto di **IC Montesilvano 1 — plesso Via
> Roma**.
>
> Se questa persona lavora nella vostra scuola ed è autorizzata, approvate
> la richiesta. Se non la conoscete, rifiutatela.
>
> Per decidere apri questo link: <link>
>
> Il link scade il gg/mm/aaaa. Non serve registrarsi a ProntoPA.

Un solo link, nessun pulsante d'azione nell'email.

### Pagina di decisione (segreteria, senza login)

`GET /deleghe/decidi/{token}` — URL firmato (`temporarySignedRoute`) con
scadenza = `token_scadenza_at`; token confrontato con `token_hash` via
`hash_equals`. Mostra persona (nome, CF, email), istituto e plessi, e **due
pulsanti: Approva · Rifiuta**. Sotto Rifiuta una casella: ☐ *Non conosco
questa persona*.

- La GET non cambia nulla: gli scanner antispam che aprono i link non
  decidono niente. Decide solo il POST dei pulsanti (CSRF, throttle
  20/min per IP).
- **Approva** → tutte le righe del gruppo `attiva`, `valida_fino_at` =
  oggi + `deleghe_mesi_validita`, token azzerato, storico `approvata`
  (`via = segreteria`, IP), email al richiedente. Assorbimento deleghe
  per-plesso se approvata una delega su istituto intero.
- **Rifiuta** → `rifiutata`, email al richiedente.
- **Rifiuta + "Non conosco questa persona"** → `rifiutata`, utente
  bloccato (`bloccato_at`, motivo), tutti i suoi gruppi pendenti su
  qualsiasi istituto chiusi `rifiutata`, email all'admin. Nessuna email
  al richiedente sul blocco oltre al rifiuto.
- Link riaperto dopo la decisione → "Richiesta già gestita il gg/mm:
  approvata/rifiutata" (idempotente).
- Link scaduto, manomesso o token non più corrente → pagina neutra "Link
  non più valido".

### Rinnovo annuale

`deleghe:rinnovi` — il 1° di ogni mese:

1. Deleghe `attive`, `valida_fino_at` entro la fine del mese successivo,
   `rinnovo_inviato_at` NULL, raggruppate per istituto. Un token per
   istituto. **Una email per scuola** con l'elenco dei nominativi e un
   link: "Queste persone sono delegate a segnalare per la vostra scuola:
   indicate chi lavora ancora con voi."
2. Pagina `GET /deleghe/rinnovo/{token}` (stesse garanzie della pagina di
   decisione): **una riga per persona/delega** con plesso e scadenza e
   **due pulsanti per riga: Conferma · Revoca**. Nessun "conferma tutti":
   ogni persona va decisa. Righe già decise mostrano l'esito.
   - Conferma → `valida_fino_at` = oggi + validità, `rinnovo_inviato_at`
     NULL, storico `rinnovata`.
   - Revoca → `revocata`, `decisa_via = segreteria`, email al delegato.
3. Delegato con delega non ancora confermata a
   `deleghe_giorni_avviso_delegato` dalla scadenza → email: "La tua delega
   per IC X scade il gg/mm e la segreteria non l'ha ancora confermata:
   sollecitala."

### Scadenze

`deleghe:scadenze` — giornaliero:

- `attive` con `valida_fino_at` passata → `scaduta`, email al delegato;
- `richiesta` oltre `token_scadenza_at` → `scaduta`;
- `richiesta` con `richiesta_inviata_at` NULL → invio (rispettando il tetto);
- avvisi al delegato del punto 3 del rinnovo.

### Admin → Deleghe

- Elenco con filtri stato/istituto/persona/CF; riquadro "istituti attivi
  senza email configurata".
- **Pre-delega** (go-live e casi noti): CF + istituto + plessi opzionali +
  motivo → righe `attiva` con `user_id` NULL, `decisa_via = admin`.
  Agganciate automaticamente al primo login SPID di quel CF (storico
  `agganciata`), dopo la verifica email. Nessuna email alla segreteria.
- **Attiva d'ufficio** una richiesta pendente, con motivo obbligatorio.
- Revoca manuale con motivo (email al delegato).
- Reinvio email alla segreteria (rigenera token).
- Sblocco CF bloccati.

### Il delegato

- "Le mie deleghe": stato e scadenza di ciascuna, rinuncia (→ `revocata`,
  `via = utente`).
- Visibilità: segnalazioni dei plessi coperti da deleghe `attive`
  (plesso NULL → tutti i plessi dell'istituto). Form di creazione: select
  plessi limitata a quelli coperti.
- Revoca/scadenza: la visibilità sparisce alla richiesta successiva (lo
  scope legge le deleghe a ogni query), senza logout forzato.

## Visibilità — modifica a `Segnalazione::scopeVisibileA`

Nuovo ramo per `auth_source = spid` prima del ramo segnalatore legacy:

```php
if ($user->auth_source === 'spid') {
    return $query->whereIn('id_plesso', Delega::plessiCopertiDa($user));
}
```

`Delega::plessiCopertiDa(User)` restituisce una subquery: plessi delle
deleghe attive per-plesso ∪ tutti i plessi degli istituti con delega
attiva su istituto intero. Gli altri rami (admin, operaio, gestore,
impresa, segnalatore legacy) invariati. Stessa regola applicata a
`SegnalazionePolicy` e al form di creazione.

## Sicurezza

- Token segreteria: 64 caratteri casuali, solo hash SHA-256 a DB,
  `hash_equals`, scadenza, rigenerato a ogni invio (i link precedenti
  smettono di funzionare). URL firmato contro la manomissione dei
  parametri.
- Azioni solo via POST + CSRF; throttle sulle pagine segreteria.
- OIDC: `state`/`nonce` monouso in sessione Laravel (legata al browser via
  cookie: niente login CSRF), sessione rigenerata post-login, id_token
  sempre verificato via JWKS del proxy, mai con segreti nostri.
- LDAP: password vuota rifiutata prima del bind; filtri con escape di
  LdapRecord; `LDAP_TLS_SKIP_VERIFY=true` con `APP_ENV=production` →
  warning all'avvio.
- Mock (`LDAP_HOST=mock`, `OIDC_MOCK=true`) con `APP_ENV=production` →
  eccezione all'avvio nel service provider.
- Dati personali: il CF compare nell'email alla segreteria (serve per
  riconoscere la persona, va a casella istituzionale) e nelle pagine
  segreteria/admin; mai nei log applicativi (solo id utente/delega).
- Audit completo in `deleghe_storico`.

## Errori

| Caso | Utente vede | Sistema |
|---|---|---|
| Proxy non raggiungibile / OIDC non configurato | "Accesso SPID/CIE temporaneamente non disponibile" | Sentry |
| id_token non valido / state errato | "Accesso non riuscito, riprova" | Sentry (warning) |
| AD non raggiungibile | "Autenticazione dipendenti non disponibile" (ditte e admin locale funzionano) | Sentry |
| Istituto senza email | "Scuola non configurata, l'ente è stato avvisato" | Notifica admin |
| Oltre tetto giornaliero | "Richiesta inviata alla segreteria domani mattina" | Avviso admin |
| Email non consegnabile | — | Job fallito → alert `CheckFailedJobs` esistente |

Nessun account d'emergenza: AD è la fonte di verità per il personale
dell'ente, incluso l'admin. Se AD non risponde, i problemi dell'ente sono
più gravi di ProntoPA; le ditte (locali) continuano comunque a lavorare.
Il primo admin di un'installazione nuova è chiunque sia nel gruppo
`PRONTOPA_ADMIN` al suo primo login.

## Test

**Unit**
- `ClaimsSpid`: `TINIT-<CF>`, claim URI eIDAS, CF assente, nome da
  `given_name`/`family_name` senza `name`.
- `OidcClient` verifica id_token contro JWKS RSA generato al volo: firma
  manomessa, `iss`/`aud` errati, scaduto, `nonce` errato, senza `kid` con
  JWKS a una chiave (ok), senza `kid` con più chiavi (rifiutato).
- `MappaGruppiLdap`: precedenze, URP, nessun gruppo.
- Copertura plessi (`Delega::plessiCopertiDa`, "plesso scoperto").

**Feature**
- Callback OIDC con `Http::fake` del proxy: percorso felice, `state`
  errato/riusato, `client_secret_basic` (header verificato, nessun secret
  nel body), userinfo con `sub` diverso, stesso CF con `sub` diverso.
- Completa profilo + verifica email; aggancio pre-delega.
- Instradamento form: con `@` → locale per email, senza `@` → LDAP;
  utente `ldap`/`spid` con email digitata → nessun login locale.
- 2FA email ditte: codice corretto/errato/scaduto, 5 tentativi, throttle
  reinvio.
- LDAP con fake LdapRecord: percorso felice, password vuota, nessun gruppo,
  senza `mail`, aggancio legacy per email (0/1/2 risultati), rimozione
  gruppo.
- Richiesta delega: tutti i controlli; salto del tetto per plesso
  scoperto; coda oltre tetto.
- Pagina segreteria: GET non modifica nulla; approva/rifiuta/blocco;
  idempotenza; token vecchio dopo reinvio; firma manomessa.
- Rinnovo: raggruppamento per istituto, decisione per riga, avviso al
  delegato, scadenze.
- Visibilità: delegato plesso, delegato istituto, delega revocata.

**Dusk**
- Login SPID mock → completa profilo → verifica email (Mailpit) →
  richiesta delega → approvazione dalla pagina segreteria → segnalazione
  sul plesso delegato.
- Login LDAP mock per ogni ruolo.

**Smoke test manuale** contro `pa-sso-proxy` reale e AD dell'ente prima del
rilascio (in `palestre` tre bug reali sono emersi solo così: issuer,
`kid` assente, claim solo da userinfo).

## Fasi di consegna

Ogni fase è rilasciabile da sola.

| Fase | Contenuto |
|---|---|
| **1 — Dipendenti via AD + ditte via email** | `ext-ldap` nei Dockerfile, LdapRecord, `LdapLoginService`, `MappaGruppiLdap`, instradamento form login (`@` → locale per email), 2FA via email per le ditte, colonne `auth_source`/`ldap_guid`/`two_factor_metodo`, `password` nullable, impostazioni gruppi, mock dev, rimozione wizard `/setup`, test |
| **2 — Scuole via SPID/CIE + deleghe** | `OidcClient`, `SpidLoginService`, completa profilo + verifica email, `deleghe` + `deleghe_storico`, `DelegaService`, email e pagine segreteria, rinnovo e scadenze, Admin → Deleghe con pre-delega, visibilità, impostazioni OIDC, mock SPID, test + Dusk |
| **3 — Cutover** | Comando `utenze:cutover` (default dry-run): disattiva segnalatori `locale`; elenca utenti `locale` non ditta (admin locali compresi) per decisione manuale; report ditte `locale` attive con email mancante o duplicata (da sistemare, altrimenti non possono entrare) e imposta `two_factor_metodo = email` su quelle senza 2FA; report istituti attivi senza `email`. Rimozione `/register` e `accounts:annual-check` dallo scheduler. Aggiornamento `CLAUDE.md`, `CHANGELOG.md`, `TODO.md` |

## Fuori scope

- SPID persona giuridica per le ditte.
- Deleghe gerarchiche (dirigente che delega/revoca).
- Rimozione fisica delle colonne `annual_verification_*` (release successiva).
- Retention GDPR e staging (decisioni/risorse dell'ente).
