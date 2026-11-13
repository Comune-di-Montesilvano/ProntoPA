# Iter segnalazioni semplice — design

Data: 2026-11-13 · Stato: pronta per approvazione

## Perché

Simulazione completa su copia dei dati di produzione (scuola via SPID → supervisore → operaio → chiusura)
e analisi dei dati reali (3585 segnalazioni) mostrano che l'app chiede più lavoro di quanto ne tolga:
campi duplicati o mai usati, azioni sparse in tre menu, chiusura che pretende ore+materiali+foto,
email che non dicono di cosa si parla. Obiettivo del prodotto: **aiutare, non aggiungere lavoro**.

Contesto d'uso reale (dal committente):
- Oggi la usa davvero un solo gestore tecnico (Caruso): **stampa le segnalazioni e le assegna su carta**
  agli operai, poi chiude. Gli operai entreranno quando ci sarà Telegram. I numeri storici di Caruso
  non vanno presi come modello di processo: riflettono il giro su carta.
- Le scuole (SPID) aprono segnalazioni e per sollecitare/integrare ne aprono di **nuove**
  ("Sollecitiamo segnalazione precedente…", "In aggiunta alla precedente…").
- L'archiviazione automatica per inattività **resta com'è**: è giudicata utile (se nessuno gestisce né
  sollecita, la segnalazione non serviva).

Dati che guidano le scelte (prod, 2026-11-13):
- `id_specializzazione` valorizzata 0/3585; `ubicazione_tipo` 61/3585; priorità ≠ Media 47/3585.
- 135 aperte su 148 hanno scadenza superata.
- Tipologia "SFALCI E POTATURE" esiste due volte (id 4 gruppo Verde pubblico, 0 segnalazioni; id 11 gruppo
  Parchi e giardini, 6 segnalazioni).
- Plessi: 38, nessuna coordinata. Segnalazioni 2026 con coordinate: 0.

## Obiettivi

1. Meno clic e meno campi per chi lavora (gestore) e per chi segnala (scuola).
2. Un'azione = un posto. Nessuna voce duplicata o sovrapposta.
3. Email che si capiscono senza aprire il link.
4. Sede riconoscibile a colpo d'occhio (mappa).

## Non obiettivi

- Telegram (progetto a parte; questo lavoro prepara testi e dati che userà).
- Cambiare calcolo SLA/orario d'ufficio o archiviazione automatica.
- Admin UI per i gruppi (restano solo a DB).
- Rifare il portale ditte (1 impresa attiva): si adegua solo dove tocca le parti comuni.

## Anti-obiettivi (fallimento anche se "funziona")

- Qualunque nuovo campo obbligatorio per chiudere o assegnare.
- Perdere storico/KPI esistenti: i valori numerici di `SegnalazioneStato` restano, lo storico non si riscrive.
- Il gestore che deve aprire una scheda per volta per fare il giro di stampa/assegnazione/chiusura.

## Decisioni

### 1. Stati e azioni (schema approvato dal committente)

`SegnalazioneStato` non cambia valori (compatibilità storico/KPI/API/webhook). Cambiano etichette, uso e
transizioni.

**Stati**

| # | Stato (etichetta) | Significato | Chi c'è dietro |
|---|---|---|---|
| 1 | Nuova | arrivata, nessuno l'ha smistata | — |
| 2 | In carico | se ne occupa il comune direttamente | gestore ("Assegna a me") |
| 3 | Assegnata | lavoro affidato a personale interno | operaio o squadra |
| 4 | Assegnata a ditta | lavoro affidato a ditta esterna | ditta + appalto |
| 5 | Preventivo in attesa | la ditta ha proposto un preventivo, decide il comune | ditta |
| 6 | Sospesa | ferma con motivo | chi l'aveva prima |
| 7 | Completata† | lavoro fatto | — |
| 8 | Accorpata† | doppione di un'altra | — |
| 9 | Chiusa senza intervento† (ex "Annullata") | errata, non pertinente, già risolta | — |
| 10 | Archiviata† | chiusa in automatico per inattività | sistema |

† terminale.

**Azioni che cambiano stato**

