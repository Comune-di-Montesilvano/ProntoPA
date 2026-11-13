# Nuova segnalazione rifatta da zero — design

Data: 2026-11-13 · Stato: bozza da approvare · Versione prevista: patch successiva alla 0.9.20

## Perché

Il form "Nuova segnalazione" è il punto da cui parte tutto, ed è rimasto quello di prima mentre
l'iter dopo è stato semplificato (0.9.19/0.9.20). Chi entra per segnalare un guasto trova:
- 12 tipologie divise in gruppi, di cui molte non c'entrano con la sede;
- un riquadro "Per conto di" e una provenienza che a chi segnala non dicono niente;
- un placeholder con dati reali ("Fornitura 15 panchine plesso Colonnetta");
- "Più dettagli" chiuso, con dentro un campo obbligatorio e le foto: nessuno lo apre;
- una mappa enorme, anche quando la sede è già scelta.

Parole del committente: "un cristo che si collega per segnalare un guasto può trovarsi questo
schifo?" — e "fondamentalmente lo stiamo rifacendo da zero". Il form cambia **per tutti**
(scuole via SPID, personale del comune, URP), non solo per un ruolo.

Successo: chi segnala vede solo campi che capisce, tutti in vista, e arriva in fondo senza
errori "nascosti". Chi lavora (operaio, ditta) trova subito il posto sulla mappa.

## Decisioni del committente

1. **Si parte sempre da una sede.** Sede obbligatoria per tutti. Niente segnalazioni senza sede
   (strade, piazze): i cittadini si valuteranno più avanti, se e quando entreranno.
2. **Tipologie: un solo elenco, uguale per tutte le sedi.** "Che sia una scuola lo dice la sede,
   non la tipologia". I **gruppi di tipologie vanno cancellati** (tabella `gruppi_segnalazioni`,
   colonna `tipologie_segnalazioni.id_gruppo`, flag `cittadini`, la scritta "· EDILIZIA SCOLASTICA"
   ovunque compaia).
3. **Appalti come i gestori: legati alle tipologie** (elenco di tipologie oppure "tutte"). Il
   pannello Assegna propone le ditte con un appalto valido per la tipologia della segnalazione.
4. **Priorità: resta com'è** nel form.
5. **Titolo: resta obbligatorio** (max 100). Si toglie solo il placeholder con dati reali.
6. **Mappa: esce dal form** (la posizione la dà la sede) e **resta lato backoffice**: le sedi hanno
   coordinate, ricavate dall'indirizzo o fissate a mano e salvate, così ditte e operai
   individuano subito il posto.
7. **Niente "Più dettagli"**: quello che resta è sempre in vista ("se le mettiamo in dettagli non le
   compilerà mai nessuno").
8. **"Per conto di": via** (inutile).
9. **Provenienza: deve sparire dal database**, non solo dal form.
10. **"Dove si trova il problema"** (interno, esterno, impianto, area verde): resta, **facoltativo**.
11. **Foto: restano, facoltative**, in vista.
12. **Specializzazioni ("Tipo di intervento richiesto"): via.** L'operaio che non può fare un lavoro
    lo scrive nel testo e lo rimanda al gestore.
13. **"Rimanda al gestore"** per operaio **e ditta** (nuova azione, vedi sotto).
14. **Appalti nel pannello Assegna**: prima le ditte con appalto valido per la tipologia, sotto
    "Altre ditte" con tutti gli altri appalti validi. Nessuno resta bloccato.
15. **API**: nessuno la usa oggi → sede obbligatoria anche via API, campi tolti ignorati.
16. **Dati del chiamante** ("Per conto di"): **cancellati dal database** come la provenienza; le
    adesioni "per conto" diventano adesioni normali.

## Il form

Ordine dei campi, tutti visibili, nessuna sezione richiudibile:

1. **Sede** (obbligatoria) — solo le sedi che l'utente può usare (scuola SPID: quelle coperte dalle
   sue deleghe; personale del comune: tutte le sedi attive). Se ne ha una sola è già selezionata.
   Sotto la select: indirizzo della sede (testo, niente mappa).
2. **Tipologia** (obbligatoria) — elenco unico, senza intestazioni di gruppo.
3. **Priorità** — come oggi (Bassa/Media/Alta/Critica, testo "intervento previsto entro…").
4. **Titolo** (obbligatorio, max 100) — senza placeholder di esempio con dati reali.
5. **Descrizione** (obbligatoria).
6. **Dove si trova il problema** (facoltativo) — interno, esterno, impianto, area verde; nessuna
   scelta = non specificato.
7. **Foto** (facoltative) — pulsante in vista.
8. Avviso 112 e pulsante **Invia segnalazione**.

Spariscono: riquadro "Per conto di" (nome/telefono/email chiamante), provenienza (select o testo),
"Tipo di intervento richiesto", "Più dettagli", la mappa e i campi nascosti latitudine/longitudine.

## Fuori dal form

### Tipologie senza gruppi
- Migration: drop di `tipologie_segnalazioni.id_gruppo` e della tabella `gruppi_segnalazioni`
  (dopo aver convertito gli appalti, sotto).
- Tutte le viste che raggruppano o mostrano il gruppo diventano elenco piatto ordinato per nome:
  form, scheda (riga "Tipologia"), stampa scheda, tipologie dei gestori, tipologie orfane, filtri
  della gestione, statistiche/report, demo.
- API (`POST /api/segnalazioni`): nessun riferimento ai gruppi; `docs/API.md` e `openapi.yaml`
  aggiornati.

### Appalti legati alle tipologie
- Nuova relazione appalto ↔ tipologie (stesso schema di gestori: pivot + flag "tutte").
- Migration: ogni appalto esistente prende **le tipologie che oggi stanno nel suo gruppo**; poi si
  toglie `appalti.id_gruppo`.
