# SLA in orario d'ufficio Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Scadenze SLA calcolate solo sull'orario d'ufficio (weekend, festivi nazionali, patrono esclusi), regole in ore o giorni lavorativi, ricalcolo delle aperte, flag urgente eliminato.

**Architecture:** Un servizio puro `App\Services\Sla\CalendarioUfficio` (orario e patrono letti da `Impostazione`, festivi calcolati) usato da `SlaService`. Colonna `unita` su `sla_configurazioni`. Job `RicalcolaScadenzeSla` lanciato da Admin → SLA e Admin → Impostazioni. Rimozione di `segnalazione_urgente` da tutto il codice (colonna lasciata a DB).

**Tech Stack:** Laravel 13, PHP 8.4, Carbon, Blade + Alpine, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-10-26-sla-orario-ufficio-design.md`

## Global Constraints

- Orario default: lun/mer/ven `08:00-14:00`, mar/gio `08:00-17:00`, sab/dom vuoti; patrono default vuoto.
- Festivi: 1/1, 6/1, Pasqua, Lunedì dell'Angelo, 25/4, 1/5, 2/6, 15/8, 1/11, 8/12, 25/12, 26/12 + patrono.
- Timezone app (Europe/Rome). Una fascia per giorno.
- N giorni lavorativi = chiusura dell'N-esimo giorno lavorativo successivo al giorno della segnalazione.
- `ore_warning` sempre in ore lavorative. "Superata" = scadenza nel passato (tempo reale).
- Nuove impostazioni → `ImpostazioniSeeder` (idempotente, gira a ogni avvio; non sovrascrive valori configurati).
- Testi italiani semplici; niente emoji come icone.
- Rilascio patch 0.9.2, CHANGELOG + `publiccode.yml` nella stessa PR, nessuna GitHub Release.

## Review Focus

1. Segnalazione aperta esattamente alla chiusura (es. lun 14:00) o all'apertura: conteggio parte dalla fascia giusta, nessuna scadenza fuori orario.
2. Cambio ora legale (ultima domenica di marzo/ottobre) dentro un intervallo: le ore lavorative restano esatte.
3. Patrono che cade di sabato/domenica o coincide con un festivo nazionale: nessun doppio conteggio, nessun errore.
4. Orario tutto vuoto o malformato in impostazioni: il salvataggio lo rifiuta; il calcolo non va in loop infinito (limite di sicurezza, ritorna null).
5. Ricalcolo che sposta la scadenza nel futuro: `sla_violato`/`sla_warning_inviato` tornano falsi.

---

### Task 1: Calendario dell'ufficio

**Files:**
- Create: `app/Services/Sla/CalendarioUfficio.php`
- Modify: `database/seeders/ImpostazioniSeeder.php` (gruppo `orario`: `orario_lun`…`orario_dom` tipo `text`, `patrono` tipo `text`)
- Test: `tests/Unit/Sla/CalendarioUfficioTest.php`

**Interfaces:**
- Produces: `CalendarioUfficio::__construct(array $orario, ?string $patrono)` (orario = `['lun' => '08:00-14:00', …]`), `static daImpostazioni(): self`, `isFestivo(CarbonInterface $giorno): bool`, `festivi(int $anno): list<Carbon>` (ordinati, patrono incluso), `fascia(CarbonInterface $giorno): ?array{0: Carbon, 1: Carbon}` (null se chiuso o festivo), `aggiungiOreLavorative(CarbonInterface $da, float $ore): ?Carbon`, `aggiungiGiorniLavorativi(CarbonInterface $da, int $giorni): ?Carbon`, `oreLavorativeTra(CarbonInterface $da, CarbonInterface $a): float`, `static valida(string $fascia): bool`, `static validaPatrono(string $gg_mm): bool`. Ritorno null se in 400 giorni non trova fasce aperte.

- [ ] **Step 1: Failing tests** — con orario default: sab 10:00 + 6h → lun 14:00; ven 13:00 + 4h → lun 11:00; mar 16:30 + 1h → mer 08:30; lun 14:00 + 1h → mar 09:00; lun 07:00 + 1h → lun 09:00; giorni: lun 10:00 +1 → mar 17:00, ven 13:00 +1 → lun 14:00, sab +1 → lun 14:00; Pasqua 2026 = 5/4, 2027 = 28/3, 2028 = 16/4 e Pasquetta festive; 2/6 festivo; patrono `13/06` festivo, patrono di domenica non rompe nulla; 25/4/2026 (sabato); intervallo che attraversa 25/10/2026 (fine ora legale) con ore esatte; orario tutto vuoto → null; `oreLavorativeTra(lun 13:00, mar 09:00)` = 2.0; `valida('08:00-14:00')` true, `valida('14:00-08:00')` false, `validaPatrono('31/02')` false, `validaPatrono('29/02')` true.
- [ ] **Step 2: Run** `php artisan test tests/Unit/Sla/CalendarioUfficioTest.php` — Expected: FAIL (classe mancante).
- [ ] **Step 3: Implement** — Pasqua con algoritmo di Gauss/Meeus (niente dipendenza da `ext-calendar`); scorrimento giorno per giorno sulle fasce.
- [ ] **Step 4: Run** stesso comando — Expected: PASS. Larastan pulito.
- [ ] **Step 5: Commit** `feat(sla): office calendar with holidays and patron saint`.

### Task 2: Regole SLA in ore o giorni lavorativi

**Files:**
- Create: `database/migrations/2026_10_26_000001_add_unita_to_sla_configurazioni.php` (`unita` string 10 default `ore`)
- Modify: `app/Models/SlaConfigurazione.php` (fillable, `descrizioneTempo(): string` → "4 ore lavorative" / "1 giorno lavorativo" / "N giorni lavorativi"), `app/Services/SlaService.php`, `app/Http/Controllers/Admin/SlaController.php`, `resources/views/admin/sla/{form,index}.blade.php`
- Test: `tests/Feature/SlaOrarioUfficioTest.php`

**Interfaces:**
- Consumes: `CalendarioUfficio` (Task 1).
- Produces: `SlaService::calcolaScadenza(Segnalazione): ?Carbon` (via calendario, unità della regola), `isArischio` in ore lavorative (`oreLavorativeTra(now, scadenza) < ore_warning`), validazione `unita` in `['ore','giorni']`.

- [ ] **Step 1: Failing tests** — segnalazione creata da form sabato 10:00 (`Carbon::setTestNow`) con regola 6 ore → `data_scadenza_sla` lunedì 14:00; regola `giorni` 1 → lunedì 14:00; isArischio: scadenza fra 3 ore lavorative ma 60 di calendario (weekend) con ore_warning 4 → true; admin salva regola con `unita=giorni` e la vede in elenco come "1 giorno lavorativo".
- [ ] **Step 2–4** RED → GREEN (+ `tests/Feature/*Sla*`). **Step 5: Commit** `feat(sla): rules in working hours or days`.

### Task 3: Ricalcolo e impostazioni orario

**Files:**
- Create: `app/Jobs/RicalcolaScadenzeSla.php`
- Modify: `app/Http/Controllers/Admin/SlaController.php` (dispatch dopo store/update/destroy), `app/Http/Controllers/Admin/ImpostazioniController.php` (validazione `orario_*`, `patrono`; dispatch se cambiano), `resources/views/admin/impostazioni.blade.php` (etichetta gruppo "Orario d'ufficio", aiuto formato, anteprima prossimi festivi dell'anno)
- Test: `tests/Feature/RicalcoloSlaTest.php`

**Interfaces:**
- Produces: `RicalcolaScadenzeSla::handle(SlaService)` — tutte le aperte, a blocchi: nuova scadenza; se nel futuro azzera `sla_violato`; se oltre soglia rischio azzera `sla_warning_inviato`.

- [ ] **Step 1: Failing tests** — modifica regola da 48 a 4 ore → aperta ricalcolata, chiusa intatta; scadenza spostata nel futuro azzera `sla_violato`; salvataggio impostazioni con `orario_lun=14:00-08:00` → errore di validazione; `patrono=31/02` → errore; tutti i giorni vuoti → errore; cambio orario dispatcha il job (`Bus::fake`), salvataggio identico no; pagina impostazioni mostra Pasquetta dell'anno corrente.
- [ ] **Step 2–4** RED → GREEN. **Step 5: Commit** `feat(sla): recompute open deadlines on rule or schedule change`.

### Task 4: Via il flag urgente, tempo previsto nel form

**Files:**
- Create: `database/migrations/2026_10_26_000002_urgenti_aperte_a_critica.php` (aperte urgenti → `livello_priorita=4`; scadenze ricalcolate dal job dopo deploy: dispatch nel migration non è affidabile → ricalcolo in `up()` via `SlaService` diretto, a blocchi)
- Modify: `SegnalazioneController` (create: `$tempiPrevisti`; store/priorita: niente urgente), `resources/views/segnalazioni/create.blade.php` (tolto checkbox, avviso 112, tempo previsto Alpine), `scheda/pannelli/priorita.blade.php`, `scheda/_testata.blade.php`, `scheda/lavoro.blade.php`, `components/pa/prio.blade.php`, `segnalazioni/_riga.blade.php`, `Segnalazione` (fillable/cast/`scopeUrgente`), `SlaService` (niente urgente→4), `CheckSlaViolazioni`, `TelegramWebhookController`, `GestioneController` (CSV), `ReportController` + `reports/mensile-gestore.blade.php`, `Api/SegnalazioneApiController`, `docs/API.md`, `docs/openapi.yaml`, `PopulateDemoData`, `ImpostazioniSeeder` (descrizione telegram)
- Test: `tests/Feature/UrgenteRimossoTest.php`

**Interfaces:**
- Produces: `$tempiPrevisti` = `array<int, array<int, string>>` chiave 0 = regola senza tipologia; JS: `mappa[tipologiaId]?.[priorita] ?? mappa[0]?.[priorita]`.

- [ ] **Step 1: Failing tests** — form create non contiene `segnalazione_urgente`, contiene "chiama il 112" e "La priorità definitiva la stabilisce l'ufficio" e il tempo previsto JSON ("4 ore lavorative"); store con `segnalazione_urgente=1` non lo salva; migrazione: aperta urgente media → critica, chiusa urgente invariata; `grep` di `segnalazione_urgente` in `app/` e `resources/` vuoto (test che legge i file); API ignora il campo.
- [ ] **Step 2–4** RED → GREEN (+ suite completa, test che citavano urgente aggiornati). **Step 5: Commit** `feat(segnalazioni): drop urgent flag, show expected time in form`.

### Task 5: Rilascio 0.9.2

- [ ] CHANGELOG `## [0.9.2]` con nota operativa (regole SLA da rivedere in ore/giorni lavorativi; urgenti aperte → Critica; impostare patrono), `publiccode.yml` 0.9.2, CLAUDE.md (gruppo `orario` nella tabella impostazioni, `CalendarioUfficio`, job, rimozione urgente).
- [ ] Larastan + `npm run build`; PR, CI verde, squash merge, tag `v0.9.2`, `gh run watch`. Nessuna GitHub Release.
