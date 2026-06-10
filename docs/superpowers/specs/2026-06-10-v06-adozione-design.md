# ProntoPA v0.6 — "Adozione" — Design

**Data**: 2026-06-10
**Stato**: approvato dall'utente (revisione sezione per sezione)
**Branch di partenza**: `dev` (commit `b63c60e`)

## Obiettivo

Ogni richiesta di manutenzione — telefonata all'URP, segnalazione a voce, email —
entra in ProntoPA al primo contatto. Misura di successo: percentuale di
segnalazioni create il giorno stesso dell'evento; zero pratiche gestite fuori
sistema.

Principio: il sistema toglie lavoro, non ne aggiunge. Ogni automatismo è
disattivabile da Admin → Impostazioni. Tutte le migrazioni sono additive
(colonne nullable o tabelle nuove): nessun impatto sui dati legacy.

## Contesto emerso in fase di brainstorming

- I dolori dell'ente, in ordine: capire le richieste, coordinare le ditte,
  pratiche che ristagnano, rendicontare.
- Le telefonate arrivano all'URP/centralino, che deve poterle inserire
  in meno di 30 secondi.
- Le ditte esterne sono "metà e metà": le strutturate useranno il portale,
  gli artigiani no (magic link via email: rinviato alla release successiva).
- La squadra operai è mista sul piano digitale: il caposquadra fa da tramite.
- Obiettivo a 6 mesi: produzione nell'ente + pacchetto riusabile da altri enti.

## S2 — Form unico con modalità compatta e "per conto di"

Nessuna pagina nuova: si potenzia `segnalazioni/create`.

- **Fieldset "Per conto di"** (nome e telefono del chiamante, entrambi
  opzionali): visibile solo a chi ha la permission Spatie
  `segnalazioni.per-conto`. Se compilato, `SegnalazioneController::store()`
  scrive quei valori in `segnalante`/`telefono` invece dei dati dell'utente
  loggato (oggi sovrascrive sempre). `id_utente_segnalazione` resta l'operatore
  che inserisce, per tracciabilità.
- **Progressive disclosure**: campi essenziali subito (tipologia, descrizione,
  luogo, urgente, per-conto-di); il resto (specializzazione, tipo ubicazione,
  mappa fine, allegati) sotto un blocco "Più dettagli" collassato (Alpine.js).
- **"Salva e inserisci nuova"**: secondo submit che ritorna al form vuoto,
  per le telefonate in serie.
- Per chi non ha la permission il form si comporta come oggi.
- Niente campo priorità nel percorso rapido: solo il toggle urgente già
  esistente; la priorità fine la imposta il gestore al triage.

## S3 — Anti-duplicato con adesioni (+ merge a posteriori)

**Prevenzione in creazione**

- Scelti tipologia + luogo, il form chiama `GET /segnalazioni/simili`
  (tipologia, plesso, lat, lng).
- Criterio "simile": stessa tipologia AND (stesso plesso OR entro raggio
  configurabile, default 150 m via bounding box su lat/lng) AND aperta AND
  creata negli ultimi N giorni (default 90). Soglie in Impostazioni.
- Banner sopra il submit con anteprima dei simili (testo troncato, stato,
  data, prima foto). Due scelte: "Aggiungiti" oppure "È un problema diverso,
  continua".

**Adesioni**

- Tabella `adesioni_segnalazioni`: `id_adesione`, `id_segnalazione`,
  `id_utente`, `segnalante` (nullable), `telefono` (nullable), `created_at`.
