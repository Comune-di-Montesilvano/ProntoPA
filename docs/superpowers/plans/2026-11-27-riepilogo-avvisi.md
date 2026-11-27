# Riepilogo degli avvisi — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Gli avvisi sulle segnalazioni non partono più all'evento ma si accumulano a DB e partono come un solo riepilogo per persona, ogni ora in orario d'ufficio o una volta al giorno (con il quadro del mattino), su email e Telegram.

**Architecture:** Le notifiche raggruppabili implementano `Raggruppabile`; un listener su `NotificationSending` le salva in `avvisi_in_attesa` e annulla l'invio. Il comando `avvisi:invia` (schedulato) raggruppa per destinatario, prenota le righe e accoda un job `InviaRiepilogo` per destinatario, che compone (dedup per segnalazione, `ancoraValida`, quadro del mattino) e spedisce `RiepilogoAvvisiNotification` o la notifica originale se è una sola.

**Tech Stack:** Laravel 13, PHP 8.5, MariaDB (SQLite nei test), Redis queue, Pest/PHPUnit, Blade.

**Spec:** `docs/superpowers/specs/2026-11-27-riepilogo-avvisi-design.md`

## Global Constraints

- Ritmi: solo `ora` | `giorno`, nessun «subito». Default: `assegnazioni` `solleciti` `lavoro` `richieste` = `ora`; `nuove` `scadenze` `inattivita` `esiti` = `giorno`.
- Ordine sezioni: `assegnazioni`, `solleciti`, `richieste`, `lavoro`, `scadenze`, `nuove`, `inattivita`, `esiti`.
- Fuori ambito, sempre immediate: `BackupFallitoNotification`, `SyncAdFallitaNotification`, `JobFallitiNotification`, `Notifications/Deleghe/*`, webhook outbound.
- Mai invii fuori dall'orario di `CalendarioUfficio::daImpostazioni()` (fascia del giorno, festivi, patrono; estremi inclusi).
- Telegram: stessi contenuti dell'email, solo se `User::usaTelegram()` + chat collegata + `telegram_riepiloghi` (default true). Pulsanti `apri:ID` max 10, poi «e altre N»; mai più di un messaggio.
- 3 tentativi falliti → `scartata_at`, `Log::warning`.
- Righe inviate/scartate tenute 30 giorni.
- Oggetto email: `Riepilogo ProntoPA — N segnalazioni`.
- Ditte (destinatario email): link a `route('accesso')`.
- Nuova impostazione → `Sezioni` + `ImpostazioniSeeder` (idempotente) + `<x-pa.impostazione>`.
- Test: `withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class])` sui POST web; in locale solo test toccati + `composer run analyse`, suite intera in CI.
- Rilascio patch **1.0.21**: CHANGELOG + `publiccode.yml` `softwareVersion` nella stessa PR.
- Modifiche PHP con Edit tool (mai heredoc bash: altera i backslash).

## Review Focus

1. Notifica raggruppabile inviata a un `AnonymousNotifiable` (email aziendale della ditta) → deve finire in attesa con `destinatario_tipo=email`, non perdersi né partire subito. Test in Task 3.
2. Segnalazione eliminata tra evento e giro → la deserializzazione fallisce (`ModelNotFoundException`) → riga scartata in silenzio, il resto del riepilogo parte. Test in Task 5.
3. Stessa notifica su due canali (`mail` + Telegram nel `via()`) → una sola riga in attesa (unique `id_notifica`+destinatario). Test in Task 3.
4. Giro lanciato due volte in parallelo (scheduler + «a mano») → nessun doppio invio grazie a `giro`. Test in Task 7.
5. Utente disattivato tra evento e giro → nessun invio, righe marcate. Test in Task 5.

---

## File Structure

