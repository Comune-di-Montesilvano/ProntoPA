# Elenco segnalazioni leggibile — design (1.0.4)

## Perché

"Così è un mischiume. Tutte uguali" (committente, 2026-11-16). Sulle 142 aperte in prod:
106 priorità Media, 132 scadute (rosso grassetto ovunque), 108 Nuove, 108 col lucchetto
"Riservata". I segnali accesi su quasi tutte le righe non distinguono più niente; ciò che
distingue davvero (sede, tipologia) sta nella riga grigia piccola, e i titoli iniziano tutti
con "Si chiede…/Si segnala…".

**Obiettivo:** a colpo d'occhio dove, che tipo, cosa c'è di diverso. Ciò che è uguale tace.
**Non obiettivi:** cambiare filtri, ordinamenti, azioni multiple, conteggi in alto.
**Anti-obiettivo:** perdere informazioni oggi presenti (restano in scheda, filtri o tooltip).

## Approcci

- A. Solo riga ridisegnata.
- **B. Riga ridisegnata + "Raggruppa per" (nessuno/sede/sede poi tipologia/tipologia/tipologia poi sede) nell'elenco gestione — scelto dal committente** dopo la bozza con i dati veri, che ha chiesto anche i due livelli "sede, poi tipologia" e "tipologia, poi sede".
- C. Solo ritocchi (togliere Media/lucchetto, abbassare il rosso): rapido ma la riga resta piatta.

## Design

### Colori unici per tutto il software

Committente: "definire i colori per tutto il software in unico punto. Stati, tipologie, priorità
[…] autoesplicativi (verde e giardini mi aspetto verde). Il colore va usato ovunque, anche su
apertura".

- **Tavolozza** di toni con nome, definita una volta come token CSS in `resources/css/app.css`
  (sfondo tenue, testo scuro, punto/icona): verde, salvia, blu, azzurro, giallo, rosso, arancio,
  marrone, pietra, viola, rosa, indaco, grigio (valori della bozza).
- **Mappa unica in PHP** (`App\Support\Colori`): stato → tono, priorità → tono, elenco toni per le
  tipologie. `SegnalazioneStato::tono()` delega lì.
- **Stati** (segno: pallino + nome): Nuova arancio · In carico azzurro · Assegnata blu · Assegnata a
  ditta indaco · Preventivo in attesa, Sospesa, In attesa di risposta giallo · Completata verde ·
  Chiusa senza intervento, Accorpata, Archiviata grigio.
- **Priorità** (segno: etichetta rettangolare): Critica rosso pieno · Alta rosso chiaro con bordo ·
  Media niente · Bassa grigio.
- **Tipologie** (segno: cella/riquadro pieno): colonna `colore` su `tipologie_segnalazioni`
  (migration additiva, nullable = grigio), scelta da tendina della tavolozza in Admin → Tipologie.
  Valori iniziali per nome (migration + seeder solo se vuoto): Verde e giardini verde · Sfalci e
  potature salvia · Impianto idrico blu · Fontane pubbliche azzurro · Impianto elettrico giallo ·
  Impianto termico rosso · Infissi e porte marrone · Arredi arancio · Manutenzione edile pietra ·
  Rete telefonica/dati viola · Giochi rosa.
- **Componenti Blade unici** `x-pa.stato`, `x-pa.tipologia`, `x-pa.priorita`, usati ovunque: elenchi
  (gestione, operaio, ditta, segnalante), testata/fatti della scheda, nuova segnalazione (riquadri
  tipologia colorati: tenue non scelta, bordo pieno scelta; bottoni priorità con gli stessi colori),
  grafici statistiche (stessi toni). Email escluse.

### Colonne (elenco gestione)

Ordine (committente: "i dati di segnalazione per ultima colonna"):
**Sede · Tipologia · ☐ · Stato · Priorità · Scadenza · Segnalazione · ⋮** (con raggruppamento per
tipologia le prime due si scambiano). Tabella vera con `rowspan` sulle celle di gruppo: le colonne
restano allineate in ogni modalità; senza raggruppamento ogni riga ha le sue celle sede/tipologia.

