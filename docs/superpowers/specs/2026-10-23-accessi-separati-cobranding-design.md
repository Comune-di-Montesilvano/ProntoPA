# Accessi separati, ditte via SPID/CIE, co-branding loghi — design

Data: 2026-10-23 · Stato: rivisto, in attesa di approvazione

## Perché

- Le ditte entrano oggi con email+password+2FA email: un canale di credenziali in più da gestire, per appalti in gran parte scaduti. SPID/CIE è già in casa (scuole) e dà un'identità certa (codice fiscale).
- I segnalatori legacy con account `locale` dovevano sparire al cutover (fase 3 di v1.2): è il momento.
- Operatori (dominio AD) e utenti esterni (scuole, ditte) usano la stessa pagina di login: confonde e mescola canali diversi.
- Il logo dell'ente si può dare solo come URL (spesso hotlink a siti terzi, che si rompe) e nelle email viene caricato da remoto (bloccato dai client, tracciabile). ProntoPA non ha una favicon propria e non compare come marchio accanto all'ente.

## Obiettivi

1. Favicon ProntoPA.
2. Logo ente da URL **o** da file caricato.
3. Co-branding "logo ente + logo ProntoPA" su login, pagina accesso SPID, layout app, layout pubblico, email.
4. Email con loghi incorporati (inline CID), mai immagini esterne.
5. Due pagine di accesso distinte: operatori (AD) e utenti esterni (SPID/CIE).
6. Ditte solo via SPID/CIE, sempre tramite pre-delega fatta da un operatore del comune.
7. Rimozione registrazione self-service e account `locale` di ditte e segnalatori legacy.

## Non-obiettivi

- Nessuna richiesta di delega self-service per le ditte, nessuna approvazione della segreteria, nessuna scadenza/rinnovo annuale.
- Nessun cambio al login AD in sé (`LdapLoginService`, blocco tentativi, mappa gruppi).
- Nessuna cancellazione fisica di utenti.

## Anti-obiettivi (sarebbe un fallimento anche se "funziona")

- Perdere lo storico (note, stati, rapportini) degli account legacy.
- Un operatore AD che si trova davanti un bottone SPID, o una scuola davanti al form username/password, senza un rimando chiaro all'altra porta.
- Email che caricano anche una sola immagine da URL esterno.
- Una persona SPID che vede segnalazioni di una ditta senza pre-delega attiva.

## Decisioni del committente

- **Ditte**: l'operatore del comune crea una pre-delega = codice fiscale persona fisica + impresa (anagrafica `Impresa`). Al primo accesso SPID/CIE quella persona viene agganciata, prende ruolo `impresa` + `id_impresa` e gestisce le segnalazioni assegnate alla ditta. Sempre pre-delega, mai richiesta dal basso.
- **Storico ditte**: la visibilità resta per `appalto.id_impresa` (`Segnalazione::scopeVisibileA`, `app/Models/Segnalazione.php:268`). Una persona pre-delegata a un'impresa già esistente vede anche le segnalazioni dei vecchi appalti di quella ditta. È voluto ("se una ditta riavesse l'appalto è carino fargli vedere le segnalazioni a lui assegnate"). L'operatore deve scegliere l'impresa esistente, non crearne una doppia.
- **Account legacy ditte e segnalatori `locale`**: disattivati (non cancellati). Il segnalatore che torna entra con SPID e chiede/riceve la delega sui plessi come fanno già le scuole: la visibilità passa da `Delega::plessiCopertiDa()`, quindi rivede le segnalazioni dei suoi plessi senza migrazione dati.
- **Registrazione**: tolta del tutto (rotte, controller, vista, link, test), insieme al flusso `approval_status` / "in attesa di approvazione" che serviva solo a lei.
- **Tutti gli account `locale`** (anche admin/gestori/operai legacy) vengono disattivati: in prod nessuno entra più con utenza locale, gli operatori usano AD.
- **Segnalatori legacy non scuola** (provenienza portale/interno): non servono, disattivati e basta, nessuna via di ritorno.
- **Notifiche ditta**: vanno alle persone delegate **e** all'email aziendale (`imprese.email`); se un indirizzo coincide, una sola email (dedup per indirizzo, case-insensitive). Tutte le email contengono il link alla pagina SPID.
- **Magic-link ditte eliminato** (decisione committente: "o SPID o niente"): via `MagicLinkController`, rotte firmate, viste e notifiche che lo generano, test. Una ditta senza persone delegate riceve la notifica sull'email aziendale ma per operare serve una pre-delega. I link firmati già inviati smettono di funzionare (risposta chiara che rimanda alla pagina SPID, non un 403 nudo).
- **2FA**: il TOTP resta (codice e self-service dal profilo); forse in futuro verrà attivato per gli AD — fuori ambito qui, ma niente che lo renda più difficile. Il 2FA via email (`CodiceAccessoEmail`) si toglie: serviva solo alle ditte con password.
- **Verifica periodica**: per le scuole resta com'è il rinnovo annuale delle deleghe (`deleghe:rinnovi`). La verifica annuale degli account `locale` (`CheckAnnualAccountVerification`, `AnnualAccountVerificationController`, notifiche, rotta, impostazioni) non ha più utenti e si rimuove. Per le ditte nessuna verifica: la delega decade quando l'operatore disattiva la ditta.

