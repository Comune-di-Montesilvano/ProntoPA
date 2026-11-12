# Area amministrazione rifatta — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sostituire l'area admin attuale (9 voci piatte + form Impostazioni generato) con 4 blocchi (Persone, Enti e sedi, Regole di lavoro, Ente e sistema) e una home "Stato del sistema", togliendo il legacy.

**Architecture:** Le pagine nuove riusano servizi e regole esistenti (sync AD, SincronizzaScuole, SLA, OidcConfig, CatalogoBackup); cambiano solo dove stanno e come si presentano. Le impostazioni passano da un form generico a pagine scritte a mano, ognuna dichiarata in un'unica mappa `Sezioni` (chiave → tipo) salvata da un solo servizio `SalvaImpostazioni`. Le categorie di enti diventano una tabella con FK su `istituti`.

**Tech Stack:** Laravel 13, PHP 8.4, Blade + Tailwind 4 + Alpine, componenti `x-pa.*`, Leaflet (già in `public/build/vendor`), PHPUnit, Larastan.

**Spec:** `docs/superpowers/specs/2026-11-12-area-admin-design.md` (leggerla prima: contiene il perché, gli anti-obiettivi e le regole di migrazione).

## Global Constraints

- Una sola release patch: **0.9.18**, `publiccode.yml` `softwareVersion: 0.9.18` + CHANGELOG `## [0.9.18] - <data>` nella stessa PR.
- Test: `MSYS_NO_PATHCONV=1 docker compose exec -T php php artisan test` (no `--parallel`); larastan `MSYS_NO_PATHCONV=1 docker compose exec -T php composer run analyse` pulito.
- Feature test POST: `$this->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class, \Illuminate\Routing\Middleware\ThrottleRequests::class])`.
- Autorizzazione via middleware `role:admin` del gruppo `admin.` o Gate/Policy, mai `hasRole` nel controller. Squadre/Tipologie restano `can:gestisci-organizzazione`.
- Niente CDN; componenti `x-pa.*` e token `.pa-*`; un `<i class="fas">` con classi responsive va avvolto in uno span.
- Testi utente in italiano, nessuna chiave tecnica (`osm_zoom`) visibile, nessun ID numerico da digitare.
- Segreti (`oidc_client_secret`, `telegram_bot_token`, `telegram_webhook_secret`, `webhook_cittadini_secret`): mai stampati nella pagina; campo vuoto = valore invariato. `oidc_client_secret` resta cifrato con `Crypt::encryptString` (gli altri restano in chiaro a DB come oggi: il codice li legge così).
- `Impostazione::CHIAVI_INTERNE` (`ripristino_id`, `ente_logo_file`, `ldap_sync_esito`) mai esposte né salvate dalle pagine.
- Migration datate `2026_11_12_00000N_*`; copiare i dati **prima** di eliminare colonne, nella stessa migration o in una precedente.
- Commit stile caveman-commit con trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Salvataggio di una pagina impostazioni con un segreto già configurato e il campo lasciato vuoto** → il segreto resta quello di prima (test in Task 4 `test_segreto_vuoto_non_sovrascrive`).
2. **Checkbox booleana tolta** (es. digest spento): il form non invia la chiave → deve salvare `0`, non lasciare `1` (test in Task 4 `test_bool_assente_salva_zero`).
3. **Ente legacy con `tipo` scritto a mano diverso da "Scuola"** (gli asili comunali) → dopo la migrazione sta nella categoria con quel nome, non in "Altro" (test in Task 2 `test_migrazione_tiene_tipo_scritto_a_mano`).
4. **Bookmark o link email ai vecchi URL** (`/admin/impostazioni`, `/admin/utenti`, `/admin/organizzazioni`, `/admin/sedi`, `/admin/sla`, `/admin/profili`, `/admin/provenienze`) → redirect alla pagina nuova, mai 404 (test in Task 8 `test_vecchi_url_reindirizzano`).
5. **Controllo di rete lento o irraggiungibile** (Ollama/clamd/Telegram spenti) → la home Stato del sistema si carica comunque subito, riquadro grigio, nessuna chiamata di rete al caricamento (test in Task 8 `test_home_non_chiama_la_rete`).

---

## File Structure

```
app/Support/Impostazioni/Sezioni.php            # mappa sezione → [chiave → tipo], unica fonte
app/Services/Impostazioni/SalvaImpostazioni.php # validazione per tipo + salvataggio + segreti
app/Services/Admin/StatoSistema.php             # riquadri home
app/Services/Admin/Riquadro.php                 # DTO riquadro
app/Models/CategoriaEnte.php
app/Http/Controllers/Admin/EntiController.php           # sostituisce OrganizzazioniController
app/Http/Controllers/Admin/CategorieEntiController.php
app/Http/Controllers/Admin/Regole/{ScadenzeController,SegnalazioniController,AvvisiController}.php
app/Http/Controllers/Admin/{AspettoController,IntegrazioniController}.php
resources/views/admin/stato/index.blade.php
resources/views/admin/utenti/{dominio,scuole,ditte}.blade.php   # sostituiscono index
resources/views/admin/enti/{index,create,show}.blade.php
resources/views/admin/regole/{scadenze,segnalazioni,avvisi}.blade.php
resources/views/admin/{aspetto,integrazioni}.blade.php
resources/views/components/pa/impostazione.blade.php    # un campo per tipo (riuso in tutte le pagine)
Eliminati: ProfiliController, ProvenienzaController, OrganizzazioniController, ImpostazioniController (resta solo pubblicaEsistenti spostato),
           Models/Profilo, views admin/{profili,provenienze,organizzazioni,impostazioni.blade.php}, layouts/navigation.blade.php
```

