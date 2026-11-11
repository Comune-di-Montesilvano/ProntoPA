# Ruoli e smistamento per tipologia — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Gestori legati a tipologie (visibilità + azioni + avvisi email), alert tipologie orfane, squadre/tipologie gestibili dal supervisore, pre-registrazione utenti AD da parte dell'admin.

**Architecture:** Pivot `gestori_tipologie` + flag `users.gestore_tutte_tipologie`; un servizio unico `CoperturaTipologie` risponde a "chi copre questa tipologia / quali sono orfane" e lo usano scope, policy, destinatari avvisi e UI. Avvisi da observer sul model `Segnalazione` (copre tutti i canali). Pre-registrazione: nuovo metodo `Directory::cerca()` che fa bind come l'admin e legge l'utente cercato.

**Tech Stack:** Laravel 13, PHP 8.4, Spatie Permission v6, LdapRecord v4, Blade + Tailwind 4 + Alpine, PHPUnit Feature test.

**Spec:** `docs/superpowers/specs/2026-11-11-ruoli-smistamento-design.md`

## Global Constraints

- Tre PR, ognuna patch con bump `publiccode.yml` `softwareVersion` + `CHANGELOG.md` `## [X.Y.Z] - data` nella stessa PR: Fase A = 0.9.11, Fase B = 0.9.12, Fase C = 0.9.13 (0.9.9 e 0.9.10 usate da fix organizzazioni).
- Comandi sempre nel container: `MSYS_NO_PATHCONV=1 docker compose exec -T php php artisan test --filter=<Nome>`; a fine fase suite completa `php artisan test` + `composer run analyse`.
- Feature test POST su rotte `web`: `$this->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class, \Illuminate\Routing\Middleware\ThrottleRequests::class])`.
- Migration additive, nome `2026_11_11_00000N_<descrizione>.php`.
- Autorizzazione via Policy/Gate, mai controlli di ruolo nel controller.
- Ogni parametro password: `#[\SensitiveParameter]` (con backslash) + riga in `tests/Unit/PasswordNonNeiLogTest.php`.
- Nuova notifica → etichetta in `NotificaSegnalazione::ETICHETTE`; notifiche `ShouldQueue` con `$afterCommit = true`.
- Destinatari: mai account con `attivo = false`, mai l'utente che ha causato l'evento. **Admin esclusi dagli avvisi SLA.**
- Nessun CDN, componenti `x-pa.*` dove esistono; testo UI in italiano.
- Commit con skill caveman-commit, trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. Gestore con `attivo = false` o con ruolo cambiato da AD (ora operaio) ma con tipologie in pivot → non deve contare come copertura né ricevere email (test in Task A1).
2. Segnalazione creata da `artisan demo` / seed → nessuna email (test in Task B1).
3. Riclassificazione verso la **stessa** tipologia (update che non cambia `id_tipologia_segnalazione`) o update di altri campi → nessuna email (test in Task B1).
4. Pre-registrazione di uno username già presente (account AD attivo o altro pre-registrato) → rifiutata con messaggio, nessun duplicato (test in Task C2).
5. Login AD di un utente il cui username coincide con un pre-registrato "sulla fiducia" ma che ha già un account con GUID → vince il GUID, il pre-registrato non viene toccato (test in Task C3).

---

## Fase A — Tipologie dei gestori, visibilità, orfane, organizzazione (0.9.11)

Branch: `feat/ruoli-smistamento` (contiene già lo spec).

### Task 1 (A1): Modello copertura

**Files:**
- Create: `database/migrations/2026_11_11_000003_create_gestori_tipologie_table.php`
- Create: `app/Services/Segnalazioni/CoperturaTipologie.php`
- Modify: `app/Models/User.php` (fillable, casts, relazione)
- Test: `tests/Feature/CoperturaTipologieTest.php`

