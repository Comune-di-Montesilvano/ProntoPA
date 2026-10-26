# SLA in orario d'ufficio — design

Stato: bozza da approvare · 2026-10-26

## 1. Perché

Oggi la scadenza SLA è "data segnalazione + N ore di calendario". Una regola da 48 ore aperta il
venerdì alle 16 scade la domenica alle 16, quando l'ufficio è chiuso: il tempo massimo risulta
superato senza che nessuno potesse intervenire, e scadenze, avvisi "a rischio" e digest perdono
credibilità. Il comune lavora solo in orario d'ufficio e senza reperibilità ("se è una vera
urgenza si chiamano i pompieri, non il comune").

## 2. Obiettivi, non obiettivi, anti-obiettivi

**Obiettivi**
- La scadenza conta solo il tempo in cui l'ufficio è aperto.
- Orario per giorno della settimana e data del patrono impostabili dall'admin (riuso da altri enti).
- Ogni regola SLA si esprime in **ore lavorative** o in **giorni lavorativi**.
- Il segnalante, scelta la priorità, vede il tempo previsto, con l'avvertenza che la priorità
  definitiva la decide l'ufficio.
- Eliminato il flag "urgente": era un doppione di Critica e prometteva un intervento immediato
  che il comune non può garantire.

**Non obiettivi**
- Pausa pranzo o più fasce per giorno (pausa "trascurabile", decisione del committente).
- Chiusure straordinarie o ponti (vedi §9, possibile estensione).
- Sospendere il conteggio mentre la segnalazione è Sospesa (comportamento attuale invariato).
- Cambiare la scala di priorità per anzianità (`sla_anzianita_scala_giorni`, resta in giorni di calendario).

**Anti-obiettivi**
- Una scadenza che cade fuori orario d'ufficio (di notte, nel weekend, in un festivo).
- Il segnalante che legge il tempo previsto come una promessa vincolante.
- Segnalazioni aperte che restano con la scadenza vecchia dopo una modifica di regole o orario.

## 3. Decisioni del committente

1. Contano solo le ore in cui il comune lavora. Orario tipico: lunedì, mercoledì e venerdì 8–14;
   martedì e giovedì 8–17. Sabato e domenica chiuso.
2. Esclusi i festivi nazionali e il patrono (data impostabile).
3. Una sola fascia al giorno: la pausa pranzo non si conta.
4. Per alcune priorità ha senso parlare di giorni lavorativi invece che di ore (es. Critica in
   ore, Alta 1 giorno lavorativo). I valori delle regole li rivede il committente.
5. Il flag urgente si elimina. Non c'è reperibilità.
6. Il form di segnalazione mostra il tempo previsto per la priorità scelta e specifica che la
   priorità corretta la decide l'ufficio.
7. Alla modifica di regole, orario o patrono le segnalazioni aperte **ricalcolano** la scadenza.
8. API verso il sito del Comune: il campo urgente sparisce, senza mappature (lì la priorità non esiste).

## 4. Calendario dell'ufficio

**Impostazioni** (Admin → Impostazioni, nuovo gruppo `orario`):
- `orario_lun` … `orario_dom`: fascia `HH:MM-HH:MM`, vuota = chiuso. Default seeder:
  lun/mer/ven `08:00-14:00`, mar/gio `08:00-17:00`, sab/dom vuoti.
- `patrono`: giorno e mese (`GG/MM`), vuoto = nessun patrono. Default vuoto: ogni ente mette il suo.

Validazione: inizio < fine, formato orario valido, patrono data esistente (29/02 ammesso). Almeno
un giorno aperto, altrimenti nessuna scadenza è calcolabile.

