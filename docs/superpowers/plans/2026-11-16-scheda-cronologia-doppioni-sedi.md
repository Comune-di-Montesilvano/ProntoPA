# Scheda leggibile, doppioni, sedi sulla mappa — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Scheda segnalazione che si capisce in cinque secondi (fatti, cronogramma, storia vera), doppioni portati su sollecito/integrazione, sedi geocodificate da sole.

**Architecture:** Logica di presentazione in classi `App\Support\Segnalazioni\*` che restituiscono array pronti per Blade (testabili in PHP, nessuna libreria JS nuova); correzioni dati con una colonna `note_segnalazioni.origine` e un controllo "a vuoto" nel workflow; doppioni con un metodo nuovo di `DedupService` usato da form, endpoint `simili` e `store`; geocodifica con comando schedulato + observer su `Plesso`.

**Tech Stack:** Laravel 13, PHP 8.4, MariaDB, Blade + Tailwind 4 + Alpine, Leaflet già caricato, PHPUnit (Feature su SQLite).

**Spec:** `docs/superpowers/specs/2026-11-16-scheda-cronologia-doppioni-sedi-design.md` (leggerla prima di ogni parte: copy, tabelle frasi e regole per lettore sono lì e qui non si ripetono).

## Global Constraints

- Versione 1.0.2 (patch). `publiccode.yml` `softwareVersion` + CHANGELOG `## [1.0.2] - <data>` nella stessa PR.
- **Una sola PR** dal ramo `feat/scheda-leggibile` (scelta del piano: il merge su `main` va in prod alle 04:30, PR parziali porterebbero in prod metà scheda senza versione). Un commit per task, `/commit` (caveman-commit, niente attribuzione AI).
- Migrations solo additive: `note_segnalazioni.origine` varchar(20) nullable; `plessi.geocodifica_tentata_at` datetime nullable.
- Niente CDN, niente colori fuori dai token di `resources/css/app.css`; icone Font Awesome sempre dentro uno `span`.
- Nessuna chiamata a Nominatim in una richiesta web oltre al pulsante "trova dall'indirizzo" già esistente; job a 1 richiesta/s.
- Segnalante: mai nomi del personale, sempre `Impostazione::get('etichetta_ufficio_segnalante', 'Ufficio manutenzioni')`; "Tu" per sé.
- Etichetta di chi ha segnalato: "la scuola" se l'autore `isSpid()`, altrimenti "chi ha segnalato".
- Testo delle note a DB mai riscritto: il prefisso si toglie solo in visualizzazione.
- POST nei Feature test: `$this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class])`. `max:` = lunghezza colonna (SQLite non la controlla).
- Test con `Notification::fake()` che creano segnalazioni: restringere le asserzioni al tipo atteso (l'observer manda `NuovaSegnalazioneNotification`).
- Comandi: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test --filter=<Nome>`; suite intera `... php artisan test`; analisi `... composer run analyse`.

## Review Focus

1. **Intervallo nullo o rovesciato nel cronogramma** — segnalazione appena creata (`data_segnalazione` = ora, nessuna scadenza) o scadenza precedente all'apertura: niente divisione per zero, un solo tratto al 100%. Test in Task 12.
2. **Storico con date prima dell'apertura** (legacy importato) — eventi con `data_registrazione` < `data_segnalazione` vanno portati all'apertura, mai percentuali negative. Test in Task 12.
3. **Cambio d'ora** (ultima domenica di ottobre) dentro l'intervallo — percentuali calcolate su timestamp, la somma resta 100. Test in Task 12.
4. **Esistente che cambia tra apertura del form e invio** — `diversa_da` di una segnalazione nel frattempo chiusa → creata senza errore; nel frattempo è comparsa un'altra aperta → errore che nomina quella nuova. Test in Task 7.
5. **Indirizzo "cambiato" solo per spazi o maiuscole** (salvataggio form, sync MIUR) — non deve azzerare coordinate messe a mano. Confronto su `mb_strtolower(trim())`. Test in Task 10.

---

## Parte B — Correzioni alla storia

### Task 1: Storico in ordine crescente e righe doppie fuse in lettura

**Files:**
- Modify: `app/Support/Segnalazioni/Attivita.php` (`voci()`, `inizioLavoro()`)
- Create: `app/Support/Segnalazioni/StoricoOrdinato.php`
- Test: `tests/Feature/AttivitaTest.php`

**Interfaces:**
- Produces: `StoricoOrdinato::di(Segnalazione $s): Collection<int, StoricoStatoSegnalazione>` — storico con `reorder('data_registrazione')->orderBy('id')`, righe consecutive con stesso `id_stato_segnalazione`, `id_utente_collegato`, `id_appalto` ridotte alla prima. Usato da `Attivita` e `Cronogramma` (Task 12).

- [ ] **Step 1: Test `test_storico_3579_racconta_una_presa_in_carico_e_la_ripresa`** — storico creato con gli stessi orari di #3579 (stato 2 alle 08:16:08 collegato = gestore; 6 alle 14:00:34; 2 alle 14:01:02; 2 alle 14:01:23 collegato = gestore), inserito **in ordine inverso di id** per provare che l'ordine viene dalla data. Lettura gestore, voci di tipo `stato` dalla più vecchia:

```php
$this->assertSame(['ha preso in carico', 'ha sospeso', 'ha ripreso'], $frasi);
$this->assertSame('08:16', $stati->first()['quando']->format('H:i'));
```

Secondo test `test_righe_identiche_consecutive_mostrate_una_volta`: due righe stato 3 stesso operaio a 1 minuto → una voce.

- [ ] **Step 2: Run** `--filter=AttivitaTest` → FAIL (tre "ha preso in carico"/ordine sbagliato).
- [ ] **Step 3: Implementa `StoricoOrdinato::di()` e usalo in `Attivita::voci()`** al posto di `storicoStati()->orderBy(...)`. Controlla con grep `storicoStati()->orderBy` gli altri punti che contano sull'ordine crescente e passali a `reorder()`.
- [ ] **Step 4: Run** `--filter=AttivitaTest` → PASS.
- [ ] **Step 5: Commit** `fix(attivita): ordina storico e fondi righe doppie`

### Task 2: Passaggi a vuoto non scritti

**Files:**
- Modify: `app/Services/SegnalazioneWorkflowService.php:264-383` (`eseguiTransizione`)
- Modify: `resources/views/segnalazioni/scheda/pannelli/` (pannello assegna: opzione "a me")
- Test: `tests/Feature/WorkflowSchemaTest.php` (o nuovo `tests/Feature/PassaggiAVuotoTest.php`)

**Interfaces:**
- Produces: nessuna firma nuova. Regola: se `$nuovoStato` = stato attuale **e** `id_operatore_assegnato`, `id_squadra_assegnata`, `id_appalto` risultanti = attuali → niente `StoricoStatoSegnalazione`, niente notifiche, niente `automaticallyPublish`, niente webhook; nota (`params['nota']`) e `data_scadenza` salvate comunque.

- [ ] **Step 1: Test** `test_assegna_a_me_gia_mia_non_scrive_storico`: segnalazione stato 2 con `id_operatore_assegnato` = gestore; esegui `prendi_in_carico` → `assertSame($prima, StoricoStatoSegnalazione::count())`, `Notification::assertNothingSent()`, `Queue::assertNotPushed(InviaWebhookOutbound::class)`. `test_assegna_a_me_gia_mia_con_nota_salva_la_nota`: stessa azione con `nota` → una `NotaSegnalazione` con quel testo. `test_riassegna_stesso_operaio_ad_altro_scrive_storico`: operaio diverso → storico +1.
- [ ] **Step 2: Run** → FAIL sul primo.
- [ ] **Step 3: Implementa** il controllo confrontando `$update` con gli attributi correnti prima di `$segnalazione->update($update)`; ritorno anticipato dopo nota/scadenza. Nel pannello Assegna nascondi "Assegna a me" se `id_operatore_assegnato === auth()->id()` e stato 2.
- [ ] **Step 4: Run** test nuovi + `--filter=Workflow` → PASS.
- [ ] **Step 5: Commit** `fix(workflow): niente storico per passaggi a vuoto`

### Task 3: Origine delle note automatiche

**Files:**
- Create: `database/migrations/2026_11_16_000001_origine_note.php`
- Modify: `app/Models/NotaSegnalazione.php` (fillable + costanti)
- Modify (impostano `origine`): `app/Services/Segnalazioni/ScadenzaManuale.php:51`, `app/Services/Segnalazioni/AccorpamentoService.php:58` (solo nota sulla destinazione), `app/Http/Controllers/SegnalazioneController.php` (`sollecita`, `sollecitoSegnalante`), `app/Services/SegnalazioneWorkflowService.php` (`rimandaAlGestore` nota, `annulla` in `eseguiTransizione`), `app/Http/Controllers/AdesioniSegnalazioniController.php:67`
- Test: `tests/Feature/OrigineNoteTest.php`

**Interfaces:**
- Produces: `NotaSegnalazione::ORIGINE_SCADENZA='scadenza'`, `ORIGINE_RISEGNALATA='risegnalata'`, `ORIGINE_SOLLECITO='sollecito'`, `ORIGINE_INTEGRAZIONE='integrazione'`, `ORIGINE_RIMANDO='rimando'`, `ORIGINE_PRIORITA='priorita'`, `ORIGINE_SENZA_INTERVENTO='senza_intervento'`; `NotaSegnalazione::PREFISSI: array<string,string>` (origine → prefisso: `Scadenza spostata al `, `Segnalata di nuovo da `, `Sollecito: `, `Rimandata al comune: `, `Priorità aumentata a livello `, `Chiusa senza intervento: `); `NotaSegnalazione::testoSenzaPrefisso(): string`.

- [ ] **Step 1: Test** — `test_backfill_riconosce_ogni_prefisso`: inserisci note con ciascun prefisso via `DB::table` con colonna già presente a `null`, invoca la `up()` della migration su quelle righe (estrai il backfill in un metodo statico pubblico della migration o in `NotaSegnalazione::origineDaTesto(string): ?string` usato dalla migration — scelta del piano: **`origineDaTesto`**) → origine attesa per ognuna. `test_sollecito_senza_due_punti_resta_null`: `"Sollecito urgente per la palestra"` → `null`. `test_note_automatiche_nascono_con_origine`: `ScadenzaManuale::imposta`, `sollecitoSegnalante`, `accorpa`, `annulla`, `rimandaAlGestore` → `origine` corretta; la nota "Era già stata segnalata…" sull'accorpata resta `null`. `test_testo_senza_prefisso`: `"Sollecito: arrivate?"` → `"arrivate?"`.
- [ ] **Step 2: Run** `--filter=OrigineNoteTest` → FAIL.
- [ ] **Step 3: Implementa** migration (colonna `after('visibile_impresa')`, backfill a blocchi con `chunkById('id_nota')` su `whereNull('origine')`, `down()` drop colonna), costanti, `origineDaTesto` (match su `str_starts_with`), `testoSenzaPrefisso`, e `origine` in ogni punto di creazione elencato. Testo invariato.
- [ ] **Step 4: Run** `--filter=OrigineNoteTest` + `--filter=Sollecito` + `--filter=Accorpa` + `--filter=ScadenzaManuale` + `--filter=RimandaAlGestore` → PASS.
- [ ] **Step 5: Commit** `feat(note): colonna origine per le note automatiche`

### Task 4: "Segnalata N volte" contata giusta

**Files:**
- Modify: `app/Models/Segnalazione.php:227-231` (`volteSegnalata`)
- Test: `tests/Feature/VolteSegnalataTest.php`

**Interfaces:**
- Consumes: `NotaSegnalazione::ORIGINE_SOLLECITO`, `ORIGINE_INTEGRAZIONE` (Task 3).
- Produces: `Segnalazione::volteSegnalata(): int` = 1 + adesioni + note con origine sollecito/integrazione scritte dall'autore (`id_utente = id_utente_segnalazione`) + accorpate qui (`duplicati()`, `id_segnalazione_madre`) con stesso `id_utente_segnalazione`. Mantieni l'uso di `adesioni_count` se caricato.

- [ ] **Step 1: Test** cinque casi: solo adesione → 2; sollecito dell'autore (origine sollecito) → 2; integrazione di altra persona **con** sua adesione → 2 (non 3); accorpata dello stesso autore (caso #3579/#3588) → 2; accorpata di altro autore (che ha già l'adesione da `aggiungiAderente`) → 2.
- [ ] **Step 2: Run** → FAIL su sollecito e accorpata stesso autore.
- [ ] **Step 3: Implementa.**
- [ ] **Step 4: Run** `--filter=VolteSegnalataTest` + `--filter=Adesioni` → PASS.
- [ ] **Step 5: Commit** `fix(segnalazioni): conta solleciti e accorpate stesso autore`

---

## Parte C — Doppioni alla creazione

### Task 5: Segnalazione aperta per sede e tipologia

**Files:**
- Modify: `app/Services/DedupService.php`
- Test: `tests/Feature/DedupTest.php`

**Interfaces:**
- Produces: `DedupService::apertaPerSedeETipologia(User $u, int $idPlesso, int $idTipologia): ?Segnalazione` — `Segnalazione::aperte()->visibileA($u)`, stessa sede/tipologia, esclude quelle con storico stato 5 (`whereDoesntHave('storicoStati', stato 5)`), ordine: autore = `$u` prima, poi `data_segnalazione` desc. Indipendente da `adesioni_enabled` e `dedup_giorni`.

- [ ] **Step 1: Test** `test_trova_aperta_visibile_anche_con_adesioni_spente_e_vecchia_di_120_giorni`; `test_ignora_chiusa`; `test_ignora_altra_sede_o_tipologia`; `test_ignora_con_preventivo`; `test_ignora_non_visibile` (segnalatore AD di altra persona: `visibileA` la esclude); `test_preferisce_la_propria` (due aperte, quella dell'utente più vecchia → restituita la sua).
- [ ] **Step 2: Run** `--filter=DedupTest` → FAIL.
- [ ] **Step 3: Implementa.**
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(dedup): trova aperta per sede e tipologia`

### Task 6: Sollecito o integrazione dal form

**Files:**
- Modify: `app/Http/Controllers/SegnalazioneController.php` (nuovo `aggiornamento`)
- Modify: `app/Policies/SegnalazionePolicy.php`
- Modify: `routes/web.php` (vicino a `sollecito-segnalante`, riga ~110)
- Test: `tests/Feature/AggiornamentoDalFormTest.php`

**Interfaces:**
- Consumes: costanti origine (Task 3), `CaricaAllegati`, `DestinatariAssegnatario::per`, `DestinatariComune::per`, `SollecitoNotification($s, $testo, dalSegnalante: true)`.
- Produces: rotta `POST segnalazioni/{segnalazione}/aggiornamento` → `segnalazioni.aggiornamento`, `->middleware('throttle:6,1')`; policy `SegnalazionePolicy::aggiornaDalForm(User $u, Segnalazione $s): bool` = `view` && `! $s->isChiusa()`. Validazione: `testo` nullable string max 2000, `allegati` come `store`. Testo vuoto e niente allegati → nota `"Sollecito: il problema c'è ancora, chiedo un aggiornamento."` origine sollecito; altrimenti nota col testo (o "Ha aggiunto delle foto." se solo allegati — scelta del piano) origine integrazione. `visibile_web=true`, `visibile_impresa=(bool) id_appalto`. Non autore → `AdesioneSegnalazione::firstOrCreate(['id_segnalazione'=>…, 'id_utente'=>…])`. Redirect a `segnalazioni.show` con `success` = `"Inviato alla #{id}."`. L'estrazione dell'invio notifiche condivisa con `sollecitoSegnalante` va in un metodo privato `avvisaChiLaSegue(Segnalazione, User, SollecitoNotification): void`.

- [ ] **Step 1: Test** `test_testo_vuoto_scrive_sollecito_e_avvisa`; `test_con_testo_scrive_integrazione`; `test_solo_foto_scrive_integrazione_con_allegato`; `test_non_autore_diventa_aderente_una_volta` (due invii → 1 adesione, anche con `adesioni_enabled` false); `test_403_se_non_la_vede`; `test_rifiutato_su_chiusa` (403); `test_non_serve_scadenza_o_inattivita` (segnalazione fresca, policy `sollecitaComeSegnalante` sarebbe falsa → 302 ok).
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implementa** rotta, policy, controller.
- [ ] **Step 4: Run** `--filter=AggiornamentoDalFormTest` + `--filter=SollecitoSegnalante` → PASS.
- [ ] **Step 5: Commit** `feat(segnalazioni): sollecito o integrazione dal form`

### Task 7: Controllo sul server e endpoint `simili`

**Files:**
- Modify: `app/Http/Controllers/SegnalazioneController.php` (`store`, `simili`)
- Test: `tests/Feature/DoppioniStoreTest.php`, `tests/Feature/DedupTest.php` (endpoint)

**Interfaces:**
- Consumes: `apertaPerSedeETipologia` (Task 5).
- Produces: `store` accetta `diversa_da` (nullable integer). Se c'è un'esistente senza preventivo e `diversa_da != id` → `ValidationException` su chiave `esistente` con messaggio `"C'è già la segnalazione #{id} aperta per questa sede e tipologia."`. Con `diversa_da` valido, o esistente con preventivo (cercata con una seconda query uguale ma senza il filtro preventivo: metodo privato o parametro `bool $ancheConPreventivo = false` su `apertaPerSedeETipologia` — scelta del piano: **parametro**), `id_segnalazione_correlata` = id esistente. `simili` aggiunge alla risposta la chiave `esistente: {id, titolo, stato, aperta_da, tua, ultima_risposta}|null` — **la risposta diventa un oggetto** `{simili: [...], esistente: …}`: aggiornare il JS del form (Task 8) e i test che leggono l'array. `aperta_da` = "6 giorni fa"/"oggi"; `ultima_risposta` = `{quando: 'venerdì', testo: Str::limit(...,120)}` dall'ultima nota `visibile_web` scritta dal personale, null se nessuna. Le `simili` escludono l'`esistente`.

- [ ] **Step 1: Test** `test_store_rifiutato_con_aperta_stessa_sede_tipologia`; `test_store_con_diversa_da_crea_e_collega`; `test_store_con_esistente_con_preventivo_crea_e_collega_senza_chiedere`; `test_store_con_diversa_da_di_una_nel_frattempo_chiusa_passa`; `test_store_con_diversa_da_vecchia_e_nuova_aperta_comparsa_rifiuta_nominando_la_nuova`; `test_store_altra_tipologia_passa_senza_collegamento`; `test_simili_restituisce_esistente_con_tua_true_per_l_autore`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implementa.**
- [ ] **Step 4: Run** `--filter=DoppioniStoreTest` + `--filter=DedupTest` + `--filter=FormNuovaSegnalazioneTest` → PASS.
- [ ] **Step 5: Commit** `feat(segnalazioni): proponi l'aperta prima di crearne un'altra`

### Task 8: Form e scheda per i doppioni

**Files:**
- Modify: `resources/views/segnalazioni/create.blade.php`
- Modify: `resources/views/segnalazioni/scheda/_collegate.blade.php`
- Test: `tests/Feature/FormNuovaSegnalazioneTest.php`, `tests/Feature/CollegaTest.php`

**Interfaces:**
- Consumes: risposta `simili` `{simili, esistente}` e rotta `segnalazioni.aggiornamento` (Task 6-7), azione accorpa esistente (`segnalazioni.accorpa`).
- Produces: nel form, stato Alpine `esistente` e `diversa` (bool); con `esistente && !diversa` il resto del form è sostituito dal riquadro "ESISTE GIÀ" della spec (copy esatta lì), con form proprio `multipart` verso `segnalazioni.aggiornamento` (textarea `testo`, input `allegati[]`). "È un problema diverso, apro una nuova segnalazione →" imposta `diversa=true`, mostra l'avviso fisso "Stai aprendo una segnalazione nuova: la #N resta aperta" e l'hidden `diversa_da`. Errore di validazione `esistente` → la pagina riapre il riquadro (`old('id_plesso')`, `old('id_tipologia_segnalazione')` ricaricano `simili`). Il riquadro "Anche per me" resta per le `simili` non visibili. In `_collegate`, se `segnalazioneCorrelata` aperta e questa è nata dopo di lei: riga "Aperta con la #N ancora aperta" + pulsante "Accorpa alla #N" (solo `@can('accorpa', …)`).

- [ ] **Step 1: Test** HTML: `test_form_contiene_riquadro_esistente_e_campo_diversa_da` (assertSee delle stringhe "C'è già una segnalazione aperta per questo", `name="diversa_da"`); `test_errore_esistente_mostra_il_messaggio`; `test_collegate_mostra_accorpa_alla_correlata_aperta`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implementa** viste.
- [ ] **Step 4: Run** test + `npm run build` nel container; prova a mano con `mock.segnalatore`: due segnalazioni stessa sede/tipologia → riquadro, invio → nota in scheda.
- [ ] **Step 5: Commit** `feat(form): riquadro segnalazione già aperta`

---

## Parte D — Sedi sulla mappa

### Task 9: Geocodifica automatica giornaliera

**Files:**
- Create: `database/migrations/2026_11_16_000002_geocodifica_tentata_plessi.php`
- Create: `app/Console/Commands/GeocodificaSediMancanti.php` (firma `sedi:geocodifica`)
- Modify: `app/Jobs/GeocodificaSedi.php`, `app/Models/Plesso.php` (fillable + cast datetime), `routes/console.php`
- Test: `tests/Feature/GeocodificaAutomaticaTest.php`

**Interfaces:**
- Produces: `Plesso::scopeDaGeocodificare(Builder)` = senza `latitudine`, indirizzo non vuoto, `geocodifica_tentata_at` null. Job lavora solo su `daGeocodificare()`; su `TROVATA` e `NON_TROVATA` scrive `geocodifica_tentata_at = now()`, su `ERRORE` no. Comando: se `Plesso::daGeocodificare()->exists()` → `GeocodificaSedi::dispatch()`, stampa quante. Schedule `dailyAt('06:10')->runInBackground()`.

- [ ] **Step 1: Test** `test_comando_accoda_solo_con_sedi_mai_tentate` (`Queue::fake`, una tentata + una con coordinate → non accoda; una mai tentata → accoda); `test_job_segna_tentativo_su_trovata_e_non_trovata_non_su_errore` (`Http::fake` sequenza tre risposte per tre sedi, `Sleep::fake()`); `test_job_salta_sedi_gia_tentate`; `test_comando_schedulato_alle_0610` (cerca l'evento in `app(Schedule::class)->events()`).
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implementa.**
- [ ] **Step 4: Run** + `--filter=CoordinateSediTest` → PASS.
- [ ] **Step 5: Commit** `feat(sedi): geocodifica automatica ogni mattina`

### Task 10: Indirizzo cambiato e "riprova" manuale

**Files:**
- Create: `app/Observers/PlessoObserver.php` (`#[ObservedBy]` su `Plesso`, come `SegnalazioneObserver`)
- Modify: `app/Http/Controllers/Admin/SediController.php:110` (`geocodificaMancanti`)
- Test: `tests/Feature/GeocodificaAutomaticaTest.php`

**Interfaces:**
- Produces: `PlessoObserver::updating(Plesso $p)`: se `indirizzo` dirty **e** `mb_strtolower(trim(nuovo)) !== mb_strtolower(trim(vecchio))` **e** né `latitudine` né `longitudine` dirty → `latitudine = longitudine = geocodifica_tentata_at = null`. `geocodificaMancanti` prima azzera `geocodifica_tentata_at` delle sedi senza coordinate, poi accoda.

- [ ] **Step 1: Test** `test_cambio_indirizzo_azzera_coordinate_e_tentativo`; `test_coordinate_a_mano_nello_stesso_salvataggio_restano`; `test_indirizzo_diverso_solo_per_spazi_e_maiuscole_non_azzera`; `test_trova_mancanti_azzera_tentativi_e_accoda`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implementa.**
- [ ] **Step 4: Run** + `--filter=Scuole` (sync MIUR aggiorna indirizzi) → PASS.
- [ ] **Step 5: Commit** `feat(sedi): nuova posizione quando cambia l'indirizzo`

### Task 11: Segnali "senza posizione" e ritorno alla scheda

**Files:**
- Modify: vista elenco sedi dell'ente (`resources/views/admin/enti/show.blade.php`) + `app/Http/Controllers/Admin/EntiController.php` (filtro `?senza_posizione=1`)
- Modify: `app/Services/Admin/StatoSistema.php` (riquadro "Posizione delle sedi": link)
- Modify: `app/Http/Controllers/Admin/SediController.php` (`edit`, `update`), `resources/views/admin/sedi/edit.blade.php` (hidden `ritorno`)
- Test: `tests/Feature/Admin/EntiTest.php`, `tests/Feature/CoordinateSediTest.php`

**Interfaces:**
- Produces: `SediController::ritornoSicuro(?string $ritorno): ?string` (privato) — accetta solo percorsi che iniziano con `/` e non con `//` né contengono `\` o schema; altrimenti null. `update` reindirizza lì se valido, altrimenti come oggi. Il link "Correggi la posizione" (Task 18) usa `route('admin.sedi.edit', [$sede, 'ritorno' => '/segnalazioni/'.$id])`. Il riquadro Stato del sistema punta a una pagina che elenca le sedi senza posizione di tutti gli enti: usa `admin.enti.index` con `?senza_posizione=1` se l'elenco enti lo può mostrare, altrimenti filtro sulla pagina ente — scelta lasciata all'implementatore purché il link porti a un elenco filtrato.

- [ ] **Step 1: Test** `test_elenco_sedi_mostra_senza_posizione`; `test_filtro_senza_posizione`; `test_update_rispetta_ritorno_interno`; `test_update_ignora_ritorno_esterno` (`https://evil.example`, `//evil.example`, `/\evil`) → redirect di sempre.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implementa.**
- [ ] **Step 4: Run** `--filter=EntiTest` + `--filter=CoordinateSediTest` + `--filter=StatoSistemaTest` → PASS.
- [ ] **Step 5: Commit** `feat(sedi): segnala sedi senza posizione`

---

## Parte A — Scheda

### Task 12: Cronogramma (lettura gestore)

**Files:**
- Create: `app/Support/Segnalazioni/Cronogramma.php`
- Test: `tests/Unit/Segnalazioni/CronogrammaTest.php` (estende `Tests\TestCase` con `RefreshDatabase`: servono model)

**Interfaces:**
- Consumes: `StoricoOrdinato::di` (Task 1), `CalendarioUfficio::daImpostazioni()` (`fascia`, `isFestivo`, `oreLavorativeTra`), `Scadenza::per`.
- Produces: `Cronogramma::per(Segnalazione $s, User $lettore, ?CarbonInterface $ora = null): ?array` che restituisce:

```php
array{
  da: Carbon, a: Carbon,
  tratti: list<array{stato: ?SegnalazioneStato, nome: string, classe: string, chi: ?string, deciso_da: ?string,
                     da: Carbon, a: Carbon, sinistra: float, larghezza: float, durata: string, lavorativi: int, fumetto: string}>,
  marcatori: list<array{tipo: 'risposta'|'risegnalata'|'pausa', posizione: float, quanti: int, fumetto: string}>,
  chiusi: list<array{sinistra: float, larghezza: float, etichetta: ?string}>,
  tacche: list<array{posizione: float, testo: string}>,
  ora: ?float, scadenza: ?float, superata_da: ?float,
  riassunto: string, legenda: list<string>,
}
```

`classe` ∈ `nuova|in-carico|assegnata|preventivo|sospesa|completata|finale|non-noto`. Percentuali su timestamp; `larghezza` minima resa in CSS (3px), non qui. Intervallo a durata zero → un tratto al 100%. Eventi prima di `data_segnalazione` portati a `da`. Marcatori entro 1,5 punti percentuali raggruppati (`quanti` > 1). `pausa` = tratto Sospesa più corto di 1 ora, reso come marcatore invece che tratto. Tacche: per giorno se ≤ 14 giorni, lunedì se ≤ 90, primo del mese oltre. `fumetto` del tratto: righe della spec A3 unite da `" · "`, usato anche per `aria-label`. Riassunto gestore: "{N giorni} senza che nessuno la aprisse · {N giorni} {in carico|assegnata…}" (prima parte solo se Nuova ≥ 1 giorno lavorativo; "(di cui N di fine settimana)" se lo stato attuale include giorni chiusi). Legacy senza storico e chiusa → un tratto `non-noto`. Restituisce `null` per segnalazione senza `data_segnalazione`.

- [ ] **Step 1: Test** (lettura gestore, `Carbon::setTestNow`, orario lun-ven 08:00-14:00 da `ImpostazioniSeeder`):
  - `test_tratti_3579`: Nuova mar 10 09:13 → In carico ven 13 08:16, pausa ven 14:00:34-14:01:02, In carico fino a "ora" lun 16 10:00 → classi `['nuova','in-carico','in-carico']` (o due tratti in-carico separati dal marcatore `pausa`), un marcatore `pausa`, `round(array_sum(larghezze), 6) == 100.0`.
  - `test_righe_doppie_fuse` (2,2 stesso assegnatario → un tratto).
  - `test_fine_settimana_e_festivo_velati` (intervallo che contiene sab-dom e 8 dicembre → `chiusi` con 2 fasce, la prima con etichetta "SABATO E DOMENICA" quando larga ≥ 8%).
  - `test_scadenza_superata_ha_superata_da` e `test_scadenza_oltre_ora_allunga_intervallo` (`a` = scadenza).
  - `test_chiusa_finisce_alla_chiusura`; `test_legacy_senza_storico_un_tratto_non_noto`.
  - `test_tacche_5_30_200_giorni` → a 5 giorni una tacca per giorno (6 con gli estremi); a 30 giorni ogni tacca cade di lunedì e sono tanti quanti i lunedì dell'intervallo; a 200 giorni ogni tacca cade il primo del mese.
  - `test_marcatori_vicini_raggruppati` (due note `visibile_web` a 1 minuto → un marcatore `quanti=2`); `test_note_interne_non_sono_marcatori`.
  - Review Focus 1-3: `test_intervallo_nullo_un_tratto_pieno`, `test_evento_prima_dell_apertura_portato_all_inizio`, `test_cambio_ora_somma_100`.
  - `test_fumetto_dice_chi`: tratto In carico contiene "In carico a Anna Gestore" e "presa in carico ven 13, 08:16" quando autore = assegnatario; "affidata da {nome} ven 13, 08:16" altrimenti (scelta del piano: niente "da lui/lei", il genere non si deduce dal nome).
- [ ] **Step 2: Run** `--filter=CronogrammaTest` → FAIL.
- [ ] **Step 3: Implementa** `Cronogramma` (metodi privati per tratti, marcatori, chiusi, tacche, riassunto; giorni in parole con `Carbon::locale('it')`).
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(scheda): cronogramma della segnalazione`

### Task 13: Cronogramma per chi legge

**Files:**
- Modify: `app/Support/Segnalazioni/Cronogramma.php`; estrai `Attivita::inizioLavoro` in metodo pubblico statico riusabile (`Attivita::inizioLavoroPer(User $lettore, Collection $storico): ?Carbon`)
- Test: `tests/Unit/Segnalazioni/CronogrammaTest.php`

**Interfaces:**
- Consumes: `LetturaScheda::per`, `SegnalazioneStato::fraseSegnalante()['titolo']`.
- Produces: stesso array di Task 12, regole della tabella "Per chi legge" della spec A3. Segnalante: `nome` = titolo `fraseSegnalante()`, tratti 3 e 4 consecutivi fusi, `chi` = etichetta ufficio (mai nomi), marcatori = note `visibiliA($lettore)` scritte dall'ufficio o proprie, riassunto "Aperta da N giorni · presa in carico {giorno}", scadenza nel fumetto come "prevista entro {giorno}". Lavoro: `da` = `inizioLavoroPer`, riassunto "Assegnata a te da N giorni" (o "alla tua ditta").

- [ ] **Step 1: Test** `test_segnalante_non_vede_nomi` (nessun fumetto contiene il nome del gestore né dell'operaio; contiene "Ufficio manutenzioni"); `test_segnalante_vede_titoli_semplici_e_assegnate_fuse`; `test_segnalante_marcatori_solo_note_visibili`; `test_lavoro_parte_dall_assegnazione`; `test_ditta_che_ha_perso_il_lavoro_non_vede_oltre_la_storia` (marcatori ⊆ note `visibiliA` della ditta).
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implementa.**
- [ ] **Step 4: Run** + `--filter=AttivitaTest` → PASS.
- [ ] **Step 5: Commit** `feat(scheda): cronogramma per scuole e ditte`

### Task 14: Partial cronogramma nelle tre letture

**Files:**
- Create: `resources/views/segnalazioni/scheda/_cronogramma.blade.php`
- Modify: `resources/css/app.css` (classi `.pa-crono-*` sui token esistenti: `--ente-primary`, `--violet`, `--amber`, `--emerald`, slate; tratteggi con `repeating-linear-gradient`)
- Modify: `app/Http/Controllers/SegnalazioneController.php` (`show`: `$cronogramma = Cronogramma::per($segnalazione, $utente)`)
- Modify: `scheda/gestore.blade.php` (dopo i fatti), `scheda/segnalante.blade.php` (al posto di `_avanzamento`), `scheda/lavoro.blade.php` (sotto il prossimo passo)
- Test: `tests/Feature/SchedaLettureTest.php`

**Interfaces:**
- Consumes: array di Task 12-13.
- Produces: tratti/marcatori come elementi `tabindex="0"` con `aria-label="{fumetto}"` e fumetto in un figlio mostrato da `:hover`/`:focus-visible` (solo CSS); legenda con le voci di `legenda`; sotto 768px tacche alterne nascoste.

- [ ] **Step 1: Test** `test_scheda_gestore_ha_cronogramma` (assertSee "Com'è andata finora" e un `aria-label` con il nome dell'assegnatario); `test_scheda_segnalante_ha_cronogramma_senza_nomi_e_senza_avanzamento`; `test_scheda_lavoro_ha_cronogramma`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implementa** partial + CSS; elimina `_avanzamento.blade.php` se non è più incluso altrove (grep).
- [ ] **Step 4: Run** `--filter=SchedaLettureTest` + `--filter=VisibilitaSchedaTest`; `npm run build`; controllo visivo su #3579 nel DB dev a 1280px e 375px, tastiera (Tab mostra i fumetti).
- [ ] **Step 5: Commit** `feat(scheda): mostra il cronogramma`

### Task 15: Quattro fatti

**Files:**
- Create: `app/Support/Segnalazioni/Fatti.php`, `resources/views/segnalazioni/scheda/_fatti.blade.php`
- Test: `tests/Unit/Segnalazioni/FattiTest.php`

**Interfaces:**
- Consumes: `Scadenza::estesa`, `CalendarioUfficio`, `SlaConfigurazione` applicata (stessa ricerca di `SlaService::calcolaScadenza`: estrai `SlaService::regolaPer(Segnalazione): ?SlaConfigurazione` se non esiste), `volteSegnalata` (Task 4), `NotaSegnalazione` visibili al segnalante scritte dal personale.
- Produces: `Fatti::per(Segnalazione $s, ?CarbonInterface $ora = null): array{stato: Fatto, scadenza: Fatto, priorita: Fatto, aperta: Fatto}` con `Fatto = array{valore: string, dettaglio: ?string, tono: 'slate'|'amber'|'rose'|'emerald', colore: string}`. Copy esatte: tabella A2 della spec. "da venerdì" se entro 6 giorni, altrimenti "dal 3/11". Ore rimaste in ore lavorative (`oreLavorativeTra`).

- [ ] **Step 1: Test** casi: nuova ("nessuno l'ha ancora presa"), in carico ("a Anna Gestore, da venerdì"), a ditta ("a Edil Adriatica, da 2 giorni"), scaduta (tono `rose`, "Scaduta da 2 giorni"), a rischio (tono `amber`, "mancano 4 ore"), scadenza manuale ("· spostata a mano"), senza regola ("nessuna scadenza"), chiusa in tempo ("Chiusa in 3 giorni" / "entro la scadenza"), chiusa in ritardo ("2 giorni oltre la scadenza"), aperta oggi ("oggi"), segnalata 2 volte con 3 risposte ("segnalata 2 volte · 3 risposte a chi ha segnalato").
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implementa** classe e partial (griglia 4 colonne, 2×2 sotto 900px; link "sposta"/"cambia" aprono i pannelli `scadenza`/`priorita` con le policy di oggi).
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(scheda): quattro fatti in testa`

### Task 16: Testata e prossimo passo

**Files:**
- Modify: `resources/views/segnalazioni/scheda/_testata.blade.php` (via pill stato/priorità/scadenza, menu "Stampa ▾"), `scheda/gestore.blade.php` (ordine: testata → fatti → cronogramma → prossimo passo → avvisi), `app/Support/Segnalazioni/ProssimoPasso.php` (`gestore()`), `_prossimo-passo.blade.php` (togli Priorità…/Scadenza…)
- Test: `tests/Unit/Segnalazioni/ProssimoPassoTest.php`

**Interfaces:**
- Produces: `ProssimoPasso` gestore aggiunge `etichetta: string` ("Adesso tocca a te" se `id_operatore_assegnato === $u->id`, "Adesso tocca a {nome}" se di un altro, "Prossimo passo" altrimenti) e sottotitolo con al massimo due frasi nell'ordine della spec A4 (sollecito/integrazione/accorpata negli ultimi 3 giorni lavorativi → "Chi ha segnalato l'ha segnalata di nuovo {stamattina|ieri|lunedì}").

- [ ] **Step 1: Test** `test_etichetta_tocca_a_te`, `test_etichetta_tocca_a_altro`, `test_sottotitolo_scade_e_risegnalata`, `test_sottotitolo_massimo_due_frasi`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implementa** + viste.
- [ ] **Step 4: Run** `--filter=ProssimoPasso` + `--filter=SchedaLettureTest` + `--filter=AzioniRapide` → PASS.
- [ ] **Step 5: Commit** `feat(scheda): testata più pulita, tocca a chi`

### Task 17: Storia — dati

**Files:**
- Modify: `app/Support/Segnalazioni/Attivita.php`
- Create: `app/Support/Segnalazioni/Storia.php`
- Test: `tests/Feature/AttivitaTest.php`, `tests/Unit/Segnalazioni/StoriaTest.php`

**Interfaces:**
- Consumes: `NotaSegnalazione::origine`, `testoSenzaPrefisso` (Task 3), `StoricoOrdinato` (Task 1).
- Produces: `Voce` estesa con `forma: 'passaggio'|'messaggio'`, `stato: ?SegnalazioneStato`, `letta_da: ?string` (sostituisce `tag`; valori "La scuola la legge"|"La legge chi ha segnalato"|"Solo il comune"|"La legge la ditta"|"La leggono ditta e {scuola|chi ha segnalato}", solo lettura gestore), `nota: ?string` (testo della nota fusa nel passaggio). Frasi interne: tabella A6 della spec (sostituiscono quelle di oggi; aggiornare i test esistenti che le citano). Fusione: nota stesso autore stesso minuto di un passaggio → dentro il passaggio; note `rimando`/`senza_intervento` sempre fuse col passaggio corrispondente. Nota `risegnalata` collocata all'ora di `data_segnalazione` dell'accorpata (id dalla `duplicati()` con stesso testo/autore, altrimenti all'ora della nota).
  `Storia::giorni(Collection $voci, ?CarbonInterface $ora = null): list<array{intestazione: string, voci: list<Voce>}|array{vuoto: string}>` — gruppi dal più recente, intestazioni "Oggi · lunedì 16 novembre"/"Ieri · …"/"Venerdì 13 novembre" (anno se diverso), righe di vuoto con copy della spec A6 ("… · nessuna attività · N giorni lavorativi fermi" / "… · nessuna attività, ufficio chiuso" / oltre 3 giorni "Dal 3 al 9 novembre · …").
  Grep di `['tag']` nelle viste e nei test: tutti passano a `letta_da`.

- [ ] **Step 1: Test** `AttivitaTest`: aggiorna `test_storico_3579_…` a `['l\'ha presa in carico','ha messo in pausa','ha ripreso il lavoro']`; `test_affidata_ad_altro`, `test_riassegnata`, `test_scadenza_spostata_frase_e_prefisso_tolto`, `test_passaggio_e_nota_stesso_minuto_fusi`, `test_letta_da_nei_quattro_casi`, `test_segnalante_non_vede_nomi` (esistente, non regredire). `StoriaTest`: `test_raggruppa_per_giorno_dal_piu_recente`, `test_vuoto_lavorativo`, `test_vuoto_fine_settimana_ufficio_chiuso`, `test_vuoto_lungo_dal_al`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implementa.**
- [ ] **Step 4: Run** `--filter=AttivitaTest` + `--filter=StoriaTest` + `--filter=VisibilitaNoteTest` → PASS.
- [ ] **Step 5: Commit** `feat(scheda): storia con passaggi e messaggi`

### Task 18: Storia, "Cosa è stato segnalato" e colonna destra — viste

**Files:**
- Modify: `scheda/_attivita.blade.php` (titolo "Storia", gruppi, binario, due forme, filtri Alpine Tutto/Solo passaggi/Solo messaggi), `scheda/_composer.blade.php` (selettore a 2-3 posizioni che scrive `visibile_web`/`visibile_impresa`), `scheda/gestore.blade.php` (fasce ambra in "Cosa è stato segnalato", card Dettagli tolta, card "Importi" solo con importi), `scheda/_chi-ci-lavora.blade.php`, `scheda/_dove.blade.php` (minimappa 170px non trascinabile finché non cliccata, "Apri in Maps", "Correggi la posizione" `@can` modifica sedi con `ritorno`, riquadro tratteggiato senza coordinate), `scheda/_notifiche.blade.php` (riepilogo per destinatario + "Vedi tutti"), `show.blade.php` (init minimappa), `SegnalazioneController::show` (`$storia = Storia::giorni($attivita)`)
- Test: `tests/Feature/SchedaLettureTest.php`

**Interfaces:**
- Consumes: Task 11 (`ritorno`), Task 17 (`Storia::giorni`, `letta_da`), `NotificaSegnalazione` (riepilogo: raggruppa per segnalante / gestori / ditta come categoria del destinatario — metodo `NotificaSegnalazione::riepilogoPer(Segnalazione): list<array{a: string, quante: int, ultima: Carbon}>`).

- [ ] **Step 1: Test** `test_storia_ha_gruppi_e_chi_la_legge` (assertSee "Storia", "Solo il comune"); `test_cosa_segnalato_mostra_fascia_risegnalata` (accorpata → "Segnalata di nuovo … #N accorpata qui"); `test_dove_senza_coordinate_mostra_riquadro_e_link_per_admin` e senza link per gestore senza permesso sedi; `test_card_dettagli_assente_importi_presenti_solo_con_preventivo`; `test_avvisi_inviati_riepilogo`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implementa** viste.
- [ ] **Step 4: Run** `--filter=SchedaLettureTest` + `--filter=VisibilitaSchedaTest` + `--filter=TracciaNotificheTest`; `npm run build`; controllo visivo #3579 confrontato col mockup v2 (`.superpowers/brainstorm/1165-1794819166/content/scheda-v2.html`).
- [ ] **Step 5: Commit** `feat(scheda): storia, dove e avvisi ridisegnati`

### Task 19: Rilascio 1.0.2

**Files:**
- Modify: `CHANGELOG.md`, `publiccode.yml` (`softwareVersion: 1.0.2`, `releaseDate`), `CLAUDE.md` (origine delle note, regola doppioni, geocodifica automatica `sedi:geocodifica` 06:10, `Cronogramma`/`Fatti`/`Storia` nell'albero `Support/Segnalazioni`)
- Test: `tests/Feature/AggiornamentoDa0920Test.php` (aggiungi asserzioni su colonne nuove se il test controlla lo schema)

- [ ] **Step 1: Prova su copia prod** — `mariadb-dump --single-transaction` nello scratchpad → `migrate` + `AllineamentoSeeder` ×2 → conta `note_segnalazioni` per `origine` (atteso: nessuna nota con prefisso noto rimasta `null`) → smoke curl con `mock.admin` di `/segnalazioni/3579`, `/segnalazioni/3588` e 10 schede a campione per stato (incluse legacy senza storico): tutte 200, nessun errore in `storage/logs/laravel.log`.
- [ ] **Step 2: Suite intera** `php artisan test` + `composer run analyse` → verdi.
- [ ] **Step 3: Docs** CHANGELOG/publiccode/CLAUDE.md.
- [ ] **Step 4: Commit** `chore(release): 1.0.2`, push, PR squash con CI verde (`gh pr checks --watch`).
