# Elenco leggibile e colori unici (1.0.4) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Colori unici e autoesplicativi per stati, tipologie e priorità usati in tutto il software, ed elenco gestione ridisegnato con "Raggruppa per" a celle laterali.

**Architecture:** `App\Support\Colori` è l'unica fonte (toni con nome → esadecimali; stato/priorità → tono); il layout emette da lì le variabili CSS `.pa-tono-*`. Tipologie con colonna `colore` scelta dall'admin. Tre componenti Blade (`x-pa.stato`, `x-pa.tipologia`, `x-pa.priorita`) usati in elenchi, scheda, nuova segnalazione; grafici leggono gli stessi esadecimali. L'elenco gestione diventa una tabella con celle di gruppo `rowspan` calcolate da `App\Support\Segnalazioni\GruppiElenco`.

**Tech Stack:** Laravel 13, Blade, Alpine, Tailwind 4 + token CSS, PHPUnit, Chart.js.

**Spec:** `docs/superpowers/specs/2026-11-16-elenco-segnalazioni-design.md` (bozza approvata: `bozza-elenco.html` nello scratchpad di sessione).

## Global Constraints

- Toni (sfondo, testo, punto): verde `#dcfce7 #166534 #16a34a` · salvia `#ecfccb #3f6212 #65a30d` · blu `#dbeafe #1e40af #2563eb` · azzurro `#e0f2fe #075985 #0284c7` · giallo `#fef9c3 #854d0e #ca8a04` · rosso `#fee2e2 #991b1b #dc2626` · arancio `#ffedd5 #9a3412 #ea580c` · marrone `#f5e6d3 #78350f #a16207` · pietra `#e7e5e4 #44403c #78716c` · viola `#ede9fe #5b21b6 #7c3aed` · rosa `#fce7f3 #9d174d #db2777` · indaco `#e0e7ff #3730a3 #4f46e5` · grigio `#f1f5f9 #475569 #94a3b8`.
- Stati: 1 arancio · 2 azzurro · 3 blu · 4 indaco · 5, 6, 11 giallo · 7 verde · 8, 9, 10 grigio.
- Priorità: 4 Critica rosso pieno (sfondo `#dc2626`, testo bianco, `fa-angles-up`) · 3 Alta rosso chiaro con bordo (`fa-arrow-up`) · 2 Media nessuna etichetta negli elenchi · 1 Bassa grigio (`fa-arrow-down`).
- Tipologie iniziali: VERDE E GIARDINI verde · SFALCI E POTATURE salvia · IMPIANTO IDRICO blu · FONTANE PUBBLICHE azzurro · IMPIANTO ELETTRICO giallo · IMPIANTO TERMICO rosso · INFISSI E PORTE marrone · ARREDI arancio · MANUTENZIONE EDILE pietra · RETE TELEFONICA / DATI viola · GIOCHI rosa · altre/null grigio.
- Segni: stato = pallino + nome grassetto (mai sfondo); tipologia = sfondo pieno tenue; priorità = etichetta rettangolare.
- Nomi leggibili: minuscolo con iniziali maiuscole, parole brevi `e di a da del della dei per in` minuscole tranne in testa.
- Scadenza in elenco: "In ritardo di N g" testo normale; rosso grassetto solo se scaduta da < 24 h o scade entro 24 h.
- Migration solo additive (merge = prod). `max:` = lunghezza colonna. Email escluse.
- Commit Conventional, niente attribuzione AI.

## Review Focus

- Raggruppamento con paginazione: gruppo spezzato tra pagine → cella ripetuta con "(continua)", conteggi sull'intero filtro, non sulla pagina.
- Segnalazione senza sede (`id_plesso` 0/null) o senza tipologia: gruppo "Senza sede"/"Senza tipologia", niente errore.
- Tipologia creata dall'admin dopo il rilascio: `colore` null → grigio ovunque.
- Lettura segnalante/lavoro: niente nomi interni nuovi esposti (assegnatario nello stato solo dove già visibile oggi).
- Tab "Chiuse": ordinamento per data chiusura invariato quando non si raggruppa; con gruppo, chiave poi data chiusura.

---

### Task 1: Colori unici

**Files:**
- Create: `app/Support/Colori.php`, `resources/views/partials/toni.blade.php`, `tests/Unit/ColoriTest.php`
- Modify: `app/Enums/SegnalazioneStato.php` (aggiunge `colore(): string`), `resources/views/layouts/app.blade.php` (include partial nel `<head>`), `resources/css/app.css` (classi che leggono le variabili)

