# Changelog

Formato basato su [Keep a Changelog](https://keepachangelog.com/it/1.0.0/).
Per il dettaglio funzionalità per release vedi [TODO.md](TODO.md) (roadmap
completata) e [PIANO-SVILUPPO.md](PIANO-SVILUPPO.md) (piano/motivazioni).

## [1.0.0] - 2026-11-13

> **Aggiornamento senza ritorno: prima di aggiornare fare un backup da Admin → Backup
> ("Backup ora").** Le migration cancellano dati (provenienza, dati del chiamante, specializzazioni,
> gruppi di tipologie, coordinate delle segnalazioni) e la versione precedente non riparte sul
> database aggiornato: per tornare indietro si ripristina quel backup.

### Changed
- **Nuova segnalazione rifatta da zero**, uguale per tutti e tutta in vista: sede (obbligatoria,
  indirizzo sotto, già scelta se è una sola), tipologia, segnalazioni simili della stessa sede e
  tipologia con "Anche per me", priorità, titolo, descrizione, "Dove si trova il problema"
  (facoltativo, "Non so" di partenza), foto facoltative. Spariscono "Più dettagli", mappa,
  "Per conto di", provenienza e tipo di intervento.
- Le scuole segnalano solo per le sedi delle loro deleghe, il personale del comune per le sedi di
  enti attivi. API: `id_plesso` obbligatorio, i campi tolti sono ignorati.
- **Tipologie in un elenco unico**: niente più gruppi (né "· EDILIZIA SCOLASTICA" in scheda e
  stampe); le due "SFALCI E POTATURE" diventano una.
- **Appalti legati alle tipologie** come i gestori (elenco o "tutte"): nel pannello Assegna prima
  le ditte con appalto per quella tipologia, sotto "Altre ditte".
- Regole di scadenza su tipologia + priorità (specializzazioni eliminate); l'assistente AI propone
  solo tipologie esistenti.
- Adesioni: una per persona. Chi ha segnalato è sempre un account: le segnalazioni vecchie senza
  account attivo non mostrano più l'autore e non ricevono l'avviso di chiusura.

### Added
- **Admin → Tipologie**: aggiungi, rinomina, unisci, disattiva (il programma non le ricrea più).
- **Coordinate delle sedi**: ricavate dall'indirizzo (OpenStreetMap, "Trova coordinate mancanti" in
  Enti e sedi) o fissate sulla mappa della sede; la mappa della scheda e "Apri in Maps" usano la sede.
  Impostazione "Comune per la ricerca indirizzi" in Aspetto.