---

### Task 1: Pulizia legacy (Profili, pagina Provenienze, vecchio menu, chiave Telegram morta)

**Files:**
- Create: `database/migrations/2026_11_12_000001_rimuovi_profili.php`, `database/migrations/2026_11_12_000002_rimuovi_telegram_notifica_stato_critico.php`
- Modify: `app/Http/Controllers/SegnalazioneController.php:75-95`, `app/Models/Segnalazione.php:408-415`, `app/Models/User.php` (fillable `id_profilo`, relazione `profilo()`), `app/Http/Controllers/Admin/UtentiController.php` (edit/update: via `id_profilo` e `$profili`), `resources/views/admin/utenti/edit.blade.php` (blocco Profilo), `app/Console/Commands/ImportLegacy.php:180-190,282`, `database/seeders/ImpostazioniSeeder.php` (riga `telegram_notifica_stato_critico`), `routes/web.php` (resource `profili`, `provenienze`), `resources/views/layouts/app.blade.php` (voce "Profili e provenienze"), `resources/views/organizzazione/squadre/index.blade.php` (se referenzia profili)
- Delete: `app/Http/Controllers/Admin/ProfiliController.php`, `app/Http/Controllers/Admin/ProvenienzaController.php`, `app/Models/Profilo.php`, `resources/views/admin/profili/`, `resources/views/admin/provenienze/`, `resources/views/layouts/navigation.blade.php`
- Test: `tests/Feature/Admin/PuliziaLegacyTest.php`; aggiornare i test che creano `Profilo` (grep `Profilo` in `tests/`)

**Interfaces:**
- Produces: `users.id_profilo` e tabella `profili` non esistono più. `Provenienza` (modello e tabella) resta invariata.

- [ ] **Step 1: Write the failing tests** in `PuliziaLegacyTest`:
  - `test_colonna_profilo_e_tabella_rimosse`: `assertFalse(Schema::hasColumn('users','id_profilo'))`, `assertFalse(Schema::hasTable('profili'))`.
  - `test_segnalatore_interno_vede_solo_le_sue`: segnalatore `ldap` attivo; segnalazione sua e una di un altro sullo stesso plesso → `Segnalazione::visibileA($u)` contiene solo la sua.
  - `test_form_segnalazione_mostra_tutte_le_sedi_al_dominio`: due plessi di istituti diversi → `GET segnalazioni.create` come segnalatore ldap vede entrambi.
  - `test_rotte_profili_e_provenienze_non_esistono`: `assertFalse(Route::has('admin.profili.index'))`, idem `admin.provenienze.index`.
  - `test_chiave_telegram_morta_rimossa`: dopo `$this->seed(ImpostazioniSeeder::class)` → `assertNull(Impostazione::find('telegram_notifica_stato_critico'))`.
- [ ] **Step 2:** Run `php artisan test --filter PuliziaLegacyTest` → FAIL.
- [ ] **Step 3: Migration `rimuovi_profili`**: in `up()`, prima di tutto `Log::info('Profili rimossi: utenti attivi che perdono la limitazione a un istituto', ['username' => <lista username con u.attivo=1 e p.limita_istituto=1>])` (solo se la lista non è vuota); poi drop FK se presente, drop colonna `users.id_profilo`, `Schema::dropIfExists('profili')`. `down()`: ricrea tabella e colonna vuote (struttura dalla migration originale), senza dati.
- [ ] **Step 4: Migration `rimuovi_telegram_notifica_stato_critico`**: `DB::table('impostazioni')->where('chiave','telegram_notifica_stato_critico')->delete()`; togli la voce dal seeder.
- [ ] **Step 5: Rimuovi i rami `profilo->limita_istituto`** in `SegnalazioneController::create` e `Segnalazione::scopeVisibileA` (resta: SPID → deleghe; altrimenti segnalatore generico); rimuovi `id_profilo` da `User`, `UtentiController`, vista edit, `ImportLegacy` (non importa più profili). Elimina file e rotte elencati.
- [ ] **Step 6:** Run test filtrato → PASS; poi suite completa e correggi i test che usavano `Profilo`.
- [ ] **Step 7: Commit** `refactor(admin): via profili, pagina provenienze, menu vecchio`.

---

### Task 2: Categorie di enti (tabella, migrazione dati, import MIUR)

**Files:**
- Create: `database/migrations/2026_11_12_000003_create_categorie_enti.php`, `app/Models/CategoriaEnte.php`, `database/factories/CategoriaEnteFactory.php`
- Modify: `app/Models/Istituto.php` (fillable: via `tipo`,`tipo_ente`, + `id_categoria`; relazione `categoria()`), `app/Services/Scuole/SincronizzaScuole.php:145-155`, `app/Console/Commands/PopulateDemoData.php:117`, `database/factories/IstitutoFactory.php` (se esiste), `tests/Support/DelegheFixture.php` e test che passano `tipo`/`tipo_ente`
- Test: `tests/Feature/Scuole/CategorieEntiMigrazioneTest.php`, `tests/Feature/Scuole/SincronizzaScuoleTest.php` (estendere)

**Interfaces:**
- Produces:
  - tabella `categorie_enti`: `id_categoria` PK, `nome` string(80) unique, `ordine` unsigned int default 0, `import_miur` bool default false, timestamps. Al più una riga con `import_miur = true` (garantito dal controller/modello, non da DB).
  - `istituti.id_categoria` FK not null → `categorie_enti.id_categoria` (restrict on delete). Colonne `istituti.tipo` e `istituti.tipo_ente` eliminate.
  - `CategoriaEnte::perImportMiur(): CategoriaEnte` — la categoria con `import_miur = true`; se nessuna, crea/riusa "Scuole statali" e la marca.
  - `Istituto::categoria(): BelongsTo`.
  - Funzione pura usata dalla migration, testabile: `App\Support\Scuole\CategoriaIniziale::per(?string $fonteDati, ?string $tipo, ?string $tipoEnte): string` → nome categoria.

