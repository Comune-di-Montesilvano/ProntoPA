# ProntoPA — Piano di sviluppo

> Aggiornato al 2026-06-10 sullo stato reale del branch `dev` (commit `dad6d44`).
> Punto di vista: ufficio tecnico di una PA italiana che coordina le manutenzioni
> tra segnalatori (scuole, URP, uffici), imprese esterne e operai interni.
>
> **Principio guida**: il sistema deve *togliere* lavoro, non aggiungerne.
> Ogni funzionalità risponde alla domanda: "questa cosa fa risparmiare una
> telefonata, una mail o un giro in macchina a qualcuno?"

---

## 1. Stato attuale (cosa c'è già su `dev`)

| Area | Stato |
|---|---|
| Workflow segnalazioni | 14 stati, 11 azioni, transizioni in `SegnalazioneWorkflowService` |
| Ruoli e visibilità | admin / gestore (±supervisore) / segnalatore / impresa, Spatie + `scopeVisibileA` |
| **Allegati** | Upload multiplo (creazione e scheda), download sicuro, eliminazione con policy, limiti configurabili |
| **Triage** | Priorità 1–4, flag urgente, specializzazione, tipo ubicazione |
| **SLA** | Regole per tipologia/specializzazione/priorità (ore target + warning), comando violazioni, notifiche warning/violazione, escalation priorità per anzianità |
| **Dashboard operaio** | Lavori assegnati per priorità, mappa, statistiche personali, KPI |
| **Dashboard impresa** | Lavori per stato con scadenze |
| **Report** | Mensile gestore (KPI + SLA violati), riepilogo impresa con importi affidato/liquidato |
| Notifiche | Email al cambio stato, bot Telegram (`/lista`, `/apri`, bottoni inline), notifica nuova nota |
| Trasparenza | Pubblicazione automatica, statistiche pubbliche anonimizzate |
| Integrazione | API Sanctum, webhook outbound HMAC in coda |
| Account | Verifica annuale account, disattivazione utenti |
| Deploy | Docker multi-arch su GHCR, Portainer-ready, rootless |

### Bug noto

`SegnalazioneController::store()` salva gli allegati sul disk hardcoded
`'local'`, mentre `AllegatiSegnalazioniController` legge
`allegati_storage_disk` dalle impostazioni: con disk configurato diverso,
download ed eliminazione restituiscono 404. Fix previsto in v0.6.

### Contesto raccolto (brainstorm 2026-06-10)

- Dolori dell'ente in ordine: capire le richieste → coordinare le ditte →
  pratiche che ristagnano → rendicontare.
- Le telefonate arrivano all'**URP/centralino**: vanno inserite nel sistema
  in meno di 30 secondi, altrimenti restano fuori e i dati mentono.
- Ditte esterne "metà e metà": le strutturate useranno il portale,
  gli artigiani comunicano solo via email/telefono.
- Squadra operai mista sul piano digitale: il **caposquadra fa da tramite**.
- Obiettivo a 6 mesi: **produzione nell'ente + pacchetto riusabile** da altri enti.

---

## 2. Piano generale

```
    ADOZIONE          DITTE E CANALI       ASSISTENTE LOCALE      RIUSO E NUMERI
  ┌────────────┐     ┌────────────┐       ┌────────────┐        ┌────────────┐
  │    v0.6    │  →  │    v0.7    │   →   │    v0.8    │   →    │    v1.0    │
  │ Tutto passa│     │ Rapportino │       │ LLM locale │        │ publiccode │
  │ da ProntoPA│     │ magic link │       │ opzionale  │        │ KPI export │
  └────────────┘     └────────────┘       └────────────┘        └────────────┘
```

| Release | Tema | Beneficio per l'ente | Effort indicativo |
|---|---|---|---|
| **v0.6 — Adozione** | URP rapido, anti-duplicato, digest, azioni rapide, squadre | Ogni richiesta entra al primo contatto; il gestore smaltisce la coda in metà tempo | 4–6 settimane |
| **v0.7 — Ditte e canali** | Rapportino fotografico, magic link email, Telegram esteso | L'artigiano lavora senza login; prova documentale che si genera da sola | 4–6 settimane |
| **v0.8 — Assistente locale** | LLM on-premise opzionale: titoli, triage suggerito, dedup semantico | Triage in un tap, liste leggibili, duplicati trovati per significato — dati mai fuori dall'ente | 3–4 settimane |
| **v1.0 — Riuso e numeri** | publiccode, wizard, export contabile, KPI, accessibilità | Relazioni in un click; software pronto al riuso tra enti | 4–5 settimane |

