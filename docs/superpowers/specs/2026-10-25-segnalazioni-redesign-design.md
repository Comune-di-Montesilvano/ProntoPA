# Redesign sezione segnalazioni — documento di progetto

Data: 2026-10-25 · Stato: proposta da approvare · Mockup: canvas "ProntoPA — Redesign segnalazioni"
(https://claude.ai/artifact/Ur8wHZg82Zav8yQSnMDSdq, privato del committente)

## 1. Perché

La sezione segnalazioni è il cuore di ProntoPA ed è la parte più vecchia: la scheda è stata
portata dal vecchio applicativo così com'era (modulo di campi + 5 linguette) e l'elenco è cresciuto
per aggiunte. Risultato, osservato su dev il 25/10:

- **Due linguaggi visivi.** Login e menu usano i token ProntoPA (`resources/css/app.css`:
  Titillium Web, `--ente-primary`, palette slate/amber/rose/emerald/violet, `.pa-*`); elenco e
  scheda usano Tailwind di default (blue-600, green-600, red-600, maiuscoletto). Sembrano due prodotti.
- **Ripetizioni.** Elenco: 6 card KPI + 6 tab con le stesse voci; 5 bottoni in testata.
  Tabella a 12 colonne, testo troncato, toggle Riservata/Pubblica (la cosa meno importante) è
  l'elemento più colorato.
- **Le cose importanti si perdono.** La scadenza SLA, la cosa più urgente, non compare
  nell'elenco. Nella scheda le azioni stanno dietro una linguetta "Gestione" e una select.
- **Una scheda uguale per tutti.** Ditta e segnalante vedono la scheda del gestore, con pulsanti
  e dati che non li riguardano.
- **Accorpamento rotto.** "Segna come duplicata" chiude la nuova e basta: la vecchia non riceve
  nulla, nessuno viene avvisato, il segnalante vede solo "Duplicata". Il riquadro "Unisci
  duplicato" sposta foto/adesioni ma non imposta `id_segnalazione_madre` (la nuova "si perde").
- **Il segnalante non viene ascoltato.** Le note scritte dal segnalante nascono interne e
  `notificaNota()` non avvisa il comune: chi segnala apre una nuova segnalazione per sollecitare.
- **Operaio senza scheda.** `operaio/dashboard` linka `segnalazioni.show` ma
  `SegnalazionePolicy::view` non ha un ramo operaio → 403.

(Già corretto in 0.8.22: le note interne erano visibili a ditte e segnalanti.)

## 2. Obiettivi, non obiettivi, anti-obiettivi

**Obiettivi**
1. Ogni persona che apre una segnalazione capisce in 3 secondi *a che punto è* e *cosa tocca a lei*.
2. Una scheda, tre letture: gestore (pratica completa), ditta/operaio (ordine di lavoro, da
   telefono), segnalante (tracciamento + messaggi).
3. Il segnalante scrive dalla scheda e il comune lo sa; niente più segnalazioni-sollecito.
4. Accorpa (ex Duplicata) e Collega a precedente fanno davvero quello che dice il nome.
5. Un solo linguaggio visivo: i token ProntoPA già esistenti, applicati con componenti Blade
   riusabili.

**Non obiettivi**
- Nessun cambio al workflow stati (`SegnalazioneStato`) né alle transizioni di
  `SegnalazioneWorkflowService`, salvo Accorpa/Collega descritti sotto.
- Nessun nuovo framework frontend: Blade + Alpine + Tailwind 4 come oggi. Niente SPA.
- Admin, statistiche, anagrafiche: fuori da questo giro.
- Il modulo di creazione segnalazione (`segnalazioni/create`) resta com'è in questo giro,
  salvo restyling coi nuovi componenti se a costo zero.

**Anti-obiettivi** (sarebbe un fallimento anche se "funziona")
- Nascondere informazioni che il gestore usa oggi senza un posto dove ritrovarle.
- Una ditta o un segnalante che vedono dati interni (nomi di operai, priorità, note interne,
  altre ditte).
- Una scheda che sul telefono della ditta richiede zoom o scroll orizzontale.
- Azioni distruttive (accorpa, annulla) a un clic senza conferma né possibilità di capire cosa succede.

## 3. Decisioni del committente

- Impostazione **C — una scheda, tre letture**, con al centro il **prossimo passo** (B).
- Il **segnalante deve poter scrivere** al comune dalla scheda.
- **Duplicata = accorpa** (stesso problema, segnalato di nuovo). **Collega a precedente = guasto
  di una riparazione precedente** (problema già riparato che si ripresenta): sono due cose diverse.
- Rilasci in sequenza, ognuno patch (memoria `feedback_versioning`), bump versione + CHANGELOG
  nella stessa PR.

## 4. La scheda

Una sola rotta (`segnalazioni.show`) e un solo controller; la vista sceglie la lettura dal ruolo
di chi guarda (non da un parametro). Struttura a partial: `segnalazioni/scheda/{gestore,lavoro,segnalante}.blade.php`
+ componenti condivisi.

Quale lettura:

| Chi | Lettura |
|---|---|
| admin, gestore | gestore |
| operaio, impresa | lavoro (ordine di lavoro) |
| segnalatore (anche SPID scuole, URP) e partecipanti da accorpamento | segnalante |

### 4.1 Elementi comuni

- **Testata**: `#id` · tipologia · sede, titolo (`titolo_generato` o testo troncato), etichette.
- **Prossimo passo** (§5): riquadro in evidenza sotto la testata, contenuto per ruolo.
- **Barra di avanzamento** a 4 tappe: Ricevuta → Presa in carico → In lavorazione → Chiusa
  (le date dal primo ingresso in ciascuno stato da `stati_segnalazioni`). Stati terminali
  diversi da Completata mostrano l'ultima tappa con l'etichetta dello stato (Annullata, Accorpata…).
  Sospesa: la tappa corrente porta "Sospesa" in ambra.
- **Attività** (§6): un'unica storia al posto delle linguette Note / Storico / Allegati.

### 4.2 Lettura gestore (mockup 2)

- Testata con: stato, priorità+urgente come etichetta cliccabile (apre il modulo della 0.8.21),
  scadenza ("Tempo massimo superato da 2 giorni" rosa / "Scade tra 6 h" ambra / data neutra),
  Riservata come etichetta con lucchetto.
- A destra: stella evidenza (icona), menu "⋯" (Stampa, PDF fascicolo, Rendi pubblica/riservata,
  Accorpa a…, Collega a precedente…), "Altre azioni ▾" (tutte le azioni disponibili non già nel
  prossimo passo).
- Corpo a due colonne: sinistra "Cosa è stato segnalato" (testo grande, foto in miniatura,
  inviata da/quando) → eventuale suggerimento AI (Applica / Ignora) → Attività. Destra: **Chi ci
  lavora** (operaio/squadra/ditta+appalto, link Riassegna), **Dove** (plesso, indirizzo,
  ubicazione, referente e recapiti, mappa), **Dettagli** (tipologia/gruppo, specializzazione,
  provenienza, registrata da, aperta il, scadenza, preventivo/liquidato se presenti),
  **Collegate** (madre, accorpate, precedente, successive).
- Rapportino fotografico prima/dopo: dentro Attività all'evento di chiusura, più in "Cosa è
  stato segnalato" una riga "Foto dopo l'intervento" quando c'è.
- Sotto 1024 px le colonne si impilano (destra sotto sinistra, "Chi ci lavora" subito dopo il prossimo passo).

### 4.3 Lettura lavoro — ditta e operaio (mockup 4)

Progettata per telefono (390 px) prima, funziona anche a desktop (colonna centrale max ~720 px).
Mostra solo: titolo, priorità+urgente e **entro quando** (scadenza in linguaggio naturale),
prossimo passo con bottone grande, **Dove** con "Naviga" (link `geo:`/Google Maps da lat/lng o
indirizzo) e "Chiama il referente" (`tel:`), "Cosa è stato segnalato" con foto, **Messaggi con il
comune** (Attività filtrata, §6), riga finale con data assegnazione e appalto.
Non mostra: evidenza, riservata, modifica priorità, provenienza, nomi del segnalante oltre a quanto
serve, altre ditte, suggerimenti AI, storico interno.

Operaio: stessa lettura; "Chi ci lavora" mostra la squadra se assegnata a squadra e, per il
caposquadra, il comando "Affida a un membro" (oggi `operaio.smista`).
**Correzione permessi**: `SegnalazionePolicy::view` concede all'operaio le segnalazioni che
`scopeVisibileA` gli mostra (stessa regola: assegnate a sé, squadre guidate, squadre di cui è
membro non ancora smistate). La policy e lo scope devono restare la stessa regola: estrarla in un
metodo unico usato da entrambi.

### 4.4 Lettura segnalante (mockup 5)

Pagina a colonna singola (max ~880 px). In ordine:
1. Testata semplice: "Segnalazione #id del gg/mm/aaaa", titolo.
2. **Risposta immediata**: icona + frase di stato in linguaggio semplice + ultimo aggiornamento
   pubblico se c'è (vedi tabella frasi sotto).
3. Barra di avanzamento.
4. **Messaggi con il comune**: note `visibile_web` + proprie, campo "Vuoi aggiungere qualcosa?",
   "Aggiungi foto", "Invia al comune".
5. "Cosa hai segnalato": testo, foto, dove, chi l'ha inviata.

Il segnalante **non vede nomi di persone del comune o della ditta**: gli autori interni compaiono
come "Ufficio manutenzioni" (etichetta configurabile, default "Ufficio manutenzioni", nuova
impostazione `etichetta_ufficio_segnalante` nel gruppo brand). Non vede priorità, ditta,
appalto, scadenza SLA, note interne.

Frasi di stato per il segnalante (prima stesura, rivedibili dal committente):

| Stato | Frase | Dettaglio |
|---|---|---|
| Nuova | Ricevuta | Il comune la esaminerà a breve. |
| In carico | Presa in carico | Il comune sta organizzando l'intervento. |
| Assegnata operatore / impresa, Preventivo | Ci stiamo lavorando | Ti avviseremo quando il lavoro sarà chiuso. |
| Sospesa | In attesa | Il lavoro è momentaneamente sospeso. + ultima nota pubblica se c'è |
| Completata | Risolta | Se il problema persiste, scrivici qui sotto. |
| Accorpata (Duplicata) | Già in lavorazione | Era già stata segnalata: segui gli aggiornamenti nella #id (link). |
| Annullata | Chiusa senza intervento | + motivazione se resa pubblica |
| Archiviata | Archiviata | — |

Scrivere su una segnalazione Completata: consentito; la nota arriva al comune come le altre
(il comune decide se riaprire). Archiviata/Accorpata/Annullata: campo nascosto, rimando alla
madre per le accorpate.

## 5. Prossimo passo

Calcolato a lettura (nessuna colonna nuova) da: stato, ruolo di chi guarda, assegnatario,
scadenza, ultimo evento. Implementazione: una classe `App\Support\ProssimoPasso` (o service)
che dato `(Segnalazione, User)` restituisce `{tono, titolo, sottotitolo, azioni[]}`; le azioni
sono riferimenti ad azioni del workflow (`id_azione`) già disponibili per l'utente
(`getAzioniDisponibili`) o a comandi della scheda (Riassegna, Sollecita, Chiudi con rapportino).
Il riquadro non mostra mai un'azione che l'utente non può eseguire.

Prima tabella (gestore/admin); tono: rosa se scadenza superata, ambra se entro 24 h, blu altrimenti.

| Stato | Titolo | Azioni |
|---|---|---|
| Nuova | Da smistare: conferma priorità e assegna | Prendi in carico · Assegna… · Accorpa a… |
| In carico | Da assegnare | Assegna… |
| Assegnata operatore/squadra | {nome} deve chiudere il lavoro (+ "è in ritardo di N giorni" se scaduta) | Sollecita · Sospendi · Riassegna |
| Assegnata impresa | {ditta} deve chiudere il lavoro (idem) | Sollecita · Sospendi · Riassegna |
| Preventivo in attesa | Preventivo di € X da valutare | Accetta · Rifiuta |
| Sospesa | Sospesa dal gg/mm (+ motivo dall'ultima nota) | Riprendi |
| Terminali | Chiusa il gg/mm (+ esito) | Riapri (se disponibile) |
| Sollecitata (§7) | in testa al titolo: "Segnalata di nuovo N volte" | + "Alza priorità" |

Ditta/operaio: Assegnata → "Quando hai finito, chiudi il lavoro con le foto" [Chiudi con
rapportino] + [Invia preventivo] (se l'azione è disponibile) + [Scrivi al comune]. Sospesa →
"In pausa: ti avviseremo quando riprendere". Preventivo in attesa → "Preventivo inviato, in
attesa del comune".

Segnalante: la frase di §4.4.

**Sollecita** (nuovo comando, gestore/admin): crea una nota "Sollecito" visibile all'assegnatario
(per la ditta `visibile_impresa=true`) e invia l'email (`NuovaNotaSegnalazione` o notifica
dedicata) a operaio/caposquadra/persone delegate della ditta + email aziendale, con la stessa
regola di destinatari delle notifiche ditta 0.8.19. Testo precompilato modificabile.

## 6. Attività

Sostituisce le linguette Note / Comunicazioni, Storico, Allegati. Una lista cronologica
(più recente in alto) che fonde:

- note (`note_segnalazioni`), filtrate con `NotaSegnalazione::visibiliA()` (0.8.22);
- cambi di stato (`stati_segnalazioni`) come frasi: "Anna Gestore ha assegnato a Paolo Neri";
- allegati caricati (con miniatura per le immagini, stato scansione antivirus come oggi);
- eventi di accorpamento/collegamento (§7).

Per chi non è del comune, i cambi di stato si mostrano solo come tappe (la barra) e come frasi
neutre ("Presa in carico", "Lavoro chiuso"), senza nomi interni per il segnalante; la ditta vede
gli eventi della propria assegnazione in poi. Gli allegati: tutti per il comune; ditta e segnalante
quelli che vedono oggi (nessun cambio di regola in questo giro — da verificare nel piano che oggi
non ci sia la stessa fuga delle note).

**Scrivere** — un campo unico in cima:
- comune: "Chi la vede: Solo comune · + ditta · + segnalante" (default Solo comune; "+ ditta"
  solo se c'è un'impresa assegnata), "Allega", "Pubblica nota";
- ditta: scrive sempre al comune; la nota è `visibile_impresa=true` (la vedono le altre persone
  della ditta), mai `visibile_web`;
- segnalante: scrive sempre al comune; la nota è `visibile_web=true` (la vedono gli altri
  segnalanti della stessa scuola, coerente con la visibilità per plesso), mai `visibile_impresa`.

**Avvisi** (correzione di `notificaNota`): oltre a quanto avviene oggi, una nota scritta da ditta
o segnalante avvisa il comune: il gestore assegnatario se c'è, altrimenti i gestori supervisori
(stessi destinatari del digest). Nota del segnalante su una segnalazione assegnata a ditta: il
comune decide se girarla (nessun inoltro automatico alla ditta).

## 7. Accorpa e Collega

### 7.1 Accorpa a una segnalazione esistente (sostituisce "Segna come duplicata" e "Unisci duplicato")

Dove: menu "⋯" della scheda gestore e prossimo passo di una Nuova. Pannello laterale:
- proposte: segnalazioni **aperte** simili (`DedupService`, stesse regole del controllo
  anti-duplicato alla creazione) + ricerca per numero o testo; ogni riga mostra titolo, sede,
  stato, data;
- riepilogo di cosa succederà (testo statico) e conferma.

Effetti, in una transazione:
1. **Nuova** (quella che si accorpa): stato DUPLICATA (etichetta mostrata "Accorpata"),
   `id_segnalazione_madre` = vecchia, `data_chiusura` = ora, storico stato.
2. Allegati e adesioni della nuova → vecchia (come fa oggi `unisci`).
3. Il segnalante della nuova (utente o dati anagrafici) diventa **adesione** della vecchia.
4. **Vecchia**: evento in Attività "Segnalata di nuovo da {segnalante} il gg/mm" con il testo
   della nuova (nota `visibile_web=false`, `visibile_impresa` = true se c'è una ditta
   assegnata, così la ditta vede il sollecito) + allegati spostati visibili come oggi.
5. Contatore "segnalata N volte" = 1 + adesioni (già esistenti) sulla vecchia, mostrato in
   testata e nell'elenco come etichetta **Sollecitata** quando N > 1 e la segnalazione è aperta.
6. **Avvisi**: assegnatario della vecchia (operaio/caposquadra/ditta come in §5 Sollecita); se
   non assegnata, gestori supervisori.
7. Al segnalante della nuova: nota pubblica automatica sulla nuova "Era già stata segnalata: segui
   gli aggiornamenti nella #id" + email.
8. **Priorità non cambia** da sola (decide il comune, come 0.8.21): il prossimo passo della vecchia
   propone "Alza priorità".

Vincoli: non si accorpa a sé stessa, a una chiusa, a una già accorpata (si risale alla madre),
né una segnalazione già chiusa. Permesso: `update` su entrambe.

**Partecipanti**: chi ha un'adesione con `id_utente` vede la vecchia nella propria lista ("Le
mie segnalazioni", con etichetta "Segui") e nella lettura segnalante, riceve gli avvisi delle note
`visibile_web` e può scrivere. Va aggiunto a `SegnalazionePolicy::view` e `scopeVisibileA`
(stessa regola unica di §4.3).

Rimozione: sparisce l'azione "Segna come duplicata" dal menu azioni e il riquadro "Unisci
duplicato"; la rotta `segnalazioni.unisci` e il ramo `segna_duplicata` del workflow vengono
sostituiti dal nuovo comando. Le segnalazioni già Duplicata restano leggibili (con la madre se
impostata; senza madre mostrano solo "Accorpata").

### 7.2 Collega a precedente (guasto ricorrente)

Resta un'azione distinta; la nuova **resta aperta**. Migliorie:
- proposte: segnalazioni **chiuse** della stessa sede e tipologia negli ultimi 24 mesi + ricerca;
- nella scheda della nuova, riquadro in "Collegate" (e riga in Attività): "Stesso problema
  riparato il gg/mm/aaaa da {ditta/operaio}, N giorni fa" con link;
- nella vecchia: "Si è ripresentato il gg/mm (#id)";
- nell'elenco: etichetta "Ricorrente" (filtro disponibile).
Nessun effetto su priorità o assegnazione.

## 8. Elenco (gestore) — mockup 1

- Testata: titolo + riassunto ("226 aperte, di cui 73 da smistare"), "Stampa ed esporta ▾"
  (Stampa lista, Stampa schede (N), Esporta CSV, Report mese), "Nuova segnalazione".
- **Da guardare**: 3 riquadri cliccabili = filtri — tempo massimo superato, scadono entro 24 h,
  nuove da smistare. Nascosti se a zero.
- **Viste** con conteggio, un solo controllo (sostituisce card KPI + tab): Da smistare (=Nuove),
  Aperte, In carico, In lavorazione (=In gestione), In evidenza, Chiuse.
- Ricerca + bottone **Filtri** (pannello con i filtri attuali: tipologia, provenienza, operatore,
  priorità, specializzazione, date + nuovi Sollecitata, Ricorrente) + etichette dei filtri attivi
  con ×, "Azzera". I filtri si mantengono cambiando vista (oggi le tab ne perdono una parte).
- Ordinamento, default **più urgenti**: scadute prima (per ritardo), poi per scadenza crescente,
  poi priorità; alternative: più recenti, più vecchie.
- Colonne: Priorità (pallino + parola + URGENTE) · Segnalazione (titolo; sotto `#id` · tipologia ·
  sede; lucchetto se riservata, stella se in evidenza, etichette Sollecitata/Ricorrente) · Stato ·
  Assegnata a ("Da assegnare" in grigio) · Scadenza · menu ⋮ (azioni rapide come oggi).
  Rimosse: colonna Visibilità e toggle in riga, colonna Data (in tooltip/ordinamento), "Apri →"
  (si apre dal titolo / riga).
- Sotto 768 px: righe a scheda (titolo, sotto stato+scadenza), niente scroll orizzontale.

Altri elenchi (segnalatore, ditta, operaio): stessi componenti, colonne per ruolo; nella lettura
segnalante niente priorità/assegnatario, sì "Segui" per le partecipate.

## 9. Pannello azioni — mockup 3

Le azioni con dati (assegna, preventivo, chiusura con rapportino, sospendi con motivo, annulla,
accorpa, collega) si aprono in un **pannello laterale** dalla scheda, non più in una linguetta con
select. Domande guidate al posto dei campi tecnici:
- Assegna: "A chi lo affidi?" Operaio · Squadra · Ditta → per Ditta, **ricerca appalto** per ditta
  o descrizione tra gli appalti validi (oggi si digita l'ID); avviso di chi riceverà l'email.
- Chiudi con rapportino: ore, materiali, foto (obbligatorie come oggi), descrizione.
- Accorpa / Collega: §7.
Le azioni senza dati (Prendi in carico, Riprendi, Accetta preventivo) eseguono con conferma breve.
Validazione e regole restano quelle di `SegnalazioneWorkflowService`.

## 10. Linguaggio visivo

- Solo token di `resources/css/app.css` (`--ente-primary*`, slate, amber, rose, emerald, violet,
  radius, shadow). Il colore dell'ente resta configurabile (Impostazioni brand).
- Componenti Blade nuovi in `resources/views/components/pa/`: `card`, `pill` (stato, mappa unica
  stato→tono in `SegnalazioneStato::tono()` al posto di `badgeClass()`), `prio`, `scadenza`,
  `btn` (primary/secondary/ghost), `menu`, `drawer`, `timeline-item`, `field`.
- Colori di stato coerenti ovunque: Nuova slate · In carico primary · Assegnata violet ·
  Preventivo amber · Sospesa amber · Completata emerald · Duplicata/Annullata/Archiviata slate.
- Contrasti AA (testo ≥ 4.5:1), target touch ≥ 44 px nella lettura lavoro, niente emoji come
  icone (oggi 🔒 🔓 👷 ☎ ✉): icone SVG o Font Awesome già incluso.
- Testi in italiano semplice, sentence case (niente MAIUSCOLETTO nei bottoni).

## 11. Rilasci

Decisione del committente (in fase di piano): tutto in un unico rilascio **0.9.0** (minor, non
più quattro patch), PR unica con CHANGELOG + `publiccode.yml`, test TDD, suite completa.
Contenuto: fondamenta e scheda nelle tre letture, prossimo passo, Attività, avvisi al comune,
Sollecita, Fine lavori, pannello azioni, Accorpa e Collega (§7, nessuna migrazione: colonne
esistenti) con rimozione di "Segna come duplicata"/"Unisci duplicato", elenco gestore (§8),
elenchi segnalatore/ditta/operaio.

## 12. Lasciato al costruttore

- Nomi esatti di classi/partial/componenti, purché rispettino la struttura sopra.
- Come calcolare le date delle tappe in modo efficiente (eager load dello storico).
- Ricerca appalti/segnalazioni nel pannello: endpoint JSON + Alpine, con debounce; niente
  librerie nuove senza necessità.
- Microcopy oltre a quella fissata qui, nello stesso registro.

## 13. Scelte fatte dal progettista (da verificare dal committente)

- Il segnalante non vede nomi interni ("Ufficio manutenzioni", configurabile).
- Accorpare non alza la priorità; propone "Alza priorità".
- La nota del segnalante non va in automatico alla ditta.
- Default visibilità di una nota del comune: "Solo comune".
- Sollecita come nuovo comando del gestore.
- Collega: proposte limitate a stessa sede e tipologia, ultimi 24 mesi.
- Etichetta "Accorpata" per lo stato DUPLICATA (solo testo mostrato; enum invariato).