- [ ] **Step 1: Write the failing tests** (`CategorieEntiMigrazioneTest`, unit su `CategoriaIniziale` + feature su migration):
  - `test_miur_va_in_scuole_statali`: `per('miur','Scuola','scuola') === 'Scuole statali'`.
  - `test_migrazione_tiene_tipo_scritto_a_mano`: `per('manuale','Asilo comunale','comune') === 'Asilo comunale'`; `per('manuale','  asilo COMUNALE ','altro')` raggruppa con lo stesso nome (normalizzazione: trim + spazi singoli; il nome salvato è quello della prima occorrenza incontrata; il confronto ignora maiuscole).
  - `test_default_scuola_usa_tipo_ente`: `per('manuale','Scuola','scuola') === 'Scuole'`, `per(null,null,'comune') === 'Comune'`, `'asl' → 'ASL'`, `'provincia' → 'Provincia'`, `'regione' → 'Regione'`, `'altro'`/`null` → `'Altro'`.
  - `test_ogni_ente_ha_categoria_dopo_migrazione`: con `migrate:rollback --step=1` + inserimento di 3 istituti con tipo/tipo_ente vari + `migrate` → `Istituto::whereNull('id_categoria')->count() === 0`, nessuna categoria vuota, "Scuole statali" ha `import_miur = true` se c'erano enti MIUR (altrimenti creata comunque e marcata, così l'import ha sempre una destinazione).
  - In `SincronizzaScuoleTest`: `test_import_usa_categoria_import_miur`: categoria marcata rinominata in "Statali" → istituto importato ha quella categoria.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3: Implement** `CategoriaIniziale::per`, modello, migration (crea tabella; aggiunge `id_categoria` nullable; per ogni istituto calcola il nome con `CategoriaIniziale::per`, crea/riusa la categoria (`ordine` progressivo per ordine alfabetico), assegna; marca "Scuole statali" `import_miur`; rende la colonna not null + FK; elimina `tipo` e `tipo_ente`). `down()`: ricrea `tipo`/`tipo_ente` riempiendo `tipo` col nome categoria e `tipo_ente` = `'scuola'` se categoria import, altrimenti `'altro'`; drop FK/colonna/tabella.
- [ ] **Step 4:** `SincronizzaScuole`: al posto di `tipo`/`tipo_ente` usa `'id_categoria' => CategoriaEnte::perImportMiur()->id_categoria` solo alla **creazione** dell'istituto (un riallineamento non cambia la categoria scelta dall'admin). `PopulateDemoData`: categorie "Comune" e "Scuole statali".
- [ ] **Step 5:** Run test filtrati → PASS; suite completa, correggi fixture/test che usavano `tipo`/`tipo_ente`. (`OrganizzazioniController` rotto viene sostituito in Task 3: in questo task togli solo `tipo`/`tipo_ente` dalle sue regole e passa `id_categoria` required `exists:categorie_enti,id_categoria`, così la suite resta verde.)
- [ ] **Step 6: Commit** `feat(enti): categorie personalizzabili al posto di tipo/tipo_ente`.

---

### Task 3: Enti e sedi in una sezione

**Files:**
- Create: `app/Http/Controllers/Admin/EntiController.php`, `app/Http/Controllers/Admin/CategorieEntiController.php`, `resources/views/admin/enti/{index,create,show}.blade.php`
- Modify: `routes/web.php`, `app/Http/Controllers/Admin/SediController.php` (redirect di store/update/destroy/unisci verso la scheda ente), `app/Http/Controllers/Admin/AnagrafeMiurController.php` (dopo `salva` → redirect a `admin.enti.show`), `resources/views/admin/anagrafe-miur/index.blade.php` (titolo "Aggiungi da MIUR", link indietro a Enti), `resources/views/segnalazioni/create.blade.php` (select sede con `<optgroup>` per categoria), `app/Http/Controllers/SegnalazioneController.php` (eager load `istituto.categoria`, ordina per `categorie_enti.ordine`, poi nome ente, poi nome sede)
- Delete: `OrganizzazioniController`, `resources/views/admin/organizzazioni/`, `resources/views/admin/sedi/index.blade.php` e `create.blade.php` (la creazione sede è nella scheda ente; `edit` resta per i campi completi e "unisci")
- Test: `tests/Feature/Admin/EntiTest.php`; aggiornare `OrganizzazioniCreaTest`, `UnisciSediTest`, `FormMiurTest`, `AnagrafeMiurAdminTest`, `ColonnaSedeTest`

**Interfaces:**
- Consumes: `CategoriaEnte`, `Istituto::categoria()` (Task 2); `SincronizzaScuole`, `AnagrafeMiur` invariati.
- Produces rotte (gruppo `admin.`, `role:admin`):
  - `GET admin/enti` → `admin.enti.index` (query `categoria` = id per filtrare, `q` ricerca su descrizione/codice)
  - `GET admin/enti/nuovo` → `admin.enti.create` (form "a mano"; link "Cerca su MIUR" → `admin.anagrafe-miur.index`)
  - `POST admin/enti` → `admin.enti.store` → redirect `admin.enti.show`
  - `GET admin/enti/{ente}` → `admin.enti.show` (dati ente + elenco sedi + form "aggiungi sede")
  - `PATCH admin/enti/{ente}` → `admin.enti.update`; `DELETE` → `admin.enti.destroy` (stesse regole di eliminazione di oggi in `OrganizzazioniController::destroy`)
  - `POST admin/enti/{ente}/sedi` → `admin.enti.sedi.store` (riusa validazione di `SediController::store`, `id_istituto` dal parametro)
  - `POST admin/categorie-enti` → `admin.categorie-enti.store`; `PATCH admin/categorie-enti/{categoria}` → `.update` (nome, ordine, `import_miur`); `DELETE` → `.destroy` (rifiuta con errore "La categoria contiene N enti" se non vuota). Gestione categorie in un riquadro della pagina `admin.enti.index`.
  - `admin.sedi.edit|update|destroy|unisci` restano con lo stesso nome.
  - Route model binding `{ente}` → `Istituto`.

