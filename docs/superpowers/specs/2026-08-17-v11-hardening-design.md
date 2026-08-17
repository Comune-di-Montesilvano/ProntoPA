# ProntoPA v1.1 — "Hardening" — Design

**Data**: 2026-08-17
**Stato**: bozza, da revisionare
**Branch di partenza**: `main` (commit `5225154`)

## Obiettivo

Chiudere i gap emersi dall'audit produzione di v1.0 (P0-P3, completato e
pushato) che non erano bug attivi ma rischio residuo: policy di sicurezza
incoerenti, nessuna automazione contro il decadimento delle dipendenze,
nessuna osservabilità sui fallimenti silenziosi, debito tecnico documentato
ma non pagato.

Principio invariato: nessun automatismo obbligatorio senza opt-out da
Admin → Impostazioni dove ha senso; migrazioni additive.

## Contesto

v1.0 ha risolto i problemi che rompevano la produzione in silenzio (coda
senza worker, wizard irraggiungibile, licenza incoerente, 42 CVE). Questo
spec copre quello che restava *a rischio* ma non *rotto*: emerso rileggendo
il codice con occhio da manutentore a lungo termine, non da checklist.

## H1 — Password policy coerente ✅ FATTO

**Problema**: il wizard di setup (`SetupController::richiediOtp`) impone
`Password::min(10)->mixedCase()->numbers()`. Registrazione utente
(`RegisteredUserController`) e cambio password (`PasswordController`) usano
`Password::defaults()` puro — 8 caratteri, zero complessità, mai
personalizzato in `AppServiceProvider::boot()`. Per un sistema che autentica
gestori/imprese con dati di minori (scuole), l'account più debole è quello
usato più spesso.

**Soluzione**: `Password::defaults()` centralizzato in
`AppServiceProvider::boot()`:
```php
Password::defaults(fn () => Password::min(10)->mixedCase()->numbers());
```
Il wizard può poi usare `Password::defaults()` invece di ripetere la regola
inline — un'unica fonte di verità. Nessuna migrazione, nessun impatto su
password esistenti (si applica solo a nuove/cambiate).

**Effort**: < 1h.

## H2 — Dependabot per Composer e npm ✅ FATTO

**Problema**: le 42 CVE risolte in v1.0 si erano accumulate perché nessuna
automazione segnala dipendenze vulnerabili — solo un audit manuale le ha
trovate. Senza dependabot/renovate, la stessa situazione si ripresenta tra
qualche mese, silenziosamente.

**Soluzione**: `.github/dependabot.yml`, aggiornamenti settimanali per
`composer` e `npm`, PR automatiche (non merge automatico — revisione umana
resta necessaria, `tests.yml` già gira su ogni PR e la blocca se rompe).

```yaml
version: 2
updates:
  - package-ecosystem: composer
    directory: "/"
    schedule: { interval: weekly }
  - package-ecosystem: npm
    directory: "/"
    schedule: { interval: weekly }
  - package-ecosystem: github-actions
    directory: "/"
    schedule: { interval: weekly }
```

**Effort**: < 30 min.

## H3 — Alert su job falliti

**Problema**: `failed_jobs` esiste (migrazione base Laravel) ma nessuno la
guarda. Un webhook verso un host irraggiungibile fallisce 3 volte
(`InviaWebhookOutbound::$tries = 3`) e finisce lì, silenzioso — l'ente non
sa che il sito del Comune non riceve più notifiche di cambio stato.

**Soluzione**: si chiude in gran parte da sé quando `SENTRY_LARAVEL_DSN`
viene configurato (sentry-laravel cattura le eccezioni dei job falliti di
default). Da fare comunque, indipendente dal DSN: comando schedulato
settimanale che conta `failed_jobs` e notifica i gestori via digest
esistente se `> 0` — difesa in profondità per il caso in cui Sentry non sia
configurato o sia giù.

**Effort**: 2-3h (comando + test).

## H4 — Ambiente di staging

**Problema**: `main` → tag → produzione diretto. Le modifiche a
`docker-compose.yml` di v1.0 (servizi `queue`/`scheduler`) non sono mai
girate su un ambiente prod-simile prima di questo spec — solo su dev locale
con bind mount, un contesto diverso (niente immagini GHCR, niente rootless
Podman).

**Soluzione**: fuori scope per un singolo spec — richiede infrastruttura
(un secondo host o namespace Portainer). Minimo utile: branch `staging` con
lo stesso `docker-compose.yml` di prod ma tag `:dev` (già buildata da
`release.yml` su ogni push a `dev`), deployata su un'istanza separata prima
di taggare una release.