## Come

### 1. Favicon

Da `public/logo.svg` (il marchio ProntoPA): `favicon.svg`, `favicon-32.png`, `apple-touch-icon.png` (180), `favicon.ico` rigenerato. `<link rel="icon">` in tutti i layout (`app`, `guest`, `public`, pagine standalone tipo `spid-mock`, decisione delega). File statici in `public/`, niente CDN.

### 2. Logo ente: URL o file

- Admin → Impostazioni → brand: campo upload accanto a `ente_logo_url`. Formati PNG, JPG, WebP, SVG; max 1 MB. Nuova chiave `ente_logo_file` (path relativo sul disco `public`, es. `brand/logo-ente.png`), in `ImpostazioniSeeder` con valore vuoto (idempotente, non sovrascrive).
- Precedenza: file caricato > URL > nessuno. Bottone "rimuovi file".
- SVG caricato: sanificato oppure rifiutato (rischio XSS servendolo dallo stesso dominio). **Lasciato al builder** scegliere tra sanificazione con libreria e rasterizzazione/rifiuto, purché nessuno script arrivi al browser.
- Un helper unico (es. `App\Support\Brand::logoEnteUrl()` / `logoEntePerEmail()`) sostituisce le letture dirette `Impostazione::get('ente_logo_url')` in `layouts/app`, `layouts/public`, `vendor/mail/html/message.blade.php` e ovunque altro compaia.
- Il file sta in `storage/app/public` → incluso nel backup storage esistente (verificare che il backup copra quel percorso).

### 3. Co-branding

