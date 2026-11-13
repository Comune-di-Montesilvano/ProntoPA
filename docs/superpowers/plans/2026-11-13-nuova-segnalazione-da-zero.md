# Nuova segnalazione rifatta da zero — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** form "Nuova segnalazione" rifatto da zero (sede → tipologia → simili → priorità → titolo → descrizione → dove → foto) e pulizia del modello dati (via gruppi, provenienza, chiamante, specializzazioni, coordinate per segnalazione), con tipologie gestibili, appalti per tipologia, coordinate delle sedi e "Rimanda al gestore". Rilascio **1.0.0**.

**Architecture:** prima le aggiunte (tipologie gestibili, appalti↔tipologie, coordinate sedi), poi le eliminazioni una famiglia per volta (ognuna con migration conversione → dropForeign → dropColumn → drop table, seeder allineato nello stesso commit), poi il form e l'API che non dipendono più da ciò che è stato tolto, poi la nuova azione, infine il test di aggiornamento da 0.9.20 e i documenti.

**Tech Stack:** Laravel 13, PHP 8.4, MariaDB (prod) / sqlite (test), Blade + Alpine + Tailwind, Leaflet da `public/build/vendor/`, Spatie Permission.

**Spec:** `docs/superpowers/specs/2026-11-13-nuova-segnalazione-da-zero-design.md`

## Global Constraints