- **Rimanda al comune** per operaio, caposquadra e ditta (da Assegnata, Assegnata a ditta,
  Preventivo in attesa), con motivo obbligatorio: torna in carico al gestore che aveva assegnato
  (o Nuova ai supervisori se non c'è più), chi rimanda perde il lavoro, la scadenza resta.

### Removed
- Provenienza, permesso "per conto", nome/email/telefono del chiamante, specializzazioni, gruppi di
  tipologie, coordinate per segnalazione, impostazione "distanza massima" dei simili.

## [0.9.20] - 2026-11-13

### Changed
- **Sospendi tolta**: non fermava la scadenza e Riprendi la ricalcolava dall'apertura, quindi non
  serviva. Le segnalazioni sospese tornano allo stato di prima, con la scadenza che avevano.
- **Scadenza…**: pulsante nel prossimo passo (e cliccando la scadenza in testata) per rimandarla
  con un motivo obbligatorio, che la scuola vede sempre e riceve per email. Il campo scadenza esce
  dal pannello Assegna; "Torna al calcolo automatico" è nello stesso pannello.

### Fixed
- Attività: dopo una sospensione compare "ha ripreso", non una seconda "ha preso in carico".
- L'etichetta "Ditta" sulle note compare solo se una ditta è assegnata.
- "Chi ci lavora" dice "In carico a" quando un gestore se n'è fatto carico, non "Operatore".

## [0.9.19] - 2026-11-13

### Changed
- Iter delle segnalazioni semplificato: si assegna (a sé, a un operaio, a una squadra o a una
  ditta) direttamente da Nuova e si può riassegnare in ogni momento; chi aveva il lavoro prima
  non lo vede più e viene avvisato solo il nuovo.
- Chiudere non chiede più ore, materiali e foto: nota e foto sono facoltative.
- Da Nuova si chiude "senza intervento" (non pertinente, errata, già risolta, altro) con un
  motivo che vede anche chi ha segnalato. "Annullata" diventa "Chiusa senza intervento".
- Fine lavori di operaio o caposquadra chiude subito la segnalazione; quella della ditta chiude
  subito se non c'è stato un preventivo, altrimenti resta al comune da verificare. Foto facoltative.
- Sospendere e rifiutare un preventivo richiedono un motivo.
- Tutte le email sulle segnalazioni hanno nell'oggetto numero, tipologia e titolo e in testa un
  riepilogo; chi riceve il lavoro trova anche testo segnalato, sede, referente, scadenza e la nota
  di chi assegna.

### Added
- Scadenza fissata a mano dal pannello Assegna: non viene più ricalcolata in automatico
  (si torna al calcolo automatico dallo stesso pannello).
- Azioni multiple nell'elenco gestione: seleziona le righe e assegna, chiudi, chiudi senza
  intervento o stampa le schede (fino a 100 per volta).

### Security
- Una ditta non può più eseguire azioni riservate al comune (chiudere, accettare il proprio
  preventivo, assegnare) inviando a mano la richiesta: la competenza dell'azione ora è verificata
  anche all'esecuzione, non solo nel menu.
- Telegram: pulsanti e `/chiudi` solo per chi può aggiornare la segnalazione dalla scheda e solo
  per azioni senza campi da compilare; un'azione rifiutata risponde con il motivo invece di un errore.

### Fixed
- "Riassegna" compariva ma non si poteva usare.
- Riaprire una segnalazione la stacca dalla ditta e dalla squadra di prima.
- L'email di chiusura a scuole e cittadini dice "dal comune" invece del nome di chi ha chiuso.
- Il menu ⋮ dell'elenco non propone più azioni che chiedono un motivo o un pannello.
- Tornare al calcolo automatico con una scadenza già passata fa partire l'avviso di scadenza superata.
- Assegna (scheda e azioni multiple) accetta solo personale del comune attivo e squadre attive con
  le squadre accese, come negli elenchi del pannello.
- Azioni multiple: "0 fatte" è segnalato come errore; un errore imprevisto su una segnalazione non
  ferma le altre e compare tra le saltate.
- Stampa schede delle selezionate raggruppata per ditta, squadra o persona (in ordine di nome), poi
  per sede.
- Digest mattutino: niente più una query per riga per la tipologia.
- Testi allineati ai nomi nuovi degli stati (attività, notifiche tracciate, documentazione API).
- Il digest mattutino usava una funzione SQL solo MariaDB per l'ordinamento.

## [0.9.18] - 2026-11-12

### Changed
- Area admin rifatta in quattro blocchi: **Stato del sistema** (home con riquadri verde/giallo/rosso:
  sincronizzazione AD, backup, SPID, email, MIUR, tipologie senza gestore, richieste di delega,
  Telegram/AI/antivirus; nessuna chiamata di rete, i servizi esterni mostrano l'esito dell'ultima
  prova), **Persone** (utenti di dominio, scuole e ditte in tre elenchi separati; deleghe; squadre e
  tipologie dei gestori), **Enti e sedi** (una sezione sola, categorie personalizzabili, aggiunta
  da MIUR o a mano), **Regole di lavoro** (Scadenze, Segnalazioni, Avvisi), **Ente e sistema**
  (Aspetto, Integrazioni con tasto "Prova", Backup).
- Le impostazioni non stanno più in un form unico: ogni chiave ha la sua pagina e un solo
  salvataggio guidato dal tipo (colori, url, email, orari e segreti validati). Un valore non
  testuale inviato a mano ora dà un errore di validazione invece di un'eccezione.
- I vecchi indirizzi (`/admin/impostazioni`, `/admin/utenti`, `/admin/organizzazioni`,
  `/admin/sedi`, `/admin/sla`, `/admin/profili`, `/admin/provenienze`) reindirizzano alla pagina nuova.
- "Etichetta ufficio segnalante" lasciata vuota ora ricade sul testo predefinito invece di
  restare vuota.
- Salvando una sezione di Integrazioni l'ultimo esito di "Prova" viene cancellato: un esito
  negativo vecchio non sopravvive alla correzione.
- Elenco Ditte: compaiono solo le persone SPID (gli account locali storici non sono in nessun elenco).

### Removed
- Profili (e la pagina Provenienze modificabile), l'impostazione morta
  `telegram_notifica_stato_critico`, le colonne `tipo`/`tipo_ente` degli enti (sostituite dalle
  categorie).
- **Nota di deploy**: la migration dei profili scrive nel log (`laravel.log`) gli utenti di dominio
  che perdono la limitazione legata al profilo: controllarlo dopo l'aggiornamento.
- **Nota di deploy**: tornare all'immagine 0.9.17 dopo l'aggiornamento non è supportato (colonne e
  tabella `profili` rimosse): per tornare indietro si ripristina il backup fatto prima dell'aggiornamento.
- **Nota di deploy**: `ente_logo_url` ora deve essere un indirizzo completo (`https://…`): un
  percorso relativo già salvato blocca il salvataggio di Aspetto finché non lo si corregge (oppure
  si carica il logo come file).
- Segreti (token Telegram, secret SPID e dei webhook): nuova casella "Rimuovi il valore salvato",
  perché il campo lasciato vuoto li lascia invariati e prima non si potevano più togliere.

### Fixed
- Il token del bot Telegram non finisce più nei log: i messaggi di errore HTTP lo riportano
  nell'URL e ora vengono ripuliti prima di essere scritti.

## [0.9.17] - 2026-11-12

### Fixed
- Admin → Impostazioni: l'esito della sincronizzazione AD (dato interno) compariva come campo
  "Generale" con il JSON dell'ultimo giro e, con un motivo lungo, "Salva impostazioni" falliva
  (oltre 500 caratteri) senza salvare nulla. Ora è nascosto e non modificabile dal form.

## [0.9.16] - 2026-11-12

### Changed
- Admin → Utenti → Modifica: per gli utenti di dominio nome, username, email, ruolo,
  supervisore e provenienza sono in sola lettura ("Gestito da Active Directory"); restano
  modificabili telefono e profilo. Prima si potevano cambiare, ma la sincronizzazione li
  riscriveva al giro successivo.
- "Sincronizza comunque" applica solo il numero di disattivazioni che l'admin ha visto e
  confermato: se nel frattempo in AD sono aumentate, il giro si blocca di nuovo e chiede
  una nuova conferma.

### Fixed
- Un errore su un singolo utente non annulla più l'intero giro di sincronizzazione: quell'utente
  resta com'era (e non viene disattivato), gli altri vengono allineati, e l'esito mostra
  "N non allineati per errore".
- "Sincronizza ora" con un giro già in corso o in coda (doppio clic, giro notturno) avvisa invece
  di perdere la richiesta in silenzio; durante il giro la pagina mostra "Sincronizzazione in
  corso". Un giro saltato perché un altro è in corso non sovrascrive più l'esito né manda
  l'email di sync fallita agli admin.

## [0.9.15] - 2026-11-11

### Fixed
- La sincronizzazione AD si fermava con "Gruppi non trovati: PRONTOPA_URP, PRONTOPA_SEGNALATORI"
  quando quei gruppi non esistono in AD. I nomi di default arrivano dal compose anche senza
  variabili nello stack (e una variabile vuota non li toglie), quindi il consiglio di
  "svuotare la variabile" non funzionava. Ora un gruppo assente è ignorato se nessun utente
  attivo ha il ruolo di quel gruppo; la sync si ferma solo se manca un gruppo che ha ancora
  persone (es. un gruppo rinominato), per non disattivarle. `ldap:prova --sync` elenca i
  gruppi non trovati.

## [0.9.14] - 2026-11-11

### Added
- **Sincronizzazione utenti da Active Directory** con un account di servizio in sola lettura
  (`LDAP_SYNC_USERNAME` / `LDAP_SYNC_PASSWORD`, da aggiungere allo stack). Ogni notte alle 05:30,
  e su richiesta con "Sincronizza ora" in Admin → Utenti, ProntoPA legge i membri dei gruppi
  `PRONTOPA_*`: crea gli account (i gestori compaiono subito in "Tipologie dei gestori", senza
  aspettare il loro primo accesso), aggiorna nome, email e ruolo, riattiva chi rientra e
  **disattiva chi è uscito dai gruppi o è disabilitato/eliminato in AD** (niente più email a chi
  non lavora più su ProntoPA). Account e storico restano.
- Protezioni: se AD non risponde, i gruppi risultano vuoti o **un gruppo configurato non
  esiste** non cambia nulla (un gruppo che non usate, es. URP: svuotate la sua variabile
  `LDAP_GRUPPO_*` nello stack, altrimenti la sync segnala l'errore ogni notte); se il giro
  disattiverebbe più della metà degli utenti di dominio si blocca e chiede conferma
  ("Sincronizza comunque"). Esito dell'ultimo giro in Admin → Utenti; se la notturna non riesce
  gli amministratori ricevono un'email.
- `php artisan ldap:prova --sync`: verifica l'account di servizio e conta i membri per gruppo,
  senza scrivere. `php artisan ldap:sincronizza --prova` mostra cosa cambierebbe.

### Changed
- Utenti di dominio: attivazione e disattivazione le decide solo AD (tolti il pulsante
  "Disattiva" e la casella "Account attivo" per questi account). Per bloccare qualcuno lo si
  toglie dai gruppi `PRONTOPA_*`. Gli account SPID restano disattivabili a mano.
- Un gestore che in AD passa a un altro ruolo perde le tipologie assegnate (se diventa
  amministratore tiene quelle esplicite, non "tutte"). Se viene solo disattivato le ritrova
  al rientro.

Senza le due nuove variabili tutto funziona come prima (utenti allineati solo al login).

## [0.9.13] - 2026-11-11

### Added
- **Email "nuova segnalazione"** ai gestori della tipologia, da qualsiasi canale (web, API,
  Telegram, per conto). Se la tipologia non ha gestori arriva ai supervisori. Anche quando
  una segnalazione cambia tipologia ("riclassificata"). Chi l'ha creata o spostata non la
  riceve; i dati demo (`artisan demo`) non mandano email.
- Un **amministratore può essere anche gestore** di alcune tipologie (Organizzazione →
  Tipologie dei gestori): copre quelle tipologie e ne riceve gli avvisi. Agli
  amministratori non si assegna "tutte le tipologie".

### Changed
- **SLA scaduto**: avviso ai gestori della tipologia e ai supervisori, sempre subito (anche
  con il digest mattutino attivo, prima solo per le critiche). Gli amministratori non lo
  ricevono più, salvo per le tipologie di cui sono gestori.
- **SLA a rischio**: avviso ai gestori della tipologia (ai supervisori solo se la tipologia
  non ha gestori).
- Se non c'è nessun supervisore attivo, gli avvisi che spetterebbero ai supervisori vanno
  agli amministratori, così nessuna segnalazione resta senza destinatari.

### Fixed
- Gli avvisi SLA arrivavano anche a supervisori con account disattivato.

## [0.9.12] - 2026-11-11

### Added
- Titolo della segnalazione: campo breve (max 100 caratteri) obbligatorio nel form per
  tutti (comune, scuole, URP), mostrato in elenchi, scheda e riepiloghi al posto
  dell'inizio del testo. Gestori e supervisori lo correggono dalla scheda (matita accanto
  al titolo). Via API resta facoltativo: se manca lo propone l'assistente AI (se attivo),
  che non sovrascrive mai un titolo scritto. I titoli già generati dall'AI diventano il
  titolo della segnalazione; le segnalazioni senza continuano a mostrare l'inizio del testo.

## [0.9.11] - 2026-11-11

### Added
- **Tipologie dei gestori**: ogni gestore si occupa di alcune tipologie (es. impianti) o di
  tutte. Vede e smista le segnalazioni delle sue tipologie, anche quelle nuove da smistare;
  più gestori sulla stessa tipologia vedono tutti tutto. Riapertura ed eliminazione allegati
  restano ai supervisori.
- Nuova area **Organizzazione** (amministratori e supervisori): Tipologie dei gestori e
  Squadre.
- Avviso **tipologie senza gestore**: nella pagina delle tipologie, nella dashboard del
  supervisore e nella scheda della segnalazione. Le loro segnalazioni arrivano solo ai
  supervisori.

### Changed
- I gestori già in servizio partono con "tutte le tipologie": vedono e gestiscono tutte le
  segnalazioni, non più solo quelle assegnate a loro. Restringetele da Organizzazione →
  Tipologie dei gestori.
- Le squadre si spostano da Amministrazione a Organizzazione (`/organizzazione/squadre`).
- Digest mattutino: le segnalazioni da smistare arrivano anche ai gestori, filtrate per le
  loro tipologie.

## [0.9.10] - 2026-11-11

### Changed
- Organizzazioni: la spunta "Ente attivo per registrazioni" è divisa in due.
  **Attiva** = l'ente è usabile (sedi, segnalazioni, deleghe assegnate dall'amministratore).
  **Accetta richieste di delega via SPID/CIE** = le persone esterne la trovano e chiedono
  una delega da sole. Per gli enti senza richieste (es. asili nido comunali) le deleghe si
  danno solo da Admin → Deleghe → predelega. Le nuove organizzazioni create a mano partono
  senza richieste SPID; quelle esistenti e le scuole dall'anagrafe MIUR restano come prima.

