# Nuova segnalazione rifatta da zero — design

Data: 2026-11-13 · Stato: rivista (revisore + risposte del committente) · Un solo rilascio, patch dopo la 0.9.20

## Perché

Il form "Nuova segnalazione" è il punto da cui parte tutto ed è rimasto quello di prima, mentre l'iter
dopo è stato semplificato (0.9.19/0.9.20). Chi entra per segnalare un guasto trova 12 tipologie divise
in gruppi che non c'entrano con la sede, un riquadro "Per conto di" e una provenienza che non dicono
niente, un placeholder con dati reali, "Più dettagli" chiuso con dentro un campo obbligatorio e le
foto, e una mappa enorme anche con la sede già scelta.

Parole del committente: "un cristo che si collega per segnalare un guasto può trovarsi questo schifo?"
e "fondamentalmente lo stiamo rifacendo da zero". Il form cambia **per tutti** (scuole via SPID,
personale del comune, URP).

Successo: chi segnala vede solo campi che capisce, tutti in vista, e arriva in fondo senza errori
nascosti. Chi lavora (operaio, ditta) trova subito il posto sulla mappa.

## Decisioni del committente

1. **Si parte sempre da una sede**, obbligatoria per tutti. Niente segnalazioni senza sede (strade,
   piazze). I cittadini si valuteranno più avanti.
2. **Tipologie: un solo elenco, uguale per tutte le sedi.** "Che sia una scuola lo dice la sede, non
   la tipologia". **I gruppi di tipologie si cancellano** (tabella, colonna, flag `cittadini`, la
   scritta "· EDILIZIA SCOLASTICA" ovunque).
3. **Pagina admin delle tipologie**: aggiungi, rinomina, unisci, disattiva. Il programma smette di
   ricrearle a ogni avvio. Le due "SFALCI E POTATURE" si uniscono subito.
4. **Appalti come i gestori: legati alle tipologie** (elenco oppure "tutte").
5. **Pannello Assegna**: prima le ditte con appalto valido per la tipologia, sotto "Altre ditte" con
   tutti gli altri appalti validi. Nessuno resta bloccato.
6. **Priorità** come oggi. **Titolo** obbligatorio, senza il placeholder con dati reali.
7. **Mappa fuori dal form**, resta nel backoffice: le sedi hanno coordinate (ricavate dall'indirizzo
   o fissate a mano) così ditte e operai trovano subito il posto.
8. **Niente "Più dettagli"**: tutto in vista.
9. **"Per conto di": via.** **Provenienza: via dal database.** **Specializzazioni: via** (l'operaio
   scrive nel testo e rimanda al gestore).
10. **Dati del chiamante/segnalante** (`segnalante`, `email`, `telefono` su segnalazioni e adesioni):
    **cancellati dal database**, anche se per le segnalazioni vecchie sono l'unica traccia di chi ha
    segnalato. Conseguenza accettata: le segnalazioni senza account attivo non mostrano più chi le
    ha fatte e non ricevono l'avviso di chiusura.
11. **"Dove si trova il problema"** e **foto**: restano, facoltativi, in vista.
12. **Segnalazioni simili**: restano nel form, per sede e tipologia, con "Anche per me".
13. **"Rimanda al gestore"** per operaio **e ditta**.
14. **API**: nessuno la usa → sede obbligatoria anche via API, campi tolti ignorati.
15. **Un solo rilascio**, con le cancellazioni dentro (non si torna alla versione precedente).

## Il form

Tutti i campi visibili, nessuna sezione richiudibile, in quest'ordine:

1. **Sede** (obbligatoria): scuola SPID → sedi coperte dalle sue deleghe; personale del comune → sedi
   di enti attivi (`istituti.attivo`). Una sola → già selezionata. Sotto: indirizzo (testo).
2. **Tipologia** (obbligatoria): elenco unico delle tipologie attive, ordinato per nome.
3. **Segnalazioni simili**: appena scelte sede e tipologia, le aperte della stessa sede e tipologia
   (riuso della rotta `segnalazioni.simili` filtrata così) con "Anche per me" (adesione).
4. **Priorità**: come oggi.
5. **Titolo** (obbligatorio, max 100): nessun esempio con nomi reali.
6. **Descrizione** (obbligatoria).
7. **Dove si trova il problema** (facoltativo): interno, esterno, impianto, area verde, più
   "Non so" preselezionato (un gruppo di radio non si può deselezionare). Mai obbligatorio, neanche
   per le scuole.
8. **Foto** (facoltative): pulsante in vista.
9. Avviso 112 e **Invia segnalazione**.

Spariscono: "Per conto di", provenienza, "Tipo di intervento richiesto", "Più dettagli", mappa e
campi latitudine/longitudine, il pulsante "Salva e inserisci nuova" (era legato al "per conto").

## Fuori dal form

### Tipologie
- Tabella `tipologie_segnalazioni` + colonna `attiva` (le disattivate non compaiono nel form né in
  filtri nuovi, restano sulle segnalazioni vecchie).
