# Ruoli e smistamento: categorie dei gestori, avvisi, pre-registrazione AD

Data: 2026-11-11 · Stato: bozza da approvare · Release prevista: patch 0.9.x (vedi memoria versioning)

## Perché

Il committente (Comune) ha questa organizzazione reale:

- **Dirigente** → ruolo supervisore. Non smista nel quotidiano: monitora, viene
  avvisato quando uno SLA scade, all'occorrenza agisce come un gestore.
- **Gestori** → smistano le segnalazioni agli operai/squadre/imprese, ognuno solo
  per alcune **tipologie** (es. impianti) oppure per tutte.
- **Operai** → organizzati in squadre (esistenti, rese raggiungibili in 0.9.8).

Oggi il programma non lo permette:

1. Il gestore non supervisore vede solo le segnalazioni con `id_operatore_assegnato`
   = sé o aperte da sé (`Segnalazione::scopeVisibileA`): **le nuove (stato 1) non
   le vede**, quindi non può smistarle. Di fatto solo i supervisori smistano.
2. Non esiste un legame gestore ↔ tipologia.
3. Nessuna email immediata alla creazione di una segnalazione (solo digest mattutino).
   Gli avvisi SLA scaduto vanno solo ai supervisori (+ admin), e con il digest attivo
   solo per priorità 4.
4. Un utente AD esiste in ProntoPA solo dopo il suo primo login: l'admin non può
   assegnargli tipologie o metterlo in una squadra prima.

## Obiettivi

- **G1** Tipologie per gestore: ogni gestore ha un insieme di tipologie oppure "tutte".
- **G2** Visibilità: il gestore vede tutte le segnalazioni (qualsiasi stato) delle sue
  tipologie, più quelle assegnate a lui o aperte da lui (regola attuale, invariata).
  Più gestori sulla stessa tipologia vedono tutti tutto; smista chi arriva prima.
- **G3** Avvisi email (tabella sotto).
- **G4** Tipologie orfane (nessun gestore le copre): segnalazioni ai supervisori +
  alert visibile.
- **G5** Pre-registrazione utente AD da parte dell'admin, con lettura dati da AD usando
  la password dell'admin e fallback "sulla fiducia".
- **G6** Tipologie dei gestori e squadre gestibili da **admin e supervisore**.

## Non obiettivi

- Riorganizzazione dell'area Impostazioni/admin: è il **progetto 2**, separato, dopo questo.
- Dashboard di ingresso con criticità SLA, statistiche e code: **progetto 3**.
- Tipologie per operai: agli operai bastano le squadre.
- Account di servizio AD (`LDAP_BIND_DN`): scartato dal committente.
- Assegnazione esclusiva a un solo gestore / code di lavoro / round robin.
- Nuovi canali (Telegram, push): solo email via Laravel Notifications esistenti.

## Anti-obiettivi (fallimento anche se "funziona")

- Il dirigente sommerso di email per ogni nuova segnalazione.
- Una segnalazione che non arriva a nessuno (tipologia orfana silenziosa).
- La password dell'admin che finisce in DB, sessione, log, Sentry o stacktrace.
- Un account pre-registrato con username sbagliato che si aggancia alla persona
  sbagliata, o che blocca il login della persona giusta.
- Rompere l'invariante esistente: il ruolo lo decide **sempre** AD al login.

## Regole di dominio

### Copertura

- Copertura definita a livello di **tipologia** (`tipologie_segnalazioni`), non di
  gruppo (`gruppi_segnalazioni`). *Scelta del builder-designer:* le tipologie sono la
  granularità che il committente ha usato ("impianti" = elettrico/idrico/termico, che
  stanno tutte in EDILIZIA SCOLASTICA insieme ad arredi e infissi). La UI raggruppa le
  tipologie per gruppo con una spunta "tutto il gruppo" per comodità, ma salva tipologie.