## [0.9.9] - 2026-11-11

### Fixed
- Creare un'organizzazione senza codice meccanografico (es. asili nido comunali, uffici)
  dava errore 500: la colonna era obbligatoria nel database pur essendo facoltativa nel
  form. Ora è facoltativa anche a DB, come già per le sedi; i codici vuoti esistenti
  diventano "nessun codice".

## [0.9.8] - 2026-11-11

### Fixed
- La pagina Squadre era raggiungibile solo scrivendo l'indirizzo a mano: ora c'è la voce
  "Squadre" nel menu Amministrazione e nel pannello admin.
- Se le squadre sono disattivate (impostazione "Abilita la gestione squadre di operai",
  spenta di default) la pagina Squadre lo dice e rimanda alle Impostazioni: prima si potevano
  creare squadre che poi non comparivano tra le scelte di assegnazione.

## [0.9.7] - 2026-10-27

### Fixed
- Gli account disattivati (segnalatori legacy `locale`) non ricevono più l'email di cambio
  stato delle loro vecchie segnalazioni: il link portava a un login dove non possono entrare.
  Le segnalazioni restano intatte e visibili alle scuole tramite delega sul plesso. Allineato
  al resto delle notifiche, che già escludevano i disattivati.

### Changed
- Roadmap aggiornata: ProntoPA è in produzione, il cutover è avvenuto in 0.8.19.