- Form appalto: scelta delle tipologie come nella pagina "Tipologie dei gestori" (con "tutte").
- Pannello Assegna (scheda e azioni multiple): ditte/appalti validi che coprono la tipologia della
  segnalazione.

### Provenienza eliminata
- Drop di `segnalazioni.id_provenienza`, `users.id_provenienza` e della tabella delle provenienze
  (model `Provenienza`).
- Oggi la provenienza serve anche a riconoscere le scuole (`User` riga ~153, `SpidLoginService::
  PROVENIENZA_SCUOLE`) e a distinguere l'URP (`MappaGruppiLdap`, provenienza 3 + permesso
  `segnalazioni.per-conto`): va sostituita da ciò che c'è già (utente `spid` con delega = scuola;
  gruppo AD URP = segnalatore del comune, senza "per conto").
- Via filtri e colonne "Provenienza" in gestione, statistiche, export XLSX, PDF, API.
- Il permesso `segnalazioni.per-conto` va eliminato; le colonne del chiamante (`segnalante`,
  `email`, `telefono` su segnalazioni e adesioni) vengono **cancellate** con i loro dati. Le adesioni
  dell'URP restano come adesioni dell'utente; il dedup "per chiamante" sparisce.
- API `POST /api/segnalazioni`: `id_plesso` obbligatorio (422 se manca o non valido), `id_provenienza`
  non più richiesto; `segnalante`/`email`/`telefono`/`latitudine`/`longitudine`/`id_specializzazione`
  ignorati se inviati. `docs/API.md`, `openapi.yaml` e CHANGELOG aggiornati (nessun integratore
  attivo, conferma del committente).

### Specializzazioni eliminate
- **Eliminate dal database** (tabella, `segnalazioni.id_specializzazione`,
  `sla_configurazioni.id_specializzazione`) — *scelta di Claude, coerente con provenienza e chiamante*;
  il triage AI smette di suggerirle.
- Via dal form, dai filtri e dalle regole di scadenza (oggi una regola SLA può dipendere da
  tipologia, **specializzazione** e priorità: resta tipologia e priorità). Le regole che
  differivano solo per specializzazione vanno unite in modo deterministico (vedi "Lasciato al
  costruttore").

### Coordinate delle sedi
- `plessi` ottiene latitudine/longitudine.
- Admin → Enti e sedi → scheda sede: mappa con il punto; pulsante "Trova dall'indirizzo"
  (geocoding OpenStreetMap/Nominatim, **scelta di Claude**: gratuito, esce solo l'indirizzo
  dell'edificio pubblico, rispettando i limiti d'uso di Nominatim) e punto trascinabile a mano;
  si salva.
- Comando per ricavare in blocco le coordinate delle sedi che non le hanno (con pausa tra le
  richieste), utilizzabile anche dopo l'import MIUR.
- Scheda segnalazione (gestore, operaio, ditta): mappa piccola della sede + link "Apri in Maps".
  Sede senza coordinate: solo l'indirizzo, niente mappa vuota.
- Le coordinate per segnalazione (`segnalazioni.latitudine/longitudine`) **si eliminano** — *scelta di
  Claude*: la mappa della dashboard operaio usa le coordinate della sede; il dedup "per vicinanza"
  diventa "stessa sede". Segnalazioni storiche senza sede (`id_plesso` 0): in scheda nessuna mappa.
- "Tutte le sedi attive" = sedi di enti attivi (`istituti.attivo`), anche per gestori e admin —
  *scelta di Claude*; nessun flag nuovo sulle sedi.

### "Rimanda al gestore" (operaio e ditta)
- Nuova azione da **Assegnata** (operaio/squadra) e **Assegnata a ditta**, **nota obbligatoria** (il
  perché: "serve un elettricista"). Torna a **In carico** al gestore che aveva fatto l'ultima
  assegnazione, che riceve un'email con la nota; chi rimanda perde il lavoro (come una riassegnazione).
- Chi aveva assegnato non è più attivo o non è un gestore → torna **In carico** senza nome e l'email
  va ai supervisori — *scelta di Claude*.
- La scadenza resta com'è (manuale o calcolata) — *scelta di Claude*.

## Non obiettivi
- Segnalazioni senza sede, cittadini, portale pubblico.
- Cambiare l'iter dopo l'invio (0.9.19/0.9.20).
- Ridisegnare la scheda o gli elenchi oltre a quanto serve per togliere gruppi/provenienza.

## Anti-obiettivi (fallimento anche se "funziona")
- Un campo obbligatorio che non si vede.
- Una sezione richiudibile nel form.
- Esempi o placeholder con nomi di sedi/persone reali.
- I gruppi o la provenienza che sopravvivono in qualche schermata, export o API.
- Una mappa nel form di invio.

## Vincoli
- È in produzione (deploy automatico ~1 h dal tag): le migration convertono i dati, non li perdono
  dove servono (appalti → tipologie); provenienza e gruppi si eliminano per scelta del committente
  (il backup notturno c'è).
- Tutto ciò che sta in `AllineamentoSeeder` resta idempotente (il seeder non deve ricreare gruppi,
  provenienze o specializzazioni).
- Niente CDN: Leaflet già servito da `public/build/vendor/`.
- Nuove dipendenze: nessuna. Geocoding via `Http` di Laravel.

## Lasciato al costruttore
- Struttura delle migration e ordine (appalti prima del drop dei gruppi).
- Come unire le regole SLA che differivano solo per specializzazione (proposta: tenere la più
  stringente).
- Layout esatto del form dentro i componenti `x-pa.*` esistenti.
- Dimensione e posizione della mappa nella scheda.