- Flag "tutte le tipologie" sul gestore: equivale a coprire anche le tipologie future.
- Una tipologia è **orfana** se nessun gestore attivo la copre (né esplicitamente né
  con "tutte"). Gestore attivo = ruolo `gestore`, `attivo = true`, non supervisore
  (i supervisori vedono comunque tutto e non contano come copertura), **con email**.
  *Scelta del designer:* un pre-registrato letto da AD (ha email) copre già prima del
  primo login, ed è lo scopo della pre-registrazione; un pre-registrato "sulla fiducia"
  (senza email) **non** copre, perché l'avviso non gli arriverebbe. Nella pagina
  orfane compare come "copertura non verificata: X (in attesa del primo accesso)".
- Default `gestore_tutte_tipologie` (*scelta del designer*): `false` per i nuovi
  gestori (le loro tipologie si configurano; finché nessuno le copre scatta l'alert
  orfane); la migration lo mette a `true` sui gestori **già esistenti** (vedi
  domande aperte), così al deploy non diventa tutto orfano.
- Le tipologie restano salvate anche se l'utente cambia ruolo; contano solo mentre ha
  ruolo `gestore`.

### Visibilità (`scopeVisibileA`, `SegnalazionePolicy::view` resta = scope)

| Utente | Vede |
|---|---|
| admin | tutto (invariato) |
| supervisore | tutto (invariato) |
| gestore | segnalazioni delle sue tipologie (o tutte se flag) **∪** `id_operatore_assegnato` = sé **∪** aperte da sé |
| altri | invariato |

**Azioni del gestore della tipologia** (*scelta del designer*, coerente con l'obiettivo
"il gestore smista"): `SegnalazionePolicy::update` (oggi supervisore o
`id_operatore_assegnato` = sé) si estende al gestore che copre la tipologia. Quindi
può: prendere in carico, assegnare a operaio/squadra/impresa, cambiare stato, chiudere,
sospendere, sollecitare, cambiare priorità, accorpare, collegare a precedente.
Restano **solo al supervisore/admin** le azioni che oggi lo sono esplicitamente:
riapertura (`SegnalazioneWorkflowService` ~292) ed eliminazione allegati
(`SegnalazionePolicy` ~178).

### Avvisi email

| Evento | Destinatari |
|---|---|
| Nuova segnalazione (qualsiasi canale: web, API, Telegram, portale, per-conto) | gestori della tipologia + gestori "tutte". Se tipologia orfana → supervisori |
| Tipologia cambiata (triage manuale, triage AI accettato) | gestori della **nuova** tipologia (stessa notifica "nuova", testo che dice "riclassificata") |
| SLA scaduto | gestori della tipologia + "tutte" **e** supervisori (admin esclusi). Sempre immediato, anche con digest attivo |
| SLA a rischio | solo gestori della tipologia + "tutte" (se orfana: supervisori, come oggi) |

- Uno per segnalazione (volume dichiarato: 5–10/giorno per gestore).
- Esclusi: account non attivi; l'utente che ha causato l'evento (chi ha creato o
  riclassificato).
- Il digest mattutino resta com'è (riepilogo), ma le sezioni "non assegnate" del
  gestore vanno filtrate per sue tipologie invece di essere solo dei supervisori.
- Ogni nuova notifica → etichetta in `NotificaSegnalazione::ETICHETTE` (tracciamento v0.9.4).
- Bug esistente da sistemare insieme: in `CheckSlaViolazioni` la query
  `where(supervisore)->orWhere(amministratore)->where(attivo)` include supervisori
  disattivati (precedenza `orWhere`). Destinatari SLA da centralizzare in un servizio
  tipo `DestinatariComune` (es. `Services/Segnalazioni/DestinatariTipologia`).
- *Da decidere (vedi domande aperte):* gli admin continuano a ricevere gli SLA scaduti
  come oggi?

### Tipologie orfane: alert

- Pagina di configurazione tipologie dei gestori: riquadro "Tipologie senza gestore"
  con l'elenco (richiesta esplicita del committente).
- Dashboard del supervisore: stesso avviso, compatto, con link alla configurazione.
- Scheda segnalazione (lettura gestore, visibile a supervisore/admin): banner "Nessun
  gestore copre la tipologia X" quando la tipologia è orfana.

## Gestione tipologie dei gestori (G1, G6)