## [0.9.6] - 2026-10-27

### Changed
- Priorità più facile da cambiare nella scheda: azione "Priorità…" nel prossimo passo delle
  segnalazioni nuove e in carico, badge in testata con l'icona di modifica, scelta della
  priorità direttamente nel pannello Assegna (la scadenza si ricalcola e resta la nota
  interna "Priorità modificata"). Prima, su una segnalazione nuova, l'unica strada visibile
  era assegnarla.

## [0.9.5] - 2026-10-27

### Security
- Immagini pubblicate solo dopo i controlli: push per digest, scansione Trivy bloccante
  (anche sui tag di rilascio), firma cosign keyless, SBOM e provenance SLSA allegate; i tag
  vengono applicati solo a immagine scansionata e firmata. Verifica in `SECURITY.md`.
- Nuovo workflow `security.yml` (required check): `composer audit`, `npm audit`, Trivy su
  lockfile, segreti e Dockerfile, Trivy sulle immagini buildate dalla PR. L'auto-merge di
  Dependabot non porta più CVE su `main`.
- Stack di produzione con `no-new-privileges` e nessuna capability Linux (`cap_drop: ALL`);
  nginx, mariadb e redis riaggiungono solo quelle che servono all'avvio. Nessuna azione
  richiesta: basta "Update the stack".
