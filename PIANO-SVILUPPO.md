# ProntoPA — Roadmap

> Aggiornata al 2026-10-08 (`main` @ `68af61c`, ultima release `v0.7.2`).
> Punto di vista: ufficio tecnico di una PA italiana che coordina le manutenzioni
> tra segnalatori (scuole, URP, uffici), imprese esterne e operai interni.
>
> **Principio guida**: il sistema deve *togliere* lavoro, non aggiungerne.
> Ogni funzionalità risponde alla domanda: "questa cosa fa risparmiare una
> telefonata, una mail o un giro in macchina a qualcuno?"
>
> **Obiettivo**: produzione nell'ente + pacchetto riusabile da altri enti.
>
> Questo file è la roadmap (cosa, perché, in che ordine). Lo stato di
> avanzamento voce per voce è in [`TODO.md`](TODO.md), il dettaglio di ciò che
> è uscito in [`CHANGELOG.md`](CHANGELOG.md), il design in `docs/superpowers/specs/`.

---

## 1. Numerazione

Fino a ottobre 2026 i documenti usavano nomi di release interni (v0.8
"Assistente locale", v1.0 "Riuso e numeri", v1.1 "Hardening", v1.2 "Identità e
accessi") scollegati dai tag pubblicati: tutto quel lavoro è uscito nei tag
`0.6.x`–`0.7.x`. **Da qui in poi la roadmap usa solo i numeri dei tag reali.**
I nomi storici restano nei nomi dei file di spec/plan.

**1.0.0 = ProntoPA in produzione nell'ente con tutti i canali di accesso
definitivi** (dipendenti AD, scuole SPID/CIE con deleghe, ditte email+2FA) e
adempimenti PA a posto. Non prima.

---

## 2. Cosa c'è già (fino a `v0.7.2`)

| Tag | Data | Contenuto |
|---|---|---|
| 0.1–0.3 | mar–apr 2026 | Base Laravel dal legacy, landing pubblica, disattivazione utenti, notifiche email, bot Telegram |
| 0.4–0.5 | apr–mag 2026 | Allegati foto/video da mobile, API REST Sanctum, webhook HMAC |
| 0.6.0–0.6.2 | mag 2026 | Adozione: URP "per conto di", anti-duplicato + adesioni + merge, digest gestori, azioni rapide, squadre. Ditte e canali: rapportino fotografico, magic link, Telegram esteso, preventivi tracciati. AI locale opzionale (Ollama): titolo, triage suggerito, dedup semantico. KPI, export XLSX, fascicolo PDF |
| 0.6.3 | set 2026 | Hardening: Dependabot, Trivy bloccante, Action pinnate, 2FA TOTP, ClamAV sugli allegati, Dusk in CI, alert job falliti |
| 0.7.0–0.7.2 | ott 2026 | Identità fase 1+2a: login AD con ruoli dai gruppi, ditte email+2FA via email, SPID/CIE via pa-sso-proxy (solo accesso + verifica email), rimozione wizard, `publiccode.yml`, demo, OpenAPI, servizi `queue`/`scheduler`, Sentry |
| *Unreleased* | | `ldaprecord` 3→4 (semantica TLS cambiata), `php-jwt` 6→7 |

---

## 3. Cosa manca, in ordine

Non siamo in produzione: nessuna release intermedia urgente. Gli
aggiornamenti in *Unreleased* (`ldaprecord` 4, `php-jwt` 7) escono con la 0.8.0
(AD già verificato in un altro ambiente; resta lo smoke SPID contro il proxy
reale prima del go-live).

### 0.8.0 — Deleghe scuole (fase 2b, ~3–5 giorni di sviluppo)

**Oggi un utente che entra con SPID/CIE resta parcheggiato sulla pagina
d'attesa (`LimitaAccessoSpid::consentite()` restituisce solo `spid.attesa`):
il canale scuole è rilasciato ma inutilizzabile.** È il pezzo più grande che
manca ed è sul percorso critico del go-live.

Spec: [`2026-09-30-v12-identita-accessi-design.md`](docs/superpowers/specs/2026-09-30-v12-identita-accessi-design.md) §"Flussi — deleghe".

- [ ] Plan di dettaglio (`docs/superpowers/plans/`)
- [ ] **Anagrafe scuole MIUR** (prerequisito: senza `istituti.email`
      nessuna delega è richiedibile) — vedi §3.1
- [ ] Tabelle `deleghe` + `deleghe_storico`, `DelegaService` con le invarianti
- [ ] Richiesta delega (istituto intero o plessi, 6 controlli anti-abuso)
- [ ] Email + pagina segreteria senza login (GET innocua, POST approva/rifiuta/blocca)
- [ ] `deleghe:rinnovi` (mensile, una email per scuola) e `deleghe:scadenze` (giornaliero)
- [ ] Admin → Deleghe: elenco, pre-delega per CF, attivazione d'ufficio, revoca, reinvio, sblocco
- [ ] Visibilità: ramo `spid` in `scopeVisibileA`, Policy, form creazione limitato ai plessi coperti
- [ ] `LimitaAccessoSpid::consentite()` per delega attiva
- [ ] Test Feature + Dusk (SPID mock → profilo → delega → approvazione → segnalazione)

### 3.1 Anagrafe scuole MIUR

Sostituisce lo script legacy `scuole.php` (rimosso). L'admin sceglie da
Admin → Anagrafe MIUR quali istituti e sedi esistono in ProntoPA; nome,
indirizzo ed email dei record selezionati vengono dall'open data MIUR e si
riallineano quando l'admin scarica il file di un nuovo anno (URL in
Impostazioni). Nessuna cancellazione automatica. Primo go-live: Comune di
Montesilvano (infanzia, primarie, medie, comprensivi).