- Nuova pagina accessibile ad **admin e supervisore** (fuori dal gruppo rotte
  `role:admin`, autorizzazione via Policy/Gate, niente controllo ruoli nel controller).
- Elenco gestori (attivi + pre-registrati) con le loro tipologie; modifica per gestore:
  checkbox raggruppate per gruppo + "tutte le tipologie".
- Vista inversa utile per gli orfani: per ogni tipologia, chi la copre.
- Il supervisore modifica le tipologie di qualsiasi gestore non supervisore
  (i supervisori non hanno tipologie: vedono tutto). *Scelta del designer.*
- Anche le **squadre** (`SquadreController`, oggi sotto `admin.`) diventano accessibili
  al supervisore, gestione **completa** (crea, modifica, caposquadra, membri,
  disattiva): *scelta del designer*, il committente ha detto "anche il supervisore"
  senza limitazioni. Voce di menu per il supervisore in una sezione propria (es.
  "Organizzazione"), l'admin continua a vederle in Amministrazione.
- Storage: tabella pivot `gestori_tipologie (id_utente, id_tipologia_segnalazione)` +
  colonna `users.gestore_tutte_tipologie` boolean default false. Migration additiva.
- Migrazione dati: i gestori **esistenti** non supervisori ricevono
  `gestore_tutte_tipologie = true`? → domanda aperta (sotto). Senza, dopo il deploy i
  gestori attuali vedrebbero meno di prima? No: oggi vedono solo le proprie assegnate,
  quindi vedere "tutte" è un allargamento. Decisione pendente.

## Pre-registrazione AD (G5) — solo admin

### Flusso

1. Admin → Utenti → "Pre-registra utente di dominio".
2. Form: username da cercare (sAMAccountName, normalizzato con `NormalizzaUsernameAd`)
   + **password AD dell'admin** (campo password, `autocomplete="current-password"`).
3. Il server fa bind come l'admin (username dell'admin = il suo `username`, che per
   gli account `ldap` è il sAMAccountName) e cerca l'utente per `samaccountname`
   (non con `filtroIdentita`, che serve a rileggere chi fa bind). Legge objectguid,
   samaccountname, displayname, mail, gruppi annidati (stessa query
   `LDAP_MATCHING_RULE_IN_CHAIN` del login).
4. Mostra: nome, email, gruppi `PRONTOPA_*`, **ruolo risultante** (`MappaGruppiLdap`).
   Se nessun gruppo ProntoPA → avviso "al login sarà rifiutato" ma salvabile.
5. Admin sceglie tipologie (se gestore) e squadra (se operaio), salva.
6. Si crea uno `User` con `auth_source = ldap`, `ldap_guid`, `username`, `name`,
   `email`, ruolo provvisorio = quello letto da AD, `attivo = true`,
   `last_login = null` (= "mai entrato").

### Fallback "sulla fiducia"

Se il bind dell'admin fallisce (credenziali errate → messaggio "password errata",
**nessun** fallback silenzioso in questo caso), l'AD non è raggiungibile, la ricerca è
negata per permessi o l'utente non è trovato:

- avviso chiaro sul motivo (non trovato con AD raggiungibile = "probabile refuso");
- l'admin può salvare comunque con: username + ruolo provvisorio scelto a mano
  (gestore / operaio / segnalatore) + tipologie/squadra;
- account creato con `ldap_guid = null`, `name` = username, `email` vuota/null,
  marcato pre-registrato.

*Scelta del builder-designer:* password errata dell'admin = errore e basta (si
ridigita); il fallback scatta solo quando la password è giusta ma la lettura non va
a buon fine, o quando AD non risponde. Evita che un refuso nella password porti a
creare account "alla cieca".

### Aggancio al primo login

`LdapLoginService::sincronizza` oggi cerca per `ldap_guid`, poi legacy per email, poi
crea; e **rifiuta** il login se lo username è già usato da un altro account. Nuovo
ordine:

1. per `ldap_guid` (pre-registrato con lettura AD rientra qui, nessuna modifica);
2. account pre-registrato **senza** `ldap_guid` con lo stesso `username`
   (case-insensitive), `auth_source = ldap`, mai entrato → aggancio: si scrivono guid,
   nome, email, ruolo da AD come oggi;