- **Cella tipologia colorata per intero** (sfondo tenue + testo scuro dello stesso tono, icona + nome
  in grassetto; committente: "i colori sul tipo ci stavano"). Tutto il resto della riga su bianco
  (celle stato colorate provate e scartate: "regressione"). colore da `TipologiaSegnalazione::tono()` (palette fissa di 8, `id % 8`,
  niente migration). Cella sede: sfondo grigio chiarissimo, nome in grassetto con icona scuola.
  Nomi sede e tipologia in maiuscolo/minuscolo leggibile (a DB maiuscoli; parole brevi e, di, a,
  da, del, della, dei, per, in minuscole).
- **Stato = pallino colorato + nome in grassetto (testo scuro del tono), senza sfondo**: segno
  diverso dall'etichetta piena, così stato e tipologia non si confondono anche a tono simile. Sotto,
  in grigio: il nome dell'assegnatario senza preposizione (committente: "a" superflua), "da assegnare" per le Nuove. La colonna "Assegnata a" diventa "Stato".
- **Priorità = colonna sua** (committente: "la priorità si è completamente persa"): etichetta
  rettangolare solo se non Media — 4 Critica rosso pieno testo bianco (doppia freccia), 3 Alta
  arancio chiaro con bordo (freccia su), 1 Bassa grigia (freccia giù), 2 Media cella vuota.
  Telefono: sotto lo stato, solo se presente.
- **Segnalazione**: `#id` mono grigio, testo in peso normale fino a 2 righe (testo fino a
  ~260 caratteri, `line-clamp`).
- **Scadenza**: "In ritardo di 61 g" in testo normale; rosso grassetto solo se scaduta da ≤ 1 giorno
  o scade entro 24 h. Chiuse: invariato.
- **Lucchetto "Riservata"**: tolto dall'elenco (resta in scheda). Stella "In evidenza" resta.

### Altri elenchi (operaio, ditta, segnalante)

Stessa riga (`segnalazioni/_riga`) con le stesse regole visive (striscia stato, tipologia colorata,
testo ultimo, niente Media/lucchetto), senza raggruppamento.

### Raggruppa per (solo `gestione.dashboard`)

- Select "Raggruppa per: nessuno · sede · sede, poi tipologia · tipologia · tipologia, poi sede" accanto a "Più urgenti",
  parametro `gruppo` (`''|sede|sede_tipologia|tipologia|tipologia_sede`), ricordato in localStorage solo come default
  del select (l'URL decide). Default: nessuno (comportamento di oggi).
- La riga non ripete ciò che è nell'intestazione: per sede → tipologia + titolo in grassetto; per
  due livelli → solo titolo in grassetto; per tipologia → sede + titolo.
- **Gruppi = celle laterali con `rowspan`, non righe di intestazione** (richiesta del committente: "usare lo
  spazio orizzontale per i gruppi"). Desktop: una colonna a sinistra per ogni livello (~210px il
  primo, ~180px il secondo) che affianca tutte le righe del gruppo, con nome (sede in grassetto con
  icona scuola, tipologia con la cella colorata), "N · M in ritardo" sul primo livello, niente
  numero sul secondo, contenuto `position: sticky` così resta visibile scorrendo. Le intestazioni di colonna
  dicono "Sede"/"Tipologia" sopra le colonne laterali.
- Telefono (< 768px): le colonne laterali diventano righe di intestazione sopra il gruppo (una per
  livello), stessa informazione.
- Ordinamento: chiave del primo livello → chiave del secondo → ordine scelto.
- Paginazione invariata (30). Conteggi del primo livello sull'intero filtro (una query `groupBy`).
  Un gruppo spezzato tra due pagine ripete la cella laterale con "(continua)".
- Checkbox nella cella del primo livello: seleziona tutte le righe del gruppo nella pagina
  (azioni multiple). Niente chiudi/apri gruppo (la colonna laterale non lo rende necessario).

Bozza approvata: `bozza-elenco.html` (scratchpad di sessione, dati veri, non versionata).

## Lasciato al costruttore

Classi CSS esatte, palette precisa (contrasto AA su sfondo chiaro e scuro), test di dettaglio.