**Interfaces — Produces:**
- `Colori::TONI: array<string, array{sfondo:string, testo:string, punto:string}>`
- `Colori::stato(SegnalazioneStato $s): string` (nome tono) · `Colori::priorita(int $livello): ?string` (null per Media) · `Colori::tono(string $nome): array{...}` (sconosciuto → grigio) · `Colori::css(): string` (blocco `:root`/classi)
- CSS: `.pa-tono-{nome}` imposta `--tono-sfondo`, `--tono-testo`, `--tono-punto`.
- `SegnalazioneStato::colore(): string` → `Colori::stato($this)`.

- [ ] Test: `test_ogni_stato_ha_il_suo_colore` (tutti i case → valori di Global Constraints), `test_priorita` (4 rosso, 3 rosso, 2 null, 1 grigio), `test_tono_sconosciuto_e_grigio`, `test_css_contiene_ogni_tono` (`.pa-tono-verde{--tono-sfondo:#dcfce7` …).
- [ ] Run `--filter=ColoriTest` → FAIL (classe mancante).
- [ ] Implementa; partial `toni` stampa `<style>{!! Colori::css() !!}</style>`.
- [ ] Run → PASS. Commit `feat(colori): tavolozza unica per stati, tipologie e priorità`.

### Task 2: Colore delle tipologie

**Files:**
- Create: `database/migrations/2026_11_16_000002_colore_tipologie.php`, `tests/Feature/Admin/ColoreTipologieTest.php`
- Modify: `app/Models/TipologiaSegnalazione.php` (fillable `colore`, `tono(): string`, `nomeLeggibile(): string`), `app/Models/Plesso.php` (`nomeLeggibile(): string`), `app/Support/Testo.php` o metodo statico in `Colori`? → **`App\Support\NomeLeggibile::di(string): string`** (nuovo, condiviso), `database/seeders/TabelleRiferimentoSeeder.php` (colori sulle righe), `app/Http/Controllers/Admin/TipologieController.php` (`colore` → `nullable|in:` chiavi `Colori::TONI`), `resources/views/admin/tipologie/index.blade.php` (select colore con anteprima)

- [ ] Test: migration assegna i colori per descrizione (crea tipologie con quei nomi prima di `migrate`? → test sul metodo statico `ColoreTipologieMigration::coloriIniziali()` oppure sul risultato dopo `RefreshDatabase` + seed); `tono()` null → `grigio`; admin salva `colore=verde`; `colore=fucsia` rifiutato; `NomeLeggibile::di('D.D. MONTESILVANO-V. VERROCCHIO')` → `D.D. Montesilvano-V. Verrocchio`, `'VERDE E GIARDINI'` → `Verde e Giardini`, `'INFANZIA "GIUSEPPE DEZIO"'` → `Infanzia "Giuseppe Dezio"`.
- [ ] RED → implementa (colonna `varchar(20) nullable`, update per descrizione dentro la migration) → GREEN. Commit `feat(tipologie): colore scelto dall'admin`.

### Task 3: Componenti stato, tipologia, priorità

**Files:**
- Create: `resources/views/components/pa/stato.blade.php` (props `stato`, `chi` opzionale, `segnalante` bool → `fraseSegnalante()['titolo']`), `resources/views/components/pa/tipologia.blade.php` (props `tipologia`, `variante` `etichetta|cella|riquadro`), `resources/views/components/pa/priorita.blade.php` (props `livello`, `media` bool = mostra "Media"), `tests/Feature/ComponentiColoriTest.php`
- Modify: `resources/views/components/pa/prio.blade.php` (delega a `x-pa.priorita` con `media=true`), `resources/css/app.css` (`.pa-stato`, `.pa-tipologia`, `.pa-priorita-{1..4}`)

- [ ] Test con `$this->blade(...)`: stato 1 contiene `pa-tono-arancio` e "Nuova"; con `chi` "Mario" (senza "a "); tipologia con colore verde contiene `pa-tono-verde` e "Verde e Giardini"; priorità 2 senza `media` è vuota, 4 contiene "Critica" e `fa-angles-up`.
- [ ] RED → implementa → GREEN. Commit `feat(ui): componenti stato, tipologia e priorità`.

### Task 4: Riga ed elenchi