- [ ] **Step 1: Write the failing tests** (`EntiTest`):
  - `test_elenco_raggruppa_per_categoria_e_mostra_sedi`: 2 categorie, ente con 2 sedi → `assertSeeInOrder([nomeCategoria1, ente, sede1])`.
  - `test_crea_ente_a_mano_poi_sede_nella_scheda`: POST store (`descrizione`, `id_categoria`) → redirect `admin.enti.show`; POST `admin.enti.sedi.store` → sede con `id_istituto` corretto e `fonte_dati = 'manuale'`.
  - `test_campi_miur_bloccati_sulla_scheda`: ente `fonte_dati=miur` → PATCH con `descrizione` diversa non la cambia (stesso comportamento di `CAMPI_MIUR`).
  - `test_categoria_non_vuota_non_si_cancella`: DELETE → errore in sessione, categoria esiste.
  - `test_rinomina_categoria_non_tocca_deleghe`: ente con `richieste_delega_spid=true` e delega attiva; rinomina categoria → `Delega::plessiCopertiDa($u)` invariato.
  - `test_import_miur_unico`: PATCH `import_miur=1` su categoria B → A perde il flag.
  - `test_select_sede_raggruppata_per_categoria`: `GET segnalazioni.create` come segnalatore ldap → `<optgroup label="Comune">` presente.
  - `test_non_admin_non_entra`: gestore → `admin.enti.index` 403.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3: Implement** controller/viste/rotte. Logica di validazione dei campi ente spostata da `OrganizzazioniController` (stessa lista, con `id_categoria` al posto di `tipo`/`tipo_ente`; costante `CAMPI_MIUR` spostata su `EntiController`). Scheda ente: riquadro "Scarica/aggiorna anagrafe MIUR" (form verso `admin.anagrafe-miur.scarica`, stato da `AnagrafeMiur::stato()`) in testa a `admin.enti.index`.
- [ ] **Step 4:** Run test filtrati e i test Scuole aggiornati → PASS; suite completa.
- [ ] **Step 5: Commit** `feat(enti): enti e sedi in una sezione, aggiunta MIUR o a mano`.

---

### Task 4: Base comune delle pagine impostazioni

**Files:**
- Create: `app/Support/Impostazioni/Sezioni.php`, `app/Services/Impostazioni/SalvaImpostazioni.php`, `resources/views/components/pa/impostazione.blade.php`
- Test: `tests/Feature/Admin/SalvaImpostazioniTest.php`, `tests/Feature/Admin/CoperturaChiaviTest.php`