- Create `database/migrations/2026_11_27_000001_create_avvisi_in_attesa_table.php`
- Create `app/Models/AvvisoInAttesa.php`
- Create `app/Support/Avvisi/Categorie.php` — chiavi, etichette, ordine, ritmo letto da impostazioni
- Create `app/Notifications/Riepilogo/Raggruppabile.php` (interfaccia) + `app/Notifications/Riepilogo/Raggruppa.php` (trait: flag `daRiepilogo`)
- Create `app/Listeners/IntercettaAvvisi.php`
- Create `app/Services/Avvisi/ComponiRiepilogo.php` + `app/Services/Avvisi/Voce.php` (value object)
- Create `app/Services/Avvisi/QuadroMattino.php`
- Create `app/Notifications/RiepilogoAvvisiNotification.php`
- Create `app/Jobs/InviaRiepilogo.php`
- Create `app/Console/Commands/InviaAvvisi.php` (`avvisi:invia`)
- Modify 17 notifiche in `app/Notifications/`, `app/Channels/TelegramChannel.php`, `app/Listeners/TracciaNotificaInviata.php`, `app/Models/NotificaSegnalazione.php`, `resources/views/segnalazioni/scheda/_notifiche.blade.php`, `app/Support/Impostazioni/Sezioni.php`, `app/Services/Impostazioni/SalvaImpostazioni.php`, `resources/views/components/pa/impostazione.blade.php`, `database/seeders/ImpostazioniSeeder.php`, `resources/views/admin/regole/avvisi.blade.php`, `routes/console.php`, `app/Services/Admin/StatoSistema.php`, `CLAUDE.md`, `CHANGELOG.md`, `publiccode.yml`
- Tests in `tests/Feature/Avvisi/`

---

### Task 1: Impostazioni dei ritmi e pagina Avvisi

**Files:**
- Create: `app/Support/Avvisi/Categorie.php`
- Modify: `app/Support/Impostazioni/Sezioni.php` (`AVVISI`), `app/Services/Impostazioni/SalvaImpostazioni.php`, `resources/views/components/pa/impostazione.blade.php`, `database/seeders/ImpostazioniSeeder.php`, `resources/views/admin/regole/avvisi.blade.php`
- Test: `tests/Feature/Avvisi/ImpostazioniRitmiTest.php`

**Interfaces:**
- Produces: `Categorie::ORDINE: list<string>` (ordine sezioni), `Categorie::etichetta(string $categoria): string` («Assegnate a te», «Solleciti», «Richieste di informazioni», «Da seguire», «Scadenze», «Nuove», «Ferme o archiviate», «Esiti»), `Categorie::ritmo(string $categoria): string` (`ora`|`giorno`, da `Impostazione::get("avvisi_ritmo_$categoria", default)`), `Categorie::DEFAULT: array<string,string>`.
- Produces: impostazioni `avvisi_ritmo_<categoria>` (tipo `ritmo`) e `telegram_riepiloghi` (bool); rimosse da `Sezioni::AVVISI` e dalla pagina `telegram_notifica_operatore_assegnazione/_sla_warning/_nuova_nota` (seeder: non più create; righe esistenti a DB lasciate, mai lette).

- [ ] **Step 1: Write failing tests**
  - `test_seeder_crea_ritmi_di_default`: dopo `ImpostazioniSeeder`, `Categorie::ritmo('assegnazioni') === 'ora'`, `Categorie::ritmo('scadenze') === 'giorno'`, `Impostazione::get('telegram_riepiloghi')` truthy.
  - `test_seeder_non_sovrascrive_ritmo_configurato`: imposta `avvisi_ritmo_nuove=ora`, rilancia seeder → resta `ora`.
  - `test_admin_salva_ritmo`: PATCH `admin.regole.avvisi.update` con `impostazioni[avvisi_ritmo_esiti]=ora` → salvato; con `=subito` → errore di validazione su `impostazioni.avvisi_ritmo_esiti`.
  - `test_pagina_avvisi_mostra_ritmi_e_non_vecchi_interruttori`: GET pagina → vede «Ogni ora», «Una volta al giorno», non vede `telegram_notifica_nuova_nota`.