**Files:**
- Modify: `resources/views/segnalazioni/_riga.blade.php` (colonne nuove: `sede`, `tipologia`, `seleziona`, `stato` = pallino + nome assegnatario senza "a" / "da assegnare", `priorita`, `scadenza`, `testo` = `#id` + `titoloLeggibile(260)` clamp 2 righe con link, `azioni`; tolto lucchetto; stella evidenza resta nel testo; pill Segui/Sollecitata/Ricorrente restano sotto il testo), `app/Support/Segnalazioni/Scadenza.php` (`per()`: scaduta → "In ritardo di N g"/"In ritardo di N h", tono `rose` solo se < 24 h, altrimenti `slate`), `resources/css/app.css` (`.pa-elenco` nuova: celle gruppo, telefono a griglia con aree), `resources/views/gestione/dashboard.blade.php` (thead nuove colonne), `resources/views/operaio/dashboard.blade.php`, `resources/views/imprese/dashboard.blade.php`, `resources/views/segnalazioni/_elenco-segnalante.blade.php` (colonne `sede, tipologia, stato, priorita?, scadenza, testo`; segnalante senza assegnatario), test esistenti `ElencoGestioneTest`, `PresentazioneTest` aggiornati.
- [ ] Test nuovi in `ElencoGestioneTest`: riga mostra `pa-tono-` della tipologia e dello stato, "da assegnare" per Nuova, nessun `fa-lock`, "Media" assente, "Alta" presente per livello 3; `PresentazioneTest`: scaduta da 2 g → `['In ritardo di 2 g','slate']`, da 5 h → `['In ritardo di 5 h','rose']`.
- [ ] RED → implementa → GREEN (anche test di operaio/ditta/segnalante esistenti). Commit `feat(elenco): riga con sede, tipologia e stato a colori`.

### Task 5: Raggruppa per

**Files:**
- Create: `app/Support/Segnalazioni/GruppiElenco.php`, `tests/Unit/Segnalazioni/GruppiElencoTest.php`, `tests/Feature/RaggruppaElencoTest.php`
- Modify: `app/Http/Controllers/GestioneController.php` (`gruppo` in `''|sede|sede_tipologia|tipologia|tipologia_sede`, ordinamento per chiave con `leftJoin` su `plessi`/`tipologie_segnalazioni` e `select segnalazioni.*`, conteggi primo livello con una query `groupBy` sull'intero filtro, chiave dell'elemento precedente la pagina per "(continua)", `gruppo` in `parametriFiltro`), `resources/views/gestione/dashboard.blade.php` (select "Raggruppa per" con default da localStorage, righe con celle `rowspan`), `resources/views/segnalazioni/_riga.blade.php` (param `celleGruppo` = HTML celle iniziali)

**Interfaces — Produces:** `GruppiElenco::costruisci(Collection $pagina, string $gruppo, array $conteggi, ?array $chiavePrecedente): list<array{s: Segnalazione, celle: list<array{livello:int, tipo:'sede'|'tipologia', chiave:int, nome:string, rowspan:int, totale:?int, inRitardo:?int, continua:bool}>}>`

- [ ] Unit test: due livelli con 3 righe stessa sede (2 tipologie) → prima riga 2 celle (rowspan 3 e 2), terza riga 1 cella (rowspan 1); `chiavePrecedente` uguale → `continua=true`; senza sede → nome "Senza sede".
- [ ] Feature test: `?gruppo=sede_tipologia` ordina per nome sede poi tipologia e mostra "N · M in ritardo"; `gruppo` ignoto → come nessuno; tab chiuse con gruppo funziona.
- [ ] RED → implementa → GREEN. Commit `feat(gestione): raggruppa l'elenco per sede e tipologia`.

### Task 6: Colori in nuova segnalazione, scheda, statistiche

**Files:**
- Modify: `resources/views/segnalazioni/create.blade.php` (riquadri tipologia `pa-tono-*`: tenue non scelta, bordo `--tono-punto` scelta; bottoni priorità con classi `.pa-priorita-*` da selezionati), `resources/views/segnalazioni/scheda/_testata.blade.php` e `scheda/lavoro.blade.php` (`x-pa.tipologia variante=etichetta`, sede `nomeLeggibile`), `app/Support/Segnalazioni/Fatti.php` (colori stato/priorità da `Colori`), `app/Http/Controllers/StatisticheController.php` + `resources/views/statistiche/index.blade.php` + `resources/views/operaio/statistiche.blade.php` + `resources/views/public/home.blade.php` (colori per etichetta da `Colori`: tipologia → `tono()['punto']`, stato → `Colori::stato`), test esistenti di scheda/form/statistiche.
- [ ] Test: form create contiene `pa-tono-verde` sul riquadro di "Verde e giardini"; scheda testata contiene `pa-tono-` della tipologia; statistiche passano array colori lunghi quanto le etichette.
- [ ] RED → implementa → GREEN. Commit `feat(ui): stessi colori in apertura, scheda e statistiche`.

### Task 7: Rilascio 1.0.4

**Files:** `CHANGELOG.md`, `publiccode.yml`, `CLAUDE.md` (sezione colori + gruppi), `docs/superpowers/specs/...` già committata.
- [ ] `npm run build`, controllo visivo Playwright 1400px e 390px (gestione con ogni gruppo, create, scheda, operaio).
- [ ] Suite intera + PHPStan → verdi. Commit `docs: 1.0.4`.
- [ ] Push, PR, CI verde, squash merge, tag `v1.0.4`, watch release.
