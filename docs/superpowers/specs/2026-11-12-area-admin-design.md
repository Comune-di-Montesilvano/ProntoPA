# Area amministrazione rifatta: Stato del sistema, Persone, Enti e sedi, Regole, Ente e integrazioni

Data: 2026-11-12 · Stato: design, da approvare · Release: **un'unica patch 0.9.x** (decisione committente)

Progetto 2 di `2026-11-11-ruoli-smistamento-design.md` ("riorganizzazione area Impostazioni/admin").

## Perché

Parole del committente sull'area admin di oggi: "Non si capisce niente. È un'accozzaglia di cose
legacy e nuove, forse conviene rifarla da zero."

Stato attuale (ricognizione 2026-11-12):

- Menu "Amministrazione" con 9 voci piatte (Pannello, Impostazioni ente, Organizzazioni, Sedi,
  Anagrafe MIUR, Backup, Deleghe, SLA, Profili e provenienze). **Utenti non è nel menu**: ci si
  arriva solo da un link nel Pannello admin. Squadre e Tipologie dei gestori stanno in un blocco
  "Organizzazione" separato (condiviso con i supervisori).
- `admin/impostazioni.blade.php` è un unico form generato dalla tabella `impostazioni`: ~50 campi
  in 15 gruppi, ognuno con la chiave tecnica visibile (`osm_zoom`), un `match` sul gruppo per i
  titoli, casi speciali sparsi (`publication_auto_state_id` chiesto come "ID stato", logo, SPID).
  Mescola aspetto, regole di lavoro e integrazioni tecniche.
- Scuole spezzate in tre pagine (Organizzazioni = istituti, Sedi = plessi, Anagrafe MIUR).
- L'orario d'ufficio e il patrono, che servono solo agli SLA, stanno in Impostazioni, lontani
  dalla pagina SLA.
- Legacy: Profili (`profili`, `users.id_profilo`, `limita_istituto`) del vecchio programma;
  `resources/views/layouts/navigation.blade.php` non incluso da nessuna vista;
  impostazione `telegram_notifica_stato_critico` non letta da nessuno; due colonne doppie su
  `istituti` (`tipo` stringa default "Scuola" e `tipo_ente` enum validato nel controller:
  comune/scuola/asl/provincia/regione/altro).

## Chi la usa e come (il committente si è descritto così)

L'admin è l'informatico del Comune, che è anche gestore di una tipologia (rete telefonica/dati).
Entra soprattutto per **controllare che tutto funzioni** (sync AD, backup, SPID, email) e per
**sistemare persone** (un utente, una delega di scuola). Le integrazioni si configurano una volta
e si riaprono solo quando qualcosa si rompe. Aggiunge enti a mano quando non sono su MIUR (caso
reale appena capitato: gli asili comunali): crea l'ente, poi le sedi.

## Obiettivi

- **A1** Home admin = **Stato del sistema**: si capisce in 5 secondi se va tutto bene.
- **A2** Menu in 4 blocchi chiari al posto delle 9 voci piatte.
- **A3** Ogni pagina scritta a mano con spiegazioni in italiano, niente chiavi tecniche visibili,
  niente ID numerici da digitare.
- **A4** Utenti **separati per tipo** (dominio, scuole SPID, ditte): "così è troppo dispersivo".
- **A5** Enti e sedi in una sezione sola, con **categorie personalizzabili** dall'admin (scuole,
  asili comunali, uffici comunali, impianti...): "non lo fisserei nel codice".
- **A6** Via il legacy: Profili, provenienze modificabili, vecchio menu, chiave Telegram morta,
  doppione `tipo`/`tipo_ente`.

## Non obiettivi

- Dashboard di ingresso per supervisore/gestore con criticità SLA e code (progetto 3).
- Cambiare il comportamento di sync AD, deleghe, SLA, avvisi, backup: solo dove stanno e come si
  presentano. Le regole di dominio restano quelle di `CLAUDE.md`.
- Spostare in Impostazioni cose che oggi stanno in env (gruppi `LDAP_GRUPPO_*`, credenziali sync,
  `BACKUP_PASSPHRASE`): restano in env; la UI al massimo le **mostra** (configurata sì/no).
- Rifare imprese, appalti, statistiche (menu del gestore, invariati).

## Anti-obiettivi (fallimento anche se "funziona")

- Un'impostazione che oggi esiste e funziona e che dopo il rilascio non si trova più da nessuna
  parte, o il cui valore salvato si perde nella migrazione.
- Un segreto (secret SPID, token Telegram, secret webhook) mostrato in chiaro o sovrascritto con
  vuoto salvando una pagina.