| Azione | Da | A | Chi | Obblighi |
|---|---|---|---|---|
| Assegna / Riassegna (a me · operaio · squadra · ditta) | 1, 2, 3, 4, 5 | 2 / 3 / 4 | gestore | ditta → appalto; nota e scadenza facoltative |
| Chiudi (lavoro fatto) | 2, 3, 4 | 7 | gestore | nessuno: nota e foto facoltative |
| Comunica fine lavori | 3 | 7 | operaio o caposquadra | nessuno: foto, ore, materiali facoltativi |
| Comunica fine lavori | 4, **senza** preventivo | 7 | ditta | nessuno |
| Comunica fine lavori | 4, **con** preventivo accettato | resta 4, "fine lavori da verificare" | ditta | poi il gestore usa Chiudi |
| Presenta preventivo | 4 | 5 | ditta | importo |
| Accetta / Rifiuta preventivo | 5 | 4 | gestore | motivo se rifiuta |
| Chiudi senza intervento | 1–6 | 9 | gestore | motivo (non pertinente / errata / già risolta / altro + testo) |
| Accorpa a… | 1–6 | 8 | gestore | segnalazione di destinazione |
| Sospendi | 2–5 | 6 | gestore | motivo |
| Riprendi | 6 | stato precedente | gestore | — |
| Riapri | 7–10 | 2, in carico a chi riapre | supervisore o admin | entro `reopen_days_limit` |
| Archiviazione automatica | 1–5 | 10 | sistema | invariata |

Regole che ne derivano:
- **"Prendi in carico" sparisce**: si smista direttamente con Assegna; "Assegna a me" sostituisce la presa
  in carico.
- **Completata solo dopo l'assegnazione** (decisione del committente). Da Nuova il pulsante "Chiudi"
  apre "Chiudi senza intervento": una segnalazione errata o non pertinente si chiude subito.
- Niente Sospendi da Nuova; niente Chiudi durante Preventivo in attesa o Sospesa (*scelte del redattore,
  approvate con lo schema*).
- **Fine lavori dell'operaio/caposquadra chiude** (decisione del committente): al segnalante
  `SegnalazioneChiusa`, al gestore che ha assegnato `FineLavori` come informazione. **Ditta**: chiude
  direttamente come l'operaio, **salvo se c'è stato un preventivo** (accettato nello storico, stato 5→4):
  allora resta in attesa di verifica del comune (decisione del committente).
- **Archivia manuale** e **"Segna duplicata"** spariscono dall'interfaccia (Accorpa copre i doppioni,
  10 resta all'automatica).
- **Riassegna**: il nuovo assegnatario sostituisce il vecchio (operatore, squadra, appalto azzerati se non
  pertinenti: da ditta a operaio o a "me" la ditta perde visibilità, come voluto). Avviso solo al nuovo.

**Azioni che non cambiano stato**: gestore → nota (visibile alla scuola / nuova scadenza facoltative),
priorità, titolo, collega a precedente, pubblica/riservata, in evidenza, sollecita assegnatario, stampa.
Scuola → messaggio (vale come sollecito se scaduta o ferma, §6).

**Cosa vede la scuola** (stepper a 4 passi invariato): Ricevuta (1) → Presa in carico (2, 5, 6) →
In lavorazione (3, 4) → Chiusa, con il perché: completata · senza intervento (motivo) · accorpata ad
altra · archiviata.

**Implementazione**: le righe di `db_azioni` restano (storico). `prendi_in_carico`, `archivia`,
`segna_duplicata` diventano non applicabili da UI; `annulla` prende descrizione "Chiudi senza intervento";
`parametri_filtro.stati` secondo la tabella sopra. Aggiornamento via migration dedicata (non basta
`insertOrIgnore` del seeder sulle righe esistenti) + seeder allineato per le installazioni nuove.
`SegnalazioneStato::label()` aggiornato (9 → "Chiusa senza intervento", 3 → "Assegnata").

### 2. Scheda segnalazione (lettura gestore)

Struttura dall'alto:
1. **Intestazione**: #, titolo (modificabile), chip stato, chip priorità, chip scadenza. Nient'altro.
2. **Barra azioni unica**: pulsante primario contestuale (Assegna / Riassegna / Chiudi) + "Chiudi" se non
   primario + menu **"Altro ▾"** con: Priorità, Sospendi/Riprendi, Annulla, Accorpa a…, Collega a precedente…,
   Rendi pubblica/riservata, In evidenza, Stampa, PDF fascicolo. Spariscono "Altre azioni" e "⋯" separati,
   il riquadro "Prossimo passo" diventa una riga di testo dentro la barra.