3. legacy per email (invariato);
4. nuovo.

Il controllo "username già usato" deve escludere il caso 2. Dopo l'aggancio
l'account è un normale account AD. Ruolo = sempre quello di AD al login: le tipologie
pre-assegnate restano salvate e contano solo se AD lo fa gestore.

### Sicurezza

- Password admin: `#[\SensitiveParameter]` su ogni firma che la riceve, mai in
  sessione/DB/log; aggiungere i nuovi metodi a `tests/Unit/PasswordNonNeiLogTest.php`.
- La ricerca conta nel blocco tentativi del login (`LoginRequest` / `config('auth.login')`,
  chiave = identità dell'admin) così l'admin non si fa bloccare l'account AD facendo prove.
- Richiede admin con `auth_source = ldap` (un admin non AD non ha password AD:
  in quel caso solo fallback).
- Nessuna rotta raggiungibile da non-admin; validazione username stessa regola del
  login (niente filtri LDAP iniettabili: `ldap_escape` sul valore cercato).
- Log: `Log::info` della pre-registrazione con id admin e username creato, mai la password.

### Directory

- Estendere l'interfaccia `Directory` con una ricerca "come utente X":
  es. `cerca(string $chi, #[\SensitiveParameter] string $password, string $username): RisultatoRicerca`
  dove il risultato distingue: trovato (DirectoryIdentity) · non trovato · bind
  rifiutato · ricerca negata · AD non raggiungibile.
- Implementazioni: `LdapRecordDirectory` (reale), `MockDirectory` (dev: mock.* noti +
  qualche utente "mai entrato" da cercare, es. `mock.nuovo.gestore`), `NullDirectory`
  (sempre "AD non configurato" → solo fallback), `tests/Support/FakeDirectory`.

### Elenco utenti

- In Admin → Utenti gli account pre-registrati mai entrati sono distinti (badge
  "in attesa del primo accesso") e filtrabili; quelli senza GUID con badge diverso
  ("non verificato in AD"). Eliminabili finché mai entrati.

## Vincoli tecnici

- Seguire le convenzioni di `CLAUDE.md`: Policy, migration datate additive,
  `AllineamentoSeeder` idempotente se servono permessi nuovi (es. permesso
  `organizzazione.gestisci` per il supervisore), test Feature con
  `withoutMiddleware([...PreventRequestForgery, ...ThrottleRequests])`.
- Niente CDN, componenti `x-pa.*`.
- Versione: patch (0.9.x), bump `publiccode.yml` + CHANGELOG nella stessa PR.
- Notifiche in coda (`ShouldQueue`) come tutte le altre.
- Punto unico per "segnalazione creata" e "tipologia cambiata": evento/observer sul
  model (`created`, `updated` con dirty `id_tipologia_segnalazione`) così copre tutti i
  canali, come lo scan antivirus su `AllegatoSegnalazione::booted()`. Attenzione ad
  `artisan demo` (non deve spedire email: disattivare le notifiche durante il seed demo)
  e all'accorpamento (non genera "nuova").

## Lasciato al builder

- Nomi esatti di classi, rotte, viste, notifiche.
- Layout delle pagine (seguendo lo stile della 0.9.0).
- Se dividere in più PR (consigliato: 1) tipologie+visibilità+orfani, 2) avvisi,
  3) pre-registrazione AD), ognuna con la sua patch.

## Decisioni del committente (dopo revisione)

1. La migration imposta `gestore_tutte_tipologie = true` su tutti i gestori già
   esistenti non supervisori.
2. Gli **admin non ricevono più** gli avvisi SLA (scaduto e a rischio): solo
   supervisori e gestori della tipologia. Cambia `CheckSlaViolazioni` (oggi include
   `amministratore = true`).

## Dopo questo progetto

- Progetto 2: riorganizzazione area Impostazioni/admin.
- Progetto 3: **dashboard di ingresso** per supervisore/gestore con tutte le criticità
  SLA, statistiche, code di lavoro. Qui ci limitiamo all'avviso orfane compatto nella
  dashboard del supervisore esistente.