- Rinominare o cancellare una categoria di enti che cambia chi vede cosa o rompe le deleghe SPID.
- Una segnalazione esistente che perde la sua provenienza o la sua sede.
- Un riquadro "verde" in home quando la cosa è rotta (meglio grigio "non verificabile" che
  verde falso).

## Struttura del menu (blocco "Amministrazione", solo admin)

```
Stato del sistema            ← home admin (route admin.dashboard)
Persone
  Utenti di dominio
  Scuole (SPID)
  Ditte (SPID)
  Deleghe delle scuole
  Squadre                    ← anche supervisori (resta in organizzazione.*)
  Tipologie dei gestori      ← anche supervisori (resta in organizzazione.*)
Enti e sedi
Regole di lavoro
  Scadenze
  Segnalazioni
  Avvisi
Ente e sistema
  Aspetto
  Integrazioni
  Backup
```

Il supervisore non admin continua a vedere solo il suo blocco con Squadre e Tipologie dei gestori
(gate `gestisci-organizzazione`, invariato). Le rotte possono cambiare nome/percorso; i vecchi URL
più usati (`/admin/impostazioni`, `/admin/utenti`, `/admin/organizzazioni`, `/admin/sedi`) devono
reindirizzare alla nuova pagina corrispondente, non dare 404 (link nei bookmark e nelle email).

## A1 · Stato del sistema

Griglia di riquadri. Ogni riquadro: titolo, stato (verde ok · giallo attenzione · rosso problema ·
grigio non verificabile), una riga di spiegazione in italiano, link alla pagina che lo risolve.
**Si mostrano solo le funzioni accese/configurate**; quelle spente stanno in fondo come elenco
compatto "Non attive: Telegram, AI, Antivirus" con link a Integrazioni.

| Riquadro | Verde | Giallo | Rosso | Fonte |
|---|---|---|---|---|
| Sync AD | ultimo giro ok < 36 h | ok ma `senzaEmail`/`usernameOccupati`/`errori` > 0, oppure bloccata | fallita, o nessun giro da > 36 h | `SincronizzaUtentiAd::ultimoEsito()`; assente se sync non configurata |
| Backup | come allarme attuale | | allarme attuale (fallito o > 36 h) | logica esistente di dashboard/pagina Backup |
| SPID/CIE | `OidcConfig::problemi() === []` | | problemi presenti | esistente |
| Email | mittente configurato | | mittente vuoto | `mail_from_address` (nessun invio di prova automatico) |
| Telegram / Webhook / AI / Antivirus | configurato e raggiungibile se già verificabile senza effetti collaterali | | configurato ma non raggiungibile | solo se acceso; dove non c'è un controllo innocuo → grigio |
| Anagrafe MIUR | indice scaricato | indice vecchio (soglia: lasciata al builder, es. > 1 anno) | | file `miur/*.json` |
| Tipologie orfane | 0 | ≥ 1 (elenco) | | `CoperturaTipologie` |
| Deleghe in attesa | 0 | ≥ 1 in attesa della segreteria | | modello `Delega` |

Nessun riquadro deve fare chiamate di rete lente sul caricamento della pagina: se un controllo
richiede rete (Ollama, clamd, Telegram), o lo si fa con timeout breve (≤ 2 s) e risultato in cache
di pochi minuti, o si mostra grigio con pulsante "verifica ora" nella pagina Integrazioni.

## A4 · Persone

Tre elenchi separati, ognuno con le sue colonne e azioni. Filtro "mostra disattivati" e ricerca
per nome/username/CF su ognuno.

- **Utenti di dominio** (`auth_source = ldap`): nome, username, ruolo (+ supervisore), tipologie
  per i gestori, ultimo accesso, stato. In testa riquadro esito sync + "Sincronizza ora" (già in
  0.9.16). Modifica: solo telefono (profilo sparisce). Tutto il resto in sola lettura con nota
  "Gestito da Active Directory" (comportamento 0.9.16).
- **Scuole (SPID)** (`auth_source = spid`, non ditta): persona, CF, email verificata sì/no, scuole
  delegate (dalle deleghe attive), stato. Link alle sue deleghe. Disattivazione manuale permessa
  (gate `cambia-attivo-utente` già lo consente per non-ldap).
- **Ditte (SPID)** (persone con ruolo `impresa`): persona, CF, ditta. Solo consultazione + link
  alla scheda della ditta, dove si gestiscono (regola `DelegaImpresaService`, invariata).
- Account `locale` legacy (tutti disattivati dalla 0.8.19): **nessuna vista**. *Scelta del
  designer.* Restano a DB, non si eliminano (storico).
- Deleghe delle scuole: pagina esistente, solo spostata nel blocco.
- Squadre: pagina esistente; l'interruttore `squadre_enabled` si sposta qui (in cima alla pagina).

## A5 · Enti e sedi

