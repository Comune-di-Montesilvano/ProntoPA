# Riepilogo degli avvisi — design

Data: 2026-11-27 · Stato: in revisione

## Perché

Ogni evento su una segnalazione manda oggi un'email (e un Telegram) per destinatario, subito.
Assegnare 10 segnalazioni allo stesso operaio = 10 email; `sla:check` alle 07:00 manda una email per
ogni segnalazione scaduta. Il committente: «troppe notifiche = nessuna notifica, una notifica ogni ora
ha più valore (o idealmente al giorno)». «Non siamo i carabinieri»: nessun avviso sulle segnalazioni
deve partire subito.

## Obiettivi

- Gli avvisi sulle segnalazioni non partono più al momento dell'evento: si accumulano e partono come
  **un solo riepilogo per destinatario**, con un ritmo che dipende dal tipo di avviso.
- Due ritmi: **ogni ora** (in orario d'ufficio) e **una volta al giorno** (mattina).
- Il riepilogo è un elenco, non una chat: una riga per segnalazione con lo stato **al momento
  dell'invio**; gli avvisi diventati inutili nel frattempo spariscono.
- Stesso trattamento per email e Telegram.
- Ritmo per categoria configurabile dall'admin.

## Non obiettivi

- Preferenze per singolo utente (ritmo, canali, disiscrizione).
- Avvisi di sistema / non legati a segnalazioni: `BackupFallitoNotification`, `SyncAdFallitaNotification`,
  `JobFallitiNotification`, tutte le `Deleghe/*` (contengono link firmati da usare): restano immediati,
  invariati.
- Notifiche che sono già riepiloghi (`DigestGestoreNotification`, `RiepilogoInattivitaNotification`):
  non vengono rimesse in attesa (vedi però «Digest» sotto).
- Webhook outbound verso il sito del comune: invariato, resta immediato (non è una notifica a persone).

## Anti-obiettivi (sarebbe un fallimento anche se funziona)

- Un riepilogo che elenca la stessa segnalazione più volte, o eventi già superati (riassegnata ad altri,
  già chiusa) → torna a essere rumore.
- Avvisi persi (riavvio, Redis svuotato, worker giù): quello che è in attesa deve stare a DB.
- Email inviate di notte o nel weekend.
- Un segnalante (scuola) che nel riepilogo vede informazioni interne che oggi la singola email non gli
  mostra.

## Approcci considerati

- **A. Tabella degli avvisi in attesa + invio programmato — scelto dal committente.** Le notifiche
  raggruppabili vengono intercettate all'invio e salvate a DB; un comando schedulato le raggruppa per
  destinatario e le spedisce. Durevole, ispezionabile, testabile, nessuna modifica ai punti che oggi
  chiamano `notify()`.
- B. Job ritardati con debounce in Redis: fragile (perdita a Redis svuotato), inadatto al ritmo
  giornaliero. Scartato.
- C. Solo digest giornaliero più ricco, togliendo gli avvisi per evento: operai e ditte saprebbero dei
  lavori il giorno dopo. Scartato.

## Ritmi per categoria (decisi col committente)

| Categoria (chiave) | Notifiche | Ritmo di default |
|---|---|---|
| `assegnazioni` | `OperatoreAssegnatoNotification`, `SquadraAssegnataNotification`, `ImpresaAssegnataNotification` | ogni ora |
| `solleciti` | `SollecitoNotification` | ogni ora |
| `lavoro` | `RimandataAlGestoreNotification`, `FineLavoriNotification`, `NotaDaEsternoNotification`, `NuovaNotaSegnalazione`, `RispostaInformazioniNotification` | ogni ora |
| `richieste` | `RichiestaInformazioniNotification` (al segnalante, anche promemoria) | ogni ora |
| `nuove` | `NuovaSegnalazioneNotification` | una volta al giorno |
| `scadenze` | `SlaViolazioneNotification`, `SlaWarningNotification` | una volta al giorno |
| `inattivita` | `InattivitaNotification`, `ArchiviataInattivitaNotification` | una volta al giorno |
| `esiti` | `SegnalazioneChiusaNotification`, `SegnalazioneStatoCambiato`, `AccorpataNotification` | una volta al giorno |

Ogni categoria ha un'impostazione `avvisi_ritmo_<categoria>` con valori `ora` | `giorno` (nuovo tipo
`ritmo` in `Sezioni`, sezione `AVVISI`, pagina Regole di lavoro → Avvisi, seeder con i default sopra).
Nessun valore «subito».