Spec: [`2026-10-08-v080-anagrafe-miur-deleghe-design.md`](docs/superpowers/specs/2026-10-08-v080-anagrafe-miur-deleghe-design.md).

### 0.9.0 — Cutover (~2 giorni di sviluppo)

Release candidate per il go-live.

- [ ] `utenze:cutover` (dry-run di default): disattiva segnalatori legacy,
      report utenti `locale` non ditta, ditte con email mancante/duplicata,
      istituti senza email; forza 2FA email sulle ditte senza
- [ ] Rimozione `/register` e `accounts:annual-check` (oggi schedulato in
      `bootstrap/app.php`, gli altri comandi stanno in `routes/console.php`:
      unificare in un posto solo)
- [ ] Retention GDPR (H6 in spec hardening): implementazione 1 giorno,
      *serve la decisione dell'ente sui tempi*

### 1.0.0 — Go-live

- [ ] Pilota con 2–3 scuole: pre-deleghe da admin, giro completo richiesta → segreteria → segnalazione
- [ ] `utenze:cutover` reale in finestra concordata
- [ ] Staging (anche solo un secondo stack Portainer con AD/SPID di test) prima del cutover
- [ ] Spec v1.2 da "bozza" a "approvata", CLAUDE.md/README allineati

---

## 4. Dipendenze dall'ente (percorso critico reale)

Lo sviluppo che manca è di pochi giorni; i tempi del go-live li dettano queste voci.

| Cosa | Serve per | Chi |
|---|---|---|
| Gruppi `PRONTOPA_*` in AD popolati | Go-live (già verificato altrove) | Sistemista |
| Client registrato su `pa-sso-proxy` (issuer, client id/secret) | Smoke SPID, 0.8.0 | Sistemista / gestore proxy |
| Selezione istituti/sedi comunali da anagrafe MIUR | 0.8.0 | Admin ProntoPA |
| Elenco iniziale delegati per scuola (CF + istituto/plessi) | Pre-deleghe al go-live, evita la valanga di richieste il primo giorno | Ufficio tecnico + scuole |
| Ditte attive con email univoca | Cutover: senza, la ditta non entra | Ufficio tecnico |
| Tempi di conservazione dati | Retention GDPR | DPO / segretario |
| Informativa privacy / dichiarazione accessibilità | Già pubblicate sul proxy di accesso | — |
| Host o namespace per staging | 1.0.0 | Sistemi informativi |

---

## 5. Rischi aperti

- **Canale scuole fermo fino a 0.8.0.** Chi entra con SPID resta sulla pagina
  d'attesa. Non bloccante finché non siamo in produzione, ma 0.8.0 è il
  percorso critico del go-live.
- **Nessuno staging**: SPID è testabile solo con mock o contro il proxy
  reale. Il cutover sui segnalatori legacy è poco critico (dati legacy non
  vincolanti), ma va lanciato prima in dry-run.
- **Email alle segreterie**: tutto il modello deleghe regge sul fatto che la
  casella `<codmecc>@istruzione.it` venga letta. Il rinnovo annuale senza
  risposta fa scadere le deleghe: prevedere un sollecito via telefono/URP
  nei primi mesi e monitorare le deleghe scadute.
- **Recapito email**: le email partono dall'ente verso `istruzione.it`;
  verificare SPF/DKIM del mittente prima del go-live, o finiscono in spam.
- **PHPStan locale vs CI** diverso (vedi CLAUDE.md): ogni modifica al
  baseline va verificata su CI.

---

## 6. Dopo 1.0 — idee in riserva

In ordine di valore stimato, da rivalutare con i dati dei primi mesi.

1. **Stato "in attesa di" con causale** (preventivo/ditta/materiale): i report
   distinguono ritardo dell'ente da ritardo del fornitore.
2. **Email-in**: casella `segnalazioni@ente` → bozza in coda triage URP (con
   l'LLM locale per estrarre i campi).
3. **Intake guidato per tipologia** (domande chiuse → testo strutturato).
4. **GPS automatico** con suggerimento del plesso più vicino.
5. **Modulo cittadino pubblico** senza account (attivabile per ente).
6. **Integrazione protocollo informatico** (solo con casi reali).
7. Rimozione colonne `annual_verification_*` (release successiva al cutover).

## 7. Cosa NON fare

- **Niente app native**: PWA + Telegram coprono il campo a costo ~zero.
- **Niente motore BPMN configurabile**: i 10 stati di `SegnalazioneStato` bastano.
- **Niente AI cloud e niente chatbot al cittadino**: AI solo locale,
  asincrona, con conferma umana. Mai scritture automatiche.
- **Niente bulk actions** in gestione: troppa superficie di errore.
- **Niente SPID persona giuridica per le ditte** né deleghe gerarchiche.

## 8. Regole trasversali

- Nessun campo obbligatorio in più per chi segnala: le informazioni arrivano
  da default intelligenti, non da moduli più lunghi.
- Ogni automatismo è disattivabile da Admin → Impostazioni.
- Migrazioni solo additive (colonne nullable, tabelle nuove).
- Ogni release: branch → PR (con bump `publiccode.yml` + CHANGELOG) → CI
  verde → squash → tag → GHCR → stack Portainer.