Una sezione sola al posto di Organizzazioni + Sedi + Anagrafe MIUR.

- **Elenco enti** raggruppato/filtrabile per categoria; ogni riga si espande e mostra le sue sedi
  (etichetta MIUR / a mano).
- **"Aggiungi ente"** con due strade:
  - *Da MIUR*: cerca per codice o nome nell'indice scaricato, importa l'istituto con i plessi in
    un colpo (logica `SincronizzaScuole` esistente).
  - *A mano*: per asili comunali, paritarie, il Comune stesso, ecc. Crei l'ente e nella **stessa
    pagina** aggiungi le sedi, senza cambiare menu.
- **Scheda ente**: dati in alto, sedi sotto modificabili lì. Campi da MIUR bloccati con
  "aggiorna da MIUR" (regole `fonte_dati = miur` esistenti). "Unisci doppione" sulla sede
  (`admin.sedi.unisci`, esistente). Flag "accetta deleghe SPID" (`richieste_delega_spid`) sulla
  scheda ente.
- **Scaricamento anagrafe MIUR**: pulsante dentro la sezione, non più voce di menu.
- **Il Comune** è un ente come gli altri, nella sua categoria, con le sue sedi (municipio,
  uffici, magazzino...). Conferma del committente: "anche municipio e uffici comunali".

### Categorie di enti (personalizzabili)

- Nuova tabella categorie (nome, ordine) gestita dall'admin dentro Enti e sedi; `istituti` ha
  una FK alla categoria che sostituisce **sia** `tipo` **sia** `tipo_ente`.
- Migrazione dati (*scelta del designer*, per non perdere distinzioni scritte a mano come gli
  asili comunali appena inseriti), regola in quest'ordine:
  1. `fonte_dati = miur` → categoria **"Scuole statali"**;
  2. altrimenti `tipo` valorizzato e diverso dal default `Scuola` → categoria con quel nome
     (testo libero dell'admin, raggruppato ignorando maiuscole/spazi);
  3. altrimenti → categoria dall'etichetta leggibile di `tipo_ente` (scuola → "Scuole",
     comune → "Comune", asl → "ASL", provincia → "Provincia", regione → "Regione",
     altro/null → "Altro").
  Nessun ente senza categoria dopo la migrazione; categorie vuote non create.
- I nuovi import MIUR vanno nella categoria marcata come "categoria degli import MIUR" (flag
  sulla categoria, inizialmente su "Scuole statali"): rinominarla non rompe l'import.
- *Scelta del designer:* **il comportamento resta sull'ente, non sulla categoria**. Deleghe SPID
  (`richieste_delega_spid`) e dati MIUR (`fonte_dati`) restano per ente. La categoria serve solo
  a raggruppare e filtrare: elenco enti e select della sede nelle segnalazioni
  (`Plesso::etichetta()`/`dettagli()` raggruppati per categoria). Rinominare o cancellare una
  categoria non cambia visibilità né deleghe. Una categoria con enti dentro non si cancella.

## Regole di lavoro

Tre pagine:

1. **Scadenze**: regole SLA come oggi (`SlaController`, per tipologia/priorità, soglie inattività)
   + **orario d'ufficio, patrono e anteprima festivi dell'anno** (oggi in Impostazioni).
   Comportamento invariato: cambio regole/orario/patrono → `RicalcolaScadenzeSla`; validazioni
   attuali di `ImpostazioniController::validaOrario` mantenute.
2. **Segnalazioni**: riapertura entro N giorni (`reopen_days_limit`); anti-duplicato (on/off,
   raggio metri, finestra giorni, ogni quante adesioni sale la priorità); pubblicazione automatica
   con lo **stato scelto per nome** (select) + "Applica alle esistenti" (esistente);
   etichetta "ufficio segnalante".
3. **Avvisi**: digest mattutino (on/off, ora, salta weekend) — **il digest resta** (committente:
   "serve ancora, tienilo"); quali eventi notificare su Telegram (le 3 chiavi lette; la quarta,
   `telegram_notifica_stato_critico`, si elimina).

## Ente e sistema

