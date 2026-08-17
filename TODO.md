# ProntoPA — Roadmap sviluppi

## v0.3 — UX e comunicazioni ✅ COMPLETATO

### Landing page pubblica ✅
- Pagina `/` con presentazione del sistema, link al login e statistiche anonimizzate (totali per stato, tipologia, ente)
- Sostituisce l'attuale pagina demo di Laravel

### Disattivazione utenti ✅
- Campo `attivo` (boolean) su `users`
- Blocco login per utenti disattivati (middleware o `AuthenticatedSessionController`)
- UI admin: toggle attivo/disattivato al posto del pulsante Elimina
- Filtro "Mostra disattivati" nella lista utenti

### Notifiche email ✅
- Notifica all'operatore quando gli viene assegnata una segnalazione
- Notifica all'impresa quando riceve un appalto
- Notifica al segnalante alla chiusura
- Configurazione mittente tramite impostazioni ente (già presente: `mail_from_address`, `mail_from_name`)
- Implementare via Laravel Notifications con Mailable

### Bot Telegram ✅
- Notifica push all'operatore su assegnazione segnalazione
- Notifica push all'impresa su assegnazione appalto
- Comandi: `/lista` (segnalazioni assegnate), `/apri <id>` (dettaglio), bottoni inline per cambio stato
- Configurazione token bot e chat ID per utente (campo `telegram_chat_id` su `users`)
- Webhook Telegram → endpoint Laravel

### Infrastructure & Operations ✅
- SANCTUM_STATEFUL_DOMAINS configuration fix
- Legacy data reimport script (legacy/reimport-legacy.ps1) for testing with realistic data
- Deploy-ready tooling (untracked, reusable)

---

## v0.4 — Allegati e mobile ✅ COMPLETATO

### Upload foto/video nelle segnalazioni
- Tabella `allegati_segnalazioni` (id, id_segnalazione, percorso, tipo, nome_originale, dimensione)
- Upload multiplo al momento della creazione e dalla scheda segnalazione
- Accesso diretto alla fotocamera su mobile (`<input capture="environment">`)
- Anteprima miniature, download, eliminazione (solo per chi ha creato o gestori)
- Storage: Laravel disk (`local` in dev, volume o S3-compatibile in prod)
- Limite dimensione e tipi consentiti configurabili in impostazioni

---

## v0.5 — Integrazioni esterne ✅ COMPLETATO

### API REST per segnalazioni esterne
- `POST /api/segnalazioni` — crea segnalazione da sito Comune (già scaffolded in `SegnalazioneApiController`)
- `GET /api/segnalazioni/{id}/stato` — legge stato corrente
- Autenticazione via Laravel Sanctum (token per ente)
- Documentazione endpoint (OpenAPI/Swagger o README)

### Webhook outbound
- Notifica HTTP POST firmata HMAC al sito Comune ad ogni cambio stato
- Payload JSON: id, stato, data, note pubbliche
- UI admin per configurare URL e secret (già presente in impostazioni: `webhook_cittadini_url`, `webhook_cittadini_secret`)
- Log tentativi e risposte nella tabella `api_logs` (già esistente)
- Retry automatico su errore (Laravel Queue)

---

## v0.6 — Adozione ✅ COMPLETATO

- Inserimento "per conto di" con permission `segnalazioni.per-conto`, form compatto, salva-e-nuova
- Anti-duplicato: endpoint simili, adesioni con escalation priorità, merge a posteriori, API 409
- Digest mattutino gestori (`digest:invia`), alert immediato solo per critiche
- Azioni rapide senza parametri dalla dashboard gestione
- Squadre: CRUD admin, assegnazione, smistamento caposquadra, visibilità per ruolo
- Fix disk allegati in creazione + suite test allegati
- Ricerca FULLTEXT su testo segnalazione

---

## v0.7 — Ditte e canali ✅ COMPLETATO

- Rapportino fotografico di fine lavoro (foto prima/dopo, descrizione, ore/materiali)
- Magic link firmato per imprese non registrate (`MagicLinkController`, zero login)
- Telegram esteso: ricezione foto via chat come allegato
- Accettazione preventivo tracciata (data + utente in storico)

---

## v0.8 — Assistente locale ✅ COMPLETATO

AI opzionale on-premise (Ollama, profilo Docker `ai`), sempre asincrona (job in coda), degrada in silenzio se disattivata o non raggiungibile.

- Titolo automatico generato dal testo libero (`GeneraTitoloSegnalazione`)
- Triage suggerito: tipologia/specializzazione/priorità proposti, mai scritti senza conferma gestore (`SuggerisciTriageSegnalazione`)
- Dedup semantico via embeddings, coseno sui candidati già filtrati per luogo (`DedupService`, `CalcolaEmbeddingSegnalazione`)

---

## v1.0 — Riuso e numeri ✅ COMPLETATO

### Pacchetto riuso
- Wizard primo avvio (`/setup`, token + OTP via email) ✅
- `publiccode.yml` + `LICENSE` (EUPL-1.2) ✅
- Comando `artisan demo` per dati realistici ✅
- Documentazione API OpenAPI (`docs/openapi.yaml`) ✅
- Audit accessibilità AGID (L. 4/2004): home/login/setup a 100/100 Lighthouse ✅

### KPI e rendicontazione ✅
- Tempi medi per transizione da storico stati, report mensile gestore, riepilogo impresa
- Export XLSX, fascicolo PDF completo
- Indicatori aggregati in home pubblica

---

## v1.1 — Hardening 📋 SPEC (non iniziato)

Spec: [`docs/superpowers/specs/2026-08-17-v11-hardening-design.md`](docs/superpowers/specs/2026-08-17-v11-hardening-design.md)

- Password policy coerente (wizard vs registrazione/cambio password) ✅
- Dependabot (composer/npm/github-actions) ✅
- Alert su job falliti in coda ✅
- 2FA via Fortify (TOTP + recovery codes), opzionale self-service da profilo ✅
- Scan antimalware upload allegati (ClamAV, profilo Docker `security`) ✅
- Test E2E (Dusk) sui flow critici
- Retention/cancellazione dati GDPR (richiede decisione ente)
- Ambiente di staging (richiede infrastruttura)