**Interfaces:**
- Produces:
  - tabella `gestori_tipologie(id_utente FK users.id cascade, id_tipologia_segnalazione FK cascade, PK composta)`; colonna `users.gestore_tutte_tipologie boolean default false`.
  - Migration `up()` imposta `gestore_tutte_tipologie = true` per gli utenti con ruolo Spatie `gestore` e `supervisore_segnalazioni = false` (decisione committente).
  - `User::tipologieGestite(): BelongsToMany` (pivot `gestori_tipologie`, chiavi `id_utente` / `id_tipologia_segnalazione`).
  - `User::copreTipologia(int $idTipologia): bool` — true se ruolo `gestore`, non supervisore, e (`gestore_tutte_tipologie` o tipologia in pivot).
  - `CoperturaTipologie::gestoriDi(int $idTipologia): Collection<User>` — gestori attivi (`attivo` true o null), ruolo `gestore`, non supervisori, **con email non vuota**, che coprono la tipologia.
  - `CoperturaTipologie::orfane(): Collection<TipologiaSegnalazione>` — tipologie per cui `gestoriDi()` è vuoto.
  - `CoperturaTipologie::nonVerificati(int $idTipologia): Collection<User>` — gestori che coprono ma senza email (pre-registrati sulla fiducia), per la UI.
  - `CoperturaTipologie::supervisori(): Collection<User>` — ruolo `gestore`, `supervisore_segnalazioni` true, attivi, con email.

- [ ] **Step 1: Test** `CoperturaTipologieTest`:
  - `test_gestore_con_tipologia_copre_solo_quella` — pivot {1} → `gestoriDi(1)` contiene, `gestoriDi(3)` no.
  - `test_gestore_tutte_copre_anche_tipologie_nuove` — flag true, crea tipologia nuova → coperta.
  - `test_supervisore_non_conta_come_copertura`.
  - `test_gestore_disattivato_o_non_piu_gestore_non_copre` — `attivo=false`; altro con pivot ma ruolo `operaio` → `orfane()` contiene la tipologia.
  - `test_gestore_senza_email_e_non_verificato` — email null → assente da `gestoriDi`, presente in `nonVerificati`, tipologia in `orfane()`.
  - `test_migration_gestori_esistenti_tutte` — utente gestore creato prima del `migrate` non si può testare con RefreshDatabase: verificare invece richiamando la closure di backfill estratta come `CoperturaTipologie::abilitaTutteAiGestoriEsistenti(): int` (la migration la chiama) → gestore non supervisore true, supervisore false.
- [ ] **Step 2:** Run `--filter=CoperturaTipologieTest` → FAIL (classe mancante).
- [ ] **Step 3:** Migration (rende anche `users.email` nullable: serve alla Fase C, va fatta una volta) + model + servizio.
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit `feat(gestori): copertura tipologie per gestore`.

### Task 2 (A2): Visibilità e azioni del gestore per tipologia

**Files:**
- Modify: `app/Models/Segnalazione.php:361-369` (`scopeVisibileA`, ramo gestore)
- Modify: `app/Policies/SegnalazionePolicy.php:45-60` (`update`)
- Modify: `app/Console/Commands/InviaDigestGestori.php:56` (sezione non assegnate)
- Test: `tests/Feature/VisibilitaGestoreTipologiaTest.php`

**Interfaces:**
- Consumes: `User::copreTipologia()`, `tipologieGestite`, `gestore_tutte_tipologie` (A1).
- Produces: gestore non supervisore vede `id_operatore_assegnato = sé` ∪ `id_utente_segnalazione = sé` ∪ (`gestore_tutte_tipologie` ? tutto : `id_tipologia_segnalazione IN pivot`). `update` = supervisore ∨ assegnato ∨ `copreTipologia($s->id_tipologia_segnalazione)`. `deleteAllegato` **non** cambia; riapertura (`SegnalazioneWorkflowService:292`) **non** cambia.

- [ ] **Step 1: Test**:
  - `test_gestore_vede_nuove_della_sua_tipologia_e_non_delle_altre` — due segnalazioni stato 1, tipologie 1 e 3; gestore pivot {1}: `visibileA` → solo la prima; GET `segnalazioni.show` seconda → 403.
  - `test_gestore_tutte_vede_tutto`.
  - `test_gestore_vede_ancora_assegnate_fuori_tipologia`.
  - `test_gestore_tipologia_puo_assegnare` — `can('update')` true, `can('modificaPriorita')` true, `can('accorpa')` true.
  - `test_gestore_tipologia_non_elimina_allegati` — `can('deleteAllegato')` false se non assegnato.
  - `test_due_gestori_stessa_tipologia_vedono_entrambi`.
  - `test_digest_non_assegnate_filtrate_per_tipologia` — `Notification::fake()`, `artisan digest:invia`; gestore pivot {1} riceve `DigestGestoreNotification` con solo id della tipologia 1 tra le non assegnate.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implementare; digest: `$nonAssegnate` = supervisore ? globali : globali filtrate con `copreTipologia`.