3. **Blocco "Cosa e dove"** (in evidenza, colonna principale): testo segnalato, poi plesso (nome + istituto),
   indirizzo, referente con telefono cliccabile, **mini mappa** (vedi §7), tipologia.
4. **Attività**: composer nota + elenco. Il composer ha:
   - "Visibile alla scuola" (oggi "+ segnalante");
   - **"Nuova scadenza"** (data, facoltativa): se compilata imposta la scadenza manuale (vedi §3) e
     l'attività registra "scadenza spostata al gg/mm".
5. Colonna laterale ridotta a: Chi ci lavora, Notifiche inviate. "Dettagli" (provenienza, registrata da,
   aperta il) va in una riga compatta sotto l'intestazione.

Le letture operaio/ditta/segnalante mantengono la loro impostazione (già buone in simulazione), ricevono
la stessa mini mappa nel blocco Dove.

### 3. Scadenza manuale

- Nuova colonna `segnalazioni.scadenza_manuale` (bool, default false).
- Impostabile da chi può agire sulla segnalazione (stessa policy di `update`), dal composer nota o dal
  pannello Assegna/Riassegna (campo data facoltativo).
- Con `scadenza_manuale = true` nessun ricalcolo automatico la tocca: `SegnalazioneWorkflowService`,
  `applicaPriorita`, `RicalcolaScadenzeSla`. Cambiare priorità chiede di nuovo la data? No: la priorità
  cambia solo l'etichetta; il gestore può togliere la scadenza manuale ("torna al calcolo automatico"),
  che ricalcola dalla regola.
- Avvisi SLA (`sla:check`, digest) usano `data_scadenza_sla` come oggi: funzionano senza modifiche.
- Cambi di stato (*scelta del redattore*): **Riapri** e **Riprendi** azzerano la scadenza manuale e
  ricalcolano dalla regola (la data vecchia non ha più senso). **Riassegna** la conserva, salvo nuova data
  indicata nel pannello.
