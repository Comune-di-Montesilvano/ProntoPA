# Redesign segnalazioni (0.9.0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Scheda segnalazione in tre letture con prossimo passo e Attività, Accorpa/Collega veri, fine lavori per ditta/operaio, Sollecita, elenchi rifatti coi token ProntoPA — rilascio unico 0.9.0.

**Architecture:** Logica di presentazione in classi `App\Support\Segnalazioni\*` (testabili senza HTML): `LetturaScheda`, `ProssimoPasso`, `Attivita`, `Scadenza`. Nuove operazioni in `App\Services\Segnalazioni\*` (`AccorpamentoService`, `DestinatariAssegnatario`, `FineLavoriService`). Visibilità unica: `Segnalazione::scopeVisibileA` è la regola, `SegnalazionePolicy::view` la interroga. Viste Blade a partial + componenti `x-pa.*`, Alpine per pannello laterale e ricerche.

**Tech Stack:** Laravel 13, PHP 8.4, Blade, Alpine.js, Tailwind 4 (token `resources/css/app.css`), Spatie Permission, PHPUnit, Larastan, Dusk (solo CI).

**Spec:** `docs/superpowers/specs/2026-10-25-segnalazioni-redesign-design.md` (+ decisione committente 25/10: un solo rilascio **0.9.0**, non 0.8.23–26).

## Global Constraints