- [ ] **Step 4:** Run filtro + `--filter=Segnalazione` (regressioni visibilità) → PASS.
- [ ] **Step 5:** Commit `feat(gestori): visibilità e azioni per tipologia`.

### Task 3 (A3): Area "Organizzazione" (squadre + tipologie gestori) per admin e supervisore

**Files:**
- Modify: `app/Providers/AppServiceProvider.php` (Gate)
- Move: `app/Http/Controllers/Admin/SquadreController.php` → `app/Http/Controllers/Organizzazione/SquadreController.php`; viste `resources/views/admin/squadre/*` → `resources/views/organizzazione/squadre/*`
- Create: `app/Http/Controllers/Organizzazione/TipologieGestoriController.php`, `resources/views/organizzazione/tipologie-gestori/{index,edit}.blade.php`, `resources/views/organizzazione/_orfane.blade.php`
- Modify: `routes/web.php` (togliere `squadre` dal gruppo admin, nuovo gruppo), `resources/views/layouts/app.blade.php` (sidebar), `resources/views/admin/dashboard.blade.php` (tile), `resources/views/gestione/dashboard.blade.php` (alert orfane per supervisore), `resources/views/segnalazioni/scheda/gestore.blade.php` (banner orfana)
- Modify: `tests/Feature/SquadreGestioneTest.php` (rotte rinominate)
- Test: `tests/Feature/TipologieGestoriTest.php`

**Interfaces:**
- Consumes: `CoperturaTipologie::orfane()`, `nonVerificati()` (A1).
- Produces:
  - Gate `gestisci-organizzazione`: `isAdmin() || hasRole('admin') || (hasRole('gestore') && isSupervisore())`.
  - Gruppo rotte `middleware(['auth', 'can:gestisci-organizzazione'])->prefix('organizzazione')->name('organizzazione.')`: `resource('squadre', …)->except(['show'])->parameters(['squadre' => 'squadra'])`; `GET tipologie-gestori` → `index`, `GET tipologie-gestori/{utente}` → `edit`, `PUT tipologie-gestori/{utente}` → `update`.
  - Le rotte `admin.squadre.*` spariscono: aggiornare ogni riferimento (`grep -rn "admin.squadre" app resources tests`).
  - `update` valida `tutte` boolean, `tipologie` array di id esistenti; 404 se `{utente}` non è gestore o è supervisore.
  - Sidebar: sezione "Organizzazione" (voci Squadre, Tipologie dei gestori) visibile con `@can('gestisci-organizzazione')`; tolta la voce Squadre da Amministrazione; tile admin dashboard → `organizzazione.squadre.index`.
  - Partial `_orfane` (riceve `$orfane`, `$nonVerificati` per tipologia): riquadro ambra "Tipologie senza gestore" con elenco e, per le non verificate, "copertura non verificata: <username> (in attesa del primo accesso)"; incluso in `tipologie-gestori/index` e, compatto con link, in `gestione/dashboard` solo se `@can('gestisci-organizzazione')` e ci sono orfane.
  - Banner scheda: in `scheda/gestore.blade.php`, se l'utente `can('gestisci-organizzazione')` e la tipologia della segnalazione è orfana: "Nessun gestore copre la tipologia <descrizione>: questa segnalazione arriva solo ai supervisori." + link a `organizzazione.tipologie-gestori.index`.
  - Index: due viste nella stessa pagina — per gestore (nome, "tutte" o elenco tipologie, link modifica) e per tipologia (raggruppata per gruppo, chi la copre), più il partial `_orfane` in cima.
  - Edit: checkbox raggruppate per `tipologia.gruppo`, spunta "tutto il gruppo" (solo Alpine, nessun salvataggio a livello gruppo) e checkbox "Tutte le tipologie, anche future".