- Valore: fine giornata lavorativa della data scelta (chiusura della fascia d'ufficio, da `CalendarioUfficio`);
  date passate rifiutate.

### 4. Elenco gestore: azioni multiple (priorità per il giro su carta)

- Checkbox per riga + "seleziona tutte nella pagina" nella vista elenco gestione.
- Barra azioni che compare con ≥1 selezionata:
  - **Assegna a…** (operaio / squadra se attive / ditta / me) + nota facoltativa + scadenza facoltativa;
  - **Chiudi** (nota facoltativa uguale per tutte; si applica alle 2–4, le altre saltate);
  - **Chiudi senza intervento** (motivo uguale per tutte);
  - **Stampa schede** (riusa `print-multiple`), ordinate per assegnatario poi plesso.
- Ogni segnalazione passa dalla stessa logica della singola (workflow service, notifiche, storico):
  niente scorciatoie SQL. Le non applicabili (es. già chiuse) vengono saltate e il messaggio finale dice
  "N fatte, M saltate (motivo)".
- Policy per ciascuna segnalazione (un gestore non supervisore agisce solo sulle sue).

### 5. Nuova segnalazione

- **Una sola scelta di tipologia** (le tessere). Rimossi dal form: "Tipo di intervento richiesto"
  (specializzazione), "Dove si trova il problema" (ubicazione), "Provenienza" in sola lettura per la scuola.
  Colonne a DB restano (storico, API); l'API continua ad accettarle.
- **Priorità**: tolta a scuole e segnalatori (default Media, la decide l'ufficio). Resta per gestori/admin
  e URP "per conto di".
- Avviso 112 solo a chi segnala (scuola, segnalatore, URP), non a gestori/admin.
- **Niente più categorie (gruppi) nel form** (decisione del committente): le tipologie attive si mostrano
  come un unico elenco piatto di tessere, nell'ordine scelto dall'admin, per tutti. Ogni tessera mostra
  sotto il nome gli **esempi** (riga breve). I gruppi restano a DB (storico, API, statistiche) ma il form
  non li usa più.
- **Catalogo tipologie** (approvato dal committente; nuove voci motivate dai testi reali: sicurezza/antincendio
  98 segnalazioni sparse su 7 tipologie, disinfestazione 42, traslochi/servizi 31):

  | Tipologia | Esempi | Rispetto a oggi |
  |---|---|---|
  | Impianto elettrico | luci, neon, prese, quadri | invariata (id 1) |
  | Acqua e bagni | perdite, scarichi, sanitari | rinomina id 3 "Impianto idrico" |
  | Riscaldamento e clima | termosifoni, caldaia, condizionatori | rinomina id 6 "Impianto termico" |
  | Porte, finestre e tapparelle | serrande, maniglioni antipanico, cancelli | rinomina id 2 "Infissi e porte" |
  | Muri, tetti e pavimenti | infiltrazioni, intonaci, piastrelle | rinomina id 5 "Manutenzione edile" |
  | Sicurezza e antincendio | estintori, allarmi, uscite di emergenza | **nuova** |
  | Disinfestazione | topi, insetti, vespe | **nuova** |
  | Arredi | banchi, sedie, armadi, lavagne | invariata (id 9) |
  | Rete dati e telefono | internet, telefoni | rinomina id 7 |
  | Traslochi e piccoli servizi | spostamenti, bandiere, montaggi | **nuova** |
  | Verde, sfalci e potature | erba, siepi, alberi, aree verdi | id 8 "Verde e giardini" assorbe le due "Sfalci e potature" (4, 11) |
  | Giochi | parchi gioco | invariata (id 10) |
  | Fontane pubbliche | — | invariata (id 12) |

  Pronte ma **disattivate** (per uffici che entreranno): Strade e marciapiedi, Segnaletica, Illuminazione
  pubblica, Rifiuti abbandonati. Ascensori non prevista (5 casi in 6 anni).
- **Applicazione del catalogo**: migration una tantum, solo se i nomi attuali coincidono con quelli attesi
  (installazioni già personalizzate non vengono toccate): rinomine sugli stessi id (storico invariato),
  nuove voci, tipologie 4 e 11 **disattivate** (non cancellate, storico intatto; le loro coperture in
  `gestori_tipologie` copiate su 8 senza duplicati). Nuove installazioni: stesso catalogo dal seeder
  (`insertOrIgnore`, mai sovrascrive). Le nuove tipologie nascono orfane: finché un gestore non le copre
  vanno ai supervisori (regola `CoperturaTipologie` esistente), e l'alert "tipologie orfane" lo segnala.
- **Pagina admin "Tipologie"** (Regole di lavoro → Tipologie, solo admin): elenco con nome, icona (scelta da
  una lista Font Awesome curata), esempi, ordine (trascina o su/giù), attiva/nascosta. Niente eliminazione
  (si nasconde). Colonne nuove su `tipologie_segnalazioni`: `esempi` (varchar 150, null), `ordine` (int),
  `attiva` (bool, default true). Tipologia nascosta: sparisce da form e API (422 se usata), resta in
  filtri/statistiche/storico. Chi copre cosa resta nella pagina esistente "Tipologie dei gestori".
- Placeholder titolo neutro: "In breve, es. Termosifone che perde in aula 4" (niente dati di ticket reali).
- **Segnalazioni già aperte sul plesso**: scelto il plesso, sotto il campo compare l'elenco (max 5) delle
  aperte visibili all'utente su quel plesso con "Aggiungi un messaggio lì" (link alla scheda, composer
  messaggi). Non blocca l'invio. Riusa la logica di visibilità esistente (`scopeVisibileA`).

### 6. Lato scuola: una sola conversazione

- Il riquadro "Sollecita" separato sparisce. Resta "Messaggi con il comune".
- Quando la segnalazione è sollecitabile (policy `sollecitaComeSegnalante`, regole invariate: scaduta o
  ferma, 1 ogni 3 gg lavorativi) sotto il composer compare la spunta, già attiva, **"Vale come sollecito"**
  con la frase "Il tempo previsto è superato: il messaggio arriva per email a chi se ne occupa".
  Invio = nota + `SollecitoNotification` come oggi.

### 7. Coordinate delle sedi e mini mappa

- Nuove colonne `plessi.latitudine`, `plessi.longitudine` (decimal(10,7), nullable).
- Admin → Enti e sedi → modifica sede: mini mappa Leaflet con marker trascinabile + pulsante
  **"Trova dall'indirizzo"** che interroga Nominatim **dal browser** (CSP già ammette
  `connect-src https://nominatim.openstreetmap.org`) e posiziona il marker; l'admin conferma salvando.
  Niente chiamate server-side, niente geocodifica automatica massiva.
- Sedi senza coordinate: in Stato del sistema un riquadro "attenzione" "N sedi senza posizione" con link
  all'elenco filtrato (solo conteggio DB, nessuna rete).
- **Mini mappa nella scheda** (tutte le letture): marker sulla posizione della segnalazione se presente,
  altrimenti sulla sede. Se nessuna delle due: niente mappa, solo indirizzo + (admin) link "imposta
  posizione della sede". Non interattiva oltre zoom/pan; altezza contenuta (~180 px).
- "Naviga" (lettura operaio) usa le coordinate se presenti, altrimenti l'indirizzo come oggi.
- Form nuova segnalazione: la mappa "Posizione (opzionale)" parte centrata sulla sede scelta
  (placeholder visivo), il punto resta facoltativo.

### 8. Email più chiare

Tutte le notifiche legate a una segnalazione hanno:
- **oggetto**: `#<id> · <TIPOLOGIA> · <titolo leggibile>` preceduto dal motivo breve
  (es. "Assegnata a te — #3586 · IMPIANTO TERMICO · Termosifoni freddi aula 3B");
- **corpo**: blocco riepilogo comune (titolo, tipologia, plesso + indirizzo, stato) prima del testo
  specifico; per assegnazione a operaio/caposquadra/ditta anche testo segnalato, referente con telefono,
  nota di assegnazione e scadenza.
- Implementazione: un concern/trait condiviso in `app/Notifications/Concerns` usato da tutte le notifiche
  per segnalazione (`NuovaSegnalazione`, `OperatoreAssegnato`, `SquadraAssegnata`, `ImpresaAssegnata`,
  `Sollecito`, `SegnalazioneChiusa`, `SegnalazioneStatoCambiato`, `NuovaNotaSegnalazione`,
  `NotaDaEsterno`, `FineLavori`, `SlaWarning`, `SlaViolazione`, `Inattivita`, `ArchiviataInattivita`,
  `Accorpata`). Riepiloghi (digest, inattività) elencano per ogni riga `#id · tipologia · titolo`.
- Al segnalante esterno non si mostrano nomi interni (regola già in `Attivita`).

### 9. Rifiniture emerse

- Gestore senza tipologie assegnate: elenco vuoto con messaggio "Non hai tipologie assegnate: chiedi a
  un supervisore" invece di "Nessuna segnalazione".
- Pagina 403 in italiano e nel layout dell'app.
- Paginazione in italiano ("Da 1 a 20 di 63").
- Lista e scheda usano lo stesso linguaggio per la scadenza (oggi "Entro 14 g" vs "Scade il 27/11").

## Tappe (una PR/patch ciascuna, versioning patch)

1. **Giro di Caruso**: §4 azioni multiple, §1 Assegna da Nuova + Riassegna + Chiudi semplice + fine lavori
   operaio che chiude, §3 scadenza manuale (serve già all'assegnazione multipla; il campo nel composer nota
   arriva con la tappa 2), §8 email.
2. **Scheda**: §2 layout + barra unica (+ scadenza nel composer nota), §7 coordinate sedi + mini mappa.
3. **Segnalare**: §5 form + catalogo e pagina admin Tipologie, §6 conversazione scuola, §9 rifiniture.

## Lasciato al costruttore

- Componenti Blade/Alpine e nomi interni; riuso dei pannelli `scheda/pannelli/*`.
- Ordine dei test; aggiornamento dei test esistenti che asseriscono i vecchi obblighi (rapportino,
  "Prendi in carico", sollecito separato).
- Testo esatto dei messaggi, purché in italiano semplice.
- Aggiornare CLAUDE.md (workflow stati, chiusura) e CHANGELOG/publiccode a ogni tappa.

## Scelte del redattore da verificare

- Archivia manuale tolta dall'interfaccia (resta l'automatica).
- Nessuna geocodifica massiva automatica: le 38 sedi si posizionano a mano con "Trova dall'indirizzo".
- Le tipologie 4 e 11 "Sfalci e potature" vengono disattivate, non spostate: le 6 segnalazioni storiche
  restano con la loro tipologia.