**Interfaces:**
- Produces:
  - `Sezioni::ASPETTO`, `SCADENZE`, `SEGNALAZIONI`, `AVVISI`, `SQUADRE`, `INTEGRAZIONI_SPID`, `INTEGRAZIONI_TELEGRAM`, `INTEGRAZIONI_WEBHOOK`, `INTEGRAZIONI_AI`, `INTEGRAZIONI_ANTIVIRUS`, `INTEGRAZIONI_ALLEGATI`, `INTEGRAZIONI_MIUR`, `INTEGRAZIONI_DELEGHE`: ciascuna `array<string, string>` chiave → tipo.
  - `Sezioni::tutte(): array<string, array<string,string>>` (nome sezione → mappa), `Sezioni::di(string $sezione): array<string,string>` (eccezione 404 se sconosciuta).
  - Tipi ammessi e regola di validazione:
    `testo` → nullable string max:500 · `url` → nullable url max:500 · `email` → nullable email max:255 · `intero` → nullable integer min:0 · `bool` → assente = `'0'`, presente = `'1'` · `colore` → nullable regex `/^#[0-9a-fA-F]{6}$/` · `ora` → nullable date_format:H:i · `decimale` → nullable numeric · `segreto` → vuoto = invariato · `segreto_cifrato` → vuoto = invariato, altrimenti `Crypt::encryptString` · `stato` → nullable integer `exists:stati_segnalazioni,id_stato` · `disco` → in:local,s3 · `orario` → validato da `CalendarioUfficio::valida` (vuoto = chiuso) · `patrono` → `CalendarioUfficio::validaPatrono`.
  - Mappa chiavi (dal seeder, nessuna esclusa tranne le interne e quella eliminata in Task 1):
    - ASPETTO: `ente_nome` testo, `ente_logo_url` url, `ente_colore_primario` colore, `ente_colore_secondario` colore, `ente_sito_url` url, `mail_from_address` email, `mail_from_name` testo, `osm_lat` decimale, `osm_lng` decimale, `osm_zoom` intero
    - SCADENZE: `orario_lun`…`orario_dom` orario, `patrono` patrono
    - SEGNALAZIONI: `reopen_days_limit` intero, `adesioni_enabled` bool, `adesioni_soglia_priorita` intero, `dedup_raggio_metri` intero, `dedup_giorni` intero, `publication_enabled` bool, `publication_auto_state_id` stato, `etichetta_ufficio_segnalante` testo
    - AVVISI: `digest_enabled` bool, `digest_ora` ora, `digest_skip_weekend` bool, `telegram_notifica_operatore_assegnazione` bool, `telegram_notifica_sla_warning` bool, `telegram_notifica_nuova_nota` bool
    - SQUADRE: `squadre_enabled` bool
    - INTEGRAZIONI_SPID: `oidc_issuer` url, `oidc_client_id` testo, `oidc_client_secret` segreto_cifrato
    - INTEGRAZIONI_TELEGRAM: `telegram_bot_token` segreto, `telegram_bot_username` testo, `telegram_webhook_secret` segreto
    - INTEGRAZIONI_WEBHOOK: `webhook_cittadini_url` url, `webhook_cittadini_secret` segreto
    - INTEGRAZIONI_AI: `ai_enabled` bool, `ai_ollama_url` url, `ai_modello` testo, `ai_embedding_modello` testo
    - INTEGRAZIONI_ANTIVIRUS: `antivirus_enabled` bool
    - INTEGRAZIONI_ALLEGATI: `allegati_max_size_mb` intero, `allegati_max_per_request` intero, `allegati_mime_consentiti` testo, `allegati_storage_disk` disco
    - INTEGRAZIONI_MIUR: `miur_anagrafe_url` url, `miur_comune_default` testo
    - INTEGRAZIONI_DELEGHE: i 6 `deleghe_*` intero
  - `SalvaImpostazioni::salva(string $sezione, array $input): array` — `$input` = `request()->input('impostazioni', [])`; valida per tipo (lancia `ValidationException` con chiavi `impostazioni.<chiave>`), ignora chiavi non della sezione e `CHIAVI_INTERNE`, normalizza (`oidc_issuer` rtrim `/`, trim su orario/patrono), salva con `Impostazione::set`. Ritorna `list<string>` delle chiavi il cui valore è cambiato.
  - `<x-pa.impostazione :chiave="..." :tipo="..." :etichetta="..." :aiuto="..." />`: rende il campo giusto per tipo (checkbox per `bool`, password vuota con placeholder "Configurato — lascia vuoto per non modificarlo"/"Non configurato" per i segreti, select per `stato`/`disco`, color per `colore`), errore sotto, **nessuna chiave tecnica stampata**. `name="impostazioni[<chiave>]"`.

- [ ] **Step 1: Write the failing tests** (`SalvaImpostazioniTest`):
  - `test_segreto_vuoto_non_sovrascrive`: `telegram_bot_token = 'abc'`; `salva('INTEGRAZIONI_TELEGRAM', ['telegram_bot_token' => ''])` → resta `'abc'`.
  - `test_segreto_cifrato`: `salva('INTEGRAZIONI_SPID', ['oidc_client_secret' => 's3'])` → `Crypt::decryptString(Impostazione::get('oidc_client_secret')) === 's3'`.
  - `test_bool_assente_salva_zero`: `digest_enabled = '1'`; `salva('AVVISI', [])` → `'0'`.
  - `test_chiave_di_altra_sezione_ignorata`: `salva('AVVISI', ['ente_nome' => 'X'])` → `ente_nome` invariato.
  - `test_chiavi_interne_ignorate`: `salva('ASPETTO', ['ripristino_id' => 'x'])` → invariato.
  - `test_colore_non_valido_rifiutato`: `ente_colore_primario => 'rosso'` → `ValidationException` con chiave `impostazioni.ente_colore_primario`.
  - `test_orario_tutto_chiuso_rifiutato`: tutte le `orario_*` vuote → errore su `impostazioni.orario_lun` (messaggio esistente "Almeno un giorno deve essere aperto…").
  - `test_ritorna_chiavi_cambiate`: cambia `patrono` → risultato contiene `'patrono'`.
  - `CoperturaChiaviTest::test_ogni_chiave_del_seeder_ha_una_pagina`: chiavi in tabella dopo `ImpostazioniSeeder` meno `CHIAVI_INTERNE` == unione delle chiavi di `Sezioni::tutte()` (assertEqualsCanonicalizing).
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3: Implement** `Sezioni`, `SalvaImpostazioni` (logica orario/patrono presa da `ImpostazioniController::validaOrario`), componente Blade.
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5: Commit** `feat(impostazioni): mappa sezioni e salvataggio unico`.

---

### Task 5: Regole di lavoro (Scadenze, Segnalazioni, Avvisi) e interruttore squadre

**Files:**
- Create: `app/Http/Controllers/Admin/Regole/{ScadenzeController,SegnalazioniController,AvvisiController}.php`, `resources/views/admin/regole/{scadenze,segnalazioni,avvisi}.blade.php`
- Modify: `routes/web.php`, `app/Http/Controllers/Admin/SlaController.php` (redirect dopo store/update/destroy → `admin.regole.scadenze`; `index` → redirect 301 a `admin.regole.scadenze`), `resources/views/admin/sla/index.blade.php` (contenuto spostato in `regole/scadenze`, file eliminato), `app/Http/Controllers/Organizzazione/SquadreController.php` + `resources/views/organizzazione/squadre/index.blade.php` (riquadro interruttore visibile solo `@role('admin')`)
- Test: `tests/Feature/Admin/RegoleTest.php`; aggiornare `RicalcoloSlaTest`, `SlaOrarioUfficioTest`, `AutoPublishSegnalazioniTest`, `InattivitaTest`, `SquadreGestioneTest`