## Quando partono

- **Ogni ora**: comando `avvisi:invia --ritmo=ora` schedulato all'ora piena (e ogni 15 minuti per
  cogliere la chiusura, vedi sotto). Parte solo se in quel momento l'ufficio è aperto secondo
  `CalendarioUfficio::daImpostazioni()` (fascia del giorno, festivi, patrono), **estremi inclusi**.
  Fuori orario non fa nulla: gli avvisi si accumulano e partono al primo giro orario dell'apertura (se
  l'apertura non è all'ora piena, es. 07:30, partono alle 08:00 — accettato). *Scelta del progettista:*
  c'è sempre un giro **all'orario di chiusura** della fascia (es. 14:00 o 13:45), così quanto arriva
  nell'ultima ora non slitta al giorno dopo.
- **Una volta al giorno**: `avvisi:invia --ritmo=giorno` all'ora di `digest_ora` (default 07:30), solo
  nei giorni lavorativi del `CalendarioUfficio` (sostituisce `digest_skip_weekend`, vedi Digest).
  Gli avvisi giornalieri nati in un giorno chiuso partono il primo giorno lavorativo.
- Il giro giornaliero manda **anche** gli avvisi orari ancora in attesa per quel destinatario, nello
  stesso riepilogo (una email al mattino invece di due a pochi minuti).
- `sla:check` (07:00) resta prima del giro giornaliero: le scadute della notte arrivano nel riepilogo
  della mattina stessa.

## Come si intercetta (il meccanismo)

- Le notifiche della tabella implementano un'interfaccia `App\Notifications\Riepilogo\Raggruppabile`:
  - `categoriaRiepilogo(): string` — chiave della tabella sopra;
  - `segnalazioneRiepilogo(): int` — id della segnalazione;
  - `rigaRiepilogo(object $notifiable): string` — la riga da mostrare a quel destinatario (deve
    rispettare le stesse regole di visibilità della email singola: es. nomi interni nascosti al
    segnalante, come fa già il testo attuale della notifica);
  - `ancoraValida(object $notifiable): bool` — rilette le relazioni, l'avviso ha ancora senso?
    (es. assegnazione: la segnalazione è ancora assegnata a questo operaio/squadra/ditta e non è
    terminale; scaduta: ancora aperta e ancora `sla_violato`; richiesta di informazioni: ancora in
    stato 11). Regola di base se non serve altro: segnalazione esistente.
- Un listener sull'evento `NotificationSending` (scatta nel worker, per canale, perché le notifiche sono
  `ShouldQueue`): se la notifica è `Raggruppabile` e non è un reinvio dal riepilogo, salva una riga in
  `avvisi_in_attesa` e annulla l'invio (`return false`). Così nessun punto che oggi chiama `notify()` /
  `Notification::send()` / `Notification::route()` va toccato.
- Il listener salva **una riga per destinatario** (la prima chiamata, canale `mail` o Telegram,
  registra; le altre dello stesso invio sono solo annullate — chiave idempotente: id della notifica
  Laravel + destinatario). I canali del riepilogo li decide il giro, non `via()` (vedi Telegram).

### Tabella `avvisi_in_attesa`

- `destinatario_tipo` (`utente` | `email`), `id_utente` (nullable), `email` (nullable, per gli
  `AnonymousNotifiable` — ditte: email aziendale) → chiave di raggruppamento.
- `id_notifica` (uuid Laravel, unique con la chiave destinatario), `categoria`, `ritmo` (letto al momento del salvataggio), `id_segnalazione`,
  `tipo` (class basename), `notifica` (notifica serializzata, `longText`), `created_at`, `inviata_at`
  (nullable), `giro` (nullable, prenotazione), `tentativi` (default 0), `scartata_at` (nullable).