Regole trasversali:

- Nessun campo obbligatorio in più per chi segnala: le informazioni arrivano
  da default intelligenti, non da moduli più lunghi.
- Ogni automatismo è disattivabile da Admin → Impostazioni.
- Migrazioni solo additive (colonne nullable, tabelle nuove): compatibilità
  con i dati legacy garantita.

---

## 3. v0.6 — Adozione ✦ DESIGN APPROVATO

Spec di dettaglio: `docs/superpowers/specs/2026-06-10-v06-adozione-design.md`.

### 3.1 Form unico con modalità compatta e "per conto di"

Nessuna pagina nuova: si potenzia `segnalazioni/create`.

- Fieldset "Per conto di" (nome + telefono chiamante, opzionali) visibile solo
  con permission `segnalazioni.per-conto`: i campi `segnalante`/`telefono`
  smettono di essere sovrascritti con i dati dell'utente loggato.
- Progressive disclosure: essenziali subito, il resto sotto "Più dettagli".
- "Salva e inserisci nuova" per le telefonate in serie. Target: < 30 secondi.

### 3.2 Anti-duplicato con adesioni (+ merge)

- In creazione: endpoint `segnalazioni/simili` (stessa tipologia, stesso
  plesso o entro ~150 m, aperta, ultimi 90 giorni — soglie configurabili).
- Banner "N segnalazioni simili aperte" → "Aggiungiti" oppure "Continua".
- Tabella `adesioni_segnalazioni`: niente doppioni, aderenti notificati alla
  chiusura. Ogni N adesioni priorità +1: il duplicato diventa segnale.
- Merge a posteriori: azione gestore "Unisci a #id" per i duplicati sfuggiti.
- API: `409` con lista simili solo a flag attivo (nessuna rottura integrazioni).

### 3.3 Digest mattutino + azioni rapide

- Job `InviaDigestGestori` (default 07:30): una sola comunicazione per gestore
  con in ritardo / in scadenza / nuove non assegnate, ogni riga linka la
  scheda. Alert immediato solo per violazioni priorità 4.
- Azioni rapide: menu per riga in dashboard gestione con le azioni senza
  parametri (prendi in carico, archivia…). Niente selezione multipla.

### 3.4 Squadre

- Tabelle `squadre` + pivot `squadra_user`; `id_squadra_assegnata` su
  segnalazioni. Flag `squadre_enabled`.
