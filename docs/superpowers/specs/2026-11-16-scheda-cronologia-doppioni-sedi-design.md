# Scheda segnalazione leggibile, doppioni fermati, sedi sulla mappa

Data: 2026-11-16 · Versione prevista: 1.0.2 (patch) · Mockup approvato: `.superpowers/brainstorm/1165-1794819166/content/scheda-v2.html` (non versionato; i contenuti che contano sono riportati qui sotto).

## Perché

Il committente ha aperto la #3579 in produzione e non ha capito cosa fosse successo: storia piatta, "ha preso in carico" due volte, etichette "Segnalante"/"Interna" ambigue, nessuna mappa, e una seconda segnalazione identica (#3588) che la scuola ha potuto aprire con la prima ancora aperta. Parole sue: "non si capisce molto", "i tag di stato non sono chiari", "manca di anima l'UI".

Verificato sui dati di produzione:

| Sintomo | Causa |
|---|---|
| "Ha preso in carico" due volte, "ha ripreso" sull'evento sbagliato | (a) `Segnalazione::storicoStati()` ha già `orderByDesc('data_registrazione')`: in `Attivita::voci()` l'`orderBy('data_registrazione')` aggiunto dopo non lo sostituisce, quindi il ciclo scorre dal più recente e `$precedente` è lo stato *successivo*. (b) "Assegna a me" su una segnalazione già in carico a sé scrive una riga di storico identica (stato 2 → 2, stesso assegnatario). Storico #3579: `2` 08:16 · `6` 14:00:34 · `2` 14:01:02 (Riprendi) · `2` 14:01:23 (Assegna a me, a vuoto). |
| Doppione #3588 | Stessa persona (utente 72), stessa sede (2), stessa tipologia (7), #3579 aperta. Il form mostra le simili ma "È un problema diverso, continuo" basta a passare. Per l'autore della prima non c'è nemmeno un'alternativa sensata: "Anche per me" è un'adesione, e lui è già l'autore. |
| Nessuna mappa | 0 sedi su 38 hanno coordinate. Mappa con puntina trascinabile, campi lat/lng e "trova dall'indirizzo" esistono già in `admin/sedi/edit`, il job `GeocodificaSedi` esiste, ma parte solo a mano e nessuno l'ha lanciato. |
| Tag ambigui | `Attivita::tagNota()` dice *chi vede* la nota ("Segnalante", "Interna") con una pill uguale a quelle di stato. Letta come autore o come stato. |
| Messaggi "finti" | Le note automatiche ("Scadenza spostata al…", "Segnalata di nuovo da…", "Sollecito: …", "Rimandata al comune: …", "Priorità aumentata…") sono note normali a nome dell'operatore: sembrano scritte da lui. |

## Obiettivi

1. Chi apre la scheda gestore capisce in cinque secondi: a che punto è, di chi è, quando scade, com'è andata finora.
2. La storia racconta i fatti veri, una volta sola, con parole che non richiedono di conoscere il sistema.
3. Con una segnalazione già aperta per la stessa sede e tipologia, la persona viene portata a sollecitare o integrare quella (salvo preventivo già fatto).
4. Le sedi hanno la posizione senza che nessuno debba ricordarsi di lanciare qualcosa.

## Non obiettivi

- Letture **segnalante** e **lavoro** (operaio/ditta): il layout generale resta; cambiano solo cronogramma (A3, al posto della barra a 4 tappe per il segnalante) e resa della storia (partial condiviso). Niente "quattro fatti" per loro.
- Elenchi, stampa, fascicolo PDF, API, webhook: invariati.
- Nessuna riscrittura dello storico già a DB: le righe doppie vecchie si nascondono in lettura.
- Nessuna coordinata per singola segnalazione (tolte in 1.0.0): la posizione resta quella della sede.

## Anti-obiettivi