- Immagini base (php, composer, nginx) e servizi (mariadb, redis) fissati per digest;
  composer di build da 2.7 a 2.10. Trivy fissato a v0.76.1.
- Workflow con permessi espliciti e checkout senza credenziali persistite; OpenSSF Scorecard.

### Changed
- Dependabot ogni giorno alle 01:00 senza cooldown; il solo digest nuovo delle immagini
  php/mariadb/redis va in auto-merge, il cambio di versione resta manuale.
- `latest` ricostruita ogni giorno (04:30 UTC) con patch Alpine e dipendenze fresche, invece
  che ogni lunedì. Pulizia settimanale delle versioni superate su GHCR.

## [0.9.4] - 2026-10-27

### Added
- Scheda segnalazione (lettura del comune): riquadro "Notifiche inviate" con tipo, destinatario
  (nome o email), data e canale di ogni notifica partita per la segnalazione: assegnazioni,
  solleciti, avvisi di inattività, riepiloghi ai supervisori, tempi superati, chiusure. Si
  registra all'invio effettivo; segnalante e ditta non lo vedono. Le notifiche inviate prima
  dell'aggiornamento non compaiono.

## [0.9.3] - 2026-10-27

Segnalazioni ferme: avviso e archiviazione automatica; il segnalante può sollecitare.