**Effort**: infrastruttura, non codice — richiede decisione ente su dove
ospitarla.

## H5 — Scan antimalware upload

**Problema**: `AllegatiSegnalazioniController` valida `mimetypes` lato
Laravel (bypassabile con un file rinominato/polyglot) — nessuno scan
contenuto reale. Segnalanti esterni e imprese caricano file liberamente.

**Soluzione**: container ClamAV (`clamav/clamav` su Docker Hub) + job
`ScansionaAllegato` in coda che scansiona dopo l'upload, quarantena
(spostamento su disco separato, non cancellazione — serve per contestazioni)
se positivo. Coerente col pattern AI già in uso: async, degrada in silenzio
se il container non è attivo (profilo Docker opzionale `security`, come `ai`
per Ollama).

**Effort**: 1-2 giorni (container + job + test + UI stato scansione).

## H6 — Retention/cancellazione dati (GDPR)

**Problema**: nessuna policy di cancellazione per segnalazioni chiuse o dati
personali dei segnalanti. Con l'import legacy appena rifatto, il DB ha
segnalazioni reali di anni fa mai valutate per minimizzazione dati.

**Soluzione**: comando schedulato configurabile da Impostazioni
(`gdpr_retention_mesi`, default disattivato = nessuna cancellazione
automatica) che anonimizza `segnalante`/`email`/`telefono` sulle
segnalazioni archiviate oltre N mesi, mantenendo dati aggregati per le KPI.
Richiede decisione dell'ente sulla policy prima di scrivere codice — non è
solo tecnico.

**Effort**: 1 giorno codice + decisione policy (non tecnica) a monte.

## H7 — 2FA per admin/gestore

**Problema**: nessun secondo fattore su nessun account. Per un pannello che
tocca dati di minori (scuole), è il gap più serio della lista.

**Soluzione**: TOTP (`pragmarx/google2fa-laravel` o simile), opzionale per
`segnalatore`/`impresa`, **obbligatorio** per `admin`/`gestore` (forzato al
primo login se non configurato, pattern simile alla verifica annuale
account già esistente).

**Effort**: 2-3 giorni (setup flow, recovery codes, test, non banale da
fare bene).

## H8 — Debito tecnico: baseline PHPStan e CSP inline

**Problema**: 145 errori PHPStan baselinati (in gran parte model senza
`@property`, non bug) — la baseline nasconde anche eventuali bug futuri
nello stesso file finché nessuno la ripulisce. CSP con
`unsafe-inline`/`unsafe-eval` necessaria per centinaia di `style=""` inline
nelle view.

**Soluzione**: non uno sprint dedicato — pagare a piccoli pezzi ogni volta
che si tocca un file esistente in baseline (aggiungere `@property` al
model, spostare lo style inline in una classe Tailwind). Nessun effort
stimabile in blocco, è manutenzione continua.

## H9 — Test E2E

**Problema**: le verifiche Lighthouse/CSP/accessibilità di v1.0 sono state
fatte a mano via Chrome DevTools — non ripetibili in CI, si degradano alla
prossima modifica senza che nessuno se ne accorga.

**Soluzione**: Laravel Dusk (già nell'ecosistema, zero infra aggiuntiva vs
Playwright) per 3-4 flow critici: login, creazione segnalazione, wizard
setup, cambio stato. Non sostituisce Feature test, li completa dove serve
un browser reale (CSP, JS, mappa Leaflet).

**Effort**: 1-2 giorni setup + primi 4 test.

## Priorità consigliata

| # | Item | Effort | Impatto |
|---|---|---|---|
| H1 | Password policy | < 1h | Alto |
| H2 | Dependabot | < 30 min | Alto (previene regressione v1.0) |
| H3 | Alert job falliti | 2-3h | Medio |
| H7 | 2FA admin/gestore | 2-3 gg | Alto (dati minori) |
| H5 | Scan upload | 1-2 gg | Medio |
| H9 | Test E2E | 1-2 gg | Medio |
| H6 | Retention GDPR | 1 gg + decisione ente | Medio, blocca su policy non tecnica |
| H4 | Staging | infrastruttura | Medio, blocca su decisione ente |
| H8 | Debito PHPStan/CSP | continuo | Basso, nessuno sprint dedicato |

Sequenza suggerita: H1 → H2 (un pomeriggio, chiudono i due gap più economici)
→ H7 → H5 → H9 → H3, poi H4/H6 quando l'ente decide su infrastruttura e
policy dati.