- Un cronogramma che dice il falso: se un dato manca (segnalazione legacy senza storico) il tratto è "non noto", mai inventato.
- Un blocco doppioni che impedisce di segnalare un problema davvero diverso.
- Chiamate a Nominatim durante una richiesta web o a raffica (regola d'uso: 1 al secondo, già rispettata dal job).

## Approcci considerati

**Cronogramma** — (1) HTML/CSS reso dal server da una classe di supporto, larghezze in percentuale, hover in CSS: nessuna libreria, testabile in PHP, stampabile, coerente con `Scadenza`/`ProssimoPasso`. (2) Chart.js: già in progetto, ma un grafico a barre temporali con marcatori e fasce richiede plugin e la logica finisce in JS inline non testato. (3) SVG generato in Alpine: stessa obiezione di 2. **Scelto 1.**

**Note automatiche** — (1) colonna `note_segnalazioni.origine` + backfill per prefisso: il feed sa cosa è un fatto e cosa un messaggio, e il conteggio "segnalata N volte" diventa una query. (2) Riconoscere i prefissi del testo in lettura: nessuna migration ma fragile a ogni ritocco di una frase. (3) Spostare i fatti nello storico stati: lo storico alimenta i KPI, sporcarlo è rischioso. **Scelto 1.**

**Doppioni** — (1) proposta nel form (sollecito o integrazione sull'esistente) con via d'uscita esplicita + controllo sul server: la strada facile diventa scrivere sull'esistente, chi ha un problema diverso passa lo stesso ma il gestore lo vede. (2) Accorpamento automatico all'invio: l'utente crede di aver aperto qualcosa che non esiste, e un falso positivo fa sparire un problema vero. (3) Solo avviso più evidente: è quello che c'è già e non ha funzionato. **Scelto 1.**

Il committente ha lasciato a chi progetta la scelta del come; le tre scelte sopra sono di chi ha scritto la spec.

---

## A. Scheda gestore

Ordine dall'alto: testata → quattro fatti → cronogramma → prossimo passo → (avvisi esistenti: inattività, tipologia orfana) → griglia 2/3 + 1/3.

```
#3579 · RETE TELEFONICA / DATI · IC DELFICO                 [☆] [Stampa ▾] [Altro ▾]
Telefono non funzionante  (modifica)
┌ STATO ───────────┬ SCADENZA ─────────┬ PRIORITÀ ───────┬ APERTA DA ─────────┐
│ ● In carico      │ Oggi alle 14:00   │ ● Alta          │ 6 giorni           │
│ a Mirko, da ven  │ mancano 4 ore ·   │ regola: 2 gg    │ segnalata 2 volte ·│
│                  │ spostata a mano   │ lavorativi      │ 3 risposte         │
└──────────────────┴───────────────────┴─────────────────┴────────────────────┘
┌ COM'È ANDATA FINORA      3 giorni senza che nessuno la aprisse · 3 in carico ┐
│        ●  ● ○                                              ●  |ora  ▌scadenza│
│ ░░░░░░░░░░░░░░░░░░████████▒▒▒ SABATO E DOMENICA ▒▒▒████████                  │
│ mar 10   mer 11   gio 12   ven 13   sab 14   dom 15   lun 16                 │
└──────────────────────────────────────────────────────────────────────────────┘
┌ ADESSO TOCCA A TE  Scade tra 4 ore: assegnala o chiudila   [Assegna…][Chiudi]┐
```

### A1. Testata

Come oggi (`_testata`) ma **senza** la riga di pill stato/priorità/scadenza: passano nei quattro fatti. Restano accanto al titolo solo le etichette accessorie (Riservata, Ricorrente). "Segnalata N volte" passa nel fatto "Aperta da". I menu a destra restano; "Stampa" e "PDF fascicolo" escono da "⋯" in un menu "Stampa ▾".

### A2. Quattro fatti (`scheda/_fatti.blade.php`, nuovo)

Griglia a 4 colonne, 2×2 sotto 900px. Ogni riquadro: etichetta, valore grande, riga di dettaglio.

| Fatto | Valore | Dettaglio | Modifica |
|---|---|---|---|
| **Stato** | `SegnalazioneStato::etichetta()` con pallino del colore dello stato | chi e da quando: "a Mirko D'Addiego, da venerdì" · "a Edil Rossi srl, da 2 giorni" · "nessuno l'ha ancora presa" (Nuova) · chiusa: "da Mirko D'Addiego, il 16/11" | — |
| **Scadenza** | in parole da `Support\Segnalazioni\Scadenza` ("Oggi alle 14:00", "Domani alle 14:00", "Scaduta da 2 giorni") | "mancano 4 ore" (ore lavorative) + "· spostata a mano" se `scadenza_manuale`. Senza regola: "nessuna scadenza". Chiusa: valore "Chiusa in 3 giorni", dettaglio "entro la scadenza" / "2 giorni oltre la scadenza" | "sposta" → pannello `scadenza` (stessa policy di oggi) |
| **Priorità** | etichetta con pallino | "regola: 2 giorni lavorativi" dalla `SlaConfigurazione` applicata, omesso se non c'è | "cambia" → pannello `priorita` |
| **Aperta da** | "6 giorni" (di calendario; "oggi" se < 1) | "segnalata 2 volte · 3 risposte a chi ha segnalato", ogni parte omessa se zero/uno | — |

Tono del riquadro Scadenza: ambra se a rischio, rosso se superata, neutro altrimenti (stesse soglie di `Scadenza`). Il riquadro Stato ha sempre il bordo sinistro del colore dello stato.

"Da venerdì": giorno della settimana se entro 6 giorni, altrimenti "dal 3/11".

### A3. Cronogramma (`Support/Segnalazioni/Cronogramma`, `scheda/_cronogramma.blade.php`, nuovi)

`Cronogramma::per(Segnalazione $s, CarbonInterface $ora): array` restituisce dati già pronti per la vista (posizioni in percentuale), senza HTML:

- **intervallo**: da `data_segnalazione` a `max(ora, scadenza)`; se chiusa, a `max(data_chiusura, scadenza)`.
- **tratti**: uno per ogni permanenza in uno stato, ricavati dallo storico ordinato crescente (data, poi id). Il primo tratto è "Nuova" da `data_segnalazione` al primo evento di storico (se il primo evento è già a `data_segnalazione` non c'è). Righe consecutive con stesso stato **e** stesso assegnatario si fondono (vedi B2). Ogni tratto: stato, chi (assegnatario: operatore, squadra o ditta; per Nuova "nessuno"), chi l'ha deciso, da, a, durata in parole, giorni lavorativi (`CalendarioUfficio`).
- **marcatori**: note visibili a chi ha segnalato (verde), nuove segnalazioni/solleciti (ambra), pause brevi (cerchio vuoto ambra). Le note interne **non** sono marcatori (rumore). Marcatori entro l'1,5% di larghezza si raggruppano in uno con contatore.
- **giorni chiusi**: fasce per sabati, domeniche, festivi e patrono da `CalendarioUfficio` (giorni interi senza fascia d'orario). Consecutivi fusi; etichetta ("SABATO E DOMENICA", "FESTIVO") solo se la fascia è larga abbastanza.
- **tacche**: una per giorno fino a 14 giorni, poi una per settimana (lunedì), oltre 90 giorni una per mese.
- **ora** e **scadenza**: marcatori verticali. Scadenza superata: il tratto oltre la scadenza ha una riga rossa sotto la barra.
- **riassunto** (frase in alto a destra): i due numeri che contano, es. "3 giorni senza che nessuno la aprisse · 3 giorni in carico (di cui 2 di fine settimana)". Regola: tempo in Nuova (se ≥ 1 giorno lavorativo) + tempo nello stato attuale.

Scala in **tempo reale** (non in ore lavorative): i giorni chiusi si vedono velati invece di sparire, così la barra corrisponde al calendario che ha in testa chi legge.

Colori dei tratti: Nuova = tratteggio grigio · In carico = primario dell'ente · Assegnata / Assegnata a ditta = viola · Preventivo in attesa = ambra · Sospesa = tratteggio ambra · stati finali = verde (Completata) o grigio pieno. Tratto più stretto di 3px: reso a 3px.

**Hover (richiesta esplicita del committente: "manca il chi")** — ogni tratto e ogni marcatore ha un fumetto:

- tratto: **"In carico a Mirko D'Addiego"** / "presa da lui ven 13, 08:16" (oppure "assegnata da Anna Bianchi ven 13, 08:16") / "da 3 giorni (1 lavorativo)" / eventuale "pausa di 1 minuto ven 14:00". Per Nuova: "Nuova · nessuno l'ha presa" / "da … a …" / "avvisati: 2 gestori della tipologia" (da `notifiche_segnalazioni`, omesso se zero).
- marcatore: "Mirko D'Addiego · ven 08:19 · risposta a chi ha segnalato" · "Patrizia Ricci · lun 08:53 · segnalata di nuovo (#3588)".

Il fumetto deve funzionare anche da tastiera e a tocco: tratti e marcatori sono focusabili (`tabindex="0"`, `:focus-visible` mostra il fumetto, tocco = focus). Il contenuto del fumetto è anche nell'`aria-label`. Sotto la barra una legenda con le sole voci presenti.

Sotto 768px il cronogramma resta (barra a tutta larghezza, tacche diradate); legenda su più righe.

Segnalazione legacy senza storico e chiusa: un solo tratto "non noto" grigio chiaro dall'apertura alla chiusura.

**Per chi legge** (decisione del committente: il cronogramma lo vedono anche scuole e ditte) — `Cronogramma::per()` riceve anche il lettore e applica le stesse regole di `Attivita`:

| | Gestore | Segnalante (scuola, personale) | Lavoro (operaio, ditta) |
|---|---|---|---|
| Intervallo | dall'apertura | dall'apertura | da quando il lavoro è arrivato a loro (`Attivita::inizioLavoro`) |
| Nomi dei tratti | stati interni | titoli di `fraseSegnalante()`: "Ricevuta", "Presa in carico", "Ci stiamo lavorando", "In attesa", "Risolta"… (Assegnata e Assegnata a ditta sono lo stesso tratto) | stati interni |
| Chi, nel fumetto | nomi veri | mai nomi: `etichetta_ufficio_segnalante` ("Ufficio manutenzioni"); "tu" per sé | nomi veri, come già nella storia |
| Marcatori | risposte al segnalante, nuove segnalazioni, pause | messaggi dell'ufficio e propri, propri solleciti/integrazioni | note visibili a loro |
| Riassunto | "3 giorni senza che nessuno la aprisse · …" | frase neutra: "Aperta da 6 giorni · presa in carico venerdì" (nessun giudizio sull'ufficio) | "Assegnata a te da 2 giorni" |
| Scadenza | sì | sì, come data prevista ("prevista entro lunedì 16") | sì |

Nella lettura segnalante il cronogramma **sostituisce** `_avanzamento` (la barra a 4 tappe dice le stesse cose con meno dettaglio); nella lettura lavoro va sotto il prossimo passo.

### A4. Prossimo passo

`ProssimoPasso` e `_prossimo-passo` restano. Due ritocchi nella lettura gestore:

- etichetta "Prossimo passo" → "Adesso tocca a te" quando la segnalazione è in carico a chi legge, "Adesso tocca a {nome}" quando è di un altro; invariata negli altri casi;
- sottotitolo arricchito, in quest'ordine, con al massimo due frasi: scadenza vicina ("Scade tra 4 ore") · nuova segnalazione o sollecito negli ultimi 3 giorni lavorativi ("Chi ha segnalato l'ha segnalata di nuovo stamattina").

I pulsanti "Priorità…" e "Scadenza…" escono dalla barra del prossimo passo (ora sono nei fatti): restano le azioni di lavoro.

### A5. Cosa è stato segnalato

Testo, foto, riga "Patrizia Ricci · martedì 10 novembre, 09:13" (data per esteso). Sotto, **una fascia ambra per ogni volta che è stata segnalata di nuovo**, dalla più recente:

- segnalazioni accorpate qui (`id_segnalazione_madre` = questa): "Segnalata di nuovo lunedì 16 novembre, 08:53 · #3588 accorpata qui" + testo dell'accorpata;
- integrazioni dal form di nuova segnalazione (note `origine = integrazione`, vedi C): data + testo. I solleciti senza testo non compaiono qui (sono nella storia e nel contatore).

Le adesioni di altre persone restano una riga sola ("e da altre N persone").

### A6. Storia (ex "Attività")

Titolo della card: **Storia**.

**Composer** — al posto della casella "+ segnalante", un selettore a due (o tre) posizioni con icona e parole intere: 🔒 "Solo il comune" (default) · 👁 "La legge anche {chi ha segnalato}" · con ditta assegnata anche "La legge anche la ditta" (le due spunte attuali restano i campi inviati: `visibile_web`, `visibile_impresa`). Etichetta di chi ha segnalato, usata ovunque in questa spec: "la scuola" se l'autore è `isSpid()`, altrimenti "chi ha segnalato".

**Filtri** (Alpine, lato client, nessuna richiesta): Tutto · Solo passaggi · Solo messaggi a {chi ha segnalato}.

**Raggruppamento per giorno** — intestazioni "Oggi · lunedì 16 novembre", "Ieri · …", poi "Venerdì 13 novembre"; anno solo se diverso dal corrente. Dentro il giorno, ora a sinistra (HH:MM), binario verticale con un nodo per voce, dal più recente.

**Vuoti** — tra due giorni con attività, se in mezzo ci sono giorni senza: una riga-intestazione "Mercoledì e giovedì · nessuna attività · 2 giorni lavorativi fermi"; se sono solo giorni chiusi: "Sabato e domenica · nessuna attività, ufficio chiuso". Oltre 3 giorni: "Dal 3 al 9 novembre · nessuna attività · 5 giorni lavorativi fermi".

**Due forme di voce**:

- *passaggio* (riga sottile, nodo pieno del colore dello stato): cambi di stato, assegnazioni, e i fatti automatici. "**Mirko D'Addiego** l'ha presa in carico → **In carico**".
- *messaggio* (riquadro): note scritte da una persona. Intestazione "**Mirko D'Addiego** ha risposto a chi ha segnalato" (nota visibile al segnalante scritta dal comune) / "ha scritto una nota" (interna) / "**Patrizia Ricci** ha scritto" (segnalante). A destra, al posto della pill, **chi la legge** con icona: 👁 "La scuola la legge" (verde, e riquadro con fondo verde chiaro) · 🔒 "Solo il comune" (grigio) · "La legge la ditta" · "La leggono ditta e scuola". Solo lettura gestore, come oggi.

Un passaggio con nota scritta nello stesso momento dallo stesso autore (stesso minuto: es. sospensione con motivo, chiusura con nota) si mostra come **una** voce: riga del passaggio + testo della nota dentro un riquadro.

**Frasi dei passaggi** (lettura gestore/lavoro; il segnalante continua a vedere `fraseSegnalante()`):

| Evento | Frase |
|---|---|
| storico 2, `id_utente_collegato` = autore (o 0 e stato precedente ≠ 6) | "l'ha presa in carico" |
| storico 2, collegato ≠ autore | "l'ha affidata a {nome}" |
| storico dopo Sospesa, stato ≠ 6 | "ha ripreso il lavoro" |
| storico 6 | "ha messo in pausa" |
| storico 3 / 4 | "l'ha assegnata a {operaio / squadra / ditta}"; se prima era già 3 o 4: "l'ha riassegnata a …" |
| storico 2 o 1 scritto da chi aveva il lavoro | "l'ha rimandata al comune" (come oggi) |
| 5, 7, 8, 9, 10 | come oggi, con "l'" dove serve ("l'ha chiusa", "l'ha chiusa senza intervento", "l'ha accorpata alla #N", "ha presentato un preventivo", "archiviata per inattività" con autore "Sistema") |
| nota `origine = scadenza` | "ha spostato la scadenza a **lunedì 16, 14:00**" + motivo se c'è |
| nota `origine = risegnalata` (da accorpamento) | "**{segnalante}** l'ha segnalata di nuovo (#3588) · accorpata qui da {attore} alle 09:13", collocata all'ora della seconda segnalazione |
| nota `origine = integrazione` | "**{segnalante}** ha aggiunto informazioni" + testo |
| nota `origine = sollecito` | "**{autore}** ha sollecitato" + testo se diverso da quello predefinito |
| nota `origine = rimando` | fusa col passaggio "l'ha rimandata al comune" + motivo |
| nota `origine = priorita` | "priorità aumentata a {livello} dopo N adesioni" (autore Sistema) |
| nota `origine = senza_intervento` | fusa col passaggio di chiusura: motivo + nota |
| prima voce | "**Patrizia Ricci** ha inviato la segnalazione · avvisati N gestori" (N da `notifiche_segnalazioni`, omesso se 0) |

### A7. Colonna di destra

- **Chi ci lavora**: avatar con iniziali, nome, ruolo ("gestore", "operaio", "ditta", "squadra"), "in carico da venerdì 14:01", link Riassegna. Nuova: "Nessuno l'ha ancora presa" + pulsante "Assegna a me".
- **Dove**: minimappa in alto (Leaflet, già caricata da `show.blade.php`, altezza 170px, non trascinabile finché non ci si clicca), nome sede, indirizzo, ubicazione, "Apri in Maps" · "Correggi la posizione" (solo a chi può modificare le sedi: va a `admin.sedi.edit` con `?ritorno=` verso la scheda, e dopo il salvataggio torna qui). Referente su riga propria con pulsante telefono (`tel:`), come già fa la lettura lavoro. **Senza coordinate**: al posto della mappa un riquadro tratteggiato "Posizione sulla mappa non trovata per {indirizzo}" con, per chi può modificare le sedi, "Indica sulla mappa" (stesso link); agli altri solo il testo.
- **Dettagli**: la card sparisce (tipologia in testata, aperta/scadenza nei fatti). Preventivo e liquidato, quando presenti, passano in una card "Importi".
- **Collegate**: resta per collegamenti a precedenti e per la madre; le accorpate qui sono già in A5 e non si ripetono.
- **Avvisi inviati** (ex "Notifiche inviate"): riepilogo per destinatario — "A chi ha segnalato: 4 email · ultima oggi 09:14", "Ai gestori: 2 email · mar 10, 09:13", "Alla ditta: …" — e "Vedi tutti" che apre l'elenco attuale.

## B. Correzioni alla storia (dati e workflow)

**B1. Ordine dello storico** — in `Attivita::voci()` (e in `Cronogramma`) leggere lo storico con `reorder('data_registrazione')->orderBy('id')`. Stesso controllo per ogni altro punto che fa `storicoStati()->orderBy(...)` contando sull'ordine crescente (`_avanzamento` usa `sortBy` sulla collection: è corretto).

**B2. Passaggi a vuoto** — `SegnalazioneWorkflowService::eseguiTransizione`: se l'azione non cambia né lo stato né l'assegnatario (operatore, squadra, appalto), **non** scrive lo storico, non manda notifiche né webhook; nota e scadenza dell'eventuale pannello si salvano comunque. Il pannello Assegna non propone "a me" quando è già in carico a chi guarda. In lettura (`Attivita`, `Cronogramma`) righe consecutive con stesso stato e stesso assegnatario si mostrano una volta sola: copre i doppioni già a DB senza toccarli.

**B3. Origine delle note** — migration additiva: `note_segnalazioni.origine` `varchar(20)` nullable (null = scritta da una persona). Valori: `scadenza` `risegnalata` (nota di accorpamento) `sollecito` `integrazione` `rimando` `priorita` `senza_intervento`. Costanti su `NotaSegnalazione`. Ogni punto che oggi crea una nota automatica imposta l'origine (`ScadenzaManuale::imposta`, `AccorpamentoService::accorpa` — solo la nota sulla destinazione —, `sollecitoSegnalante` e sollecito del gestore, `rimandaAlGestore`, `AdesioniSegnalazioniController::escalaPriorita`, azione `annulla`). La stessa migration valorizza le note esistenti dal prefisso del testo ("Scadenza spostata al ", "Segnalata di nuovo da ", "Sollecito: ", "Rimandata al comune: ", "Priorità aumentata a livello ", "Chiusa senza intervento: "). Il testo resta com'è (lo leggono stampa, PDF, email): il feed toglie il prefisso solo in visualizzazione. `down()` toglie la colonna.

**B4. "Segnalata N volte"** — `Segnalazione::volteSegnalata()` = 1 + adesioni + solleciti e integrazioni arrivati dal form di nuova segnalazione (note `origine` `sollecito`/`integrazione` scritte dall'autore; quelle di altre persone portano già un'adesione, vedi C) + segnalazioni accorpate qui aperte dallo stesso autore di questa (`id_segnalazione_madre` = questa e stesso `id_utente_segnalazione`: per loro `AccorpamentoService::aggiungiAderente` non crea adesione). Da coprire con test sul caso #3579/#3588 (stesso autore → 2).

## C. Doppioni alla creazione

**Regola (decisione del committente)** — aprire una nuova segnalazione non è mai escluso a priori. Ma se per la stessa sede e la stessa tipologia ce n'è già una aperta, la strada proposta per prima è aggiungere lì un **sollecito** o un'**integrazione**. Eccezione: se quella aperta ha **già un preventivo** (`Segnalazione::haPreventivo()`), non si propone nulla e si apre una segnalazione nuova (il lavoro è già stato quotato: un problema in più va trattato a parte).

`DedupService::apertaPerSedeETipologia(User $u, int $idPlesso, int $idTipologia): ?Segnalazione` — tra le `aperte()` di quella sede e tipologia **senza preventivo**, la più recente che l'utente può vedere (`scopeVisibileA`); a parità, prima quella di cui è autore. Vale sempre: indipendente da `adesioni_enabled` e senza il limite di `dedup_giorni` (una segnalazione aperta da quattro mesi è comunque aperta).

**Form** (`segnalazioni/create`): l'endpoint `segnalazioni.simili` restituisce anche `esistente: {id, titolo, stato, aperta_da, tua: bool, ultima_risposta}`. Appena sede e tipologia sono scelte ed `esistente` c'è, il resto del form (priorità, testo, foto, invio) è sostituito dal riquadro:

```
ESISTE GIÀ
C'è già una segnalazione aperta per questo
Stessa sede e stessa tipologia. Scrivi lì: l'ufficio la sta già seguendo
e riceve subito il tuo messaggio.
┌ #3579 · aperta 6 giorni fa da te                          [In carico] ┐
│ Telefono non funzionante                                              │
│ Ultima risposta dell'ufficio, venerdì: «Guasto fibra ancora in corso» │
└───────────────────────────────────────────────────────────────────────┘
[ Hai qualcosa da aggiungere? (facoltativo)                             ]
[Invia alla #3579]
È un problema diverso, apro una nuova segnalazione →
```

("da te" / "da un'altra persona della tua sede" secondo `tua`; nomi mai mostrati al segnalante, come oggi.)

- **Invia** → `POST segnalazioni/{segnalazione}/aggiornamento` (nuova rotta; policy: può vedere la segnalazione, segnalazione aperta; testo facoltativo max 2000, allegati facoltativi con `CaricaAllegati`). Testo vuoto = **sollecito** (nota "Sollecito: il problema c'è ancora, chiedo un aggiornamento.", `origine = sollecito`); con testo o allegati = **integrazione** (nota col testo, `origine = integrazione`). Nota visibile al segnalante; avvisa chi l'ha in carico con gli stessi destinatari di `sollecitoSegnalante` (`DestinatariAssegnatario`, ripiego `DestinatariComune`) e `SollecitoNotification`. Se chi invia non è l'autore, viene anche registrata la sua adesione (`firstOrCreate`, anche con `adesioni_enabled` spento: serve a fargli seguire la segnalazione e a contarlo in "segnalata N volte"). Poi scheda con "Inviato alla #3579". Non passa dalla policy `sollecitaComeSegnalante` (quella regola il pulsante Sollecita nella scheda: scaduta o ferma, uno ogni 3 giorni lavorativi): qui la persona stava per aprire una segnalazione nuova, fermarla sarebbe peggio. Throttle di rotta `6,1`.
- **È un problema diverso** → ricompare il form, con un avviso fisso in alto "Stai aprendo una segnalazione nuova: la #3579 resta aperta" e un campo nascosto `diversa_da = 3579`. Nessun motivo richiesto: basta il testo della nuova.

**Server** (`SegnalazioneController::store`): se `apertaPerSedeETipologia` trova qualcosa e `diversa_da` non corrisponde al suo id → errore di validazione ("C'è già la segnalazione #3579 aperta per questa sede e tipologia") con il riquadro riaperto: nessuno salta la proposta per sbaglio, chi vuole passa con un clic. Con `diversa_da` valido la segnalazione nasce e si salva il legame in `id_segnalazione_correlata` se vuoto (campo già esistente, è il "collega a precedente"): il gestore la trova in "Collegate" con la riga "Aperta con la #3579 ancora aperta" e un pulsante "Accorpa alla #3579" (azione di accorpamento esistente, stessa policy).

**Esistente con preventivo**: nessun riquadro, nessun controllo, form normale. La nuova nasce comunque collegata (`id_segnalazione_correlata`) così il gestore vede il legame.

**Esistente che l'utente non può vedere** (es. personale del comune su una sede dove ha segnalato un altro): come oggi, riquadro "Anche per me" (adesione, solo con `adesioni_enabled`), ridisegnato con gli stessi componenti.

**API** (`POST /api/segnalazioni`): invariata, ha già il 409.

## D. Sedi sulla mappa

- **Geocodifica senza intervento** — nuova colonna `plessi.geocodifica_tentata_at` (datetime nullable). Comando `sedi:geocodifica` schedulato ogni giorno alle 06:10: se esistono sedi con indirizzo, senza coordinate e mai tentate, accoda `GeocodificaSedi`. Il job segna `geocodifica_tentata_at` a ogni tentativo concluso (trovata o non trovata; **non** in caso di errore di rete, così si riprova il giorno dopo) e lavora solo sulle sedi mai tentate. Al primo giro dopo l'aggiornamento copre tutte e 38 le sedi senza che nessuno faccia nulla (regola di progetto: mai interventi da console).
- **Sede nuova o indirizzo cambiato** — observer su `Plesso`: se cambia `indirizzo` e nello stesso salvataggio non sono state indicate coordinate a mano, azzera coordinate e `geocodifica_tentata_at`; ci pensa il giro successivo. "Trova le posizioni mancanti" (pulsante esistente) azzera `geocodifica_tentata_at` delle sedi senza coordinate e accoda subito: è il "riprova" manuale.
- **Segnali** — elenco sedi di un ente: accanto a ogni sede senza coordinate un segnale "senza posizione" (ambra) che porta alla modifica; filtro "senza posizione". Il riquadro "Posizione delle sedi" di Stato del sistema esiste già: aggiungere il link all'elenco filtrato.
- **Pagina sede** (`admin/sedi/edit`): mappa, puntina, campi e "trova dall'indirizzo" restano come sono. Aggiunta: parametro `ritorno` (solo percorsi interni, validato) rispettato dopo il salvataggio.
- **Scheda**: vedi A7.

Nessuna nuova impostazione. `geocoding_comune` è vuoto in produzione: gli indirizzi contengono già il comune; se molti risultano non trovati il riquadro di Stato del sistema lo dice.

## Migrations (tutte additive, nessun backup straordinario richiesto)

1. `note_segnalazioni.origine` + backfill per prefisso.
2. `plessi.geocodifica_tentata_at`.

## Test

- `Attivita`: storico #3579 riprodotto (2, 6, 2, 2 con gli stessi orari) → una sola "l'ha presa in carico" (08:16), "ha messo in pausa", "ha ripreso il lavoro", nessuna quarta voce. Ordine crescente garantito anche con relazione ordinata al contrario.
- Workflow: "Assegna a me" su segnalazione già propria → nessuna riga di storico, nessuna notifica; con nota → la nota c'è.
- Migration origine: ogni prefisso riconosciuto; nota libera che comincia per caso con "Sollecito" ma senza i due punti → resta null.
- `Cronogramma`: tratti, fusione dei consecutivi, percentuali che sommano a 100, giorni chiusi dal calendario (fine settimana + un festivo), scadenza superata, segnalazione chiusa, legacy senza storico, tacche a 5/30/200 giorni, raggruppamento marcatori.
- Fatti: testi di stato/scadenza/aperta da nei casi nuova, in carico, a ditta, scaduta, chiusa in tempo, chiusa in ritardo, senza regola SLA.
- Storia: raggruppamento per giorno, righe di vuoto (lavorativi vs chiusi), fusione passaggio+nota, etichette "chi la legge" nei quattro casi, nomi nascosti al segnalante come oggi (`Attivita` già lo copre: non regredire).
- Doppioni: una aperta per stessa sede/tipologia visibile all'utente → `store` rifiutato senza `diversa_da`; con `diversa_da` → creata e collegata; esistente **con preventivo** → nessun blocco, creata e collegata; quella chiusa non blocca; altra sede o tipologia non blocca; esistente non visibile → percorso adesione di oggi. Aggiornamento: testo vuoto → nota `sollecito`, con testo → `integrazione`; notifica a chi l'ha in carico; non autore → adesione creata una volta sola; 403 a chi non può vederla; rifiutato su chiusa. `volteSegnalata()` nei casi adesione / sollecito dell'autore / integrazione di altra persona / accorpata stesso autore / accorpata altro autore.
- Cronogramma per lettore: segnalante senza nomi interni e con titoli di `fraseSegnalante()`; lavoro dall'assegnazione; ditta che ha perso il lavoro non vede più di ciò che già vede nella storia.
- Sedi: comando accoda solo con sedi mai tentate; job segna il tentativo su trovata e non trovata ma non su errore; cambio indirizzo azzera; coordinate a mano nello stesso salvataggio restano; `ritorno` esterno ignorato.
- `AggiornamentoDa0920Test`/prova su copia dei dati di produzione: migrate + `AllineamentoSeeder` ×2, poi apertura di #3579, #3588 e di una decina di segnalazioni a campione in ogni stato (comprese legacy senza storico) senza errori.
- Suite intera prima del push.

## Lasciato a chi costruisce

- Nomi di classi CSS e scomposizione dei partial, purché sui token `.pa-*` esistenti (niente colori nuovi fuori da `app.css`; l'icona occhio/lucchetto in Font Awesome dentro uno `span`, vedi gotcha in CLAUDE.md).
- Soglie esatte di larghezza per etichette delle fasce e raggruppamento dei marcatori.
- Se spezzare il lavoro in più PR: l'ordine consigliato è B (correzioni) → C (doppioni) → D (sedi) → A (scheda); A dipende da B3.
- CHANGELOG e `publiccode.yml` nella stessa PR dell'ultima parte; CLAUDE.md aggiornato (origine delle note, regola doppioni, geocodifica automatica).

## Decisioni del committente (2026-11-16)

- Cronogramma anche per scuole e ditte, con il "chi" al passaggio del mouse.
- Nuova segnalazione mai esclusa a priori; con una già aperta per stessa sede e tipologia si propone sollecito o integrazione; se quella ha già un preventivo, nuova segnalazione.
- Confermati: scala del cronogramma in tempo reale con giorni chiusi velati, "Correggi la posizione" verso la pagina della sede, card Dettagli eliminata, versione 1.0.2.

## Scelte di chi ha scritto la spec

1. Per il segnalante il cronogramma sostituisce la barra a 4 tappe; riassunto in tono neutro, mai nomi.
2. Note interne fuori dai marcatori del cronogramma.
3. La proposta sollecito/integrazione vale per qualsiasi segnalazione aperta di quella sede e tipologia che l'utente può vedere (non solo le proprie), senza limite di giorni e anche con adesioni spente.
4. Un solo campo di testo: vuoto = sollecito, pieno = integrazione.
5. L'invio dal form non ha il limite "uno ogni 3 giorni" del pulsante Sollecita.
