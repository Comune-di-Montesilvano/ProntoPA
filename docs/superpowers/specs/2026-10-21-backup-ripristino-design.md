# Backup e ripristino — design

Data: 2026-10-21 · Stato: approvato dal committente (2026-10-22), da pianificare

## Perché

ProntoPA va in produzione senza alcun meccanismo di backup. Il committente
gestisce la produzione **solo da Portainer** (niente SSH, niente CLI podman,
stack letto da GitHub, quindi il compose non si modifica a mano) e vuole che
nessuna operazione ordinaria richieda la console ("la console non va usata
se il codice è scritto bene").

Due scenari, stesso strumento:

1. **Migrazione dev → prod**: preparo i dati in locale (es. import legacy,
   verifica), poi li porto in produzione.
2. **Ripristino da zero**: stack nuovo e vuoto, carico un backup, l'app
   riparte com'era.

Successo = "installo i container da zero, importo il backup, fine".

## Obiettivi

- Un **file di backup unico e portabile** tra installazioni diverse (APP_KEY
  diverse, versioni diverse dell'app purché il backup non sia più nuovo
  dell'app che lo riceve).
- **Backup automatici notturni** con retention "1/7/30" (vedi sotto).
- **Backup manuale** ("Backup ora"), download e **ripristino** dalla UI admin.
- Ripristino possibile su un'installazione **appena creata** (DB vuoto).

## Non-obiettivi

- Copia fuori dal server (NAS, S3, SFTP): il committente ha scelto **solo
  volume locale**. Rischio accettato: se si perde il server si perdono anche
  i backup; la copia esterna è il download manuale dalla UI. Il design non
  deve però impedire di aggiungere una destinazione esterna più avanti.
- Backup di Redis (code, cache, sessioni): si perdono i job in coda, accettato.
- Backup selettivi/parziali o ripristino di una singola segnalazione.
- Point-in-time recovery / binlog.

## Anti-obiettivi (fallimento anche se "funziona")

- Qualsiasi passo ordinario che richieda la console Portainer o la modifica
  del compose.
- Un ripristino che lascia l'app a metà (DB nuovo, allegati vecchi o
  viceversa) senza dirlo chiaramente.
- Backup che falliscono in silenzio per giorni.
- Password o segreti in chiaro nei log o in file non cifrati sul volume.

## Decisioni del committente

- Retention "1/7/30", schedulato.
- Destinazione: volume locale del container.
- Flusso UI come descritto sotto ("vai").

## Contenuto del backup

- **Dump logico completo del DB** (tutte le tabelle, schema + dati).
- **Storage applicativo**: `storage/app` (privato, `public` = volume
  `uploads` con allegati e foto rapportini, indice MIUR `miur/*.json`).
  Esclusi: `storage/logs` (possono contenere dati sensibili, vedi bug
  password 0.8.1), `storage/framework` (cache/sessioni/viste rigenerabili),
  il disco `quarantena` (file infetti — *mia scelta*; i record restano con
  il loro stato).
- **Segreti cifrati con APP_KEY**, decifrati e salvati a parte dentro il
  file cifrato, per essere ricifrati con la APP_KEY di destinazione:
  - `impostazioni.oidc_client_secret` (Crypt::encryptString)
  - `users.two_factor_secret` e `users.two_factor_recovery_codes` (Fortify)
  - qualsiasi altro valore cifrato con APP_KEY presente al momento
    dell'implementazione (il builder verifica con una ricerca completa).
- **Manifest**: versione app (`config('app.version')`, in dev vale `dev`), elenco migration applicate,
  data, host di origine (`APP_URL`), conteggi per tabella, numero/dimensione
  file, checksum. Letto **prima** di toccare qualunque cosa.

## Formato e cifratura

- Un solo archivio, cifrato con una passphrase da variabile della stack
  **`BACKUP_PASSPHRASE`** (obbligatoria per i backup; senza, la pagina
  Backup lo segnala chiaramente e lo scheduler non produce file in chiaro).
- Dev e prod devono avere la **stessa** `BACKUP_PASSPHRASE` per migrare tra
  loro; in alternativa al ripristino la UI chiede la passphrase del file.
- *Mia proposta*: ZIP con cifratura AES-256 (apribile anche con 7-Zip in caso
  d'emergenza). Formato concreto lasciato al builder, purché cifrato,
  streaming (niente archivio intero in RAM) e apribile senza ProntoPA.
- Nome file: `prontopa-<YYYYMMDD-HHMMSS>-<versione>-<tipo>.zip`
  (tipo = `notturno` · `manuale` · `pre-ripristino` · `caricato`).

## Dove stanno

- Nuovo named volume **`backups`** nel `docker-compose.yml`, montato in un
  path **fuori** da `storage` (es. `/var/www/html/backups` o `/backups`),
  così un backup non include i backup precedenti e un ripristino non li
  cancella.
- Montato su `php` (UI, download, upload), `queue` e `scheduler` (esecuzione).
- Lo stack è letto da GitHub: il volume si crea da solo al prossimo
  "Update the stack", nessun passo manuale.
- Nuove env (`BACKUP_PASSPHRASE`, eventuali altre) → blocco `x-php-env`.

## Schedulazione e retention

- Ogni notte (orario *mia scelta*: 02:00, prima di `deleghe:scadenze` 06:30)
  da `routes/console.php`, `withoutOverlapping`.
- Retention, *mia interpretazione di "1/7/30"*: l'ultimo backup sempre;
  tutti i giornalieri degli ultimi **7 giorni**; oltre, **uno a settimana
  fino a 30 giorni**; il resto si cancella. ≈ 11 file. Pulizia solo dopo un
  backup riuscito (mai cancellare se il nuovo è fallito).
- I backup `manuale` e `pre-ripristino` seguono la stessa retention per età
  (*mia scelta*), ma l'ultimo `pre-ripristino` non si cancella mai in
  automatico finché non c'è un notturno più recente.

## Verifica e allarmi

- Dopo ogni backup: riapertura del file, controllo checksum e coerenza dei
  conteggi col manifest. Fallita = backup fallito.
- Esito registrato (DB o file indice sul volume `backups`, scelta del
  builder) con data, dimensione, durata, errore.
- Avviso ben visibile in Admin (dashboard + pagina Backup) se l'ultimo
  backup è fallito o è più vecchio di **36 ore**.
- Fallimento → evento Sentry/Glitchtip + email agli admin (*mia proposta*).

## UI — Admin → Sistema → Backup (solo ruolo `admin`)

- Elenco backup: data, tipo, versione, dimensione, esito verifica;
  azioni **Scarica** · **Ripristina** · **Elimina**. *Mia scelta*: Elimina
  su qualsiasi backup tranne l'ultimo `pre-ripristino`.
- **Backup ora** → job in coda, la pagina mostra lo stato.
- **Carica backup**: dopo la verifica il file viene ricifrato con la
  `BACKUP_PASSPHRASE` locale e tenuto in lista con tipo `caricato` (*mia
  scelta*: sul volume solo file apribili con la passphrase locale; stessa
  retention per età). Upload di un file da un'altra installazione. Il
  file può pesare centinaia di MB, oltre i limiti attuali (`client_max_body_size
  25M`, `upload_max_filesize 20M`): serve upload a blocchi (chunked) o
  altro meccanismo che **non** alzi i limiti globali delle altre rotte.
  Scelta tecnica al builder.

## Ripristino

1. Admin sceglie un backup (dalla lista o caricato) → l'app apre il manifest
   e mostra il riepilogo: origine, data, versione, conteggi, e cosa verrà
   **sostituito** (tutto DB + storage).
2. Rifiuta subito se: passphrase errata, checksum non valido, backup con
   migration **sconosciute** all'app installata (= più nuovo). *Mia scelta*:
   la compatibilità si giudica dalle migration, non dalla stringa versione,
   che in dev vale sempre `dev` — così dev → prod funziona se il codice è
   allineato e viene bloccato se dev ha migration non ancora rilasciate.
3. Conferma esplicita (es. digitare il nome dell'ente o "RIPRISTINA").
4. **Backup automatico `pre-ripristino`** dello stato attuale. *Mia scelta*:
   saltato se l'installazione è "vuota" = nessuna segnalazione e al massimo
   un utente (l'admin AD appena entrato: su un'installazione nuova l'unico
   modo di arrivare alla pagina è il login AD, che crea quell'utente).
   Se non è vuota e `BACKUP_PASSPHRASE` manca → ripristino bloccato con
   messaggio (niente pre-ripristino in chiaro). Su installazione vuota la
   passphrase digitata in UI basta per aprire il file.
5. App in manutenzione per tutti tranne il processo di ripristino (attenzione:
   la manutenzione basata su file in `storage/framework` verrebbe toccata —
   il builder sceglie un driver che sopravviva, es. cache Redis).
6. Sostituzione DB + storage, poi `migrate --force` + `AllineamentoSeeder`
   (backup vecchio → schema attuale), ricifratura dei segreti con la
   APP_KEY locale, svuotamento cache.
7. Tutte le sessioni invalidate: gli utenti del backup hanno id diversi da
   quelli correnti. L'admin rientra via AD (riagganciato per `ldap_guid`).
8. Il riepilogo del punto 1 avvisa (*mia scelta*, solo avviso, nessuna
   riscrittura automatica) quando origine e destinazione differiscono per
   `APP_URL` o APP_KEY: i link firmati già inviati (magic-link ditte,
   decisione/rinnovo deleghe, verifica annuale) smettono di funzionare e le
   impostazioni legate all'host (es. `ente_logo_url`) vanno ricontrollate.
   Per il disaster recovery la documentazione raccomanda di riusare la
   stessa APP_KEY e APP_URL.
9. Esito finale visibile (anche se fallisce a metà: messaggio chiaro +
   indicazione del backup `pre-ripristino` da usare).

Il ripristino gira fuori dalla richiesta web (job/processo, scelta del
builder) — dump grandi superano i timeout di php-fpm/nginx.

## Comandi artisan (per dev e CI, non richiesti in prod)

`backup:crea` · `backup:ripristina <file>` · `backup:pulisci` — stessa
logica della UI. Utili in sviluppo e per i test.

## Hardening incluso (emerso il 2026-10-21 in prod)

- Entrypoint: il loop "Waiting for database..." nasconde l'errore
  (`2>/dev/null`). Dopo ~30 s deve stampare l'errore PDO reale con un
  suggerimento ("Access denied → DB_PASSWORD diversa da quella con cui è
  stato creato il volume mariadb_data"), **senza** stampare la password.
- `docker-compose.yml`: `mariadb` ha default `segnalazioni_secret`/
  `root_secret`, `php` no → mismatch silenzioso. `DB_PASSWORD` obbligatoria
  e identica su entrambi (`${DB_PASSWORD:?...}`), nessun default.
  *Mia scelta* (committente non ha confermato se la prod imposta
  `DB_ROOT_PASSWORD`): la root resta **opzionale**, un "Update the stack"
  su prod esistente non deve mai fallire per una variabile mancante; il
  builder sceglie come evitare `root_secret` sulle installazioni nuove
  senza rompere quelle esistenti (vale solo alla creazione del volume).
- CLAUDE.md/deploy: documentare che le password MariaDB valgono solo alla
  creazione del volume, e la procedura di reset senza console (container
  temporaneo `--skip-grant-tables` sullo stesso volume).

## Vincoli

- Laravel 13 / PHP 8.4 / MariaDB 11.4, immagine Alpine. Se servono
  `mariadb-dump`/`mariadb` client, vanno aggiunti all'immagine `app`.
- Podman rootless, named volumes, utente `www` (uid 1000) nel container:
  il volume `backups` deve essere scrivibile da `www`.
- Tutto testato (Feature test: backup → ripristino su DB pulito con conteggi
  identici, ricifratura segreti con APP_KEY diversa, rifiuto versione più
  nuova, retention con date finte, passphrase errata).
- Rilascio: bump `publiccode.yml` + CHANGELOG nella stessa PR.

## Lasciato al builder

Formato archivio esatto, libreria/strumento di dump, meccanismo di upload a
blocchi, dove registrare l'esito, come eseguire il ripristino fuori dalla
richiesta web, layout della pagina (coerente con le pagine Admin esistenti),
numero di versione (*mia proposta*: 0.9.0 = backup + hardening, l'import
legacy slitta a 0.10.0).