- Indice su (`inviata_at`, `ritmo`) e sulla chiave destinatario.
- Le righe inviate si tengono 30 giorni (pulizia nel comando stesso), poi si cancellano.

## Cosa si invia

Per ogni destinatario + canale con righe in attesa del ritmo del giro:

1. Si deserializzano le notifiche (i model si ricaricano freschi; segnalazione eliminata → riga
   scartata) e si tengono solo quelle `ancoraValida()`.
2. **Una riga per segnalazione** (*scelta del progettista*): se la stessa segnalazione ha avvisi di
   categorie diverse, compare una volta sola nella sezione della categoria più importante (ordine delle
   sezioni sotto) e la riga riporta **tutti i motivi** («Assegnata a te · Scaduta · Nuova nota»), così
   un'assegnazione non sparisce dietro una scadenza. Più avvisi della stessa categoria → conta il più
   recente.
3. Restano 0 avvisi → nulla da inviare, righe marcate. Resta **1 avviso** (una segnalazione, un motivo)
   → si reinvia la notifica originale sul suo canale (email singola di oggi, invariata, solo in
   ritardo). Altrimenti → `RiepilogoAvvisiNotification`.
4. **Invio in coda, un job per destinatario+canale** (*scelta del progettista*): il comando raggruppa e,
   per ogni destinatario, «prenota» le righe (token del giro su colonna `giro`, così un giro concorrente
   non le riprende) e accoda il job; il job invia in sincrono e marca `inviata_at` solo a invio
   riuscito. Invio fallito → righe liberate, riprovate al giro successivo; dopo **3 tentativi**
   (`tentativi`) marcate `scartata_at`, `Log::warning` e conteggio nel riquadro «Email» dello Stato del
   sistema. Job ripetuto dopo un invio riuscito → righe già marcate, non rispedisce.
5. Telegram: `TelegramChannel` oggi ignora l'esito di `sendMessage()`. Per il riepilogo l'esito
   `false` deve diventare un errore (eccezione) così il punto 4 vale anche lì; per gli avvisi immediati
   rimasti (non raggruppabili) il comportamento resta quello di oggi.

### Email di riepilogo