- [ ] **Step 1: Test** `TipologieGestoriTest`:
  - `test_supervisore_e_admin_accedono_gestore_no` — GET index: supervisore 200, admin 200, gestore 403, operaio 403.
  - `test_supervisore_salva_tipologie_di_un_gestore` — PUT `{tutte:0, tipologie:[1,3]}` → pivot {1,3}, flag false.
  - `test_non_si_modificano_supervisori` — PUT su un supervisore → 404.
  - `test_index_mostra_orfane` — nessun gestore copre tipologia 9 → `assertSee('ARREDI')` dentro "Tipologie senza gestore".
  - `test_supervisore_gestisce_squadre` — POST `organizzazione.squadre.store` da supervisore → redirect, squadra creata.
  - `test_banner_orfana_in_scheda` — supervisore apre segnalazione tipologia orfana → `assertSee('Nessun gestore copre la tipologia')`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implementare (spostamento con `git mv`).
- [ ] **Step 4:** Run `--filter='TipologieGestori|Squadre'` → PASS.
- [ ] **Step 5:** Commit `feat(organizzazione): squadre e tipologie per supervisore`.

### Task 4 (A4): Chiusura fase A

- [ ] Suite completa + `composer run analyse` verdi.
- [ ] `publiccode.yml` 0.9.11, CHANGELOG `## [0.9.11]` (Added: tipologie per gestore, area Organizzazione, alert orfane; Changed: gestori esistenti partono con "tutte le tipologie", squadre spostate in Organizzazione).
- [ ] CLAUDE.md: tabella ruoli (gestore = tipologie), architettura (`Organizzazione/`, `CoperturaTipologie`).
- [ ] Commit, push, PR, CI verde, merge squash, tag `v0.9.11`, `gh run watch` su release.

---

## Fase B — Avvisi email (0.9.12)

Branch nuovo da `main` aggiornato: `feat/avvisi-tipologia`.

### Task 5 (B1): Avviso nuova segnalazione / riclassificata

**Files:**
- Create: `app/Notifications/NuovaSegnalazioneNotification.php`
- Create: `app/Observers/SegnalazioneObserver.php` (registrato con `#[ObservedBy]` su `Segnalazione`)
- Create: `app/Services/Segnalazioni/DestinatariTipologia.php`
- Modify: `app/Models/NotificaSegnalazione.php` (etichetta), `app/Console/Commands/PopulateDemoData.php` (silenzia)
- Test: `tests/Feature/AvvisoNuovaSegnalazioneTest.php`

**Interfaces:**
- Consumes: `CoperturaTipologie::gestoriDi()`, `supervisori()` (A1).
- Produces:
  - `DestinatariTipologia::perNuova(Segnalazione $s, ?User $escludi): Collection<User>` = `gestoriDi(tipologia)`, se vuoto `supervisori()`; meno `$escludi`; unici per id.
  - `DestinatariTipologia::perSlaScaduto(Segnalazione $s): Collection<User>` = `gestoriDi` ∪ `supervisori()`.
  - `DestinatariTipologia::perSlaArischio(Segnalazione $s): Collection<User>` = `gestoriDi`, se vuoto `supervisori()`.
  - `NuovaSegnalazioneNotification(Segnalazione $segnalazione, bool $riclassificata = false)`, canale `mail`, oggetto "Nuova segnalazione #<id> – <tipologia>" o "Segnalazione #<id> riclassificata in <tipologia>", link `route('segnalazioni.show')`. Etichetta `'NuovaSegnalazioneNotification' => 'Nuova segnalazione (gestori)'`.
  - Observer `created` → `perNuova($s, auth()->user())`; `updated` con `wasChanged('id_tipologia_segnalazione')` → `perNuova(..., riclassificata: true)`. Non scatta se `Segnalazione::$silenziaAvvisi === true` (proprietà statica pubblica, default false), impostata da `PopulateDemoData` per tutta l'esecuzione.

- [ ] **Step 1: Test** (`Notification::fake()`):
  - `test_nuova_avvisa_gestori_della_tipologia` — gestore A {1}, B {3}, C tutte → crea tipologia 1 → A e C sì, B no.
  - `test_nuova_orfana_avvisa_supervisori`.
  - `test_chi_crea_non_riceve` — gestore A crea (actingAs) → A non riceve.
  - `test_disattivati_esclusi`.
  - `test_riclassificazione_avvisa_nuova_tipologia` — update tipologia 1→3 → B riceve con `riclassificata = true`.
  - `test_update_senza_cambio_tipologia_nessun_avviso` — update priorità, e update con stessa tipologia → `assertNothingSent()`.
  - `test_creazione_via_api_avvisa` — POST `/api/segnalazioni` con token Sanctum → gestore riceve.
  - `test_demo_non_invia_email` — `artisan demo` → `assertNothingSent()`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implementare.