- [ ] **Step 2: Run** `docker compose exec php php artisan test tests/Feature/Avvisi/ImpostazioniRitmiTest.php` → FAIL.
- [ ] **Step 3: Implement** — tipo `ritmo` in `SalvaImpostazioni` (`['required','in:ora,giorno']`) e nel componente (select: `ora` → «Ogni ora (in orario d'ufficio)», `giorno` → «Una volta al giorno, la mattina»); seeder con i default di `Categorie::DEFAULT` (gruppo `avvisi`); pagina: sezione «Quadro del mattino» (`digest_enabled` etichetta «Invia ogni mattina il quadro: nuove, da fare, in ritardo», `digest_ora`; `digest_skip_weekend` tolto da pagina e `Sezioni`), sezione «Quanto spesso» con una riga per categoria in `Categorie::ORDINE`, sezione Telegram con il solo `telegram_riepiloghi` («Manda i riepiloghi anche su Telegram»).
- [ ] **Step 4: Run** test → PASS; `composer run analyse` pulito.
- [ ] **Step 5: Commit** `feat(avvisi): ritmi per categoria in Regole → Avvisi`

### Task 2: Tabella avvisi in attesa e interfaccia

**Files:**
- Create: migration, `app/Models/AvvisoInAttesa.php`, `app/Notifications/Riepilogo/Raggruppabile.php`, `app/Notifications/Riepilogo/Raggruppa.php`
- Test: `tests/Feature/Avvisi/AvvisoInAttesaTest.php`

**Interfaces:**
- Tabella `avvisi_in_attesa`: `id`, `id_notifica` string(36), `destinatario_tipo` string(10) (`utente`|`email`), `id_utente` nullable unsignedBigInteger, `email` nullable string(255), `categoria` string(30), `ritmo` string(10), `id_segnalazione` unsignedBigInteger, `tipo` string(80), `notifica` longText, `giro` nullable string(36), `tentativi` unsignedTinyInteger default 0, `inviata_at` nullable, `scartata_at` nullable, timestamps. Unique (`id_notifica`, `destinatario_tipo`, `id_utente`, `email`); index (`inviata_at`, `scartata_at`, `ritmo`).
- `AvvisoInAttesa`: `scopeInAttesa($q)` (`inviata_at` e `scartata_at` null), `scopeDelRitmo($q, string $ritmo)`, `chiaveDestinatario(): string` (`u:<id>` o `e:<email minuscola>`), `notificabile(): User|AnonymousNotifiable|null` (utente attivo o `Notification::route('mail', email)`; utente inesistente/disattivato → null), `notifica(): ?Notification` (unserialize, `null` su qualsiasi `Throwable`).
- `interface Raggruppabile { categoriaRiepilogo(): string; segnalazioneRiepilogo(): int; rigaRiepilogo(object $notifiable): string; motivoRiepilogo(): string; ancoraValida(object $notifiable): bool; }` — `motivoRiepilogo` = parola breve per la riga multi-motivo («Assegnata a te», «Scaduta», «Nuova nota»…).
- Trait `Raggruppa`: `public bool $daRiepilogo = false;`.

- [ ] **Step 1: Tests**: `test_notificabile_utente_disattivato_null`, `test_notificabile_email_anonymous` (routes['mail'] === email), `test_notifica_corrotta_restituisce_null`, `test_chiave_destinatario_email_minuscola`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(avvisi): tabella avvisi in attesa`

### Task 3: Notifiche raggruppabili e intercettazione

**Files:**
- Modify: le 17 notifiche della tabella del design (Operatore/Squadra/ImpresaAssegnata, Sollecito, RimandataAlGestore, FineLavori, NotaDaEsterno, NuovaNotaSegnalazione, RispostaInformazioni, RichiestaInformazioni, NuovaSegnalazione, SlaViolazione, SlaWarning, Inattivita, ArchiviataInattivita, SegnalazioneChiusa, SegnalazioneStatoCambiato, Accorpata) — `implements Raggruppabile`, `use Raggruppa`.
- Create: `app/Listeners/IntercettaAvvisi.php`
- Test: `tests/Feature/Avvisi/IntercettaAvvisiTest.php`, `tests/Unit/Avvisi/AncoraValidaTest.php`

**Interfaces:**
- Consumes: Task 2.
- `IntercettaAvvisi::handle(NotificationSending $e): ?bool` — se `$e->notification instanceof Raggruppabile && ! $e->notification->daRiepilogo`: `insertOrIgnore` riga (ritmo da `Categorie::ritmo()`, `notifica` = `serialize(clone senza stato canale)`), return `false`; altrimenti `null`. Auto-discovery come `TracciaNotificaInviata`.
- Regole `ancoraValida` (oltre a segnalazione esistente): assegnazioni → non terminale e ancora assegnata a quel destinatario (operaio `id_operatore_assegnato`, squadra `id_squadra_assegnata` con quel caposquadra, ditta: `DestinatariAssegnatario::per()` contiene il destinatario); sollecito/rimandata/fine lavori → non terminale; SlaViolazione → aperta e `sla_violato`; SlaWarning → aperta e non `sla_violato`; Richiesta informazioni → stato 11; Inattivita → aperta; Nuova → non terminale; esiti/archiviata/accorpata/note/risposta → sempre vere.
- `via()`: si toglie `Impostazione::get('telegram_notifica_*')` dalle 4 notifiche che lo leggono (il canale Telegram lo decide il giro); `via()` resta `['mail']` (+ Telegram dove c'era) solo per il caso reinvio/immediato.
- `AccorpataNotification::segnalazioneRiepilogo()` = destinazione; `rigaRiepilogo` cita «ha assorbito la #N».

- [ ] **Step 1: Tests** (sender vero, `Mail::fake()`):
  - `test_assegnazione_finisce_in_attesa_e_non_parte`: assegna a operaio via `SegnalazioneWorkflowService` → `Mail::assertNothingSent()`, 1 riga `categoria=assegnazioni ritmo=ora destinatario_tipo=utente`.
  - `test_ditta_email_aziendale_in_attesa` (Review Focus 1): assegnazione a ditta con email aziendale → riga `destinatario_tipo=email`, `email` = indirizzo.
  - `test_due_canali_una_riga` (Review Focus 3): operaio con Telegram collegato → 1 riga sola.
  - `test_reinvio_da_riepilogo_non_intercettato`: notifica con `daRiepilogo=true` → `Mail::assertSent`.
  - `test_notifiche_di_sistema_restano_immediate`: `BackupFallitoNotification` → inviata, nessuna riga.
  - Unit `AncoraValidaTest`: assegnazione riassegnata ad altro operaio → false; Sla scaduta poi chiusa → false; richiesta informazioni con stato tornato 2 → false.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS + `composer run analyse`.
- [ ] **Step 5: Commit** `feat(avvisi): intercetta avvisi raggruppabili`

### Task 4: Quadro del mattino

**Files:**
- Create: `app/Services/Avvisi/QuadroMattino.php`
- Test: `tests/Feature/Avvisi/QuadroMattinoTest.php`

**Interfaces:**
- `QuadroMattino::per(User|AnonymousNotifiable $chi): array{nuove: Collection<Segnalazione>, da_fare: Collection<Segnalazione>, in_ritardo: Collection<Segnalazione>}` — tre collezioni vuote per segnalanti (`isSpid()` senza ruolo impresa, ruolo `segnalatore`) e per tutti se `digest_enabled` falso. Regole (copiate dal design):
  - nuove: gestori → aperte non assegnate delle loro tipologie (supervisore o `gestore_tutte_tipologie` → tutte), stessa query/limite 50 di `InviaDigestGestori`.
  - da_fare: aperte non terminali (stato 11 incluso); gestore = `id_operatore_assegnato`=sé in stato 2; operaio = assegnate a sé o a squadre di cui è caposquadra; impresa (utente con `id_impresa` o email aziendale) = `appalto.id_impresa` della propria impresa. Esclude quelle `sla_violato`.
  - in_ritardo: come da_fare ma `sla_violato`; supervisori: tutte le aperte `sla_violato`.
  - «A rischio» = `data_scadenza_sla` entro 48 h, marcato in riga (Task 6), non sezione a sé.
- [ ] **Step 1: Tests**: `test_gestore_tipologia_vede_solo_le_sue_nuove`, `test_operaio_da_fare_include_squadra_da_caposquadra`, `test_ditta_email_aziendale_da_fare`, `test_supervisore_in_ritardo_tutte`, `test_segnalante_quadro_vuoto`, `test_digest_spento_quadro_vuoto`.
- [ ] **Step 2–4**: Run FAIL → implement → PASS.
- [ ] **Step 5: Commit** `feat(avvisi): quadro del mattino`

### Task 5: Composizione del riepilogo

**Files:**
- Create: `app/Services/Avvisi/Voce.php`, `app/Services/Avvisi/ComponiRiepilogo.php`
- Test: `tests/Feature/Avvisi/ComponiRiepilogoTest.php`

**Interfaces:**
- `final class Voce { public function __construct(public readonly Segnalazione $segnalazione, public readonly string $categoria, public readonly array $motivi /* list<string> */, public readonly string $riga, public readonly ?Raggruppabile $notifica) {} }`
- `ComponiRiepilogo::componi(Collection<AvvisoInAttesa> $righe, User|AnonymousNotifiable $chi, bool $conQuadro): Composizione` con `final class Composizione { array $quadro /* nuove|da_fare|in_ritardo => list<Voce> */; array $sezioni /* categoria => list<Voce>, in Categorie::ORDINE */; list<int> $idRigheUsate; ?Raggruppabile $unica; function vuota(): bool; function totaleSegnalazioni(): int }` (definita nello stesso file di `Voce`).
- Algoritmo:
  1. per ogni riga `notifica()`; null o `! ancoraValida($chi)` → id in `idRigheUsate`, scartata dalla vista.
  2. raggruppa per `segnalazioneRiepilogo()`; per categoria tieni la riga più recente; categoria della voce = la prima in `Categorie::ORDINE` tra le presenti; `motivi` = `motivoRiepilogo()` di tutte in ordine; `riga` = `rigaRiepilogo($chi)` della notifica della categoria vincente.
  3. se `$conQuadro`: `QuadroMattino::per($chi)`; segnalazione già nel quadro → i suoi motivi si aggiungono alla voce del quadro e non va in `sezioni`.
  4. `unica` = la notifica se quadro vuoto e c'è una sola voce con un solo motivo.
  Tutte le righe lette finiscono in `idRigheUsate` (anche le assorbite).
- [ ] **Step 1: Tests**:
  - `test_stessa_segnalazione_categorie_diverse_una_voce_con_tutti_i_motivi`: assegnazione + SlaViolazione stessa segnalazione → 1 voce in `assegnazioni`, motivi `['Assegnata a te','Scaduta']`.
  - `test_avviso_non_piu_valido_sparisce`: assegnazione poi riassegnata → `vuota()` true, riga in `idRigheUsate`.
  - `test_segnalazione_eliminata_scartata_resto_parte` (Review Focus 2).
  - `test_una_sola_voce_un_motivo_restituisce_unica`.
  - `test_segnalazione_nel_quadro_non_ripetuta`.
- [ ] **Step 2–4**: FAIL → implement → PASS.
- [ ] **Step 5: Commit** `feat(avvisi): composizione del riepilogo`

### Task 6: Notifica di riepilogo (email + Telegram)

**Files:**
- Create: `app/Notifications/RiepilogoAvvisiNotification.php` (corpo con `MailMessage`, come `DigestGestoreNotification`, niente view)
- Modify: `app/Channels/TelegramChannel.php`
- Test: `tests/Feature/Avvisi/RiepilogoAvvisiNotificationTest.php`

**Interfaces:**
- Consumes: `Composizione` (Task 5).
- `RiepilogoAvvisiNotification(Composizione $c, bool $mattino)` — **non** `ShouldQueue` (la invia il job), non `Raggruppabile`. `via()`: `['mail']` + `TelegramChannel::class` se `routeNotificationForTelegram()` e `telegram_riepiloghi`. `segnalazioniTracciate(): list<int>`; `tipiTracciati(): array<int, list<string>>` (id segnalazione → class basename originali, per Task 8). `public bool $esigeEsito = true`.
- Mail: oggetto `Riepilogo ProntoPA — N segnalazioni` (N = `totaleSegnalazioni()`); se `$mattino` titolo «Il quadro di oggi» con «Nuove (n)», «Da fare (n)» («· a rischio» sulle righe entro 48 h), «In ritardo (n)»; poi per ogni sezione `Categorie::etichetta()` + «(n)» e righe `riga . ' — ' . implode(' · ', motivi)` con link alla scheda (`route('segnalazioni.show')`, `route('accesso')` per `AnonymousNotifiable`). `baseMailMessage()`.
- Telegram: stesso testo compatto; `inline_keyboard` con `apri:ID` per le prime 10 voci, testo «e altre N»; testo troncato a 4000 caratteri chiudendo con «…».
- `TelegramChannel::send()`: se `sendMessage()` restituisce `false` e la notifica ha `esigeEsito === true` → `throw new RuntimeException('Telegram: invio non riuscito')`; altrimenti come oggi.
- [ ] **Step 1: Tests**: `test_oggetto_e_sezioni_in_ordine`, `test_ditta_link_accesso`, `test_quadro_mattino_in_testa`, `test_telegram_massimo_10_pulsanti_e_altre_n` (`Http::fake`), `test_telegram_fallito_lancia_per_riepilogo` e `test_telegram_fallito_non_lancia_per_altre`.
- [ ] **Step 2–4**: FAIL → implement → PASS.
- [ ] **Step 5: Commit** `feat(avvisi): email e Telegram di riepilogo`

### Task 7: Giro di invio (comando, job, schedule)

**Files:**
- Create: `app/Console/Commands/InviaAvvisi.php`, `app/Jobs/InviaRiepilogo.php`
- Modify: `routes/console.php`, `app/Services/Admin/StatoSistema.php` (`email()`)
- Test: `tests/Feature/Avvisi/InviaAvvisiTest.php`

**Interfaces:**
- `avvisi:invia {--ritmo=ora} {--forza}` — `ora`: esegue solo se `CalendarioUfficio::daImpostazioni()->fascia(now())` non null e (minuto 0 e now ∈ [apertura, chiusura]) o now == chiusura arrotondata ai 15 min; `giorno`: solo se `fascia(today)` non null. `--forza` salta il controllo orario (test/console). Ritmo `giorno` prende le righe di **entrambi** i ritmi; `ora` solo `ora`. Per ogni chiave destinatario: `UPDATE ... SET giro=<uuid> WHERE id IN (...) AND giro IS NULL` → accoda `InviaRiepilogo($giro, $chiave, $mattino)` solo per le righe effettivamente prenotate. Destinatari con quadro (ritmo `giorno`, `digest_enabled`): anche gli utenti senza righe che hanno un quadro non vuoto (gestori attivi, operai attivi, utenti `impresa` attivi). In coda: pulizia righe `inviata_at|scartata_at` > 30 gg e righe con `giro` più vecchio di 3 h e non inviate (liberate: `giro=null`).
- `InviaRiepilogo::handle()`: carica righe del giro; `notificabile()` null → marca tutte `inviata_at` (nessun invio); `componi()`; `vuota()` → marca; `unica` → `daRiepilogo=true`, `notifyNow($unica, ['mail'])` + su Telegram `RiepilogoAvvisiNotification` a una voce (solo canale Telegram); altrimenti `notifyNow(RiepilogoAvvisiNotification)`; ok → `inviata_at=now()` sulle `idRigheUsate`; eccezione → `tentativi+1`, `giro=null`, a 3 → `scartata_at`, `Log::warning('Riepilogo avvisi scartato', [...])`; rilancia solo se < 3. `$tries = 1`.
- Schedule: `Schedule::command('avvisi:invia --ritmo=ora')->everyFifteenMinutes()->withoutOverlapping()->runInBackground();` e `Schedule::command('avvisi:invia --ritmo=giorno')->dailyAt(<digest_ora, stessa closure di oggi>)->withoutOverlapping()->runInBackground();` — rimuovere `digest:invia` dallo schedule (comando resta).
- `StatoSistema::email()`: se esistono righe `scartata_at` negli ultimi 7 giorni → `Riquadro::ATTENZIONE` «N riepiloghi non consegnati negli ultimi 7 giorni.».
- [ ] **Step 1: Tests** (`Mail::fake`, `travelTo`, orario `lun 08:00-14:00`):
  - `test_lunedi_10_00_parte_riepilogo_orario`: 3 assegnazioni allo stesso operaio → 1 email con 3 righe.
  - `test_lunedi_15_00_non_parte`, `test_domenica_non_parte`, `test_lunedi_14_00_chiusura_parte`, `test_fascia_13_45_parte_alle_13_45`.
  - `test_giro_giorno_include_righe_orarie_e_quadro`.
  - `test_un_solo_avviso_reinvia_email_originale`: `Mail::assertSent` con oggetto di `OperatoreAssegnatoNotification`.
  - `test_doppio_giro_nessun_doppio_invio` (Review Focus 4): due esecuzioni del comando con `Queue::fake` → job accodati per la chiave una sola volta.
  - `test_utente_disattivato_niente_invio_righe_marcate` (Review Focus 5).
  - `test_tre_fallimenti_scartata_e_stato_sistema_attenzione` (mailer che lancia).
  - `test_pulizia_30_giorni`.
- [ ] **Step 2–4**: FAIL → implement → PASS + `composer run analyse`.
- [ ] **Step 5: Commit** `feat(avvisi): giro di invio orario e del mattino`

### Task 8: Storico nella scheda

**Files:**
- Modify: `app/Listeners/TracciaNotificaInviata.php`, `app/Models/NotificaSegnalazione.php`, `resources/views/segnalazioni/scheda/_notifiche.blade.php` (e il punto che lo alimenta), `tests/Feature/TracciaNotificheTest.php`
- Test: `tests/Feature/Avvisi/StoricoRiepilogoTest.php`

**Interfaces:**
- Consumes: `RiepilogoAvvisiNotification::tipiTracciati()`.
- `TracciaNotificaInviata`: se la notifica ha `tipiTracciati()` → una riga per (segnalazione, tipo originale), `tipo` = basename originale, `canale` = canale con suffisso `:riepilogo` (es. `mail:riepilogo`, max 30 char). `NotificaSegnalazione::canaleLeggibile()` mostra «email (nel riepilogo)».
- Widget: avvisi in attesa della segnalazione (`AvvisoInAttesa::inAttesa()->where('id_segnalazione', …)`) elencati come «<etichetta> — in attesa, parte con il riepilogo delle HH:00 | di domattina» (`ora` → prossima ora piena in fascia, calcolata con `CalendarioUfficio`; `giorno` → «di domattina»/«del prossimo giorno lavorativo»). `NotificaSegnalazione::ETICHETTE` diventa `public const` per riusarla.
- [ ] **Step 1: Tests**: `test_riepilogo_traccia_tipo_originale`, `test_scheda_mostra_avviso_in_attesa`; riscrivere in `TracciaNotificheTest` i casi sollecito/inattività: dopo l'evento nessuna riga, dopo `avvisi:invia --forza` riga con `tipo=SollecitoNotification`.
- [ ] **Step 2–4**: FAIL → implement → PASS.
- [ ] **Step 5: Commit** `feat(avvisi): storico e avvisi in attesa nella scheda`

### Task 9: Documentazione e rilascio

**Files:**
- Modify: `CLAUDE.md` (sezione «Funzionalità»: riga «Riepilogo avvisi (1.0.21)» con tabella, listener, comando, `Raggruppabile`, gotcha test: `Notification::fake()` non passa dal listener; elenco comandi schedulati nel Deploy: `avvisi:invia`, `digest:invia` non più schedulato; tabella impostazioni AVVISI), `CHANGELOG.md` (`## [1.0.21] - 2026-11-27`), `publiccode.yml` (`softwareVersion: 1.0.21`, `releaseDate`)
- [ ] **Step 1**: aggiornare i file.
- [ ] **Step 2**: `docker compose exec php php artisan test tests/Feature/Avvisi tests/Feature/TracciaNotificheTest.php tests/Feature/DigestGestoriTest.php tests/Feature/SegnalazioneWorkflowNotificationsTest.php tests/Feature/NotificheDittaTest.php tests/Feature/AvvisiSlaTipologiaTest.php` → PASS; `composer run analyse` pulito. (`DigestGestoriTest` testa il comando, che resta: deve restare verde.)
- [ ] **Step 3: Commit** `docs(avvisi): riepilogo avvisi 1.0.21`