**Interfaces:**
- Consumes: `SalvaImpostazioni::salva`, `Sezioni` (Task 4); `RicalcolaScadenzeSla`, `CalendarioUfficio::daImpostazioni()->festivi(int)`, `PubblicazioneAutomatica::applicaAEsistenti()`.
- Produces rotte (gruppo `admin.`): `GET admin/regole/scadenze` `admin.regole.scadenze` · `PATCH admin/regole/scadenze/orario` `admin.regole.orario` · `GET|PATCH admin/regole/segnalazioni` `admin.regole.segnalazioni[.update]` · `POST admin/regole/segnalazioni/pubblica-esistenti` `admin.regole.pubblica-esistenti` · `GET|PATCH admin/regole/avvisi` `admin.regole.avvisi[.update]` · `PATCH organizzazione/squadre/attiva` `organizzazione.squadre.attiva` (middleware aggiuntivo `role:admin`).

- [ ] **Step 1: Write the failing tests** (`RegoleTest`):
  - `test_scadenze_mostra_regole_sla_e_orario`: `assertSee` descrizione di una `SlaConfigurazione`, "Orario d'ufficio", festivi dell'anno corrente (es. `25/12/<anno>`).
  - `test_cambio_orario_ricalcola`: `Queue::fake()`; PATCH `admin.regole.orario` con `orario_lun` diverso → `Queue::assertPushed(RicalcolaScadenzeSla::class)`; stesso orario → `assertNotPushed`.
  - `test_pubblicazione_stato_per_nome`: `GET admin.regole.segnalazioni` → `assertSee` descrizione di uno stato (es. "In carico") dentro una `<select>`; PATCH con `publication_auto_state_id` = 2 → salvato.
  - `test_avvisi_digest_spento`: PATCH senza `digest_enabled` → `Impostazione::get('digest_enabled') === '0'`.
  - `test_squadre_interruttore_solo_admin`: supervisore → PATCH `organizzazione.squadre.attiva` 403; admin → salva `squadre_enabled`.
  - `test_gestore_non_entra_nelle_regole`: gestore → 403 sulle tre GET.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3: Implement** controller sottili (index = vista con valori `Impostazione::get`; update = `SalvaImpostazioni::salva(<sezione>, ...)` + redirect back con `success`). `ScadenzeController::orario`: se il risultato di `salva('SCADENZE', …)` non è vuoto → `RicalcolaScadenzeSla::dispatch()` e messaggio "scadenze delle segnalazioni aperte in aggiornamento" (testo attuale). Testi di aiuto: riprendere le `descrizione` del seeder riscritte in italiano semplice.
- [ ] **Step 4:** Run filtrati → PASS; suite completa.
- [ ] **Step 5: Commit** `feat(admin): regole di lavoro in tre pagine`.

---

### Task 6: Ente e sistema (Aspetto, Integrazioni, Backup)

**Files:**
- Create: `app/Http/Controllers/Admin/{AspettoController,IntegrazioniController}.php`, `resources/views/admin/{aspetto,integrazioni}.blade.php`
- Modify: `app/Services/OllamaService.php` (+ `raggiungibile(): bool`), `app/Services/ClamAvService.php` (+ `raggiungibile(): bool`), `app/Services/TelegramBotService.php` (+ `botVerificato(): ?string`), `app/Services/Oidc/OidcClient.php` (+ `verificaDiscovery(): ?string`), `routes/web.php`
- Test: `tests/Feature/Admin/AspettoTest.php`, `tests/Feature/Admin/IntegrazioniTest.php`; spostare in questi i casi di `ImpostazioniOidcTest` e `BrandLogoTest` che passano da `admin.impostazioni.update`

**Interfaces:**
- Consumes: `SalvaImpostazioni`, `Sezioni`, `<x-pa.impostazione>` (Task 4); `OidcConfig::problemi()`/`redirectUri()`; `Brand`.
- Produces:
  - Rotte (gruppo `admin.`): `GET|PATCH admin/aspetto` `admin.aspetto[.update]` (anche upload/rimozione logo: stessa logica di `ImpostazioniController::update` righe 52-61 e `cancellaLogoFile`) · `GET admin/integrazioni` `admin.integrazioni` · `PATCH admin/integrazioni/{sezione}` `admin.integrazioni.update` (`sezione` ∈ spid, telegram, webhook, ai, antivirus, allegati, miur, deleghe → `INTEGRAZIONI_<SEZIONE>`) · `POST admin/integrazioni/{sezione}/prova` `admin.integrazioni.prova` (solo spid, telegram, ai, antivirus).
  - `IntegrazioniController::prova` salva il risultato in `Cache::put("integrazioni:prova:$sezione", ['ok' => bool, 'messaggio' => string, 'quando' => now()->toIso8601String()], now()->addMinutes(30))` e torna indietro con messaggio. Ogni prova con timeout ≤ 2 s.
  - `OllamaService::raggiungibile()`: GET `<ai_ollama_url>/api/tags`, timeout 2 s. `ClamAvService::raggiungibile()`: comando `zPING\0` sul socket, atteso `PONG`, timeout 2 s. `TelegramBotService::botVerificato()`: `getMe`, ritorna `result.username` o null. `OidcClient::verificaDiscovery()`: null se la discovery risponde e contiene `authorization_endpoint`, altrimenti il motivo in italiano.
- Pagina Integrazioni: una scheda per sezione con stato (attiva/non attiva/problema + ultimo esito prova dalla cache), motivo, form propria. SPID: redirect URI in sola lettura + `OidcConfig::problemi()`. Antivirus: nota "serve il container del profilo Docker `security`". Scheda "Sincronizzazione AD" in sola lettura: configurata sì/no (`Directory::syncConfigurata()`) con link a `admin.utenti.dominio`. Aspetto: mappa Leaflet con marker trascinabile che scrive `osm_lat`/`osm_lng` e zoom dal livello corrente in campi hidden; anteprima colori.
- Backup: rotte invariate, solo menu (Task 8).