- [ ] **Step 4:** Run filtro + `--filter=Segnalazione` → PASS.
- [ ] **Step 5:** Commit `feat(avvisi): email ai gestori per nuove segnalazioni`.

### Task 6 (B2): Avvisi SLA per tipologia, admin esclusi

**Files:**
- Modify: `app/Console/Commands/CheckSlaViolazioni.php`
- Test: `tests/Feature/AvvisiSlaTipologiaTest.php` (verificare anche i test SLA esistenti: `grep -rln "sla:check\|SlaViolazione" tests`)

**Interfaces:**
- Consumes: `DestinatariTipologia::perSlaScaduto()`, `perSlaArischio()` (B1).
- Produces: violazione → `SlaViolazioneNotification` a `perSlaScaduto` **sempre** (rimossa la condizione `digest_enabled`/priorità 4); a rischio → `SlaWarningNotification` a `perSlaArischio`. Rimossa la query `$supervisori` (bug `orWhere` + admin).

- [ ] **Step 1: Test**:
  - `test_sla_scaduto_a_gestori_tipologia_e_supervisori_non_admin` — admin puro (ruolo admin, non gestore) non riceve.
  - `test_sla_scaduto_immediato_anche_con_digest` — `digest_enabled` true, priorità 2 → inviata.
  - `test_sla_a_rischio_solo_gestori_tipologia` — supervisore non riceve; se orfana → supervisore riceve.
  - `test_supervisore_disattivato_non_riceve`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implementare; aggiornare `$description` del comando.
- [ ] **Step 4:** Run filtro + test SLA esistenti → PASS (adeguare quelli che assumevano admin/digest, citandolo nel commit).
- [ ] **Step 5:** Commit `feat(sla): avvisi ai gestori della tipologia, admin esclusi`.

### Task 7 (B3): Chiusura fase B

- [ ] Suite + analyse verdi; 0.9.12 + CHANGELOG (Added avviso nuova/riclassificata; Changed SLA scaduto sempre immediato, admin esclusi; Fixed supervisori disattivati avvisati).
- [ ] CLAUDE.md (funzionalità: avvisi per tipologia).
- [ ] PR → merge → tag `v0.9.12` → release verde.

---

## Fase C — Pre-registrazione utenti AD (0.9.13)

Branch: `feat/preregistrazione-ad`.

### Task 8 (C1): `Directory::cerca`

**Files:**
- Create: `app/Services/Directory/EsitoRicerca.php`
- Modify: `app/Services/Directory/{Directory,LdapRecordDirectory,MockDirectory,NullDirectory}.php`, `tests/Support/FakeDirectory.php`, `tests/Unit/PasswordNonNeiLogTest.php`
- Test: `tests/Unit/MockDirectoryCercaTest.php`

**Interfaces:**
- Produces:
  - `enum EsitoRicerca: string { case Trovato; case NonTrovato; case CredenzialiErrate; case RicercaNegata; case NonDisponibile; case NonConfigurata; }` + `final class RisultatoRicerca(EsitoRicerca $esito, ?DirectoryIdentity $identita = null, ?string $motivo = null)` nello stesso file `EsitoRicerca.php` o file proprio `RisultatoRicerca.php` (a scelta, stesso namespace).
  - `Directory::cerca(string $chi, #[\SensitiveParameter] string $password, string $username): RisultatoRicerca` — bind come `$chi`, cerca `samaccountname = $username` (valore con `ldap_escape(..., LDAP_ESCAPE_FILTER)`), stessi attributi e gruppi annidati di `authenticate()`. Bind codice 49 → `CredenzialiErrate`; eccezione di connessione → `NonDisponibile`; errore di ricerca per permessi (LDAP 50 insufficientAccessRights) → `RicercaNegata`; nessuna entry → `NonTrovato`. Mai eccezioni verso il chiamante.
  - Estrarre in `LdapRecordDirectory` un metodo privato condiviso con `authenticate()` per connessione e lettura identità+gruppi.
  - `MockDirectory::cerca`: `$chi`/`$password` validi come in `authenticate`; cerca tra `UTENTI` più due nuovi utenti mai entrati `mock.nuovo.gestore` (PRONTOPA_GESTORI) e `mock.nuovo.operaio` (PRONTOPA_OPERAI), aggiunti anche alla mappa di `authenticate`.
  - `NullDirectory::cerca` → `NonConfigurata`.
  - `FakeDirectory::cerca` usa gli utenti registrati con `aggiungi()`, rispetta `$nonDisponibile`, nuova proprietà `public bool $ricercaNegata = false`, incrementa `$bind`.
  - Righe nuove in `PasswordNonNeiLogTest`: `Directory::cerca`, `LdapRecordDirectory::cerca`, `MockDirectory::cerca`, `NullDirectory::cerca`.

