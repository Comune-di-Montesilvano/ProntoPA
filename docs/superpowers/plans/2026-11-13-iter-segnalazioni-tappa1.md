# Iter segnalazioni — Tappa 1 "Giro di Caruso" Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Il gestore smista, chiude e stampa senza lavoro extra: schema stati/azioni nuovo, scadenza manuale, fine lavori che chiude, azioni multiple in elenco, email con tipologia e titolo.

**Architecture:** Le transizioni restano in `SegnalazioneWorkflowService` guidate da `db_azioni` (`parametri_filtro.stati`), aggiornate da migration + seeder. Due servizi nuovi piccoli (`ScadenzaManuale`, `AzioniMultiple`) e un trait per le email. Nessun valore di `SegnalazioneStato` cambia.

**Tech Stack:** Laravel 13, PHP 8.4, PHPUnit Feature test con `RefreshDatabase`, Blade + Alpine, Spatie Permission.

**Spec:** `docs/superpowers/specs/2026-11-13-iter-segnalazioni-semplice-design.md` (§1, §3, §4, §8; tappa 1)

## Global Constraints

- Valori int di `SegnalazioneStato` invariati; storico (`stati_segnalazioni`) mai riscritto.
- Nessun nuovo campo obbligatorio per assegnare o chiudere (anti-obiettivo della spec).
- Righe `db_azioni` mai cancellate; aggiornate per `codice` da migration (il seeder fa solo `insertOrIgnore`).
- `AllineamentoSeeder` idempotente, non tocca dati configurati né ruoli utenti.
- Testi utente in italiano semplice; al segnalante esterno nessun nome interno.
- Comandi test: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test --filter=<Nome>`.
- POST nei Feature test: `$this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class])`.
- Test con `Notification::fake()` che creano segnalazioni vedono anche `NuovaSegnalazioneNotification`: asserire il tipo atteso, non `assertNothingSent()`.
- Commit con messaggi Conventional Commits brevi in italiano (stile repo), senza attribuzione AI.
- Versione: patch `0.9.19` (publiccode + CHANGELOG nella stessa PR).

## Review Focus

1. Riassegna da ditta a operaio (o "a me"): la ditta non deve più vedere la segnalazione né ricevere email → test in Task 3.
2. Azioni multiple con mix di segnalazioni non applicabili o non visibili al gestore: saltate con motivo, nessuna eccezione, nessuna azione su segnalazioni fuori visibilità → test in Task 7.
3. Fine lavori della ditta dopo un preventivo accettato: non deve chiudere → test in Task 5.
4. Scadenza manuale e job `RicalcolaScadenzeSla` lanciato da modifica regole: la data manuale non deve cambiare → test in Task 2.
5. Email per segnalazione senza titolo (legacy, `titolo` NULL) o senza plesso: oggetto e riepilogo leggibili, nessun errore → test in Task 6.

---

### Task 1: Schema stati/azioni a DB e colonne nuove

**Files:**
- Create: `database/migrations/2026_11_13_000001_schema_stati_azioni_semplice.php`
- Modify: `database/seeders/TabelleRiferimentoSeeder.php:19-48` (stessi valori della migration per installazioni nuove)
- Modify: `app/Enums/SegnalazioneStato.php` (`label()`: 3 → `Assegnata`, 4 → `Assegnata a ditta`, 9 → `Chiusa senza intervento`)
- Modify: `app/Models/Segnalazione.php` (`$fillable`/`casts`: `scadenza_manuale` bool, `fine_lavori_at` datetime)
- Test: `tests/Feature/SchemaStatiAzioniTest.php`

**Interfaces:**
- Produces: colonne `segnalazioni.scadenza_manuale` (bool, default false), `segnalazioni.fine_lavori_at` (timestamp nullable); `db_azioni` come sotto.

Valori da applicare (per `codice`; `descrizione` e `parametri_filtro`):

| codice | descrizione | stati |
|---|---|---|
| prendi_in_carico | Assegna a me | [1,2,3,4,5] |
| assegna_operatore | Assegna operatore | [1,2,3,4,5] |
| assegna_impresa | Assegna impresa | [1,2,3,4,5] |
| sospendi | Sospendi | [2,3,4,5] |
| chiudi | Chiudi | [2,3,4] |
| annulla | Chiudi senza intervento | [1,2,3,4,5,6] |
| archivia | Archivia | [] |

`db_stato_segnalazioni.descrizione`: 3 `Assegnata`, 4 `Assegnata a ditta`, 9 `Chiusa senza intervento`.

- [ ] **Step 1: Write the failing test** `SchemaStatiAzioniTest` (RefreshDatabase + `TabelleRiferimentoSeeder`):
  - `test_filtri_azioni_secondo_schema`: per ogni riga della tabella sopra `Azione::where('codice', $c)->first()->parametri_filtro['stati'] === $stati` e descrizione attesa.
  - `test_etichette_stati`: `SegnalazioneStato::from(9)->label() === 'Chiusa senza intervento'`, 3 → `Assegnata`.
  - `test_migration_aggiorna_righe_esistenti`: inserisce a mano la riga `chiudi` con `{"stati":[2,3,4,5]}`, esegue la migration (`(require base_path('database/migrations/2026_11_13_000001_schema_stati_azioni_semplice.php'))->up()`), asserisce `[2,3,4]`.
  - `test_colonne_nuove`: `Schema::hasColumns('segnalazioni', ['scadenza_manuale', 'fine_lavori_at'])`.
- [ ] **Step 2:** Run `--filter=SchemaStatiAzioniTest` → FAIL.
- [ ] **Step 3:** Migration: `up()` aggiunge le due colonne (dopo `data_scadenza_sla`) e fa `DB::table('db_azioni')->where('codice', …)->update([...])` per ogni riga + update descrizioni stati; `down()` rimuove solo le colonne. Seeder e enum allineati.
- [ ] **Step 4:** Run test → PASS; poi suite completa: aspettarsi rotture in `ProssimoPassoTest`, `Browser/CambioStatoTest` e test che usano azione 1 da stato 1 o chiudi da 5 → annotarle, si sistemano nei Task 4–5.
- [ ] **Step 5: Commit** `feat(workflow): schema stati e azioni semplificato`

### Task 2: Scadenza manuale

**Files:**
- Create: `app/Services/Segnalazioni/ScadenzaManuale.php`
- Modify: `app/Jobs/RicalcolaScadenzeSla.php` (salta `scadenza_manuale = true`)
- Modify: `app/Http/Controllers/SegnalazioneController.php` `applicaPriorita()` (non tocca la data se manuale)
- Test: `tests/Feature/ScadenzaManualeTest.php`

**Interfaces:**
- Produces:
  - `ScadenzaManuale::imposta(Segnalazione $s, CarbonInterface $giorno, User $autore): void` — `data_scadenza_sla` = chiusura della fascia d'ufficio di `$giorno` (`CalendarioUfficio::daImpostazioni()->fascia($giorno)[1]`); se quel giorno è chiuso, la chiusura del primo giorno lavorativo successivo; senza orario configurato `endOfDay()`. Imposta `scadenza_manuale = true`, `sla_violato = false`, `sla_warning_inviato = false`, crea nota `Scadenza spostata al d/m/Y` (`visibile_web` false, `visibile_impresa` true). Giorno passato → `ValidationException` su `data_scadenza` ("La nuova scadenza non può essere nel passato.").
  - `ScadenzaManuale::rimuovi(Segnalazione $s): void` — flag false, ricalcola con `SlaService::calcolaScadenza()`.

- [ ] **Step 1: Failing tests:**
  - `test_imposta_usa_chiusura_ufficio` (orario lun 08:00-14:00, giorno lunedì futuro → scadenza alle 14:00 di quel giorno, flag true, nota creata).
  - `test_giorno_chiuso_slitta_al_lavorativo_successivo` (domenica → lunedì 14:00).
  - `test_data_passata_rifiutata`.
  - `test_ricalcolo_job_non_tocca_manuale`: scadenza manuale impostata, `RicalcolaScadenzeSla::dispatchSync()` → data invariata.
  - `test_cambio_priorita_non_tocca_manuale`: PATCH priorità → `data_scadenza_sla` invariata, `livello_priorita` cambiato.
  - `test_rimuovi_torna_al_calcolo`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implementa servizio + guardie nel job e in `applicaPriorita()`.
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5: Commit** `feat(sla): scadenza manuale`

### Task 3: Workflow — assegna a me, riassegna, riapri, riprendi

**Files:**
- Modify: `app/Services/SegnalazioneWorkflowService.php` (`eseguiTransizione`, `eseguiRiprendi`, `inviaNotifiche`)
- Test: `tests/Feature/WorkflowSchemaTest.php`

**Interfaces:**
- Consumes: `ScadenzaManuale::imposta/rimuovi` (Task 2).
- Produces: `eseguiAzione()` accetta in `$params` anche `data_scadenza` (string `Y-m-d`, facoltativa). Regole:
  - `prendi_in_carico` → `id_operatore_assegnato = $user->id`, `id_squadra_assegnata = null`, `id_appalto = null`.
  - `assegna_operatore` con operatore → `id_appalto = null`, `id_squadra_assegnata = null`; con squadra → `id_appalto = null`, operatore 0.
  - `assegna_impresa` → `id_operatore_assegnato = 0`, `id_squadra_assegnata = null`.
  - Ogni assegnazione azzera `fine_lavori_at`.
  - Ricalcolo scadenza in `eseguiTransizione` solo se `! scadenza_manuale`; poi se `data_scadenza` presente → `ScadenzaManuale::imposta`.
  - `riapri` → `id_operatore_assegnato = $user->id`, `fine_lavori_at = null`, `ScadenzaManuale::rimuovi`.
  - `riprendi` → `ScadenzaManuale::rimuovi` (oggi non ricalcola).
  - Notifica di assegnazione: solo al nuovo assegnatario (già così); "Assegna a me" non notifica nessuno.

- [ ] **Step 1: Failing tests:**
  - `test_assegna_operatore_da_nuova` (stato 1 → 3).
  - `test_assegna_a_me_da_nuova` (1 → 2, operatore = attore, nessuna `OperatoreAssegnatoNotification`).
  - `test_riassegna_da_ditta_a_operaio_toglie_visibilita_ditta`: stato 4 con appalto, assegna operatore → `id_appalto` null, `Segnalazione::visibileA($utenteDitta)` non la contiene, nessuna `ImpresaAssegnataNotification`.
  - `test_riassegna_conserva_scadenza_manuale` e `test_riassegna_con_nuova_data_la_imposta`.
  - `test_riapri_mette_in_carico_a_chi_riapre_e_azzera_manuale`.
  - `test_riprendi_ricalcola_scadenza`.
  - `test_chiudi_da_nuova_non_applicabile` e `test_chiudi_da_preventivo_non_applicabile` (`ValidationException`).
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implementa.
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5: Commit** `feat(workflow): assegna da nuova, riassegna, assegna a me`

### Task 4: Chiudi semplice e "Chiudi senza intervento" (scheda)

**Files:**
- Modify: `app/Http/Controllers/SegnalazioneController.php:322-341` (regole `chiudi` e `annulla`)
- Modify: `app/Services/SegnalazioneWorkflowService.php` (testo nota per `annulla`, rimuovere blocco rapportino ore/materiali)
- Modify: `resources/views/segnalazioni/scheda/pannelli/chiudi.blade.php` (solo nota + foto facoltative)
- Modify: `resources/views/segnalazioni/scheda/pannelli/annulla.blade.php` (titolo "Chiudi senza intervento", radio `motivo`, textarea `nota`)
- Modify: `app/Support/Segnalazioni/ProssimoPasso.php` (`gestore()`)
- Modify: `tests/Unit/Segnalazioni/ProssimoPassoTest.php`, `tests/Browser/CambioStatoTest.php` (allineare a "Assegna…" invece di "Prendi in carico")
- Test: `tests/Feature/ChiusuraSempliceTest.php`

**Interfaces:**
- Produces: `nota` obbligatoria per `sospendi` e `rifiuta_preventivo` (spec §1, "motivo"); campo `motivo` ∈ `non_pertinente|errata|gia_risolta|altro` per azione `annulla`; `nota` obbligatoria solo con `altro`. Nota creata: `"Chiusa senza intervento: {Etichetta}."` + `"\n".$nota` se presente, `visibile_web = true`. Etichette: `Non pertinente`, `Segnalazione errata`, `Già risolta`, `Altro`.
- `ProssimoPasso::gestore()`:
  - NUOVA: titolo `Da smistare`, Assegna… (primaria), Chiudi → pannello `annulla`, Priorità, Accorpa.
  - IN_CARICO: titolo `In carico a {nome}`, Assegna… (primaria), Chiudi → pannello `chiudi`.
  - 3/4 senza fine lavori: Sollecita (primaria), Chiudi → pannello `chiudi`, Riassegna, Sospendi.
  - 4 con `fine_lavori_at`: titolo "… ha comunicato la fine dei lavori il …: verifica e chiudi", Chiudi primaria.

- [ ] **Step 1: Failing tests:**
  - `test_chiudi_senza_campi` (stato 3, POST azione 9 senza nota/allegati → stato 7).
  - `test_chiudi_con_foto_facoltativa_salva_allegato_dopo`.
  - `test_chiudi_senza_intervento_da_nuova_richiede_motivo` (senza motivo → errore `motivo`; con `errata` → stato 9, nota visibile_web con "Segnalazione errata").
  - `test_motivo_altro_richiede_nota`.
  - `test_sospendi_e_rifiuta_preventivo_richiedono_nota`.
  - `ProssimoPassoTest`: Nuova non contiene "Prendi in carico", contiene "Assegna…" primaria e "Chiudi".
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implementa controller, servizio, pannelli, ProssimoPasso; aggiorna Dusk `CambioStatoTest`.
- [ ] **Step 4:** Run filtri + `ProssimoPassoTest` → PASS.
- [ ] **Step 5: Commit** `feat(scheda): chiudi senza obblighi e chiudi senza intervento`

### Task 5: Fine lavori che chiude

**Files:**
- Modify: `app/Services/Segnalazioni/FineLavoriService.php`
- Modify: `app/Models/Segnalazione.php` (`haFineLavori()`, nuovo `haPreventivo()`)
- Modify: `app/Http/Controllers/SegnalazioneController.php` `fineLavori()` (foto facoltative, messaggio)
- Modify: `resources/views/segnalazioni/scheda/pannelli/fine-lavori.blade.php` (foto facoltativa)
- Modify: `app/Support/Segnalazioni/ProssimoPasso.php` `lavoro()` (titolo prima del pannello: "Quando hai finito, comunica la fine dei lavori")
- Modify: `tests/Feature/FineLavoriTest.php`

**Interfaces:**
- Consumes: workflow `eseguiAzione($s, 9, $autore, [])` (Task 3/4: chiudi applicabile da 3 e 4).
- Produces:
  - `Segnalazione::haPreventivo(): bool` — esiste in `stati_segnalazioni` un passaggio a 5 per questa segnalazione.
  - `Segnalazione::haFineLavori(): bool` — `fine_lavori_at !== null` oppure allegati `fase = dopo` (compatibilità).
  - `FineLavoriService::comunica(...)` ora restituisce `bool` (true = chiusa): salva nota e foto (se presenti), imposta `fine_lavori_at = now()`, invia `FineLavoriNotification` al comune come oggi; poi chiude via workflow se l'autore non è `impresa`, oppure è `impresa` e `! haPreventivo()`.
  - Messaggio controller: chiusa → `Lavoro chiuso. Grazie!`; altrimenti `Fine lavori comunicata al comune.`

- [ ] **Step 1: Failing tests (in `FineLavoriTest`):**
  - `test_operaio_fine_lavori_chiude` (stato 3 → 7, `SegnalazioneChiusaNotification` al segnalante, `FineLavoriNotification` al comune).
  - `test_fine_lavori_senza_foto_accettata`.
  - `test_ditta_senza_preventivo_chiude`.
  - `test_ditta_con_preventivo_resta_aperta` (storico con passaggio a 5 → resta 4, `fine_lavori_at` valorizzato, ProssimoPasso gestore "verifica e chiudi").
  - Aggiornare i test esistenti che asseriscono foto obbligatoria o stato invariato.
- [ ] **Step 2:** Run `--filter=FineLavoriTest` → FAIL.
- [ ] **Step 3:** Implementa.
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5: Commit** `feat(lavori): fine lavori chiude, salvo preventivo`

### Task 6: Email con tipologia e titolo

**Files:**
- Create: `app/Notifications/Concerns/DescriveSegnalazione.php`
- Modify: le notifiche per segnalazione: `NuovaSegnalazioneNotification`, `OperatoreAssegnatoNotification`, `SquadraAssegnataNotification`, `ImpresaAssegnataNotification`, `SollecitoNotification`, `SegnalazioneChiusaNotification`, `SegnalazioneStatoCambiato`, `NuovaNotaSegnalazione`, `NotaDaEsternoNotification`, `FineLavoriNotification`, `SlaWarningNotification`, `SlaViolazioneNotification`, `InattivitaNotification`, `ArchiviataInattivitaNotification`, `AccorpataNotification`, e le righe di `DigestGestoreNotification`, `RiepilogoInattivitaNotification`
- Modify: `app/Services/SegnalazioneWorkflowService.php` (passa `$params['nota'] ?? null` alle notifiche di assegnazione)
- Test: `tests/Feature/EmailDescrizioneSegnalazioneTest.php`

**Interfaces:**
- Produces (trait):
  - `protected function oggettoSegnalazione(Segnalazione $s, string $motivo): string` → `"{$motivo} — #{id} · {TIPOLOGIA} · {titoloLeggibile(60)}"`; senza tipologia omette quel pezzo.
  - `protected function riepilogoSegnalazione(MailMessage $m, Segnalazione $s, bool $perChiLavora = false, ?string $nota = null): MailMessage` → righe `Titolo:`, `Tipologia:`, `Sede:` (`plesso->nome` + indirizzo, se plesso), `Stato:` (`statoEnum()->etichetta()`); con `$perChiLavora` anche `Cosa è stato segnalato:` (testo, max 500 caratteri), `Referente:` (referente · recapiti del plesso, se presenti), `Scadenza:` (d/m/Y H:i se presente), `Nota del comune:` ($nota se presente).
  - `protected function rigaSegnalazione(Segnalazione $s): string` → `"#{id} · {TIPOLOGIA} · {titoloLeggibile(60)}"` (riepiloghi e Telegram).
  - `OperatoreAssegnatoNotification`, `SquadraAssegnataNotification`, `ImpresaAssegnataNotification`: nuovo argomento costruttore finale `?string $nota = null`.

Motivi negli oggetti: `Nuova segnalazione`, `Assegnata a te`, `Assegnata alla tua squadra`, `Lavoro affidato alla ditta`, `Sollecito`, `Chiusa`, `Aggiornamento`, `Nuovo messaggio`, `Fine lavori`, `In scadenza`, `Scaduta`, `Ferma da tempo`, `Archiviata per inattività`, `Accorpata`.

- [ ] **Step 1: Failing tests:** data provider con una istanza per ogni notifica sopra costruita su una segnalazione con tipologia `IMPIANTO TERMICO` e titolo `Termosifoni freddi aula 3B`:
  - `test_oggetto_contiene_id_tipologia_titolo` (`toMail($notifiable)->subject` contiene `#id`, `IMPIANTO TERMICO`, `Termosifoni freddi aula 3B`).
  - `test_assegnazione_operaio_ha_testo_sede_referente_nota` (`introLines` contengono testo segnalato, nome plesso, nota passata).
  - `test_segnalazione_senza_titolo_e_senza_plesso` (titolo NULL, `id_plesso` 0 → nessuna eccezione, oggetto usa `titoloLeggibile()`).
  - `test_riepilogo_digest_righe_con_tipologia`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implementa trait e aggiorna le notifiche (stessa struttura: `baseMailMessage(oggetto)` → `riepilogoSegnalazione()` → righe specifiche esistenti → action).