- L'adesione non crea una nuova segnalazione. L'aderente (o il chiamante
  registrato dall'URP) riceve la notifica di chiusura della segnalazione madre.
- Contatore adesioni visibile in scheda e nelle liste.
- Ogni N adesioni (default 3, configurabile) `livello_priorita` aumenta di 1
  fino al massimo 4, con nota automatica nello storico.

**API**

- Solo con `adesioni_enabled` attivo: `POST /api/segnalazioni` risponde `409`
  con la lista dei simili; il portale chiamante può forzare la creazione
  (`force=true`) o registrare un'adesione. A flag spento il comportamento
  resta quello attuale (nessuna rottura per le integrazioni esistenti).

**Merge a posteriori**

- Azione gestore "Unisci a #id" dalla scheda: la segnalazione corrente viene
  chiusa come annullata-duplicato, i suoi dati segnalante diventano
  un'adesione della segnalazione indicata, gli eventuali allegati vengono
  riassociati alla madre. Registrato nello storico di entrambe.

## S4 — Digest mattutino + azioni rapide (senza bulk)

**Digest**

- Job schedulato `InviaDigestGestori`, orario configurabile (default 07:30),
  flag `digest_enabled`, opzione skip weekend.
- Una sola email per gestore attivo (+ Telegram se collegato) con tre blocchi:
  in ritardo (SLA violato, sue assegnate), in scadenza (entro `ore_warning`),
  nuove non assegnate (solo supervisori). Ogni riga linka la scheda.
  Digest vuoto = non inviato.
- Convivenza con `CheckSlaViolazioni`: l'alert immediato resta solo per le
  violazioni con priorità 4; tutto il resto confluisce nel digest.

**Azioni rapide**

- Nella dashboard gestione, menu per riga con le sole azioni senza parametri
  obbligatori per lo stato corrente (da `getAzioniDisponibili`), POST sulla
  route esistente `segnalazioni/{id}/azione`, conferma JS, flash message.
- Le azioni con parametri (assegna operatore/impresa) restano nella scheda.
- Esplicitamente esclusa la selezione multipla (bulk): troppa superficie
  di errore.

## S5 — Squadre

**Modello**

- Tabella `squadre`: `id_squadra`, `nome`, `id_caposquadra` (FK users),
  `attiva`.
- Pivot `squadra_user` (un operaio può appartenere a più squadre).
- Colonna `id_squadra_assegnata` nullable su `segnalazioni`.
- Flag `squadre_enabled` in Impostazioni: spento, l'ente non vede nulla.

**Assegnazione**

- L'azione "assegna operatore" si estende con scelta "Operatore singolo |
  Squadra". Assegnazione a squadra → notifica al solo caposquadra.

**Caposquadra**

- Dashboard operaio con tab aggiuntiva "Squadra": lavori della squadra e dei
  membri. Può riassegnare al singolo membro, eseguire azioni e chiudere per
  conto; lo storico registra l'effettivo esecutore.

**Visibilità** (`scopeVisibileA`)

- Membro: proprie assegnate + assegnate alla squadra non ancora smistate.
- Caposquadra: tutte quelle della squadra.

**Fuori scope**: turni, ferie, reperibilità.

## S6 — Interventi trasversali

- **Bugfix**: `SegnalazioneController::store()` salva gli allegati sul disk
  hardcoded `'local'` mentre `AllegatiSegnalazioniController` usa
  `Impostazione::get('allegati_storage_disk')`. Con disk configurato diverso,
  download ed eliminazione falliscono (404). Fix: leggere l'impostazione anche
  in `store()`.
- **Test allegati**: suite feature completa (upload in creazione e su
  esistente, disk configurato, MIME e limiti, download/destroy con permessi,
  404 cross-segnalazione).
- **Ricerca**: indice FULLTEXT MariaDB su `testo_segnalazione` e uso in
  `GestioneController` al posto del solo LIKE.

## S7 — Test e rollout

- Feature test per ogni blocco (per-conto-di, simili/adesioni, merge, digest,
  azioni rapide, squadre e visibilità).
- Migrazioni solo additive con `down()` completo.
- Nuove impostazioni con seeder aggiornato: `digest_enabled`, `digest_ora`,
  `adesioni_enabled`, `adesioni_soglia_priorita`, `dedup_raggio_metri`,
  `dedup_giorni`, `squadre_enabled`.
- Rilascio: tag `v0.6.0` → GitHub Actions → GHCR.

## Fuori scope (release successive)

- Magic link per ditte non registrate; rapportino di fine lavoro con foto
  prima/dopo obbligatoria; email-in (casella segnalazioni@).
- Pacchetto riuso: publiccode.yml, wizard primo avvio, comando demo, OpenAPI,
  audit accessibilità AGID.
- Intake guidato per tipologia e GPS automatico con suggerimento plesso.
