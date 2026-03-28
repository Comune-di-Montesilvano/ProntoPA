# ProntoPA — Roadmap sviluppi

## v0.3 — UX e comunicazioni

### Landing page pubblica
- Pagina `/` con presentazione del sistema, link al login e statistiche anonimizzate (totali per stato, tipologia, ente)
- Sostituisce l'attuale pagina demo di Laravel

### Disattivazione utenti
- Campo `attivo` (boolean) su `users`
- Blocco login per utenti disattivati (middleware o `AuthenticatedSessionController`)
- UI admin: toggle attivo/disattivato al posto del pulsante Elimina
- Filtro "Mostra disattivati" nella lista utenti

### Notifiche email
- Notifica all'operatore quando gli viene assegnata una segnalazione
- Notifica all'impresa quando riceve un appalto
- Notifica al segnalante alla chiusura
- Configurazione mittente tramite impostazioni ente (già presente: `mail_from_address`, `mail_from_name`)
- Implementare via Laravel Notifications con Mailable

### Bot Telegram
- Notifica push all'operatore su assegnazione segnalazione
- Notifica push all'impresa su assegnazione appalto
- Comandi: `/lista` (segnalazioni assegnate), `/apri <id>` (dettaglio), bottoni inline per cambio stato
- Configurazione token bot e chat ID per utente (campo `telegram_chat_id` su `users`)
- Webhook Telegram → endpoint Laravel

---

## v0.4 — Allegati e mobile

### Upload foto/video nelle segnalazioni
- Tabella `allegati_segnalazioni` (id, id_segnalazione, percorso, tipo, nome_originale, dimensione)
- Upload multiplo al momento della creazione e dalla scheda segnalazione
- Accesso diretto alla fotocamera su mobile (`<input capture="environment">`)
- Anteprima miniature, download, eliminazione (solo per chi ha creato o gestori)
- Storage: Laravel disk (`local` in dev, S3-compatibile in prod)
- Limite dimensione e tipi consentiti configurabili in impostazioni

---

## v0.5 — Integrazioni esterne

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