- [ ] **Step 4:** Run filtro + `--filter=Notific` e `TracciaNotificheTest` → PASS.
- [ ] **Step 5: Commit** `feat(email): tipologia e titolo in tutte le email`

### Task 7: Azioni multiple in elenco gestione

**Files:**
- Create: `app/Services/Segnalazioni/AzioniMultiple.php`
- Create: `app/Http/Controllers/AzioniMultipleController.php` (invokable)
- Modify: `routes/web.php:136-143` → `Route::post('/azioni-multiple', AzioniMultipleController::class)->name('azioni-multiple');`
- Modify: `app/Http/Controllers/GestioneController.php` `stampaSchede()` (parametro `ids[]`)
- Modify: `resources/views/gestione/dashboard.blade.php`, `resources/views/segnalazioni/_riga.blade.php` (param `selezionabile`), nuovo `resources/views/gestione/_azioni-multiple.blade.php` (barra + drawer)
- Test: `tests/Feature/AzioniMultipleTest.php`

**Interfaces:**
- Consumes: `SegnalazioneWorkflowService::eseguiAzione()` con `data_scadenza` (Task 3); motivo/nota di `annulla` (Task 4).
- Produces:
  - `AzioniMultiple::esegui(Collection $segnalazioni, User $u, string $operazione, array $dati): array{fatte: int, saltate: array<int, string>}` — operazioni `assegna` (`tipo` ∈ `me|operaio|squadra|ditta`, `id_operatore`/`id_squadra`/`id_appalto`, `nota`, `data_scadenza`), `chiudi` (`nota`), `chiudi_senza_intervento` (`motivo`, `nota`). Per ogni segnalazione: `Gate::forUser($u)->denies('update', $s)` → saltata "non di tua competenza"; `ValidationException` dal workflow → saltata col primo messaggio. Ogni segnalazione in una propria transazione.
  - Controller: valida `ids` (array, 1..100, interi), `operazione`, campi; carica con `Segnalazione::visibileA($u)->whereIn(...)`; redirect a `gestione.dashboard` (query string precedente) con flash `"{fatte} fatte"` + `", {n} saltate"` e dettaglio `#id: motivo` (max 10 righe).
  - `stampaSchede`: con `ids` filtra `whereIn` sulla query visibile, ordina per `id_operatore_assegnato`, `id_appalto`, `id_plesso`.