- [ ] **Step 1: Write the failing tests**:
  - `AspettoTest::test_salva_nome_e_colori`, `test_logo_file_ha_precedenza` (dal vecchio `BrandLogoTest`), `test_nessuna_chiave_tecnica_visibile` (`assertDontSee('osm_zoom')`, `assertDontSee('mail_from_address')`).
  - `IntegrazioniTest::test_segreti_non_stampati`: `telegram_bot_token='tok-123'` → GET `admin.integrazioni` `assertDontSee('tok-123')`; idem `webhook_cittadini_secret`.
  - `test_update_sezione_sconosciuta_404`: PATCH `admin.integrazioni.update` con `sezione=pippo` → 404.
  - `test_prova_ai_salva_esito_in_cache`: `Http::fake(['*/api/tags' => Http::response([], 200)])`, `ai_enabled=1` → POST prova `ai` → `Cache::get('integrazioni:prova:ai')['ok'] === true`.
  - `test_prova_spid_fallita_dice_il_motivo`: `Http::fake(['*' => Http::response('', 500)])` → cache `ok=false`, `messaggio` non vuoto.
  - `test_spid_mostra_redirect_uri`: `assertSee(app(OidcConfig::class)->redirectUri())`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3: Implement** controller, metodi `raggiungibile/botVerificato/verificaDiscovery`, viste.
- [ ] **Step 4:** Run → PASS; suite completa.
- [ ] **Step 5: Commit** `feat(admin): aspetto e integrazioni con prova`.

---

### Task 7: Persone — utenti separati per tipo