- **Admin → Tipologie** (nuova voce nel menu Amministrazione, solo admin): elenco, aggiungi
  (nome + icona), rinomina, disattiva/riattiva, **unisci A in B** (sposta su B segnalazioni,
  regole di scadenza, `gestori_tipologie`, appalti→tipologie; poi elimina A).
- Il seeder **inserisce le tipologie solo se la tabella è vuota** (installazione nuova); non le
  ricrea mai su un'installazione esistente.
- Migration: unisce i doppioni "SFALCI E POTATURE" (id 4 e 11) nella più usata, stesso
  procedimento di "unisci".
- Tutte le viste con gruppi diventano elenco piatto: form, scheda, stampa scheda e stampe multiple
  (oggi il gruppo è l'intestazione), fascicolo PDF, tipologie dei gestori ("Tutto il gruppo" sparisce),
  tipologie orfane, filtri gestione, appalti, `CoperturaTipologie`, demo, factory.

### Appalti legati alle tipologie
- Pivot appalto ↔ tipologia + flag `tutte_tipologie`, stesso schema dei gestori.
- Migration (SQL puro, senza model: viene rieseguita anche nel ripristino di backup vecchi): ogni
  appalto prende le tipologie del suo gruppo; **appalti di gruppi senza tipologie** (Viabilità,
  Segnaletica, Rifiuti) → `tutte_tipologie` = vero.
- Form appalto: scelta tipologie come in "Tipologie dei gestori", con "tutte".
- Pannello Assegna: ditte che coprono la tipologia in cima, poi "Altre ditte". Azioni multiple:
  in cima quelle che coprono **tutte** le tipologie selezionate.

### Provenienza, "per conto", chiamante, specializzazioni, coordinate per segnalazione: eliminati
- **Chi è una scuola**: utente `spid` (con delega) — sostituisce `segnalaComeScuola()` e
  `puoScegliereProvenienza()`; `SpidLoginService` non scrive più `id_provenienza`.
- **URP**: il gruppo AD `PRONTOPA_URP` resta e dà ruolo `segnalatore` come `PRONTOPA_SEGNALATORI`,
  senza "per conto". **Sync AD**: la protezione "manca in AD un gruppo i cui utenti hanno ancora quel
  ruolo" conta URP e segnalatori insieme (gruppo assente blocca solo se **entrambi** mancano e ci sono
  segnalatori `ldap` attivi). Da sistemare `SincronizzaUtentiAd`, `MappaGruppiLdap`, `RuoloLdap`,
  `AllineaUtenteAd`, `ProvaLdap`.
- **Adesioni**: prima del drop delle colonne del chiamante, la migration deduplica le adesioni per
  (segnalazione, utente) tenendo la più vecchia; poi indice unique. Il controllo "già aderito" non
  usa più `segnalante`.
- **Avviso di chiusura**: solo all'account del segnalante (attivo) e agli aderenti; il ripiego
  sull'email salvata sparisce (`SegnalazioneWorkflowService`, `AccorpamentoService`).
- **Ricerca "segnalante" in gestione**: su `users.name` dell'autore.
- **Specializzazioni**: via tabella `db_specializzazioni`, `imprese_specializzazioni`,
  `segnalazioni.id_specializzazione`, `sla_configurazioni.id_specializzazione` (+ indice
  `sla_lookup_idx` rifatto senza). **Regole di scadenza**: quelle con specializzazione si
  **eliminano** se esiste la regola tipologia+priorità equivalente; se non esiste, diventano quella
  regola (senza specializzazione). Le scadenze delle aperte **non si ricalcolano**. Triage AI
  (`SuggerisciTriageSegnalazione`, `AiTriageController`) senza specializzazione; nel prompt si
  corregge anche "tipologia intero 1-3" con l'elenco reale; chiavi vecchie nei JSON già salvati
  ignorate. Anche `Inattivita`, `SlaService`, form regole di scadenza.
- **Coordinate per segnalazione**: prima del drop, ogni sede senza coordinate prende la **mediana**
  delle coordinate (≠ 0) delle sue segnalazioni. Poi via `segnalazioni.latitudine/longitudine`.
  Dedup per vicinanza → "stessa sede" (via `dedup_raggio_metri` da `Sezioni` e seeder impostazioni).
- **Permesso `segnalazioni.per-conto`**: tolto da migration (righe pivot Spatie + reset cache
  permessi) e dal seeder dei ruoli.
- **API**: `id_plesso` obbligatorio (sede di ente attivo, 422 altrimenti), `id_provenienza` non più
  richiesto, `segnalante/email/telefono/latitudine/longitudine/id_specializzazione` ignorati.
  `docs/API.md`, `openapi.yaml` aggiornati.
- Utenti admin (`UtentiController`, `admin/utenti/edit`): via la provenienza.
- `ImportLegacy`: adattato per non scrivere i campi tolti (o dismesso se non serve più: decide il
  costruttore guardando se è ancora richiamato).