- Versione **1.0.0**: `publiccode.yml` `softwareVersion: 1.0.0`, CHANGELOG `## [1.0.0] - <data>`, tag `v1.0.0`.
- Migration con nome dopo `2026_11_14_000001`: usare `2026_11_15_00000N_*`.
- Mai `DELETE` su `gruppi_segnalazioni`/`tipologie_segnalazioni` per pulire (FK `tipologie.id_gruppo` è `cascadeOnDelete`). Ordine: conversione (SQL puro, senza model) → `dropForeign` → `dropColumn` → `drop table`.
- `AllineamentoSeeder` (a ogni avvio) idempotente e coerente con lo schema nello **stesso commit** di ogni drop; tipologie inserite **solo se la tabella è vuota**.
- Nessuna dipendenza nuova, niente CDN. Geocoding: Nominatim via `Http`, User-Agent `ProntoPA (+APP_URL)` senza dati personali, max 1 req/s, mai dal browser, mai da console obbligatoria (UI + job).
- Nessun campo obbligatorio nascosto, nessuna sezione richiudibile nel form, nessun esempio con nomi reali.
- Test: `MSYS_NO_PATHCONV=1 docker compose exec -T php php artisan test --filter=<X>`; suite completa a fine task che tocca schema; `docker compose exec -T php composer run analyse` pulito prima del commit finale.
- Commit Conventional in italiano, chiusi da `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. Aggiornamento da un database 0.9.20 con dati reali (appalti di gruppi senza tipologie, adesioni URP doppie, regole SLA con specializzazione, segnalazioni con coordinate) → migrate + seeder due volte senza errori, nessuna tipologia persa. → Task 9.
2. Unire due tipologie quando lo stesso gestore o lo stesso appalto le ha entrambe (chiave primaria del pivot) → nessun errore, una riga sola. → Task 1.
3. Scuola SPID con deleghe su due sedi, una di ente disattivato → nel form vede solo quella attiva, già selezionata. → Task 7.
4. Nominatim irraggiungibile o risposta vuota → la sede resta com'è, esito "non trovata/errore" salvato, nessuna eccezione nel job. → Task 3.
5. Membro di squadra non caposquadra prova "Rimanda al gestore" → 403. → Task 8.

---

### Task 1: Tipologie gestibili (attiva, pagina admin, unisci, seeder solo su tabella vuota)

**Files:**
- Create: `database/migrations/2026_11_15_000001_tipologie_gestibili.php`, `app/Services/Tipologie/UnisciTipologie.php`, `app/Http/Controllers/Admin/TipologieController.php`, `resources/views/admin/tipologie/index.blade.php`
- Modify: `app/Models/TipologiaSegnalazione.php` (cast `attiva`, scope `attive()`), `database/seeders/TabelleRiferimentoSeeder.php` (tipologie solo se tabella vuota), `routes/web.php` (gruppo admin), `resources/views/layouts/app.blade.php` (voce "Tipologie" in Amministrazione), `app/Http/Controllers/Admin/AdminDashboardController.php`/menu test se elenca le voci
- Test: `tests/Feature/Admin/TipologieTest.php`

**Interfaces:**
- Produces: `TipologiaSegnalazione::scopeAttive(Builder): Builder`; `UnisciTipologie::unisci(int $da, int $in): void` (sposta `segnalazioni.id_tipologia_segnalazione`, `sla_configurazioni.id_tipologia_segnalazione`, `gestori_tipologie` senza duplicati, `appalti_tipologie` se la tabella esiste, poi elimina `$da`; transazione); rotte `admin.tipologie.index|store|update|unisci` (`PATCH admin/tipologie/{tipologia}` gestisce nome/icona/attiva, `POST admin/tipologie/{tipologia}/unisci` con `id_destinazione`).

- [ ] **Step 1: test che falliscono** — `test_admin_aggiunge_rinomina_disattiva` (store nome+icona → riga; update `attiva=false` → non in `TipologiaSegnalazione::attive()`); `test_unisci_sposta_tutto_senza_duplicati` (gestore con entrambe le tipologie in `gestori_tipologie`, segnalazione e regola SLA su A → dopo `unisci(A,B)`: A non esiste, segnalazione e regola su B, gestore ha B una volta); `test_non_admin_403`; `test_seeder_non_ricrea_tipologie_cancellate` (cancella una tipologia senza segnalazioni, `seed(TabelleRiferimentoSeeder)` → non torna); `test_migration_unisce_sfalci_doppi` (righe 4 e 11 "SFALCI E POTATURE" → ne resta una, quella con più segnalazioni).
- [ ] **Step 2:** run `--filter=TipologieTest` → FAIL (rotte/colonna mancanti).
- [ ] **Step 3:** migration (colonna `attiva` boolean default true + unione Sfalci in SQL puro con la stessa logica di `UnisciTipologie`, senza model), servizio, controller (authorize admin come gli altri `Admin/*`), vista elenco con form inline (componenti `x-pa.*`, select "Unisci in…"), seeder: `if (DB::table('tipologie_segnalazioni')->exists()) skip`.
- [ ] **Step 4:** run → PASS; run `--filter='AllineamentoSeeder|Tipologie|CoperturaTipologie'` → PASS.
- [ ] **Step 5:** commit `feat(tipologie): pagina admin, unisci e disattiva`.

### Task 2: Appalti legati alle tipologie

**Files:**
- Create: `database/migrations/2026_11_15_000002_appalti_tipologie.php`, `app/Support/Segnalazioni/AppaltiPerTipologia.php`
- Modify: `app/Models/Appalto.php` (relazione `tipologie()`, cast `tutte_tipologie`, `copreTipologia(int): bool`), `app/Http/Controllers/AppaltiController.php` + `resources/views/appalti/_form.blade.php`, `index.blade.php` (scelta tipologie come `organizzazione/tipologie-gestori/edit`, con "tutte"), pannello Assegna `resources/views/segnalazioni/scheda/pannelli/assegna.blade.php` e `gestione/_azioni-multiple.blade.php` (optgroup "Coprono la tipologia" / "Altre ditte"), `UnisciTipologie` (pivot appalti)
- Test: `tests/Feature/AppaltiTipologieTest.php`

**Interfaces:**
- Consumes: `UnisciTipologie::unisci` (Task 1).
- Produces: tabella `appalti_tipologie(id_appalto, id_tipologia_segnalazione)` PK composta, FK cascade; `appalti.tutte_tipologie` bool; `AppaltiPerTipologia::ordina(Collection $appalti, array $idTipologie): array{coprono: Collection, altri: Collection}` (coprono = copre **tutte** le tipologie date).

- [ ] **Step 1: test** — `test_migration_appalto_prende_tipologie_del_gruppo` (appalto gruppo 1 → tipologie con `id_gruppo=1`); `test_appalto_di_gruppo_senza_tipologie_diventa_tutte` (gruppo 2); `test_assegna_mostra_prima_chi_copre` (appalto A copre la tipologia, B no → nella scheda A compare prima di "Altre ditte" e B dopo: `assertSeeInOrder`); `test_ordina_multipla_richiede_tutte` (A copre solo t1, selezione t1+t2 → A in `altri`); `test_form_appalto_salva_tipologie_e_tutte`.
- [ ] **Step 2:** run `--filter=AppaltiTipologieTest` → FAIL.
- [ ] **Step 3:** migration (crea tabella+colonna, conversione SQL puro; `appalti.id_gruppo` **resta** fino a Task 6), modello, servizio, form (validazione `id_gruppo` non più richiesta), pannelli.
- [ ] **Step 4:** run → PASS; `--filter='Appalti|Assegna|AzioniMultiple|RevisioneIter'` → PASS.
- [ ] **Step 5:** commit `feat(appalti): legati alle tipologie come i gestori`.

### Task 3: Coordinate delle sedi e geocoding

**Files:**
- Create: `database/migrations/2026_11_15_000003_coordinate_sedi.php`, `app/Services/Sedi/Geocoding.php`, `app/Jobs/GeocodificaSedi.php`
- Modify: `app/Models/Plesso.php` (fillable/cast `latitudine`,`longitudine`, `haCoordinate(): bool`), `app/Http/Controllers/Admin/SediController.php` + `resources/views/admin/sedi/edit.blade.php` (mappa Leaflet con marker trascinabile, campi nascosti, pulsante "Trova dall'indirizzo" → `POST admin/sedi/{sede}/geocodifica` che risponde JSON), pagina sedi/enti (pulsante "Trova le posizioni mancanti" → `POST admin/sedi/geocodifica-mancanti` accoda job), `app/Support/Impostazioni/Sezioni.php` + `ImpostazioniSeeder` (chiave `geocoding_comune`, tipo `testo`, sezione ASPETTO) + `admin/aspetto` view, `app/Services/Admin/StatoSistema.php` (riquadro "Posizione delle sedi": n sedi senza coordinate + esito ultimo giro da `impostazioni.geocoding_esito`, nessuna chiamata di rete), `resources/views/segnalazioni/scheda/_dove.blade.php` + `show.blade.php` (coordinate della sede), `app/Http/Controllers/OperaioDashboardController.php` (coordinate della sede)
- Test: `tests/Feature/CoordinateSediTest.php`

**Interfaces:**
- Produces: `plessi.latitudine/longitudine` decimal(10,7) nullable; `Geocoding::cerca(string $indirizzo): ?array{lat: float, lng: float}` (null su errore/vuoto, mai eccezioni); job `GeocodificaSedi` (sedi senza coordinate, `sleep(1)` tra richieste, salva `geocoding_esito` JSON `{quando, trovate, non_trovate, errori}`); mediana nella migration: per ogni plesso senza coordinate, mediana di lat e lng (≠0) delle sue segnalazioni.

- [ ] **Step 1: test** — `test_migration_mediana_da_segnalazioni` (3 segnalazioni con coordinate + 1 a 0 → sede con mediana); `test_trova_dall_indirizzo_usa_comune_e_user_agent` (`Http::fake`, assert richiesta con `q` = indirizzo + ", " + `geocoding_comune` e header `User-Agent` che inizia con `ProntoPA`); `test_nominatim_giu_non_tocca_la_sede` (`Http::fake` 500 → coordinate invariate, esito con `errori=1`, nessuna eccezione); `test_job_solo_sedi_senza_coordinate`; `test_scheda_mostra_mappa_sede_e_link_maps` (sede con coordinate → link `https://www.google.com/maps/search/?api=1&query=lat,lng` o quello già usato in `_dove`); `test_scheda_sede_senza_coordinate_solo_indirizzo`; `test_stato_sistema_riquadro_sedi_senza_coordinate`.
- [ ] **Step 2:** run `--filter=CoordinateSediTest` → FAIL.
- [ ] **Step 3:** implementazione come da Interfaces (le coordinate per segnalazione restano lette come ripiego solo finché Task 6 non le toglie: qui la scheda usa **prima** la sede).
- [ ] **Step 4:** run → PASS; `--filter='Scheda|Operaio|StatoSistema|Aspetto'` → PASS.
- [ ] **Step 5:** commit `feat(sedi): coordinate, geocoding e mappa in scheda`.

### Task 4: Via provenienza, "per conto" e dati del chiamante

**Files:**
- Create: `database/migrations/2026_11_15_000004_togli_provenienza_e_chiamante.php`
- Modify: `app/Models/User.php` (via `id_provenienza`, `provenienza()`, `puoScegliereProvenienza()`; `segnalaComeScuola()` = `isSpid()` con ruolo segnalatore), `app/Models/Segnalazione.php`, `app/Models/AdesioneSegnalazione.php`, `app/Services/Auth/{SpidLoginService,MappaGruppiLdap,RuoloLdap,AllineaUtenteAd,SincronizzaUtentiAd}.php`, `app/Console/Commands/ProvaLdap.php`, `app/Http/Controllers/{SegnalazioneController,GestioneController,AdesioniSegnalazioniController,FascicoloPdfController}.php`, `app/Http/Controllers/Admin/UtentiController.php`, `app/Services/SegnalazioneWorkflowService.php` + `app/Services/Segnalazioni/AccorpamentoService.php` (chiusura: solo account attivo + aderenti), viste `segnalazioni/create` (togli riquadro per-conto e provenienza: il resto del form lo rifà Task 7), `scheda/gestore`, `scheda/segnalante`, `gestione/dashboard` (filtro), `gestione/print`, `_scheda-print`, `fascicolo-pdf`, `admin/utenti/edit`, `database/seeders/{TabelleRiferimentoSeeder,RolesAndPermissionsSeeder}.php`, `app/Models/Provenienza.php` (elimina), `PopulateDemoData`, `ImportLegacy`, `SegnalazioneFactory`, `UserFactory`
- Test: `tests/Feature/TogliProvenienzaTest.php` + aggiornamento dei test esistenti che usano `id_provenienza`/`segnalante`

**Interfaces:**
- Produces: nessuna colonna `id_provenienza` (users, segnalazioni), nessuna `segnalante|email|telefono` su segnalazioni e adesioni, nessun permesso `segnalazioni.per-conto`, tabella `provenienze_segnalazioni` eliminata; indice unique `adesioni_segnalazioni(id_segnalazione, id_utente)`; `MappaGruppiLdap::LIVELLI` senza provenienza né per-conto (URP e segnalatori → `segnalatore`); `SincronizzaUtentiAd`: gruppi `urp` e `segnalatori` contano come un solo gruppo per la protezione "gruppo mancante".

- [ ] **Step 1: test** — `test_migration_deduplica_adesioni` (stesso utente 3 adesioni → 1, la più vecchia); `test_permesso_per_conto_eliminato` (dopo migrate + `RolesAndPermissionsSeeder` due volte: `Permission::where('name','segnalazioni.per-conto')->doesntExist()`); `test_sync_ad_senza_gruppo_urp_non_blocca` (FakeDirectory senza gruppo URP, segnalatori presenti → sync non bloccata); `test_sync_ad_blocca_se_mancano_urp_e_segnalatori_con_utenti_attivi`; `test_chiusura_avvisa_solo_account_attivo_e_aderenti`; `test_ricerca_segnalante_per_nome_account`; `test_scuola_riconosciuta_da_spid`; `test_seeder_due_volte_dopo_migrate` (nessuna eccezione).
- [ ] **Step 2:** run `--filter=TogliProvenienzaTest` → FAIL.
- [ ] **Step 3:** migration nell'ordine (dedup adesioni SQL → `dropForeign` `segnalazioni.id_provenienza` → drop colonne → unique adesioni → elimina permesso: righe `role_has_permissions`/`model_has_permissions`/`permissions` + `app(PermissionRegistrar::class)->forgetCachedPermissions()` → `drop provenienze_segnalazioni`); poi codice e seeder; `grep -rn "provenienz\|per-conto\|per_conto\|segnalante_per_conto" app resources database/seeders` deve restare vuoto (salvo migration storiche).
- [ ] **Step 4:** suite completa → tutta verde (aggiornare i test esistenti che passavano provenienza/segnalante).
- [ ] **Step 5:** commit `refactor(segnalazioni)!: via provenienza, per conto e dati del chiamante`.

### Task 5: Via specializzazioni

**Files:**
- Create: `database/migrations/2026_11_15_000005_togli_specializzazioni.php`
- Modify: `app/Models/{Segnalazione,SlaConfigurazione,Impresa}.php`, `app/Services/SlaService.php`, `app/Services/Segnalazioni/Inattivita.php`, `app/Jobs/SuggerisciTriageSegnalazione.php`, `app/Http/Controllers/AiTriageController.php`, `app/Http/Controllers/Admin/SlaController.php` + `admin/sla/form.blade.php` + `admin/regole/scadenze.blade.php`, `SegnalazioneController` (create/store), `GestioneController` (filtro), `scheda/gestore` (triage), seeder, `app/Models/Specializzazione.php` (elimina), demo/factory/import
- Test: `tests/Feature/TogliSpecializzazioniTest.php`

**Interfaces:**
- Produces: `SlaConfigurazione::applicabileA(Segnalazione)` per tipologia+priorità (ripiego senza tipologia invariato); nessuna tabella `db_specializzazioni`/`imprese_specializzazioni`, nessuna colonna `id_specializzazione`; indice `sla_lookup_idx` su `(id_tipologia_segnalazione, livello_priorita)`; prompt triage con elenco reale `id: nome` delle tipologie attive e senza specializzazione.

- [ ] **Step 1: test** — `test_regola_con_specializzazione_eliminata_se_esiste_base` (base tipologia1/prio2 + specifica con specializzazione → resta la base, valori invariati); `test_regola_con_specializzazione_senza_base_diventa_base`; `test_scadenze_aperte_non_ricalcolate` (data_scadenza_sla invariata dopo migrate); `test_triage_prompt_senza_specializzazione_con_tipologie_reali` (Http::fake Ollama, prompt contiene un nome tipologia e non "specializzazione"); `test_triage_salvato_vecchio_con_specializzazione_non_rompe_scheda`.
- [ ] **Step 2:** run → FAIL.
- [ ] **Step 3:** migration (conversione regole SQL → `dropForeign` su sla_configurazioni e segnalazioni → drop indice/colonne → ricrea indice → drop `imprese_specializzazioni`, `db_specializzazioni`), codice, seeder.
- [ ] **Step 4:** suite completa → verde.
- [ ] **Step 5:** commit `refactor(segnalazioni)!: via specializzazioni`.

### Task 6: Via gruppi di tipologie e coordinate per segnalazione

**Files:**
- Create: `database/migrations/2026_11_15_000006_togli_gruppi_e_coordinate.php`
- Modify: `app/Models/{TipologiaSegnalazione,Appalto,Segnalazione}.php`, `app/Models/GruppoSegnalazione.php` (elimina), `app/Services/Segnalazioni/CoperturaTipologie.php`, `app/Http/Controllers/Organizzazione/TipologieGestoriController.php` + viste `tipologie-gestori/{index,edit}` (via "Tutto il gruppo") + `organizzazione/_orfane`, `AppaltiController` + viste appalti, `GestioneController` (`tipologia.gruppo` nei with), `gestione/dashboard`, `scheda/gestore` (riga Tipologia senza gruppo), `_scheda-print`, `print-multiple`, `fascicolo-pdf`, `app/Services/DedupService.php` (stessa sede invece di raggio), `SegnalazioneController::simili` e store (niente coordinate), `OperaioDashboardController` (solo sede), `scheda/_dove`/`show` (solo sede), `Sezioni.php` + `ImpostazioniSeeder` (via `dedup_raggio_metri`), seeder (via gruppi), demo/factory/import, API controller
- Test: `tests/Feature/TogliGruppiTest.php`

**Interfaces:**
- Produces: nessuna tabella `gruppi_segnalazioni`, nessuna colonna `tipologie.id_gruppo`, `appalti.id_gruppo`, `segnalazioni.latitudine/longitudine`; `DedupService` cerca simili per stessa sede (+ embeddings se attivi).

- [ ] **Step 1: test** — `test_nessuna_tipologia_persa_dopo_drop_gruppi` (conteggio tipologie prima = dopo); `test_scheda_e_stampe_senza_nome_gruppo` (assertDontSee "EDILIZIA SCOLASTICA" in scheda, stampa, fascicolo); `test_tipologie_gestori_elenco_piatto`; `test_dedup_stessa_sede`; `test_impostazione_dedup_raggio_rimossa`.
- [ ] **Step 2:** run → FAIL.
- [ ] **Step 3:** migration (`dropForeign` tipologie.id_gruppo e appalti.id_gruppo → drop colonne → drop coordinate segnalazioni → `drop gruppi_segnalazioni` → delete impostazione `dedup_raggio_metri`), codice, seeder; `grep -rn "gruppo\|id_gruppo\|GruppoSegnalazione\|latitudine" app resources database/seeders` → solo sedi (`plessi`) e testi non correlati.
- [ ] **Step 4:** suite completa → verde.
- [ ] **Step 5:** commit `refactor(tipologie)!: via gruppi e coordinate per segnalazione`.

### Task 7: Il form nuovo e l'API

**Files:**
- Modify: `resources/views/segnalazioni/create.blade.php` (riscritto), `app/Http/Controllers/SegnalazioneController.php` (`create`, `store`, `simili`), `app/Http/Requests` se esiste uno store request, `app/Models/Delega.php` (`plessiCopertiDa` solo enti attivi), `app/Http/Controllers/Api/SegnalazioneApiController.php`, `docs/API.md`, `docs/openapi.yaml`, `tests/Browser/CreaSegnalazioneTest.php`
- Create: `app/Support/Segnalazioni/SediSegnalabili.php`
- Test: `tests/Feature/FormNuovaSegnalazioneTest.php`, `tests/Feature/Api/SegnalazioneApiTest.php` (esistente, aggiornato)

**Interfaces:**
- Consumes: `TipologiaSegnalazione::attive()` (Task 1).
- Produces: `SediSegnalabili::per(User $u): Collection<int, Plesso>` (SPID → `Delega::plessiCopertiDa` su enti attivi; altri → plessi di `istituti.attivo = true`); `GET segnalazioni/simili?id_plesso=&id_tipologia=` → JSON aperte stessa sede e tipologia visibili all'utente.

- [ ] **Step 1: test** — `test_form_campi_in_ordine_senza_dettagli_ne_mappa` (`assertSeeInOrder` etichette: "Sede", "Tipologia", "Priorità", "Titolo", "Descrizione", "Dove si trova il problema", "Foto"; `assertDontSee` "Più dettagli", "Per conto di", "Provenienza", "Fornitura 15 panchine", `id="mappa`); `test_sede_unica_preselezionata`; `test_scuola_vede_solo_sedi_di_enti_attivi` (Review Focus 3); `test_sede_obbligatoria`; `test_dove_facoltativo_anche_per_scuola` (invio senza `ubicazione_tipo` → 302 success, valore "non specificato"); `test_foto_facoltativa`; `test_simili_per_sede_e_tipologia`; `test_tipologie_disattive_non_nel_form`; API: `test_api_sede_obbligatoria_422`, `test_api_ignora_campi_tolti` (invia `id_provenienza`, `segnalante`, `latitudine` → 201, nessun errore).
- [ ] **Step 2:** run → FAIL.
- [ ] **Step 3:** form (componenti `x-pa.*`, sede select → testo indirizzo sotto via Alpine da `data-indirizzo`; tipologie a pulsanti come oggi ma senza intestazioni; riquadro simili sotto la tipologia con "Anche per me" → rotta adesione esistente; radio "Dove" con "Non so" preselezionato `value=0`; input file in vista; avviso 112; un solo pulsante "Invia segnalazione"); validazione store `id_plesso` required `Rule::in(SediSegnalabili::per($u)->pluck('id_plesso'))`; API come spec; Dusk aggiornato.
- [ ] **Step 4:** `--filter='FormNuovaSegnalazione|SegnalazioneApi|Simili|Adesion|Deleghe'` → PASS; suite completa → verde.
- [ ] **Step 5:** commit `feat(segnalazioni): form nuova segnalazione rifatto da zero`.

### Task 8: "Rimanda al gestore"

**Files:**
- Create: `database/migrations/2026_11_15_000007_azione_rimanda_al_gestore.php`, `app/Notifications/RimandataAlGestoreNotification.php`, `resources/views/segnalazioni/scheda/pannelli/rimanda.blade.php`
- Modify: `database/seeders/TabelleRiferimentoSeeder.php` (riga azione), `app/Services/SegnalazioneWorkflowService.php` (`rimandaAlGestore(Segnalazione, User, string $nota): void`), `app/Policies/SegnalazionePolicy.php` (`rimanda`), `app/Http/Controllers/SegnalazioneController.php` (`POST segnalazioni/{id}/rimanda`, rotta `segnalazioni.rimanda`), `app/Support/Segnalazioni/ProssimoPasso.php` (letture lavoro/ditta), `app/Support/Segnalazioni/Attivita.php` ("ha rimandato al comune"), `app/Models/NotificaSegnalazione.php` (`ETICHETTE['RimandataAlGestoreNotification'] = 'Rimandata al comune'`), viste scheda lavoro/ditta (include pannello)
- Test: `tests/Feature/RimandaAlGestoreTest.php`

**Interfaces:**
- Produces: azione `codice = 'rimanda'`, stati ammessi `[3, 4, 5]`, `competenza_azione = 2`; policy `rimanda(User, Segnalazione): bool` (operaio assegnato, caposquadra della squadra assegnata, persona `impresa` della ditta assegnata; solo stati 3/4/5).

- [ ] **Step 1: test** — `test_operaio_rimanda_torna_in_carico_al_gestore_che_aveva_assegnato` (stato 2, `id_operatore_assegnato` = gestore, email `RimandataAlGestoreNotification` al gestore con la nota); `test_ditta_rimanda_da_preventivo_perde_visibilita` (stato 5 → 2, `id_appalto` null); `test_nota_obbligatoria`; `test_membro_non_caposquadra_403` (Review Focus 5); `test_gestore_non_attivo_torna_nuova_e_avvisa_supervisori`; `test_scadenza_invariata`; `test_attivita_e_prossimo_passo`.
- [ ] **Step 2:** run → FAIL.
- [ ] **Step 3:** implementazione (gestore = `stati_segnalazioni.id_utente` dell'ultimo passaggio a 3 o 4; attivo e con ruolo gestore/admin → stato 2 a lui, altrimenti stato 1 e notifica ai supervisori attivi; azzera `id_operatore_assegnato`/`id_squadra_assegnata`/`id_appalto` prima di riassegnare; nota visibile al comune, non al segnalante).
- [ ] **Step 4:** run → PASS; `--filter='Workflow|ProssimoPasso|Attivita|DitteECanali'` → PASS.
- [ ] **Step 5:** commit `feat(workflow): rimanda al gestore per operaio e ditta`.

### Task 9: Aggiornamento da 0.9.20, documenti, versione

**Files:**
- Create: `tests/Feature/AggiornamentoDa0920Test.php`
- Modify: `CHANGELOG.md` (`## [1.0.0]` con avviso in testa: "Aggiornamento senza ritorno: fare un backup da Admin → Backup prima"), `publiccode.yml` (`1.0.0`), `CLAUDE.md` (Impostazioni, Architettura, Ruoli/AD, Workflow Stati, Funzionalità: niente provenienza/gruppi/specializzazioni/per-conto, tipologie admin, appalti per tipologia, coordinate sedi, rimanda), `docs/API.md`/`openapi.yaml` se non già fatti, `PopulateDemoData` (demo coerente, rilanciabile)
- Test: suite completa + Dusk in CI

**Interfaces:**
- Consumes: tutto quanto sopra.

- [ ] **Step 1: test** — `test_aggiornamento_da_schema_0920_con_dati` (senza `RefreshDatabase`: `migrate:fresh --realpath --path=<cartella temporanea con le sole migration fino a 2026_11_14_000001>`, inserisce dati tipici (appalto gruppo 2, adesioni URP doppie, regola SLA con specializzazione, segnalazione con coordinate su sede senza coordinate), poi `migrate`, poi `AllineamentoSeeder` **due volte**, poi `GET` scheda e form come admin → 200; asserzioni su tipologie, appalto `tutte_tipologie`, adesione unica, regola, coordinate sede).
- [ ] **Step 2:** run → se FAIL correggere la migration responsabile (non il test).
- [ ] **Step 3:** documenti e versione; `php artisan demo` due volte nel container → nessun errore.
- [ ] **Step 4:** suite completa → verde; larastan → `[OK] No errors`.
- [ ] **Step 5:** commit `docs: 1.0.0`.