**Files:**
- Create: `resources/views/admin/utenti/{dominio,scuole,ditte}.blade.php`, `resources/views/admin/utenti/_filtri.blade.php`
- Modify: `app/Http/Controllers/Admin/UtentiController.php` (`index` → redirect a `admin.utenti.dominio`; nuovi `dominio`, `scuole`, `ditte`), `routes/web.php`
- Delete: `resources/views/admin/utenti/index.blade.php` (contenuto esito sync spostato in `dominio`)
- Test: `tests/Feature/Admin/UtentiPerTipoTest.php`; aggiornare `SyncAdAdminTest` (asserzioni dell'index → `admin.utenti.dominio`)

**Interfaces:**
- Produces rotte: `GET admin/utenti/dominio` `admin.utenti.dominio` · `GET admin/utenti/scuole` `admin.utenti.scuole` · `GET admin/utenti/ditte` `admin.utenti.ditte`. Query comuni: `q` (nome, username, CF, email; `LIKE` case-insensitive), `mostra_disattivati`. Paginazione 25 con `withQueryString`.
- Filtri: dominio = `auth_source='ldap'`; scuole = `auth_source='spid'` e senza ruolo `impresa`; ditte = ruolo `impresa`. Account `locale`: in nessuna lista.
- Colonne: dominio = nome, username, ruolo (+ "supervisore"), tipologie (conteggio o "tutte"; solo gestori/admin con tipologie), ultimo accesso (`last_login` o "mai"), stato, link modifica. Scuole = nome, CF (`codice_fiscale`), email verificata sì/no, scuole delegate (nomi degli istituti delle deleghe attive), stato, azione disattiva/riattiva (`admin.utenti.toggle-attivo`), link a `admin.deleghe.index` filtrato se il filtro esiste (altrimenti link semplice). Ditte = nome, CF, ditta (link `imprese.edit`), stato; nessuna azione.

- [ ] **Step 1: Write the failing tests** (`UtentiPerTipoTest`):
  - `test_index_reindirizza_a_dominio`.
  - `test_liste_separate`: un utente per tipo (ldap, spid, impresa, locale) → ognuno compare solo nella sua lista; il locale in nessuna.
  - `test_ricerca_per_codice_fiscale`: `GET admin.utenti.scuole?q=RSSMRA` trova la persona.
  - `test_dominio_mostra_esito_sync`: esito salvato → `assertSee('Sincronizza ora')` su `admin.utenti.dominio`.
  - `test_ditte_senza_azioni`: `assertDontSee(route('admin.utenti.toggle-attivo', $ditta))`.
  - `test_scuola_mostra_istituto_delegato`: delega attiva → nome istituto visibile.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3: Implement** azioni, viste, eager loading (`roles`, `tipologieGestite` count, deleghe attive con istituto) senza N+1.
- [ ] **Step 4:** Run filtrati + `SyncAdAdminTest` → PASS; suite completa.
- [ ] **Step 5: Commit** `feat(utenti): elenchi separati dominio, scuole, ditte`.

---

### Task 8: Stato del sistema, menu nuovo, redirect, rilascio

**Files:**
- Create: `app/Services/Admin/StatoSistema.php`, `app/Services/Admin/Riquadro.php`, `resources/views/admin/stato/index.blade.php`
- Modify: `app/Http/Controllers/Admin/AdminDashboardController.php` (usa `StatoSistema`), `resources/views/layouts/app.blade.php` (blocco Amministrazione nuovo, struttura nella spec), `routes/web.php` (redirect vecchi URL; elimina rotte `admin.impostazioni.update` e `pubblica-esistenti` vecchie), `CHANGELOG.md`, `publiccode.yml`, `CLAUDE.md` (architettura controller, tabella impostazioni → "pagine", menu admin)
- Delete: `app/Http/Controllers/Admin/ImpostazioniController.php`, `resources/views/admin/impostazioni.blade.php`, `resources/views/admin/dashboard.blade.php`
- Test: `tests/Feature/Admin/StatoSistemaTest.php`, `tests/Feature/Admin/MenuERedirectTest.php`; sostituire `AdminDashboardTest`

**Interfaces:**
- Consumes: `SincronizzaUtentiAd::ultimoEsito()`, `Directory::syncConfigurata()`, `CatalogoBackup::inAllarme(CarbonImmutable)`, `OidcConfig::configurato()/problemi()`, `CoperturaTipologie::orfane()`, `Delega` stato `Delega::RICHIESTA`, `AnagrafeMiur::stato()`, `Cache "integrazioni:prova:<sezione>"` (Task 6), `Impostazione::get`.
- Produces:
  - `Riquadro` readonly: `chiave` string, `titolo` string, `stato` string ∈ `ok|attenzione|problema|non_verificabile`, `messaggio` string, `url` string.
  - `StatoSistema::riquadri(): list<Riquadro>` e `StatoSistema::spente(): list<string>` (titoli delle funzioni spente). **Nessuna chiamata di rete**: per AI, antivirus, Telegram si usa solo l'esito in cache della prova; senza esito → `non_verificabile` con messaggio "Verifica dalla pagina Integrazioni".
  - Regole (dalla spec): Sync AD solo se `syncConfigurata()`: `ok` se ultimo esito `ok` < 36 h e contatori `senzaEmail`/`usernameOccupati`/`errori` a 0; `attenzione` se ok con contatori > 0 o `bloccata`; `problema` se `fallita`, nessun esito, o esito > 36 h. Backup: `problema` se `inAllarme`, altrimenti `ok`. SPID: mostrato se almeno issuer o client id valorizzati; `ok` se `problemi() === []`, altrimenti `problema`. Email: `ok` se `mail_from_address` valorizzato, altrimenti `problema`. Webhook: se URL valorizzato → `non_verificabile` ("nessuna prova automatica: invierebbe un evento al sito"). MIUR: `attenzione` se `stato()['scaricato_at']` nullo o più vecchio di 365 giorni, altrimenti `ok`. Tipologie orfane: `attenzione` con i nomi se ≥ 1, `ok` se 0. Deleghe: `attenzione` con il numero se ≥ 1 in `RICHIESTA`, `ok` se 0. Telegram/AI/antivirus: mostrati solo se accesi (`telegram_bot_token` valorizzato / `ai_enabled` / `antivirus_enabled`).
  - Redirect 301 (gruppo admin): `admin/impostazioni` → `admin.aspetto`; `admin/utenti` (index) → `admin.utenti.dominio` (Task 7); `admin/organizzazioni[/...]` → `admin.enti.index`; `admin/sedi` → `admin.enti.index`; `admin/sla` → `admin.regole.scadenze`; `admin/profili`, `admin/provenienze` → `admin.dashboard`; `admin/anagrafe-miur` resta (pagina "Aggiungi da MIUR").
  - Menu: voci e ordine della spec; Persone mostra Squadre e Tipologie anche al supervisore non admin (solo quelle due, `@can('gestisci-organizzazione')`), il resto `@if($isAdmin)`.

- [ ] **Step 1: Write the failing tests**:
  - `StatoSistemaTest::test_sync_fallita_rossa`, `test_sync_ok_con_errori_gialla`, `test_sync_non_configurata_assente`, `test_backup_in_allarme_rosso`, `test_spid_con_problemi_rosso`, `test_orfane_gialla_con_nome`, `test_deleghe_in_attesa_conta`, `test_ai_spenta_fra_le_spente`, `test_ai_accesa_senza_prova_grigia`.
  - `test_home_non_chiama_la_rete`: `Http::fake()` + `ai_enabled=1`, `telegram_bot_token='x'`, SPID configurato → GET `admin.dashboard` OK e `Http::assertNothingSent()`.
  - `MenuERedirectTest::test_vecchi_url_reindirizzano`: per ogni URL vecchio della lista sopra → `assertRedirect` alla rotta nuova (status 301).
  - `test_menu_admin_quattro_blocchi`: `assertSeeInOrder(['Stato del sistema','Persone','Enti e sedi','Regole di lavoro','Ente e sistema'])`.
  - `test_supervisore_vede_solo_squadre_e_tipologie`: supervisore → `assertSee('Squadre')`, `assertDontSee('Enti e sedi')`.
  - `test_pagine_admin_senza_overflow_mobile`: nessun test automatico; verifica manuale in Step 4.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3: Implement** servizio, vista home (griglia riquadri colorati con `.pa-*`, in fondo "Non attive: …" con link a Integrazioni), menu, redirect; elimina il vecchio form e la vecchia dashboard.
- [ ] **Step 4: Verifica manuale nel container dev**: `docker compose exec php npm run build`, login `mock.admin`, aprire ogni pagina nuova a 375px di larghezza (DevTools) → nessuno scroll orizzontale; salvare una pagina per blocco.
- [ ] **Step 5:** Suite completa + larastan → verdi.
- [ ] **Step 6: Docs e versione**: CHANGELOG `## [0.9.18]` (Changed: area admin rifatta; Removed: profili, pagina provenienze, `telegram_notifica_stato_critico`, `tipo`/`tipo_ente` sostituiti dalle categorie; nota deploy: la migration logga gli utenti di dominio che perdono il profilo), `publiccode.yml` 0.9.18, `CLAUDE.md` aggiornato (architettura `Admin/`, sostituire la tabella `impostazioni` con la mappa `Sezioni`, menu), spec: stato "rilasciata 0.9.18".
- [ ] **Step 7: Commit** `feat(admin): stato del sistema e menu in quattro blocchi (0.9.18)`.