### Added
- Admin → SLA, per ogni regola: dopo quanti giorni lavorativi senza aggiornamenti una
  segnalazione è "ferma" e dopo quanti, dall'avviso, viene archiviata. Vale in tutti gli stati
  aperti, anche quando è affidata a ditta o operaio; le sospese sono escluse.
- Avviso per email a chi ha aperto la segnalazione e a chi ce l'ha in carico (persone delegate ed
  email della ditta, operaio o caposquadra, gestore assegnato), con la data di archiviazione; ai
  supervisori un riepilogo giornaliero di avvisate e archiviate. Qualsiasi nota, cambio di stato,
  allegato o sollecito azzera il conteggio. La scheda mostra "verrà archiviata il …".
- Archiviazione automatica con nota "Archiviata per inattività" ed email; si può riaprire.
- Sollecito del segnalante: chi ha aperto la segnalazione, quando il tempo previsto è superato o
  è ferma, la sollecita dalla scheda (uno ogni 3 giorni lavorativi). Arriva a chi ha il lavoro, o
  al comune se non è ancora affidata.
- Comando giornaliero `segnalazioni:inattivita` (07:15).

### Note operative
- La funzione parte spenta: impostare le soglie in Admin → SLA (suggeriti Critica 2 + 5,
  Alta 5 + 10, Media 15 + 15, Bassa 30 + 15 giorni lavorativi). Al primo giro partono gli avvisi
  per tutte le segnalazioni già ferme.

## [0.9.2] - 2026-10-26

Le scadenze SLA contano solo l'orario d'ufficio.

### Added
- Admin → Impostazioni → Orario d'ufficio: orario di apertura per ogni giorno della settimana
  (default lun/mer/ven 8–14, mar/gio 8–17, sabato e domenica chiuso) e giorno del patrono,
  con l'elenco dei festivi dell'anno per controllo.