**Festivi nazionali** (non configurabili, calcolati per anno): 1/1, 6/1, Pasqua, Lunedì
dell'Angelo, 25/4, 1/5, 2/6, 15/8, 1/11, 8/12, 25/12, 26/12. Pasqua con l'algoritmo
gregoriano (es. `easter_days()` di PHP, o un calcolo equivalente se l'estensione `calendar`
manca nell'immagine).

**Fuso orario**: tutto nel timezone dell'app (Europe/Rome), compresi i cambi d'ora legale.

## 5. Calcolo della scadenza

Le regole SLA (`sla_configurazioni`) aggiungono una colonna **unità**: `ore` | `giorni`
(migrazione additiva, default `ore` per le regole esistenti). `ore_target` diventa "valore"
(si può tenere il nome di colonna e cambiare solo l'etichetta: lasciato al costruttore).

- **Ore lavorative**: dal momento della segnalazione si scorre il calendario sommando solo il
  tempo dentro le fasce aperte. Se la segnalazione arriva a ufficio chiuso, il conteggio parte
  dall'apertura successiva.
  - Esempio: sabato 10:00, regola 6 ore → lunedì 14:00.
  - Esempio: venerdì 13:00, regola 4 ore → 1 ora venerdì, poi lunedì 8–11 → lunedì 11:00.
  - Esempio: martedì 16:30, regola 1 ora → 30' martedì + 30' mercoledì → mercoledì 8:30.
- **Giorni lavorativi** *(scelta del progettista)*: N giorni lavorativi = **chiusura dell'ufficio
  dell'N-esimo giorno lavorativo successivo** al giorno della segnalazione. Il giorno della
  segnalazione non conta, anche se aperto.
  - Esempio: lunedì 10:00, 1 giorno → martedì 17:00. Venerdì 13:00, 1 giorno → lunedì 14:00.
  - Esempio: sabato, 1 giorno → lunedì 14:00 (lunedì è il primo giorno lavorativo dopo il sabato).

La scadenza cade sempre dentro o al limite di una fascia aperta (anti-obiettivo §2).

**A rischio**: `ore_warning` resta in **ore lavorative** anche quando la regola è in giorni.
"A rischio" = mancano meno di `ore_warning` ore lavorative alla scadenza (il calcolo attuale
usa ore di calendario e va allineato).

**Superata**: invariato, scadenza nel passato. Tempo reale, non lavorativo.

## 6. Ricalcolo

Si ricalcola `data_scadenza_sla` di tutte le segnalazioni **aperte** con una regola applicabile:
- al salvataggio o eliminazione di una regola SLA;
- al salvataggio delle impostazioni `orario_*` o `patrono` (solo se cambiano davvero).

Con il ricalcolo si rivalutano `sla_violato` e `sla_warning_inviato`: se la nuova scadenza è nel
futuro e oltre la soglia di rischio, i flag tornano falsi, così l'avviso può ripartire. Le
segnalazioni chiuse non si toccano. Il ricalcolo gira in un job in coda (possono essere migliaia
di righe); i messaggi di conferma lo dicono ("scadenze in aggiornamento").

Oltre ai casi sopra, la scadenza si ricalcola già oggi alla creazione e alla modifica della
priorità: continua a farlo con la nuova regola.

## 7. Eliminazione del flag urgente

- **Dati**: migrazione che porta a Critica (4) le segnalazioni **aperte** con
  `segnalazione_urgente=true`, poi ricalcolo delle loro scadenze. La colonna resta a DB per lo
  storico (nessun dato distrutto) ma non è più scritta né letta dall'app *(scelta del progettista)*.
- **Rimozioni**: checkbox nel form di creazione e nel pannello Priorità della scheda, tag
  "URGENTE" in `x-pa.prio` e negli elenchi, `scopeUrgente`, ordinamento e icona 🚨 nel bot
  Telegram, regola "urgente → priorità 4" in `SlaService`, alert immediato per urgenti in
  `sla:check` (resta solo per Critica), campo `segnalazione_urgente` dell'API (`SegnalazioneApiController`,
  `docs/API.md`, `docs/openapi.yaml`), colonna "Urgente" nel CSV e nel report mensile.
- **Avviso al posto del flag** nel form: "Per pericoli immediati (incendio, fuga di gas, crolli)
  chiama il 112: il comune interviene in orario d'ufficio."

## 8. Form di segnalazione e pagine admin

- **Form**: sotto la scelta della priorità, "Intervento previsto entro 4 ore lavorative" /
  "… entro 1 giorno lavorativo" per la combinazione tipologia + priorità scelta (stessa regola di
  `SlaConfigurazione::applicabileA`, specializzazione esclusa perché il segnalante non la sceglie).
  Si aggiorna al cambio di tipologia o priorità, senza ricaricare. Senza regola: niente testo.
  Sotto: "La priorità definitiva la stabilisce l'ufficio." Il testo è indicativo, non è la
  scadenza calcolata.
- **Admin → SLA**: nel form della regola, valore + unità (ore lavorative / giorni lavorativi);
  in elenco "4 ore lav." / "1 giorno lav.". Una riga di aiuto ricorda l'orario impostato.
- **Admin → Impostazioni**: gruppo "Orario d'ufficio" con 7 righe giorno + campo patrono, e
  un'anteprima dei prossimi festivi dell'anno (aiuta a verificare Pasqua e patrono).
- Scheda ed elenchi: nessun cambio di testo. "Superata da 2 g" / "Entro 5 h" restano in tempo
  reale (dicono quanto manca davvero, non quante ore lavorative).

## 9. Lasciato al costruttore

- Dove vive il calcolo (es. un servizio `CalendarioUfficio` con `aggiungiOreLavorative()`,
  `aggiungiGiorniLavorativi()`, `isFestivo()`), con test unitari sugli esempi del §5, il cambio
  d'ora legale, Pasqua di più anni e il patrono che cade di domenica.
- Formato di salvataggio delle fasce e del patrono in `impostazioni`.
- Se tenere `ore_target` come nome di colonna.
- Performance del ricalcolo (batch, chunk).
- Possibile estensione futura, **non** in questo lavoro: elenco di chiusure straordinarie
  (ponti, chiusura natalizia), stesso meccanismo dei festivi.

## 10. Rilascio

Patch (regola di versioning del progetto): CHANGELOG + `publiccode.yml` nella stessa PR, tag
dopo merge con CI verde, nessuna GitHub Release automatica. Nota operativa nel CHANGELOG: le
regole SLA esistenti diventano ore lavorative, vanno riviste in Admin → SLA; le segnalazioni
urgenti aperte diventano Critica.

## 11. Scelte del progettista (da verificare)

- "N giorni lavorativi" = chiusura dell'N-esimo giorno lavorativo successivo (§5).
- `ore_warning` sempre in ore lavorative (§5).
- Colonna `segnalazione_urgente` lasciata a DB, non più usata (§7).
- Tempo previsto nel form senza specializzazione (§8).
- Testi di scheda ed elenchi in tempo reale, non lavorativo (§8).