- [ ] **Step 1: Test** `MockDirectoryCercaTest`: admin valido trova `mock.nuovo.gestore` con gruppo PRONTOPA_GESTORI; password admin errata → `CredenzialiErrate`; username inesistente → `NonTrovato`.
- [ ] **Step 2:** Run → FAIL. **Step 3:** Implementare. **Step 4:** Run `--filter='MockDirectoryCerca|PasswordNonNeiLog|Ldap'` → PASS.
- [ ] **Step 5:** Commit `feat(ad): ricerca utente con credenziali dell'admin`.

### Task 9 (C2): Pagina pre-registrazione (solo admin)

**Files:**
- Create: `app/Http/Controllers/Admin/PreregistrazioneController.php`, `app/Services/Auth/PreregistrazioneAd.php`, `resources/views/admin/utenti/preregistra.blade.php`
- Modify: `routes/web.php` (gruppo admin), `resources/views/admin/utenti/index.blade.php` (pulsante + badge), `app/Http/Controllers/Admin/UtentiController.php` (commento "niente creazione" aggiornato), `tests/Unit/PasswordNonNeiLogTest.php`
- Test: `tests/Feature/PreregistrazioneAdTest.php`

**Interfaces:**
- Consumes: `Directory::cerca` (C1), `MappaGruppiLdap::risolvi()`, `NormalizzaUsernameAd::normalizza()`, `CoperturaTipologie` / pivot (A1), `Squadra::membri()`.
- Produces:
  - Rotte admin: `GET utenti/preregistra` (`admin.utenti.preregistra`), `POST utenti/preregistra/cerca` (`…cerca`, ritorna la stessa vista con il risultato, nessuna scrittura), `POST utenti/preregistra` (`…store`). Dichiarare **prima** di `Route::resource('utenti')`.
  - `PreregistrazioneAd::cerca(User $admin, #[\SensitiveParameter] string $password, string $username): RisultatoRicerca` — rate limit: chiave `'login-account|ad:'.mb_strtolower($admin->username)` con `config('auth.login')` (`max_tentativi_account`, `blocco_account_minuti`): se bloccato → `ValidationException` con `auth.throttle`; su `CredenzialiErrate` → `RateLimiter::hit`. Admin con `auth_source !== 'ldap'` → `NonConfigurata` senza bind.
  - `PreregistrazioneAd::crea(array $dati, ?DirectoryIdentity $identita): User` — rifiuta (`ValidationException` su `username`) se esiste già un utente con `LOWER(username)` uguale o con lo stesso `ldap_guid`. Con identità: `auth_source=ldap`, `ldap_guid`, `username`, `name`, `email` (minuscolo), ruolo/flag da `MappaGruppiLdap` (stessi campi di `LdapLoginService::sincronizza`), `last_login=null`, `attivo=true`, `approval_status=approved`. Senza identità (fiducia): `ldap_guid=null`, `name=username`, `email=null`, ruolo scelto (`gestore`|`operaio`|`segnalatore`; segnalatore → `id_provenienza` 1). Poi tipologie (se gestore) e squadra (`membri()->syncWithoutDetaching`) se operaio. `Log::info('Pre-registrazione AD', ['admin_id', 'username', 'verificato' => bool])`.
  - Il form di salvataggio non porta la password: l'identità trovata viaggia firmata (`encrypt()` del payload identità in un campo hidden) e viene decifrata in `store`; fallback esplicito con checkbox "Salva senza verifica AD" + ruolo obbligatorio.
  - Messaggi per esito: `CredenzialiErrate` "Password errata: riprova." (nessun fallback offerto); `NonTrovato` "Nessun utente <x> in AD: probabile refuso. Puoi salvarlo comunque senza verifica."; `RicercaNegata`/`NonDisponibile`/`NonConfigurata` "Impossibile leggere AD (<motivo breve>): puoi salvarlo senza verifica."; trovato senza gruppi ProntoPA → avviso "al login sarà rifiutato".
  - Elenco utenti: badge "in attesa del primo accesso" (`auth_source=ldap` e `last_login` null) e "non verificato in AD" (anche `ldap_guid` null).