- Regole SLA in ore lavorative o in giorni lavorativi (N giorni = entro la chiusura
  dell'N-esimo giorno lavorativo dopo quello della segnalazione).
- Nel form di segnalazione, scelta la priorità, compare il tempo previsto ("Intervento
  previsto entro 4 ore lavorative") con l'avvertenza che la priorità la stabilisce l'ufficio,
  e l'indicazione di chiamare il 112 per i pericoli immediati.

### Changed
- Scadenze e soglia "a rischio" contano solo le ore di apertura: weekend, festivi nazionali
  (Pasqua e Pasquetta comprese) e patrono esclusi. "Superata" resta in tempo reale.
- Cambiando regole SLA, orario o patrono le scadenze delle segnalazioni aperte si ricalcolano.

### Removed
- Flag "urgente" (form, scheda, elenchi, bot Telegram, CSV, report, API): era un doppione di
  Critica e prometteva un intervento immediato che il comune non garantisce. Le segnalazioni
  aperte urgenti diventano Critica; la colonna resta a DB per lo storico. Il campo
  `segnalazione_urgente` dell'API viene ignorato.

### Note operative
- Le regole SLA esistenti valgono ora come **ore lavorative**: rivederle in Admin → SLA
  (es. Critica in ore, Alta 1 giorno lavorativo).
- Impostare il patrono in Admin → Impostazioni → Orario d'ufficio e verificare l'orario.

## [0.9.1] - 2026-10-26

### Fixed
- Accorpa: le proposte automatiche mostravano anche segnalazioni che chi accorpa non può
  vedere (solo il titolo; accorparle era comunque vietato). Ora si propone solo ciò che è visibile.
- Le note dell'operaio restano sempre interne al comune anche con una richiesta costruita a
  mano, e ora avvisano il comune come quelle di ditta e segnalante.
- Scheda: "Riservata" con icona invece dell'emoji.

## [0.9.0] - 2026-10-26

Nuova interfaccia delle segnalazioni: una sola scheda letta in tre modi (comune, chi fa il
lavoro, chi ha segnalato) con il "prossimo passo" in evidenza, ed elenchi ridisegnati.

### Added
- Scheda segnalazione rifatta: in alto il prossimo passo con le azioni giuste per chi guarda
  (es. "Da smistare: conferma priorità e assegna", "Edil Adriatica deve chiudere il lavoro: è
  in ritardo di 3 giorni"), avanzamento, attività unica (note, cambi di stato, foto) e un solo
  campo per scrivere. Le azioni con dati (assegna, chiudi, sospendi, preventivo, accorpa,
  collega, sollecita) si aprono in un pannello laterale con domande guidate.
- Lettura "lavoro" per ditte e operai: comunica fine lavori (ore, materiali, foto,
  descrizione), naviga verso il luogo, chiama il referente, scrivi al comune.
- Lettura "segnalante": stato in parole semplici, i nomi interni non compaiono, si può
  scrivere al comune (il comune riceve un avviso) invece di aprire una nuova segnalazione.
- Accorpa: una segnalazione già presente viene unita alla più vecchia, che risulta
  "Segnalata N volte"; chi ha segnalato la segue da lì e riceve un avviso, insieme a chi ha
  il lavoro.
- Collega a precedente: per un guasto che si ripresenta la nuova segnalazione resta aperta e
  mostra la riparazione precedente (quando, da chi, dopo quanti giorni).
- Sollecita: email a chi ha il lavoro (persone delegate della ditta e indirizzo aziendale,
  operaio o caposquadra), registrata nell'attività.
- Elenco gestione: ordinamento "più urgenti" (tempo massimo superato prima, poi scadenza e
  priorità), riquadri "Da guardare", filtri rapidi (tempo superato, entro 24 h, sollecitate,
  ricorrenti), etichette dei filtri attivi, colonna Scadenza, righe a scheda sul telefono.

### Changed
- Gestione: una sola barra di viste con conteggio al posto di card e linguette; i filtri
  restano applicati cambiando vista; stampa ed esportazioni in un unico menu.
- Elenchi di segnalatori, ditte e operai con gli stessi componenti e colonne per ruolo; chi
  segue una segnalazione per adesione la vede con l'etichetta "Segui".
- Visibilità della scheda allineata agli elenchi: chi ha aderito a una segnalazione può aprirla.

### Fixed
- "Chiudi" dalla scheda falliva la validazione del rapportino anche con i dati inseriti.
- Operaio: 403 aprendo la scheda di un lavoro della propria squadra.
- Portale ditta: le segnalazioni su appalti scaduti sparivano dall'elenco.
- Le note scritte da ditta o segnalante non avvisavano nessuno in comune.
- "Unisci duplicato" lasciava la nuova segnalazione persa e quella vecchia invariata.

### Removed
- "Segna come duplicata" e "Unisci duplicato", sostituiti da Accorpa.

## [0.8.22] - 2026-10-25

### Security
- Note interne non più visibili a ditte e segnalanti. Finora la scheda (e stampa,
  fascicolo PDF, Telegram per i segnalanti) mostrava tutte le note a chiunque potesse
  aprire la segnalazione: "visibile impresa" / "visibile web" decidevano solo chi
  riceveva l'email. Ora la ditta vede le note per la ditta e quelle della propria ditta,
  il segnalante quelle pubbliche e le proprie; il personale del comune vede tutto.

## [0.8.21] - 2026-10-25

### Added
- Scheda segnalazione: gestori e admin possono modificare priorità e urgenza ("Modifica"
  accanto al badge). La scadenza SLA viene ricalcolata e resta una nota interna con
  priorità precedente e nuova. Segnalatori, operai e ditte non possono modificarla.

## [0.8.20] - 2026-10-23

### Added
- Gestione → "Stampa schede": tutte le schede dell'elenco filtrato in un unico documento,
  una per pagina, uguali alla stampa della singola segnalazione.
- Filtro rapido "Nuove" (stato Nuova) in Gestione, con contatore.

### Fixed
- "Stampa lista" ignorava i filtri operatore, date, priorità e specializzazione: ora
  dashboard, stampa e CSV usano gli stessi filtri.

## [0.8.19] - 2026-10-23

> **Nota operativa.** All'aggiornamento tutti gli account locali (email/password) vengono
> disattivati, non eliminati. Le ditte entrano solo con SPID/CIE: per ogni ditta, dalla
> scheda Imprese, aggiungi il codice fiscale della persona che la gestirà (usa la ditta già
> presente, così vede anche le segnalazioni dei vecchi appalti). I segnalatori senza dominio
> si ricollegano con SPID/CIE e la delega scuola, come per le scuole.

### Added
- Favicon ProntoPA.
- Logo dell'ente anche come file caricato (Admin → Impostazioni), affiancato al logo
  ProntoPA in login, pagine e email.
- Loghi nelle email incorporati nel messaggio (niente immagini esterne).
- Pagina `/accesso` per scuole e ditte (SPID/CIE), separata da `/login` per il personale
  del comune (solo credenziali di dominio). In home entrambi gli accessi.
- Deleghe ditta: un operatore delega il codice fiscale di una persona a una ditta; al primo
  accesso SPID/CIE la persona gestisce le segnalazioni della ditta, anche dei vecchi appalti.
  Revoca dalla scheda impresa.
- Disattivazione/riattivazione ditta: revoca tutte le deleghe e blocca le email.

### Changed
- Email di assegnazione alla ditta: alle persone delegate e all'email aziendale (una sola
  se coincidono), con link alla pagina di accesso SPID.
- Admin → Utenti: niente creazione di account locali; il ruolo delle persone delle ditte
  si gestisce dalle deleghe.
- Una ditta con persone delegate non si elimina: si disattiva.

### Removed
- Registrazione, password dimenticata, cambio password.
- Magic-link per le ditte (anche nelle risposte Telegram).
- Codice 2FA via email (resta il TOTP).
- Verifica annuale degli account locali (resta il rinnovo delle deleghe scuola).

## [0.8.18] - 2026-10-22

### Added
- Admin → Deleghe: riquadro "In attesa di verifica email" con le persone entrate con
  SPID che hanno completato il profilo ma non confermato l'email (non possono ancora
  chiedere deleghe), e pulsante "Reinvia email di verifica".

## [0.8.17] - 2026-10-22

### Added
- Admin → Sedi: "Unisci in un'altra sede" per i doppioni (es. plesso del vecchio
  programma accanto a quello MIUR): segnalazioni e deleghe passano alla sede scelta,
  poi la sede vecchia viene eliminata.
- Colonna Sede in "Le mie segnalazioni" e in Gestione (al posto di Provenienza, che
  resta come filtro); icona della tipologia nelle liste.
- Select e liste dei plessi con ordine di scuola, codice meccanografico e indirizzo
  (apertura segnalazione, deleghe, sedi); istituto con codice nei gruppi.
- Elenco deleghe admin: motivo e chi ha deciso; caselle "Motivo attivazione" e
  "Motivo revoca" distinguibili.

### Changed
- Profilo: password e 2FA solo per gli account locali (SPID/CIE e AD si autenticano altrove).
- Nome dell'ente nella barra laterale su due righe invece che troncato.
- Redis 7.2 → 8.0 (cache, sessioni e code; i dati esistenti vengono riletti all'avvio).
- Font Awesome, Leaflet e Chart.js serviti da ProntoPA (npm + build), niente più CDN
  esterni; CSP ristretta di conseguenza.

### Fixed
- Eliminare una sede con segnalazioni le lasciava senza sede (nessun vincolo a DB) e
  con deleghe dava errore: ora l'eliminazione è rifiutata e propone l'unione.

## [0.8.16] - 2026-10-22

### Changed
- MariaDB 11.4 → 12.3 LTS (supporto fino a giugno 2029). Al primo avvio con la nuova
  immagine `MARIADB_AUTO_UPGRADE` aggiorna da solo le tabelle di sistema. L'upgrade non
  si può annullare: fare "Backup ora" prima di aggiornare lo stack.

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