- Versione **0.9.0**: `publiccode.yml` `softwareVersion: 0.9.0` + CHANGELOG `## [0.9.0] - <data merge>` nella stessa PR.
- Niente CDN, niente nuove librerie npm/composer. Icone: SVG inline o Font Awesome già incluso. Niente emoji come icone.
- Colori solo dai token `--ente-primary*`, `--slate-*`, `--amber*`, `--rose*`, `--emerald*`, `--violet*` (via classi `.pa-*` nuove in `app.css` o `style`/arbitrary Tailwind `[var(--…)]`).
- Testi in italiano, sentence case, niente maiuscoletto nei bottoni.
- Autorizzazione via Policy (`SegnalazionePolicy`), mai ruoli nel controller.
- Feature test sui POST web: `withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class])`.
- `AllineamentoSeeder` idempotente: nessuna riga di riferimento cancellata (l'azione `segna_duplicata` resta a DB, viene solo esclusa).
- Larastan pulito: relazioni con generics (`@return HasMany<X, $this>`), Builder con `@param Builder<X>`.
- Workflow stati invariato: nessuna nuova transizione oltre a quanto scritto qui.
- Il segnalante non vede mai: nomi di persone interne, priorità, ditta/appalto, scadenza SLA, note non `visibile_web` altrui. La ditta non vede: note interne, evidenza/riservata, modifica priorità, suggerimenti AI.

## Review Focus

1. Segnalante SPID di una scuola che apre la segnalazione di un collega dello stesso plesso: lettura segnalante, vede le note `visibile_web` del collega ma nessun nome interno.
2. Partecipante da accorpamento senza ruolo segnalatore-scuola (adesione con `id_utente`): deve poter aprire la vecchia e scriverci, e non vedere le altre segnalazioni della scuola. → test in Task 1.
3. Ditta con delegato revocato (`attivo=false`) o ditta disattivata: nessuna email da Sollecita/Accorpa/Fine lavori. → test in Task 4.
4. Accorpare una segnalazione la cui destinazione è a sua volta Duplicata o chiusa: rifiuto con messaggio; accorpare a catena risale alla madre. → test in Task 6.
5. Chiudi da gestore dopo che la ditta ha comunicato fine lavori: non deve richiedere di nuovo foto/ore/materiali. → test in Task 5.

---

### Task 1: Visibilità unica (operaio, partecipanti) e permessi nuovi

**Files:**
- Modify: `app/Models/Segnalazione.php` (`scopeVisibileA`), `app/Policies/SegnalazionePolicy.php`
- Test: `tests/Feature/VisibilitaSchedaTest.php`

**Interfaces:**
- Produces: `SegnalazionePolicy::view` = `Segnalazione::visibileA($user)->whereKey($s->getKey())->exists()`; nuove ability `comunicaFineLavori(User, Segnalazione)`, `sollecita(User, Segnalazione)`, `accorpa(User, Segnalazione)`; `scopeVisibileA` include le segnalazioni con `adesioni.id_utente = $user->id` per ogni ruolo non-staff (segnalatore, SPID, generico).

- [ ] **Step 1: Write the failing tests** — `test_operaio_apre_scheda_assegnata` (assertOk), `test_operaio_non_apre_altrui` (403), `test_caposquadra_apre_lavori_squadra`, `test_partecipante_apre_segnalazione_a_cui_ha_aderito` (segnalatore generico con adesione `id_utente` → 200 su quella, 403 su un'altra dello stesso plesso), `test_segnalatore_scuola_vede_plesso_coerente_con_elenco` (utente con profilo `limita_istituto`: ciò che `visibileA` elenca si apre), `test_comunica_fine_lavori_ditta_e_operaio_assegnati` (Gate::forUser()->allows: ditta della segnalazione in stato 4 → true; stato 7 → false; operaio assegnato stato 3 → true; gestore → false), `test_sollecita_e_accorpa_solo_comune` (gestore supervisore true; segnalatore/ditta false).
- [ ] **Step 2: Run** `docker compose exec -T php php artisan test tests/Feature/VisibilitaSchedaTest.php` — Expected: FAIL (operaio 403, partecipante 403, ability inesistenti).
- [ ] **Step 3: Implement.** `view`: admin → true; altrimenti exists sulla scope. `comunicaFineLavori`: segnalazione aperta, stato ∈ {3,4}, e (impresa con `appalto.id_impresa === user.id_impresa`) o (operaio e `visibileA` la include). `sollecita`: `modificaPriorita` && stato ∈ {3,4,5} && aperta. `accorpa`: `modificaPriorita` && aperta. Scope: in fondo ai rami segnalatore/SPID/generico aggiungere `orWhereHas('adesioni', fn ($q) => $q->where('id_utente', $user->id))` (dentro un `where(function…)` che avvolge la condizione del ramo; per SPID bloccato resta `1=0`).
- [ ] **Step 4: Run** test file + `tests/Feature/Deleghe tests/Feature/ModificaPrioritaTest.php tests/Feature/VisibilitaNoteTest.php` — Expected: PASS.
- [ ] **Step 5: Commit** `feat(policy): single visibility rule, operaio and participants`.

### Task 2: Supporti di presentazione — stato, scadenza, lettura, componenti

**Files:**
- Modify: `app/Enums/SegnalazioneStato.php`, `resources/css/app.css`, `database/seeders/ImpostazioniSeeder.php`
- Create: `app/Support/Segnalazioni/Scadenza.php`, `app/Support/Segnalazioni/LetturaScheda.php`, `resources/views/components/pa/{pill,prio,scadenza,btn,card,drawer}.blade.php`
- Test: `tests/Unit/Segnalazioni/PresentazioneTest.php`

**Interfaces:**
- Produces:
  - `SegnalazioneStato::tono(): string` → `slate|primary|violet|amber|emerald` (Nuova slate, In carico primary, Assegnata operatore/impresa violet, Preventivo amber, Sospesa amber, Completata emerald, Duplicata/Annullata/Archiviata slate); `etichetta(): string` = `label()` tranne DUPLICATA → `Accorpata`; `fraseSegnalante(): array{titolo:string, dettaglio:string}` (tabella spec §4.4); `tappa(): int` 1–4 (1 Nuova; 2 In carico; 3 Assegnate/Preventivo/Sospesa; 4 terminali).
  - `Scadenza::per(Segnalazione $s, ?Carbon $ora = null): ?array{testo:string, tono:'rose'|'amber'|'slate'}` — null se chiusa o senza `data_scadenza_sla`; scaduta → `"Superata da N g"`/`"Superata da N h"` rose; ≤24h → `"Entro N h"` amber; altrimenti `"Entro N g"` slate. `Scadenza::estesa()` stessa logica in frase lunga ("Tempo massimo superato da 2 giorni", "Scade tra 6 ore", "Scade il 23/10").
  - `LetturaScheda::per(User $u): 'gestore'|'lavoro'|'segnalante'` (admin/gestore → gestore; operaio/impresa → lavoro; altri → segnalante).
  - Impostazione `etichetta_ufficio_segnalante` (gruppo brand, default `Ufficio manutenzioni`) in `ImpostazioniSeeder` (insertOrIgnore come le altre).
  - Componenti: `<x-pa.pill :tono="'violet'">`, `<x-pa.prio :livello="4" :urgente="true"/>`, `<x-pa.scadenza :segnalazione="$s" estesa/>`, `<x-pa.btn variant="primary|secondary|ghost" :href=null>`, `<x-pa.card>`, `<x-pa.drawer name="…" title="…">` (Alpine: `$dispatch('apri-pannello', 'name')`).
- [ ] **Step 1: Write failing unit tests** per `tono`, `etichetta` (DUPLICATA → Accorpata), `fraseSegnalante` (COMPLETATA → titolo "Risolta"), `tappa`, `Scadenza::per` con ora fissata (scaduta 50h → "Superata da 2 g" rose; +6h → "Entro 6 h" amber; +4g → "Entro 4 g" slate; chiusa → null), `LetturaScheda::per` per i 5 ruoli.
- [ ] **Step 2: Run** `php artisan test tests/Unit/Segnalazioni/PresentazioneTest.php` — Expected: FAIL (metodi/classi mancanti).
- [ ] **Step 3: Implement** enum/classi/seeder; CSS: `.pa-pill-*` esistono già → aggiungere `.pa-tono-*` testo/sfondo se servono, `.pa-btn` già esiste; componenti Blade che usano queste classi. `badgeClass()` resta (usato altrove finché i task 8–10 non lo sostituiscono; rimuoverlo in Task 10 se non più referenziato).
- [ ] **Step 4: Run** test — Expected: PASS. `npm run build` OK.
- [ ] **Step 5: Commit** `feat(ui): presentation helpers and pa components`.

### Task 3: Attività e note per ruolo

**Files:**
- Create: `app/Support/Segnalazioni/Attivita.php`, `app/Notifications/NotaDaEsternoNotification.php`
- Modify: `app/Http/Controllers/SegnalazioneController.php` (`aggiungiNota`, `notificaNota`)
- Test: `tests/Feature/AttivitaTest.php`

**Interfaces:**
- Produces: `Attivita::per(Segnalazione $s, User $u): Collection<int, array{quando:Carbon, autore:string, iniziali:string, tipo:'nota'|'stato'|'allegato', testo:?string, frase:string, tag:?string, allegato:?AllegatoSegnalazione}>` ordinata dalla più recente. Fonti: `note` filtrate `visibiliA($u)`, `storicoStati`, `allegati`. Per lettura segnalante: autore di nota/evento interno (staff o ditta) = `Impostazione::get('etichetta_ufficio_segnalante')`; eventi stato come frase neutra da `stato->fraseSegnalante()['titolo']`; allegati solo quelli caricati da sé o con `fase` ≠ null? → regola: invariata rispetto a oggi (tutti gli allegati visibili a chi vede la scheda). Per lettura lavoro: eventi stato solo da quello di assegnazione (primo record stato 3/4) in poi. Gestore: tutto, frasi "X ha assegnato a Y" ecc. (`id_utente_collegato` → nome; `id_appalto` → ditta).
- `aggiungiNota`: flag forzati per ruolo — impresa → `visibile_impresa=true, visibile_web=false`; lettura segnalante → `visibile_web=true, visibile_impresa=false`; comune → come da form. Accetta anche `allegati[]` (stesse regole/mime di `AllegatiSegnalazioniController::store`, `fase` null) per "Allega"/"Aggiungi foto".
- `notificaNota`: se l'autore è ditta o lettura segnalante → `NotaDaEsternoNotification` al gestore assegnatario (`id_operatore_assegnato` se ha ruolo gestore/admin) altrimenti a tutti i gestori supervisori attivi (`User::role('gestore')->where('supervisore_segnalazioni', true)`); resto invariato.
- [ ] **Step 1: Failing tests** — `test_segnalante_vede_ufficio_non_nomi` (gestore assegna operaio; `Attivita::per($s, $segnalatore)` non contiene il nome del gestore né dell'operaio, contiene "Ufficio manutenzioni"), `test_ditta_vede_eventi_da_assegnazione`, `test_gestore_vede_frase_assegnazione` ("ha assegnato a Paolo Neri"), `test_nota_ditta_forzata_visibile_impresa`, `test_nota_segnalante_forzata_visibile_web_e_avvisa_supervisori` (Notification::fake, assertSentTo supervisore), `test_nota_segnalante_avvisa_gestore_assegnatario_se_presente`, `test_nota_con_foto_salva_allegato`.
- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** test + `tests/Feature/VisibilitaNoteTest.php tests/Feature/SegnalazioneWorkflowNotificationsTest.php` — Expected: PASS.
- [ ] **Step 5: Commit** `feat(attivita): unified activity feed, role-aware notes`.

### Task 4: Destinatari assegnatario + Sollecita

**Files:**
- Create: `app/Services/Segnalazioni/DestinatariAssegnatario.php`, `app/Notifications/SollecitoNotification.php`
- Modify: `app/Services/SegnalazioneWorkflowService.php` (usa il servizio nel ramo appalto), `SegnalazioneController` (+`sollecita`), `routes/web.php` (`POST segnalazioni/{segnalazione}/sollecita` → `segnalazioni.sollecita`)
- Test: `tests/Feature/SollecitoTest.php`

**Interfaces:**
- Produces: `DestinatariAssegnatario::per(Segnalazione $s, ?User $escludi = null): array{utenti: Collection<int, User>, email: list<string>}` — squadra → caposquadra attivo; operaio → operatore attivo; ditta attiva → delegati `impresa` attivi + email aziendale se diversa (dedup lower/trim); ditta disattivata → vuoto. Stessa regola già in `inviaNotifiche` (0.8.19), estratta.
- `POST sollecita` (`testo` opzionale, default "Sollecito: aggiornare sullo stato del lavoro."): `authorize('sollecita')`, nota `visibile_impresa = (bool) id_appalto`, `visibile_web=false`, testo prefissato "Sollecito: ", invia `SollecitoNotification` a utenti + email. Redirect alla scheda con flash.
- [ ] **Step 1: Failing tests** — sollecito a ditta (2 delegati attivi + email aziendale, 1 delegato `attivo=false` escluso, email duplicata non doppia), a operaio, a squadra (caposquadra), ditta disattivata → nessuna notifica, nota creata con visibilità giusta, segnalatore 403. Più regressione: `tests/Feature/NotificheDittaTest.php` invariato.
- [ ] **Step 2: Run** — FAIL. **Step 3: Implement.** **Step 4: Run** test + `NotificheDittaTest SquadreTest` — PASS.
- [ ] **Step 5: Commit** `feat(segnalazioni): sollecita assignee`.

### Task 5: Fine lavori (ditta/operaio) e chiusura coerente

**Files:**
- Create: `app/Services/Segnalazioni/FineLavoriService.php`, `app/Notifications/FineLavoriNotification.php`
- Modify: `SegnalazioneController` (`eseguiAzione` regole `chiudi`, +`fineLavori`), `routes/web.php` (`POST segnalazioni/{segnalazione}/fine-lavori` → `segnalazioni.fine-lavori`)
- Test: `tests/Feature/FineLavoriTest.php`

**Interfaces:**
- Produces: `POST fine-lavori` (`ore_lavoro` numeric≥0, `materiali` string≤1000, `nota` string≤2000 required, `allegati[]` required min1 con le regole di `chiudi`): `authorize('comunicaFineLavori')`; nota "Fine lavori comunicata:\n- Ore impiegate: …\n- Materiali usati: …\n\nDescrizione:\n…" `visibile_impresa=true`; allegati `fase='dopo'`; avvisa il comune (stessi destinatari di `NotaDaEsternoNotification`, Task 3) con `FineLavoriNotification`. Nessun cambio stato.
- `Segnalazione::haFineLavori(): bool` = esistono allegati `fase='dopo'`.
- `eseguiAzione` `chiudi`: se `haFineLavori()` → `ore_lavoro`, `materiali`, `allegati` diventano `nullable`, `nota` nullable; altrimenti regole attuali.
- [ ] **Step 1: Failing tests** — ditta comunica fine lavori (stato invariato 4, 1 allegato `dopo`, nota visibile impresa, supervisore notificato), operaio assegnato idem, senza foto → errore `allegati`, ditta su segnalazione chiusa → 403, `test_chiudi_dopo_fine_lavori_non_richiede_rapportino` (gestore POST azione 9 senza campi → stato 7), `test_chiudi_senza_fine_lavori_richiede_rapportino` (errori ore/materiali/allegati).
- [ ] **Step 2: Run** — FAIL. **Step 3: Implement.** **Step 4: Run** + `SegnalazioneWorkflowNotificationsTest` — PASS.
- [ ] **Step 5: Commit** `feat(segnalazioni): end-of-work report for ditta and operaio`.

### Task 6: Accorpa (sostituisce Segna come duplicata e Unisci)

**Files:**
- Create: `app/Services/Segnalazioni/AccorpamentoService.php`, `app/Notifications/AccorpataNotification.php`
- Modify: `SegnalazioneController` (rimuovere `unisci`, +`accorpa`, +`candidatiAccorpa`), `app/Services/SegnalazioneWorkflowService.php` (`getAzioniDisponibili` esclude `codice` `segna_duplicata`; rimuovere il ramo `segna_duplicata` in `eseguiTransizione`), `app/Services/DedupService.php` (`trovaSimili(..., bool $soloSeAttivo = true)`), `routes/web.php` (via `segnalazioni.unisci`; + `POST segnalazioni/{segnalazione}/accorpa` → `segnalazioni.accorpa`, `GET segnalazioni/{segnalazione}/candidati-accorpa` → `segnalazioni.candidati-accorpa`)
- Delete: `tests/Feature/MergeSegnalazioniTest.php` (sostituito)
- Test: `tests/Feature/AccorpaTest.php`

**Interfaces:**
- Produces: `AccorpamentoService::accorpa(Segnalazione $nuova, Segnalazione $destinazione, User $u): Segnalazione` (ritorna la destinazione effettiva). Se `$destinazione` è DUPLICATA con madre → risale alla madre (max 5 salti). Rifiuti (`ValidationException` su `id_destinazione`): stessa, destinazione chiusa, nuova chiusa. Transazione: nuova → stato 8, `id_segnalazione_madre`, `data_chiusura`, storico; allegati e adesioni → destinazione; adesione per il segnalante della nuova (`id_utente` o anagrafica; nessun duplicato se già aderente o se è il segnalante della destinazione); nota sulla destinazione "Segnalata di nuovo da {nome o segnalante} il d/m/Y:\n{testo nuova}" `visibile_web=false`, `visibile_impresa=(bool) id_appalto`; nota sulla nuova "Era già stata segnalata: segui gli aggiornamenti nella #{id}." `visibile_web=true`. Dopo commit: `AccorpataNotification` all'assegnatario (`DestinatariAssegnatario`) o, se nessuno, ai supervisori; `NuovaNotaSegnalazione` (o `AccorpataNotification` variante segnalante) al segnalante della nuova se utente attivo ≠ attore, o via mail a `email` anagrafica.
- `Segnalazione::volteSegnalata(): int` = 1 + `adesioni` count; `isSollecitata(): bool` = aperta && volte > 1.
- `GET candidati-accorpa?q=` → JSON `[{id, titolo, sede, stato, data}]`: senza `q` → `DedupService::trovaSimili(tipologia, plesso, lat, lng, testo, 8, soloSeAttivo:false)` escludendo sé; con `q` → `visibileA` aperte, id = q o testo LIKE, max 10. `authorize('accorpa')`.
- [ ] **Step 1: Failing tests** — porting dei casi di `MergeSegnalazioniTest` (allegati e adesioni spostati, chiusa rifiutata, sé stessa rifiutata, permesso su entrambe) + `madre_impostata`, `nota_con_testo_sulla_vecchia`, `nota_pubblica_sulla_nuova`, `catena_risale_alla_madre`, `assegnatario_ditta_notificato`, `segnalante_nuova_notificato`, `partecipante_apre_vecchia` (con Task 1), `segna_duplicata_non_piu_tra_azioni`, `candidati_json_include_simile_ed_esclude_se_stessa`, `volte_segnalata_e_sollecitata`.
- [ ] **Step 2: Run** — FAIL. **Step 3: Implement.** **Step 4: Run** + `AdesioniTest DedupTest AzioniRapideTest` — PASS.
- [ ] **Step 5: Commit** `feat(segnalazioni): accorpa replaces duplicate and merge`.

### Task 7: Collega a precedente (guasto ricorrente)

**Files:**
- Modify: `SegnalazioneController` (+`candidatiCollega`), `routes/web.php` (`GET segnalazioni/{segnalazione}/candidati-collega` → `segnalazioni.candidati-collega`), `app/Models/Segnalazione.php`
- Test: `tests/Feature/CollegaTest.php`

**Interfaces:**
- Produces: `GET candidati-collega?q=` JSON come sopra: senza `q` → `visibileA`, chiuse (`data_chiusura` not null, stato 7), stesso `id_plesso` (se presente) e stessa tipologia, `data_chiusura >= now()-24 mesi`, max 8, più recenti prima; con `q` → chiuse per id/testo. `authorize('update')`.
- `Segnalazione::riparazionePrecedente(): ?array{id:int, chiusa_il:Carbon, da:string, giorni:int}` dalla `segnalazioneCorrelata` (da = ditta dell'appalto o nome operatore o "—"). `isRicorrente(): bool` = `id_segnalazione_correlata` not null.
- Azione `collega` invariata nel workflow.
- [ ] **Step 1: Failing tests** — candidati: include chiusa stessa sede/tipologia, esclude aperta, esclude altra sede, esclude >24 mesi; `riparazione_precedente` con ditta; collega via azione imposta correlata e la nuova resta aperta.
- [ ] **Step 2–4** come sopra. **Step 5: Commit** `feat(segnalazioni): recurring fault link candidates and details`.

### Task 8: Prossimo passo

**Files:**
- Create: `app/Support/Segnalazioni/ProssimoPasso.php`
- Test: `tests/Unit/Segnalazioni/ProssimoPassoTest.php` (con DB: `Tests\TestCase` + RefreshDatabase)

**Interfaces:**
- Consumes: `Scadenza`, `LetturaScheda`, `SegnalazioneWorkflowService::getAzioniDisponibili`, ability `sollecita|comunicaFineLavori|accorpa|modificaPriorita`, `haFineLavori`, `isSollecitata`, `volteSegnalata`.
- Produces: `ProssimoPasso::per(Segnalazione $s, User $u): array{tono:'rose'|'amber'|'primary'|'slate', titolo:string, sottotitolo:?string, azioni: list<array{tipo:'azione'|'pannello'|'post'|'link', etichetta:string, id_azione?:int, pannello?:string, route?:string, primaria:bool}>}`. Tabelle della spec §5, più: stato 4 con `haFineLavori()` → gestore "{ditta} ha comunicato la fine dei lavori il d/m: verifica e chiudi" [Chiudi (pannello chiudi)]; lavoro con `haFineLavori()` → "Fine lavori comunicata il d/m: in attesa di verifica del comune". Sollecitata → prefisso titolo "Segnalata N volte · " e azione [Alza priorità → pannello priorita] se `modificaPriorita`. Mai un'azione non autorizzata/non disponibile.
- [ ] **Step 1: Failing tests** — un test per riga della tabella gestore (Nuova, In carico, Assegnata operatore in ritardo → tono rose + "è in ritardo di 2 giorni", Assegnata impresa, Preventivo, Sospesa, Completata), ditta assegnata (azione fine lavori primaria), ditta dopo fine lavori, segnalante (titolo = fraseSegnalante), sollecitata, gestore non supervisore non assegnato → nessuna azione.
- [ ] **Step 2–4.** **Step 5: Commit** `feat(scheda): next step per role`.

### Task 9: Scheda nelle tre letture + pannello azioni

**Files:**
- Modify: `app/Http/Controllers/SegnalazioneController.php` (`show`), `resources/views/segnalazioni/show.blade.php` (riscritta: testata + switch lettura)
- Create: `resources/views/segnalazioni/scheda/{gestore,lavoro,segnalante,_testata,_prossimo-passo,_avanzamento,_attivita,_composer,_dove,_collegate}.blade.php`, `resources/views/segnalazioni/scheda/pannelli/{assegna,chiudi,preventivo,sospendi,annulla,accorpa,collega,fine-lavori,priorita,sollecita,generica}.blade.php`
- Modify: `tests/Browser/CambioStatoTest.php` (nuovi selettori: `@prossimo-passo` / bottone "Prendi in carico")
- Test: `tests/Feature/SchedaLettureTest.php`

**Interfaces:**
- Consumes: Task 1–8. `show` passa `lettura`, `prossimoPasso`, `attivita`, `azioniDisponibili`, `operatori`, `squadre` (se `squadre_enabled`), `appaltiValidi` (per il pannello assegna: `Appalto::where('valido', true)->with('impresa')`, filtro per gruppo della tipologia se `appalti.id_gruppo` = `tipologia.id_gruppo`, altrimenti tutti), `fotoPrima`, `fotoDopo`.
- Ogni pannello è un `<x-pa.drawer>` con un form che POSTa alle rotte esistenti (`segnalazioni.azione` con `id_azione`, `segnalazioni.priorita`, `segnalazioni.sollecita`, `segnalazioni.fine-lavori`, `segnalazioni.accorpa`); assegna: radio Operaio/Squadra/Ditta, ricerca appalto lato client (Alpine filtra la lista `appaltiValidi`), riepilogo destinatari email; chiudi: campi rapportino visibili se `! haFineLavori()`; accorpa/collega: Alpine `fetch` su `candidati-*` con debounce 300 ms. Errori di validazione riaprono il pannello giusto (`old('_pannello')`).
- Lettura gestore = spec §4.2; lavoro = §4.3 (colonna max-w-3xl, bottoni ≥44px, "Naviga" `https://www.google.com/maps/search/?api=1&query={lat},{lng}` o indirizzo urlencoded, "Chiama" `tel:` da `plesso->recapiti` se presente); segnalante = §4.4 (composer sempre "Invia al comune", nascosto se Archiviata/Accorpata/Annullata; Accorpata mostra link alla madre se `view` consentito).
- Rimosse dalla scheda: linguette, riquadro "Unisci duplicato", select azione, bottoni colorati in testata. Restano raggiungibili: Stampa, PDF fascicolo, evidenza, riservata (menu ⋯ gestore).
- [ ] **Step 1: Failing tests** — `gestore_vede_prossimo_passo_e_menu` (assertSee titolo prossimo passo, route stampa/fascicolo, `segnalazioni.priorita`), `ditta_non_vede_evidenza_riservata_priorita` (assertDontSee `segnalazioni.evidenza`, `toggle-riservata`, `segnalazioni.priorita`), `ditta_vede_fine_lavori_e_naviga`, `segnalante_non_vede_nomi_interni` (assertDontSee nome operatore e nome ditta, assertSee "Ufficio manutenzioni" quando c'è una nota pubblica del gestore), `segnalante_vede_composer` (`segnalazioni.nota`), `segnalante_accorpata_vede_link_madre`, `operaio_vede_lettura_lavoro`, `scheda_non_contiene_vecchie_linguette` (assertDontSee 'Gestione Segnalazione', 'Unisci duplicato'). Aggiornare `ModificaPrioritaTest::test_scheda_mostra_modulo_solo_a_chi_puo` solo se il markup cambia l'URL atteso (deve continuare a passare).
- [ ] **Step 2: Run** — FAIL. **Step 3: Implement** (partial per partial). **Step 4: Run** test file + `ModificaPrioritaTest VisibilitaNoteTest AttivitaTest AccorpaTest FineLavoriTest SollecitoTest` — PASS; `npm run build`; smoke manuale con Playwright come `mock.admin` su una segnalazione demo (screenshot nello scratchpad).
- [ ] **Step 5: Commit** `feat(scheda): three readings, next step, side panels`.

### Task 10: Elenco gestore

**Files:**
- Modify: `app/Http/Controllers/GestioneController.php`, `resources/views/gestione/dashboard.blade.php` (riscritta), `tests/Feature/ColonnaSedeTest.php` (asserzioni intestazioni), `tests/Feature/StampaSchedeTest.php` (se cambia il testo del bottone)
- Create: `resources/views/segnalazioni/_riga.blade.php` (riga elenco riusabile, parametro `colonne`)
- Test: `tests/Feature/ElencoGestioneTest.php`

**Interfaces:**
- Produces: parametri GET nuovi: `ordine` = `urgenti` (default) | `recenti` | `vecchie`; `filtro` rapido = `scadute` | `entro24h` | `sollecitate` | `ricorrenti`. `urgenti`: `ORDER BY (data_scadenza_sla IS NULL), data_scadenza_sla ASC, livello_priorita DESC, data_segnalazione ASC` (le scadute hanno scadenza più vecchia → prime). `chiuse` ignora `urgenti` (ordina per `data_chiusura` desc). `sollecitate` = aperte con `has('adesioni')`; `ricorrenti` = `whereNotNull('id_segnalazione_correlata')`; `scadute` = aperte `data_scadenza_sla < now()`; `entro24h` = aperte tra now e +24h. `parametriFiltro` include `ordine`, `filtro`. I link delle viste conservano tutti i filtri (oggi ne perdono). Conteggi `da_guardare`: scadute, entro24h, nuove.
- Vista: spec §8 (testata con menu "Stampa ed esporta", riquadri Da guardare nascosti a zero, viste con conteggio, ricerca + pannello Filtri (`<details>` o drawer) + etichette filtri attivi con link di rimozione, 6 colonne, righe-scheda sotto 768px, azioni rapide ⋮ invariate, `Azioni rapide` title mantenuto).
- [ ] **Step 1: Failing tests** — ordine urgenti (scaduta prima di in scadenza prima di senza scadenza), filtro scadute, filtro sollecitate, filtro ricorrenti, cambio vista conserva `id_tipologia` e `livello_priorita` nei link, colonna Scadenza presente, toggle riservata non più nella riga.
- [ ] **Step 2–4** (+ `AzioniRapideTest ColonnaSedeTest StampaSchedeTest RicercaFulltextTest`). **Step 5: Commit** `feat(gestione): list redesign with urgency ordering`.

### Task 11: Elenchi segnalatore, ditta, operaio

**Files:**
- Modify: `app/Http/Controllers/{SegnalatoreDashboardController,ImpreseDashboardController,OperaioDashboardController,SegnalazioneController(index)}.php`, `resources/views/{segnalatore/dashboard,segnalazioni/index,imprese/dashboard,operaio/dashboard}.blade.php`
- Test: `tests/Feature/ElenchiRuoliTest.php`

**Interfaces:**
- Consumes: `_riga.blade.php` (Task 10), componenti `x-pa.*`.
- Segnalatore (`segnalatore.dashboard` e `segnalazioni.index`, stessa vista partial): colonne Segnalazione · Stato (`etichetta()` + `fraseSegnalante` titolo) · Aggiornata; etichetta "Segui" per le segnalazioni visibili solo per adesione; niente priorità/assegnatario.
- Ditta: base = `Segnalazione::visibileA($user)` (include appalti scaduti — corregge il filtro `valido=true` su `$base`; KPI appalti attivi invariati), viste Da fare (aperte) · Preventivi (stato 5) · Chiuse; colonne Priorità · Segnalazione · Entro (Scadenza) · Stato.
- Operaio: viste Assegnate · Squadra (caposquadra) · Chiuse ultimo mese; colonne come ditta; il comando "Affida" del caposquadra resta (form `operaio.smista`).
- [ ] **Step 1: Failing tests** — ditta vede segnalazione su appalto scaduto (`valido=false`), segnalatore vede "Segui" per l'adesione, segnalatore non vede colonna priorità, operaio vede lavoro assegnato con link scheda che risponde 200.
- [ ] **Step 2–4** (+ `ColonnaSedeTest DelegaImpresaTest VisibilitaDelegheTest`). **Step 5: Commit** `feat(ui): role lists with shared components`.

### Task 12: Rilascio 0.9.0

**Files:**
- Modify: `CHANGELOG.md`, `publiccode.yml`, `CLAUDE.md` (Architettura: nuove classi/servizi; Workflow: Accorpa/Collega/Fine lavori/Sollecita; Funzionalità v0.9), spec §11 (rilascio unico 0.9.0), `app/Console/Commands/PopulateDemoData.php` solo se un test demo fallisce.
- [ ] **Step 1:** CHANGELOG `## [0.9.0] - <data>` sezioni Added/Changed/Fixed/Removed (Fixed: Chiudi da scheda che falliva la validazione; operaio 403 sulla scheda; dashboard ditta che nascondeva appalti scaduti; note del segnalante senza avviso. Removed: "Segna come duplicata", "Unisci duplicato").
- [ ] **Step 2:** `publiccode.yml` → `0.9.0`; aggiornare CLAUDE.md.
- [ ] **Step 3:** larastan + suite completa (A e B) + `npm run build` — Expected: tutto verde.
- [ ] **Step 4:** Commit, push, PR, CI verde (test, dusk, semgrep, publiccode), squash merge, tag `v0.9.0`, `gh run watch` sul release.