Logo ente (se c'è) + separatore + logo ProntoPA (`x-application-logo`), su: pagina login operatori, pagina accesso SPID, `layouts/guest`, header di `layouts/app` e `layouts/public`, header delle email. Senza logo ente: solo ProntoPA + nome ente in testo. Resa visiva lasciata al builder, coerente con Tailwind/colori ente esistenti.

### 4. Email con loghi incorporati

- Header email: entrambi i loghi come allegati inline CID (`$message->embed()` / `Attachment` inline delle Mailable/Notification). Nessun `<img src="http...">` nelle email.
- I client email non mostrano SVG: ProntoPA va incorporato come PNG statico versionato in repo (es. `resources/brand/prontopa-email.png`). Logo ente: file caricato (se SVG → servirà un PNG, vedi §2) oppure, se c'è solo l'URL, scaricato lato server e tenuto in cache locale (disco, rinfrescato quando cambia l'impostazione). Scaricamento fallito → solo ProntoPA, mai errore di invio.
- Vale per tutte le notifiche che usano il template mail Laravel (`vendor/mail`, `vendor/notifications`) e per eventuali Mailable con template propri: il builder le censisce.
- Test: nessuna email renderizzata contiene `src="http`.

### 5. Due porte d'accesso

- `/login` (rotta `login`, resta il target di `auth` middleware): solo operatori AD. Form username/password di dominio. Niente bottone SPID; link visibile "Scuole e ditte: accedi con SPID/CIE" → pagina SPID.
- Nuova pagina pubblica SPID/CIE (es. `/accesso`, nome al builder): solo bottone SPID/CIE (+ simulatore in dev con `OIDC_MOCK`), testo per scuole e ditte, link discreto "Personale del comune → login". Le email rivolte a scuole/ditte puntano qui.
- **Home pubblica** (`PublicHomeController`, decisione committente): entrambi gli accessi. SPID/CIE in evidenza (bottone primario, "Scuole e ditte"); accesso operatori meno in vista (secondario/link, "Personale del comune") ma con la differenza tra i due chiaramente scritta, così nessuno sbaglia porta.
- `LoginRequest`: si toglie il ramo "contiene `@` → account `locale` per email" e il fallback "AD non riconosce → `locale` per username". Resta solo AD. Il messaggio di errore resta il generico `auth.failed`.
- Conseguenze da rimuovere perché senza utenti: 2FA via email (`CodiceAccessoEmail`, reinvio, rami in `TwoFactorChallengeController`), reset password (`forgot-password`, `reset-password`, controller, viste, notifiche), cambio password da profilo per account `locale`. Il TOTP resta (vedi Decisioni). Nota: oggi `User::metodoSecondoFattore()` restituisce `null` per i non-`locale`, quindi dopo la modifica il TOTP non viene chiesto a nessuno al login finché non si deciderà di attivarlo per AD.
- Le pagine "Completa profilo" e verifica email SPID restano.

### 6. Ditte via SPID: pre-delega impresa

- Nuova tabella `deleghe_imprese` (separata da `deleghe`, che ha `id_istituto` obbligatorio e un ciclo richiesta/segreteria/rinnovo che qui non serve): `id`, `codice_fiscale` char(16), `user_id` nullable (NULL finché non agganciata), `id_impresa` FK, `stato` (`attiva` · `revocata`), `creata_da` FK users, `revocata_da` nullable, `revocata_at` nullable, `motivo` nullable, timestamps. Indici su (`codice_fiscale`,`stato`), (`user_id`,`stato`), (`id_impresa`,`stato`). Una sola delega `attiva` per codice fiscale (una persona = una ditta).
- Chi la crea/revoca: operatore del comune — admin e gestori (chi gestisce l'anagrafica imprese). Interfaccia nella scheda impresa (`ImpreseCRUDController`/viste imprese): elenco persone delegate, "aggiungi persona" (CF + motivo), "revoca". CF validato e normalizzato come per le scuole (maiuscolo, senza `TINIT-`).
- Aggancio al login SPID: passo proprio, **non** dentro `agganciaPredeleghe` (che salta gli utenti con email non verificata e non tocca i ruoli) e da correggere in `creaDaProfilo` (oggi `syncRoles(['segnalatore'])` fisso). Gira anche prima della verifica email: se esiste una delega impresa `attiva` per quel CF → `user_id` impostato, utente con ruolo `impresa` e `id_impresa`. Ricalcolato a ogni login SPID: delega revocata → ruolo `impresa` tolto e `id_impresa` azzerato.
- Primo accesso di una persona pre-delegata: stesso flusso SPID di oggi (Completa profilo con email → verifica email). Utile perché le notifiche alla ditta vanno per email.
- `LimitaAccessoSpid`: oggi senza delega scuola attiva l'utente `spid` vede solo verifica email e "Le mie deleghe". Una delega impresa attiva vale come accesso pieno (dashboard imprese).
- Revoca mentre la persona è collegata: alla richiesta successiva perde l'accesso. Oggi `scopeVisibileA` e `SegnalazionePolicy` controllano `hasRole('impresa')` prima di `isSpid()` e `LimitaAccessoSpid` guarda solo `bloccato_at` e le deleghe scuola → per utenti `spid` con ruolo `impresa` serve il controllo di una riga `deleghe_imprese` attiva (middleware e/o scope), non solo il login.
- **Disattivazione ditta**: nuovo flag `imprese.attiva` (default true, migration). Disattivarla dalla scheda impresa revoca tutte le sue deleghe impresa attive (`revocata_da` = operatore, motivo "ditta disattivata"); niente nuove deleghe su ditta disattivata. Riattivarla non ripristina le deleghe revocate: l'operatore le rifà.
- Persona che ha sia delega scuola sia delega ditta: un solo ruolo per utente; precedenza `impresa`. Caso raro, accettato.
- Storico in `deleghe_imprese` (chi/quando ha creato e revocato) sufficiente; nessuna tabella storico separata.

### 7. Rimozione registrazione e legacy

- Via `register` (GET/POST), `RegisteredUserController`, `auth/register.blade.php`, link nelle viste, test relativi.
- Migration dati (idempotente, `up` soltanto): **tutti** gli `users` con `auth_source='locale'` → `attivo=false` (campo letto da `EnsureUserIsActive`). Nessuna cancellazione. Restano visibili come disattivati in Admin → Utenti. Registrazioni pendenti (`approval_status` ≠ approved) incluse.
- `UtentiController`: niente più creazione di utenti `locale` con ruolo `impresa`/`segnalatore` (la regola "ditta = email come username" decade). Il builder verifica cos'altro crea account `locale` (es. `PopulateDemoData`, `AdminUserSeeder`) e lo allinea; i dati demo devono creare ditte via delega impresa.
- `artisan demo` e test Dusk aggiornati (login ditte, registrazione).

## Vincoli

- Convenzioni CLAUDE.md: niente CDN, `#[\SensitiveParameter]`, `AllineamentoSeeder` idempotente, nuove env anche in `x-php-env` (non previste).
- CSP nginx invariata: nessuna risorsa esterna.
- Backup/ripristino: nuova tabella e file logo inclusi automaticamente (dump di tutte le tabelle + storage) — da verificare.
- Docs: aggiornare CLAUDE.md (sezioni Accesso, Ruoli, Brandizzazione), `docs/` utente se descrivono login ditte/registrazione, CHANGELOG, `publiccode.yml` nella stessa PR.

## Lasciato al builder

- Nome rotta/URL della pagina SPID, resa grafica del co-branding.
- Sanificazione vs rifiuto SVG caricati; generazione PNG per email.
- Strategia cache del logo ente scaricato da URL.
- Ordine di implementazione e suddivisione in commit; numero versione secondo la regola del progetto (feature fuori tappa = patch).
