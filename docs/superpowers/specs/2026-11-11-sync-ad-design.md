# Sincronizzazione utenti da Active Directory (0.9.14) — design

Sostituisce la vecchia "Fase C" di `2026-11-11-ruoli-smistamento-design.md` (pre-registrazione con la password AD dell'admin): non si fa più.

## Perché

Oggi un dipendente esiste in ProntoPA solo dopo il suo primo login, e ruolo/email/attivo si ricalcolano solo al login. Conseguenze viste in produzione:

- l'admin non può assegnare le tipologie a un gestore finché quello non è entrato almeno una volta (pagina "Tipologie dei gestori" vuota);
- chi viene tolto dai gruppi `PRONTOPA_*` o disabilitato in AD resta `attivo` con il vecchio ruolo e **continua a ricevere email** (avvisi 0.9.13) finché un admin non lo disattiva a mano.

Obiettivo del committente: AD è la fonte di verità, ProntoPA si allinea da solo, nessun caricamento manuale.

## Decisioni del committente

1. **Account di servizio AD in sola lettura**, usato solo per leggere i membri dei gruppi. Mai per autenticare persone (il login resta bind con le credenziali dell'utente).
2. **Notturna + pulsante "Sincronizza ora"** (Admin → Utenti) per i casi urgenti.
3. **Chi esce da tutti i gruppi `PRONTOPA_*`, è disabilitato o eliminato in AD → disattivato** (`attivo=false`). Account e storico restano. Le sue tipologie restano in `gestori_tipologie` ma non contano (copertura solo su attivi): se nessun altro gestore attivo le copre tornano orfane e scatta l'alert esistente.
4. **AD comanda.** Per gli account `auth_source=ldap` la disattivazione/riattivazione manuale sparisce (pulsante in Admin → Utenti e campo `attivo` nel form di modifica). Per bloccare qualcuno lo si toglie dal gruppo. Resta per gli account `spid`/`locale`.

## Comportamento

### Chi viene sincronizzato

Membri (anche annidati, `LDAP_MATCHING_RULE_IN_CHAIN`) di almeno uno dei gruppi in `config('ldap.gruppi')`, oggetti `user` sotto `LDAP_BASE_DN`. Per ciascuno: `objectguid`, `samaccountname`, `displayname`, `mail`, gruppi `PRONTOPA_*` di appartenenza, stato abilitato (`userAccountControl` bit `0x2` ACCOUNTDISABLE).

Ruolo = `MappaGruppiLdap::risolvi()` (stessa precedenza del login).

Ricerca paginata (AD restituisce al massimo 1000 voci per pagina): un elenco troncato equivale a disattivazioni sbagliate.

### Per ogni persona trovata, abilitata, con ruolo

Stessa scrittura del login: estrarre da `LdapLoginService::sincronizza()` la parte "applica identità+ruolo all'utente" in un servizio condiviso (es. `Services/Auth/AllineaUtenteAd`), usato sia dal login sia dalla sync, così le due strade non divergono. Differenze rispetto al login:

- `last_login` **non** viene toccato dalla sync;
- **senza email** in AD → saltata (non creata; se esiste resta com'è) e conteggiata nel report come "senza email". Il login continua a rifiutarla come oggi;
- **username già usato da un altro account** → saltata, `Log::warning`, conteggiata nel report (stessa regola del login, nessuna eccezione bloccante sul giro intero);
- aggancio account legacy `locale` per email: stessa regola del login (unico, non ditta).

### Chi va disattivato

Utenti `auth_source=ldap`, `attivo=true`, il cui `ldap_guid`:
- non compare tra i membri dei gruppi, oppure
- compare ma l'account AD è disabilitato.

→ `attivo=false`, ruoli e flag **non** toccati (se torna nel gruppo la sync/il login li ricalcolano). Persone eliminate da AD rientrano nel primo caso.

Chi ricompare abilitato nei gruppi → riattivato e ruolo ricalcolato.

### Cambio di ruolo

Gestore che passa a un altro ruolo (operaio, supervisore, admin…): `gestori_tipologie` e `gestore_tutte_tipologie` **azzerati** (*scelta del builder*: chiude il minor rinviato in 0.9.11 "ex-gestore ritrova le vecchie tipologie se torna gestore"). Eccezione: chi diventa `admin` mantiene le tipologie esplicite (gli admin possono averne, 0.9.13), perde solo `tutte`. Vale sia in sync sia al login (sta nel servizio condiviso).

La disattivazione invece **non** azzera le tipologie: chi torna (es. dopo un'assenza) le ritrova.

### Sicurezze (scelte del builder)

La sync non deve mai disattivare in massa per un problema di AD o di configurazione:

- AD irraggiungibile, bind del service account fallito, errore di ricerca → **nessuna scrittura**, esito "fallita", `Log::error` (→ Sentry).
- Nessun membro trovato in nessun gruppo → **nessuna disattivazione** (solo creazioni/aggiornamenti, che a quel punto sono zero), esito "fallita" con motivo "0 membri: controlla base DN e nomi dei gruppi".
- Il giro che disattiverebbe **più del 50% degli utenti `ldap` attivi** (con almeno 5 utenti `ldap` attivi) non disattiva nessuno, esito "bloccata" con il numero. Il pulsante "Sincronizza ora" in quel caso offre "Sincronizza comunque" (conferma esplicita), la notturna no.
- Nessuna eccezione per l'admin che lancia la sync: AD comanda anche qui, se è fuori dai gruppi viene disattivato come tutti (la soglia del 50% copre gli incidenti).
- Lock: un solo giro alla volta (`Cache::lock` / `withoutOverlapping`).

### Credenziali

Env, accanto al resto della config LDAP (servono prima che esista un admin, come i gruppi):

```
LDAP_SYNC_USERNAME=   # es. svc-prontopa@ente.local o DN completo, usato così com'è nel bind
LDAP_SYNC_PASSWORD=
```

Entrambe anche nel blocco `x-php-env` di `docker-compose.yml`, `config('ldap.sync.*')`. Vuote → sync **spenta**: nessuna voce nello scheduler fa nulla, il pulsante non compare, tutto funziona come oggi (solo login-time). La password non va mai in log/stacktrace (`#[\SensitiveParameter]` + `PasswordNonNeiLogTest`).

Stesso host/porta/TLS/base DN del login (`LDAP_HOST`, `LDAP_STARTTLS`, ecc.). Con `LDAP_HOST=mock` la sync usa i 7 utenti mock (dev, Dusk).

### Esecuzione

- Comando `ldap:sincronizza` (opzioni `--forza` = ignora la soglia 50%, `--prova` = calcola e stampa senza scrivere). Notturno alle **05:30** (dopo il backup delle 02:00, prima di `deleghe:scadenze` 06:30 e `sla:check` 07:00, così gli avvisi del mattino vanno a persone aggiornate).
- Pulsante "Sincronizza ora" in Admin → Utenti → job in coda `default` (non nella richiesta HTTP), messaggio "Sincronizzazione avviata", la pagina mostra l'esito dell'ultimo giro.
- Autorizzazione: solo admin (gate/policy, non controlli di ruolo nel controller).

### Report

Ultimo esito salvato (es. `Impostazione` interna o tabella dedicata, scelta del builder) e mostrato in Admin → Utenti: data/ora, esito (ok / fallita / bloccata), conteggi creati · aggiornati · riattivati · disattivati · saltati (senza email, username occupato), motivo in caso di errore. Esito "fallita" o "bloccata" della notturna → email agli admin attivi (come il backup fallito) + Sentry.

Nessuna email agli utenti creati/disattivati.

### Directory

L'interfaccia `Directory` ottiene un metodo per l'elenco membri (es. `membriGruppi(): list<DirectoryIdentity>` con un campo `abilitato`), implementato da `LdapRecordDirectory` (bind con il service account), `MockDirectory`, `NullDirectory` (eccezione/elenco vuoto → sync fallita) e `tests/Support/FakeDirectory`.

`ldap:prova` stampa anche se le credenziali di sync sono configurate e, con un flag (`--sync`), prova il bind del service account e il numero di membri per gruppo (nessuna scrittura).

## Non obiettivi

- Nessuna scrittura verso AD.
- Nessuna gestione di gruppi da ProntoPA; i nomi restano in env `LDAP_GRUPPO_*`.
- Persone SPID (scuole, ditte) fuori dalla sync.
- Nessuna pre-registrazione manuale, nessuna ricerca AD dalla UI.
- Caposquadra resta deciso sulla squadra.
- Nessun cambio al login (salvo l'uso del servizio condiviso e l'azzeramento tipologie al cambio di ruolo).

## Anti-obiettivi

- Disattivare in massa per un AD irraggiungibile o un base DN sbagliato.
- Divergenza tra ciò che scrive il login e ciò che scrive la sync.
- Password del service account in chiaro in log, Sentry, backup o UI.

## Documentazione

CLAUDE.md (sezione accesso/gruppi AD, Deploy Prod passo 4: service account consigliato), `.env.example`, CHANGELOG 0.9.14, `publiccode.yml` 0.9.14. Testo guida in "Tipologie dei gestori": "i gestori arrivano dal gruppo di dominio dei gestori" (senza "dopo il primo accesso" quando la sync è attiva).

## Lasciato al builder

Nome del servizio condiviso, dove salvare l'esito, forma esatta del metodo Directory, layout del riquadro esito in Admin → Utenti.