- Oggetto: `Riepilogo ProntoPA — N segnalazioni` (ordine dei motivi più importanti nel testo, non
  nell'oggetto).
- Corpo: sezioni nell'ordine `assegnazioni`, `solleciti`, `richieste`, `lavoro`, `scadenze`, `nuove`,
  `inattivita`, `esiti`, ognuna con titolo e conteggio («Assegnate a te (7)»), poi le righe
  (`rigaRiepilogo`) con link alla scheda. Usa `BuildsNotificationMailMessage` (brand, logo).
- Ditte (destinatario email): link a `route('accesso')`, come oggi `ImpresaAssegnataNotification`.

### Telegram di riepilogo

- Un messaggio con le stesse sezioni (testo breve), pulsanti `apri:ID` per le prime 10 segnalazioni,
  poi «e altre N» in testo. Messaggio oltre i limiti Telegram → si tronca l'elenco, mai più messaggi.
- **Stessi contenuti dell'email** (deciso dal committente: «Telegram deve fare le stesse notifiche,
  l'accorpamento mitiga le troppe informazioni»): tutte le categorie, anche quelle che oggi sono solo
  email (solleciti, fine lavori…). Le righe in attesa sono quindi **per destinatario, non per canale**:
  al giro il riepilogo parte via email e, se `User::usaTelegram()` e Telegram collegato, anche via
  Telegram. Caso «1 avviso»: email originale; su Telegram il riepilogo a una riga (non tutte le
  notifiche hanno `toTelegram`).
- I tre interruttori `telegram_notifica_operatore_assegnazione` / `_sla_warning` / `_nuova_nota` non
  hanno più senso per singolo tipo: sostituiti da un solo `telegram_riepiloghi` (bool, default true,
  sezione `AVVISI`; il seeder lo crea a true; le vecchie chiavi tolte da `Sezioni` e dalla pagina)
  (*scelta del progettista*).

### Accorpata

`AccorpataNotification` riguarda due segnalazioni: per il riepilogo la segnalazione è la
**destinazione** (quella che resta aperta), la riga cita la nuova («ha assorbito la #N»); lo storico
nella scheda registra l'avviso su entrambe, come oggi `segnalazioniTracciate()`.

## Il quadro del mattino

Deciso dal committente: «la mattina serve un complessivo: nuove, da fare, in ritardo». Il giro
giornaliero manda **un'unica email (e un unico Telegram)** per persona che apre con il quadro della
situazione e prosegue con gli avvisi accumulati:

- **Nuove** — da smistare: per i gestori, le aperte non assegnate delle loro tipologie (supervisori:
  tutte), come la sezione «da smistare» del digest di oggi.
- **Da fare** — le aperte non terminali in carico alla persona: gestore = assegnate a sé (stato 2);
  operaio = assegnate a sé o alla squadra di cui è caposquadra; ditta = quelle della propria impresa
  (persone delegate ed email aziendale).
- **In ritardo** — tra quelle «da fare» (e, per i supervisori, tra tutte le aperte) quelle con
  `sla_violato`; a rischio (scadenza entro 48 h, come il digest) evidenziate nella sezione «da fare».

Il quadro sostituisce `DigestGestoreNotification` e lo estende a operai e ditte (*scelta del
progettista* nell'interpretare «complessivo»: vale per chiunque abbia lavoro, non solo per i gestori).
`digest:invia` tolto dallo schedule; `digest_enabled` diventa l'interruttore del quadro del mattino
(spento = al mattino partono solo gli avvisi accumulati); `digest_skip_weekend` sostituito dai giorni
lavorativi del calendario. Segnalanti (scuole, URP) non ricevono il quadro, solo i loro avvisi.

- Una segnalazione già nel quadro non si ripete negli avvisi: il suo motivo (es. «Scaduta», «Nuova»)
  si aggiunge alla riga del quadro e l'avviso è marcato inviato.
- Persona senza avvisi in attesa → riceve comunque il quadro, se ha qualcosa in nuove/da fare/in
  ritardo. Niente di niente → nessuna email.

## Storico notifiche nella scheda

- `TracciaNotificaInviata` per il riepilogo registra, per ogni segnalazione elencata, il **tipo
  originale** dell'avviso (non `RiepilogoAvvisiNotification`), così il widget `scheda/_notifiche`
  continua a dire «Assegnata — email a Mario Rossi». Etichetta aggiuntiva «nel riepilogo».
- Il widget mostra anche gli avvisi ancora in attesa per quella segnalazione: «Assegnata — in attesa,
  parte con il riepilogo delle 11:00 / di domattina».

## Vincoli

- Test esistenti con `Notification::fake()` non passano da `NotificationSending`: restano verdi senza
  modifiche. Eccezione nota: `tests/Feature/TracciaNotificheTest.php` usa il sender vero e si aspetta la
  riga di `NotificaSegnalazione` subito dopo sollecito/inattività → va riscritto (riga dopo il giro di
  `avvisi:invia`). I test nuovi usano il sender vero (`Mail::fake`, `Http::fake` per Telegram).
- Nuova impostazione → riga in `Sezioni` + `ImpostazioniSeeder` (idempotente, mai sovrascrivere valori
  configurati) + `<x-pa.impostazione>` nella pagina Avvisi.
- Lo scheduler (servizio `scheduler`) è già obbligatorio in prod; nessun nuovo servizio.
- Rilascio patch (feature fuori dalle tappe del piano), CHANGELOG + `publiccode.yml` nella stessa PR.
- Al rilascio: nessuna riga in attesa da migrare; dal deploy in poi gli avvisi si accumulano.

## Lasciato al costruttore

- Formato esatto delle righe per tipo (riusare `rigaSegnalazione` di `DescriveSegnalazione`).
- Dettaglio delle regole `ancoraValida()` per ogni notifica, nello spirito degli esempi sopra.
- Come marcare un «reinvio dal riepilogo» per non reintercettarlo (proprietà sulla notifica o contesto).
- Lock del comando (`withoutOverlapping`) e ordine dei giri.