- [ ] **Step 1: Test** (`FakeDirectory` legato nel container come in `tests/Feature/*Ldap*`):
  - `test_solo_admin` — supervisore GET → 403.
  - `test_cerca_mostra_ruolo_risultante` — POST cerca → `assertSee('gestore')`, nessun utente creato.
  - `test_password_errata_conta_nel_blocco_e_non_offre_fallback` — 5 tentativi errati → al 6° messaggio throttle; `assertDontSee('senza verifica')`.
  - `test_salva_verificato_con_tipologie` — store con identità cifrata + tipologie [1] → user con `ldap_guid`, pivot {1}, `last_login` null.
  - `test_salva_sulla_fiducia_operaio_in_squadra` — store fallback `ruolo=operaio`, `id_squadra` → user `ldap_guid` null, `email` null, membro squadra.
  - `test_username_duplicato_rifiutato` — esiste `mario.rossi` → errore, conteggio utenti invariato.
  - Riga in `PasswordNonNeiLogTest` per `PreregistrazioneAd::cerca` (il controller legge la password dalla `Request` e la passa subito al servizio, senza metodi propri con parametro password).
  - `test_preregistrato_mai_entrato_eliminabile` — `DELETE admin.utenti.destroy` su un pre-registrato → rimosso (verificare che `UtentiController::destroy` lo consenta; se oggi disattiva soltanto, per `last_login` null elimina davvero, pivot in cascata).
- [ ] **Step 2:** Run → FAIL. **Step 3:** Implementare. **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit `feat(utenti): pre-registrazione utenti AD`.

### Task 10 (C3): Aggancio al primo login

**Files:**
- Modify: `app/Services/Auth/LdapLoginService.php:55-70` (`sincronizza`)
- Test: `tests/Feature/LoginPreregistratoTest.php`

**Interfaces:**
- Consumes: account creati da C2.
- Produces: ordine di ricerca `ldap_guid` → `preregistratoDaAgganciare($identita->username)` (privato: `auth_source='ldap'`, `ldap_guid` null, `last_login` null, `LOWER(username)` uguale, esattamente uno) → legacy email → nuovo. Il controllo `usernameOccupato` esclude l'utente trovato (già così: `whereKeyNot`). Tipologie e squadre restano.

- [ ] **Step 1: Test**:
  - `test_preregistrato_fiducia_agganciato_al_primo_login` — pre-registrato `mario.rossi` (fiducia, ruolo gestore, pivot {1}) → login AD → stesso id, `ldap_guid` valorizzato, email da AD, pivot {1} intatta.
  - `test_preregistrato_verificato_agganciato_per_guid`.
  - `test_ruolo_ad_vince_sul_provvisorio` — pre-registrato gestore, AD lo mette in OPERAI → ruolo operaio, pivot conservata, `copreTipologia(1)` false.
  - `test_guid_esistente_vince_sul_preregistrato` (Review Focus 5) — esiste un account con il GUID della persona (username vecchio `m.rossi.old`) e un pre-registrato fiducia `m.rossi`; AD restituisce GUID noto + username `m.rossi`. Regola: trovato per GUID → si usa quello; lo username `m.rossi` è del pre-registrato → `usernameOccupato` → login rifiutato con il messaggio esistente e `Log::warning`. Il test verifica il rifiuto e che il pre-registrato resti identico (l'admin lo elimina e la persona rientra).
- [ ] **Step 2:** Run → FAIL. **Step 3:** Implementare. **Step 4:** Run `--filter='Login|Ldap'` → PASS.
- [ ] **Step 5:** Commit `feat(auth): aggancio account pre-registrati al primo login`.

### Task 11 (C4): Chiusura fase C

- [ ] Suite + analyse verdi; 0.9.13 + CHANGELOG.
- [ ] CLAUDE.md: sezione Accesso (pre-registrazione, aggancio per username dei pre-registrati, utenti mock `mock.nuovo.*`), riga `Directory::cerca`.
- [ ] PR → merge → tag `v0.9.13` → release verde.