- UI: checkbox per riga (`name="ids[]"`, form esterno via attributo `form`), "seleziona tutte" nell'intestazione, barra sticky in basso visibile con ≥1 selezionata: `Assegna…` (drawer con a chi/nota/scadenza), `Chiudi` (drawer nota), `Chiudi senza intervento` (drawer motivo/nota), `Stampa schede` (GET `gestione.stampa-schede` con `ids[]`, nuova scheda). Sotto 768px la barra resta in basso a tutta larghezza.

- [ ] **Step 1: Failing tests:**
  - `test_assegna_multipla_a_operaio` (3 Nuove → stato 3, una `OperatoreAssegnatoNotification` per segnalazione).
  - `test_chiudi_multipla_salta_non_applicabili` (una in 3, una in 1 → 1 fatta, 1 saltata con motivo).
  - `test_non_visibili_ignorate`: gestore non supervisore, una segnalazione di tipologia non sua negli `ids` → non toccata.
  - `test_chiudi_senza_intervento_multipla_con_motivo`.
  - `test_assegna_multipla_con_scadenza_imposta_manuale`.
  - `test_max_100_ids` (101 → errore di validazione).
  - `test_stampa_schede_con_ids_solo_selezionate`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implementa servizio, controller, rotta, stampa, UI.
- [ ] **Step 4:** Run → PASS. Verifica in browser (login `mock.supervisore`): seleziona 2 righe, assegna, poi chiudi; barra visibile anche a 375px.
- [ ] **Step 5: Commit** `feat(gestione): azioni multiple in elenco`

### Task 8: Documentazione, versione, verifica finale

**Files:**
- Modify: `CLAUDE.md` (sezione "Workflow Stati": schema nuovo, chiusura senza obblighi, fine lavori che chiude, scadenza manuale, azioni multiple)
- Modify: `CHANGELOG.md` (`## [0.9.19] - 2026-11-13`), `publiccode.yml` (`softwareVersion: 0.9.19`, `releaseDate`)

- [ ] **Step 1:** Aggiorna i file.
- [ ] **Step 2:** `docker compose exec php php artisan test` → tutta verde; `docker compose exec php composer run analyse` → nessun errore nuovo.
- [ ] **Step 3:** Giro in browser come nella simulazione (scuola crea → supervisore assegna da Nuova → operaio fine lavori senza foto → chiusa; chiudi senza intervento da Nuova; email in Mailpit con tipologia e titolo).
- [ ] **Step 4: Commit** `docs: tappa 1 iter segnalazioni (0.9.19)`