- Assegnazione a operatore singolo o squadra (notifica al solo caposquadra).
- Caposquadra: tab "Squadra", riassegna ai membri, chiude per conto
  (storico registra l'esecutore reale). L'operaio non digitale resta coperto.

### 3.5 Trasversali

- Fix bug disk allegati + suite test feature allegati completa.
- Indice FULLTEXT su `testo_segnalazione` per la ricerca in gestione.

---

## 4. v0.7 — Ditte e canali

### 4.1 Rapportino di fine lavoro

- La proposta di chiusura dell'impresa richiede: ≥1 foto dell'intervento
  eseguito, descrizione, ore/materiali (campi semplici → nota visibile).
- Il gestore riceve foto prima/dopo affiancate → accetta o respinge con
  motivazione. La coppia di foto è la prova documentale che oggi si chiede
  per mail e si archivia a mano: qui si genera da sola e finisce nella
  stampa scheda.

### 4.2 Magic link per ditte non registrate

- Email all'impresa con URL firmati a scadenza: "prendi in carico",
  "carica rapportino" — zero login, l'artigiano clicca dalla mail.
- Le ditte strutturate continuano col portale: due canali, stesso flusso.

### 4.3 Telegram operativo esteso

- `/oggi` (lavori per priorità), ricezione foto via chat (diventa allegato),
  deep-link firmato al flusso rapportino.

### 4.4 Accettazione preventivo tracciata

- `importo_preventivo` esiste già: flusso esplicito invio → accettazione
  con data e utente, visibile nello storico.

---

## 5. v0.8 — Assistente locale (AI opzionale on-premise)

**Perché locale**: i dati delle segnalazioni (nomi, telefoni, luoghi) non
escono dall'ente — niente DPA con fornitori cloud, conformità GDPR per
costruzione. Suggerimenti sempre con conferma umana: rischio limitato ai
sensi dell'AI Act e delle linee guida AGID sull'IA nella PA.

**Architettura**

- Container **Ollama** nel compose sotto profilo opzionale `ai`; flag
  `ai_enabled` e modello configurabile in Impostazioni
  (default `qwen2.5:3b` o `gemma3:4b`, quantizzati, CPU-only).
- Regola ferrea: il LLM lavora **solo in job asincroni** sulla coda Redis
  esistente, mai sul percorso sincrono di una pagina (CPU = pochi token/s).
- Degradazione elegante: Ollama spento o assente → il sistema funziona
  identico, spariscono solo i suggerimenti. L'ente senza risorse non attiva
  il profilo e non perde nulla.
- Contenuti generati sempre marcati come tali (trasparenza).

### 5.1 Titolo automatico

Job post-creazione: dal testo libero un titolo di 6–8 parole, salvato in
colonna `titolo_generato` (nullable). Le liste oggi mostrano il testo
troncato: con il titolo diventano leggibili a colpo d'occhio. Rischio zero,
valore visibile subito — è il pilota ideale.

### 5.2 Triage suggerito

Job post-creazione: il modello propone tipologia, specializzazione e
priorità dal testo. In scheda il gestore vede il badge "suggerito" e
conferma con un tap (o corregge). Nessun campo viene mai scritto
automaticamente: solo proposte. Attacca il dolore n. 1 (capire le richieste).

### 5.3 Dedup semantico

Non generativo: modello di **embeddings** (~100 MB, veloce anche su CPU)
per confrontare i testi per significato. Potenzia l'anti-duplicato di v0.6:
oggi trova simili per tipologia+luogo, con gli embeddings trova "perdita
rubinetto bagno" ≈ "gocciola l'acqua in bagno" anche se inseriti con
tipologie diverse. Vettori salvati in colonna dedicata, confronto coseno
in PHP sui candidati già filtrati per luogo (niente estensioni DB).

**Fuori scope v0.8**: bozze di testi per il gestore, report narrativi,
parsing email (legato a email-in), qualunque chatbot rivolto al cittadino.

---

## 6. v1.0 — Riuso e numeri

### 6.1 Pacchetto riuso (obiettivo "live + riuso")

- `publiccode.yml` + presenza su Developers Italia (CAD art. 69).
- Wizard primo avvio: setup ente guidato al primo login admin.
- Comando `artisan demo`: dati realistici per chi valuta il riuso.
- Documentazione API OpenAPI.
- Audit accessibilità AGID (L. 4/2004) sulle pagine pubbliche.

### 6.2 KPI e rendicontazione

- Tempi medi per transizione da `storico_stati_segnalazioni` (dati già
  presenti da sempre): per tipologia, priorità, impresa, operatore.
- Export CSV/XLSX dai report esistenti (mensile gestore, riepilogo impresa):
  pronto per determine di liquidazione.
- Fascicolo PDF completo (scheda + storico + note + foto prima/dopo).
- 2–3 indicatori aggregati in home pubblica ("tempo medio di risoluzione").

---

## 7. Idee in riserva (valutare dopo v1.0)

- Email-in: casella `segnalazioni@ente` → bozza in coda triage URP
  (con LLM locale di v0.8 per estrarre i campi dal testo email).
- Modulo cittadino pubblico senza account (attivabile per ente).
- Intake guidato per tipologia (domande chiuse → testo strutturato).
- GPS automatico con suggerimento plesso più vicino.
- Stato "in attesa di" con causale (preventivo/ditta/materiale): i report
  distinguono ritardo ente da ritardo fornitore.
- Integrazione protocollo informatico (v2, solo con casi reali).

## 8. Cosa NON fare

- **Niente app native**: PWA + Telegram coprono il campo a costo ~zero.
- **Niente motore BPMN configurabile**: i 14 stati bastano.
- **Niente AI cloud e niente chatbot al cittadino**: l'AI è solo locale,
  asincrona, con conferma umana (v0.8). Mai scritture automatiche.
- **Niente bulk actions** in gestione: troppa superficie di errore.

## 9. Sequenza operativa

1. v0.6 §3.5 fix bug disk + test allegati (subito, è un bug)
2. v0.6 §3.1 form compatto per-conto-di (sblocca l'URP)
3. v0.6 §3.3 digest + azioni rapide
4. v0.6 §3.2 anti-duplicato
5. v0.6 §3.4 squadre
6. v0.7 §4.1 rapportino → §4.2 magic link → §4.3 Telegram → §4.4 preventivi
7. v0.8 §5.1 titolo automatico (pilota) → §5.3 dedup semantico → §5.2 triage suggerito
8. v1.0 in parallelo (aree indipendenti)

Ogni release: branch → PR → test → tag → GHCR → stack Portainer
(CI/CD già in piedi).