- Nota: export XLSX, statistiche e webhook in uscita **non** usano provenienza/specializzazioni
  (verificato dal revisore): nessun intervento lì.

### Ordine obbligato delle migration
Nomi successivi a `2026_11_14_000001`. Mai `DELETE` sulle tabelle di riferimento (la FK
`tipologie.id_gruppo` è `cascadeOnDelete`: cancellerebbe tutte le tipologie).
1. Aggiunte: `tipologie.attiva`, coordinate `plessi`, pivot appalti↔tipologie + flag.
2. Conversioni (SQL puro): appalti→tipologie, unione Sfalci, regole SLA, adesioni dedup, coordinate
   sedi dalla mediana, permesso per-conto.
3. `dropForeign` di tutte le FK coinvolte (tipologie.id_gruppo, appalti.id_gruppo,
   segnalazioni.id_provenienza, id_specializzazione su segnalazioni e sla_configurazioni,
   imprese_specializzazioni).
4. `dropColumn`.
5. `drop table` (gruppi, provenienze, specializzazioni, imprese_specializzazioni).

**Seeder** (`AllineamentoSeeder`, a ogni avvio) aggiornato nello stesso commit: niente gruppi,
provenienze, specializzazioni, per-conto; tipologie solo su tabella vuota. Test: `migrate:fresh` +
seeder due volte + `migrate` da uno schema 0.9.20 con dati, seeder dopo, app risponde.

### Coordinate delle sedi
- `plessi.latitudine/longitudine`.
- Scheda sede (Admin → Enti e sedi): mappa con punto trascinabile, "Trova dall'indirizzo", salva.
- **Geocoding**: Nominatim (OpenStreetMap) via `Http` di Laravel, User-Agent che identifica l'app (senza
  dati personali), massimo 1 richiesta al secondo, mai dal browser. Indirizzo + **comune**: nuova
  impostazione `geocoding_comune` (Aspetto, default vuoto; se vuota si usa solo l'indirizzo).
- **In blocco da UI** (mai da console): pulsante "Trova le posizioni mancanti" nella pagina sedi →
  job in coda, 1 req/s, esito salvato; riquadro in **Stato del sistema** (sedi senza coordinate,
  esito ultimo giro, mai chiamate di rete).
- Scheda segnalazione: riuso di `scheda/_dove.blade.php` (mappa piccola, "Apri in Maps", "Chiama il
  referente") con le coordinate della sede; sede senza coordinate → solo indirizzo. Mappa della
  dashboard operaio: coordinate della sede.

### "Rimanda al gestore" (operaio, squadra, ditta)
- Nuova azione (riga in `db_azioni` dal seeder e dalla migration), da **Assegnata** (3),
  **Assegnata a ditta** (4) e **Preventivo in attesa** (5), **nota obbligatoria**.
- Chi può: operaio assegnato, caposquadra della squadra assegnata (non ogni membro), persone della
  ditta assegnata.
- Torna a **In carico** (2) al gestore dell'ultima assegnazione (`stati_segnalazioni.id_utente`
  dell'ultimo passaggio a 3/4), email con la nota. Se quel gestore non è attivo o non è più gestore
  → torna a **Nuova** (1), già coperta da avvisi per tipologia e supervisori.
- Chi rimanda perde il lavoro (ditta senza visibilità, come una riassegnazione). Scadenza invariata.
- Notifica nuova con etichetta in `NotificaSegnalazione::ETICHETTE`, frase nel feed `Attività`
  ("ha rimandato al comune"), pulsante nel `ProssimoPasso` di operaio e ditta.

## Non obiettivi
Segnalazioni senza sede, cittadini, portale pubblico; cambiare l'iter dopo l'invio; ridisegnare
scheda o elenchi oltre quanto serve per togliere gruppi/provenienza.

## Anti-obiettivi
Un campo obbligatorio che non si vede; una sezione richiudibile nel form; esempi con nomi reali;
gruppi, provenienza o specializzazioni che sopravvivono in schermate, stampe, export o API; una
mappa nel form; l'app che non riparte dopo l'aggiornamento.

## Vincoli
- In produzione, deploy ~1 h dal tag. **Non si torna indietro**: la versione precedente non riparte
  sul database aggiornato. Prima del tag: **backup manuale** da Admin → Backup ("pre-aggiornamento"),
  scritto nel CHANGELOG come avviso.
- `AllineamentoSeeder` idempotente e coerente con lo schema nuovo nello stesso rilascio.
- Niente CDN (Leaflet già in `public/build/vendor/`), nessuna dipendenza nuova.
- Ripristino di backup vecchi: le migration di conversione devono funzionare sui dati importati.

## Lasciato al costruttore
- Suddivisione esatta delle migration dentro l'ordine sopra.
- Layout del form e della pagina tipologie con i componenti `x-pa.*`.
- Icone delle tipologie nuove (elenco Font Awesome già usato).
- Destino di `ImportLegacy`.