- **Aspetto**: nome ente, logo (file caricato con precedenza sull'URL, come oggi), colori con
  anteprima, sito, mittente email (`mail_from_*`), mappa (centro e zoom **scelti cliccando su una
  mappa Leaflet**, non scrivendo lat/lng; Leaflet già disponibile da `public/build/vendor`).
- **Integrazioni**: una scheda per ognuna, ciascuna con stato (attivo/non attivo/problema), motivo
  in italiano e, dove ha senso, pulsante "prova":
  SPID/CIE (issuer, client id, secret cifrato, redirect URI da copiare, problemi) ·
  Telegram (token, username bot, webhook) · Webhook verso il sito (URL, secret) ·
  AI locale (on/off, URL Ollama, modelli) · Antivirus (on/off; spiegare che serve il profilo
  Docker `security`) · Allegati (dimensione max, numero max, formati, disco locale/S3) ·
  Anagrafe MIUR (URL indice, comune di default) · Deleghe (i 6 parametri `deleghe_*`).
  Segreti: mai mostrati; campo vuoto = non modificare (comportamento attuale).
  Sync AD: scheda in sola lettura che dice se è configurata (env) e rimanda a Utenti di dominio.
- **Backup**: pagina esistente, spostata nel blocco.

Il vecchio form generico `admin/impostazioni.blade.php` **sparisce** a fine lavoro: ogni chiave
oggi presente in `ImpostazioniSeeder` deve avere un posto in una delle pagine nuove (verificarlo
con un test che confronta le chiavi del seeder con quelle gestite dalle pagine), salvo quelle
eliminate esplicitamente qui.

## Pulizia legacy

- **Profili** eliminati: pagina, rotte, `ProfiliController`, modello `Profilo`, colonna
  `users.id_profilo` e tabella `profili` (migration). Regola del committente: "il profilo non è
  nient'altro che l'ente per cui scrivo. Ergo dominio = Comune". L'ente per cui uno scrive si
  ricava dal tipo di account: dominio → Comune; SPID con delega → scuola della delega; ditta →
  la ditta. Il codice che oggi legge `profilo->limita_istituto`
  (`SegnalazioneController::create` ~79, `Segnalazione::scopeVisibileA` ~410, `ImportLegacy`)
  perde quel ramo. Gli utenti legacy sono tutti disattivati (committente: "utenti legacy sono
  spariti"). Caso limite segnalato in revisione: un utente AD che al primo login ha agganciato un
  account legacy con `id_profilo` limitato a un istituto. Per la regola del committente
  (dominio = Comune) quella limitazione è un residuo da togliere: dopo il rilascio quell'utente
  vede le proprie segnalazioni come ogni segnalatore interno e sceglie tra tutte le sedi.
  Controllo facoltativo prima del deploy (console MariaDB in Portainer):
  `SELECT u.username FROM users u JOIN profili p ON p.id_profilo = u.id_profilo WHERE u.attivo = 1 AND p.limita_istituto = 1;`
  La migration logga (`Log::info`) gli username attivi che perdono il profilo, per traccia.
- **Provenienze**: pagina admin eliminata. *Scelta del designer, comunicata al committente senza
  obiezioni:* il codice dà per scontato che URP sia `id_provenienza = 3` e AD assegna URP/interno;
  modificarle a mano può rompere lo smistamento. Restano come dati di riferimento caricati da
  `TabelleRiferimentoSeeder`; le segnalazioni esistenti tengono la loro provenienza; form e API
  continuano a usarle.
- `resources/views/layouts/navigation.blade.php`: eliminato.
- `telegram_notifica_stato_critico`: riga eliminata da DB (migration) e dal seeder.
- `istituti.tipo` e `istituti.tipo_ente`: sostituiti dalla categoria (sopra), poi eliminati.

## Vincoli tecnici

- Convenzioni `CLAUDE.md`: autorizzazione via Policy/Gate (niente ruoli nel controller), migration
  datate e additive dove possibile (le eliminazioni di colonne vengono dopo la copia dei dati,
  stessa release), `AllineamentoSeeder` idempotente e mai su valori configurati.
- Componenti `x-pa.*` e token `.pa-*` dello stile 0.9.0; niente CDN; Font Awesome batte le utility
  Tailwind sugli `<i>` (avvolgere in span).
- Mobile: le pagine devono funzionare sotto 768px (l'admin a volte entra da telefono? non
  confermato: almeno nessun overflow orizzontale).
- `Impostazione::CHIAVI_INTERNE` (`ripristino_id`, `ente_logo_file`, `ldap_sync_esito`) mai
  esposte.
- Release: una sola patch, bump `publiccode.yml` + CHANGELOG nella stessa PR; aggiornare
  `CLAUDE.md` (tabella brandizzazione, architettura controller, sezione ruoli/menu).
- Test: per ogni pagina nuova, accesso (admin sì, gestore/supervisore no salvo
  Squadre/Tipologie), salvataggio di ogni chiave, segreti non sovrascritti da vuoto; test di
  copertura chiavi seeder ↔ pagine; redirect dei vecchi URL.

## Lasciato al builder

- Nomi di rotte, controller, viste; layout delle pagine (stile 0.9.0).
- Soglie esatte "vecchio" per l'indice MIUR, cache dei controlli di rete.
- Come mostrare il blocco Persone a un supervisore (solo le due voci condivise).
- Suddivisione in commit/PR interne, purché il rilascio sia unico.
