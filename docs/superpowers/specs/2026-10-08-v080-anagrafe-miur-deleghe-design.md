# ProntoPA 0.8.0 — Anagrafe scuole MIUR + deleghe — Design

**Data**: 2026-10-08
**Stato**: da revisionare
**Roadmap**: [`PIANO-SVILUPPO.md`](../../../PIANO-SVILUPPO.md) §3 (0.8.0)

## Obiettivo

Rendere utilizzabile il canale scuole (SPID/CIE), oggi fermo sulla pagina
d'attesa. Due parti, nell'ordine:

1. **Anagrafe scuole MIUR** — l'admin sceglie quali istituti e sedi esistono
   in ProntoPA; i loro dati (nome, indirizzo, email) vengono dall'open data
   MIUR. Prerequisito delle deleghe: l'email della segreteria è il
   destinatario delle richieste.
2. **Deleghe** — fase 2b della spec
   [`2026-09-30-v12-identita-accessi-design.md`](2026-09-30-v12-identita-accessi-design.md),
   con le modifiche elencate in fondo a questo documento.

Sostituisce lo script legacy `scuole.php` (endpoint JSON per Formio che
interrogava lo stesso dataset a ogni richiesta), rimosso.

## Contesto

- Primo go-live: **Comune di Montesilvano**. Competenza comunale =
  infanzia, primarie, secondarie di primo grado, comprensivi. Le superiori
  (IIS Alessandrini, Liceo D'Ascanio) sono della Provincia: l'admin
  semplicemente non le seleziona.
- Dataset 2025/26 (`SCUANAGRAFESTAT20252620250901.json`, ~50 MB, JSON-LD
  `@graph`, ~50k righe, statali), verificato il 2026-10-08:
  - Montesilvano: 6 istituti di competenza comunale (D.D. Montesilvano, IC
    Silone, IC Delfico, IC Villa Verrocchio, IC Rodari) con 27 sedi;
  - IC Rodari ha 3 sedi a Cappelle sul Tavo (edifici non comunali);
  - per comprensivi e superiori la riga con `CODICESCUOLA =
    CODICEISTITUTORIFERIMENTO` è la sede amministrativa (grado "ISTITUTO
    COMPRENSIVO"/"ISTITUTO SUPERIORE"), spesso allo stesso indirizzo di una
    sede reale; nella D.D. invece è una primaria vera;
  - email: tutte le sedi direttivo hanno `<CODICEISTITUTO>@istruzione.it`, le
    altre sedi riportano l'email dell'istituto; in provincia di Pescara 5
    istituti (omnicomprensivi, convitti) non hanno una riga sede direttivo.
- Il MIUR pubblica un file nuovo (link nuovo) per ogni anno scolastico: il
  contenuto di un link non cambia. Niente schedulazione: aggiorna l'admin
  quando cambia il link.
- I dati legacy di istituti/plessi hanno quasi sempre il codice
  meccanografico: l'abbinamento si fa per codice.
- `plessi` non ha `attivo`: "attivare una sede" = crearla.

## Decisioni (brainstorm 2026-10-08)

1. **Selezione manuale, dati MIUR.** L'admin sceglie le sedi; per i record
   selezionati i campi presenti in MIUR sono fonte di verità e si
   riallineano a ogni nuovo download.
2. **Mai cancellare.** Sedi deselezionate o sparite dal dataset vengono
   solo segnalate; decide l'admin (possono avere segnalazioni storiche).
3. **URL del dataset in Impostazioni**, modificabile; si scarica in locale
   su richiesta dell'admin. Nessun controllo periodico.
4. Organizzazioni e sedi **manuali** (uffici, palestre, immobili non
   scolastici) restano gestite come oggi.

## Modello dati

Migration additiva `2026_10_08_000001_add_miur_to_istituti_plessi`:

| Tabella | Modifica |
|---|---|
| `plessi` | `fonte_dati varchar(30) default 'manuale'` (come `istituti`) |
| `istituti` | `descrizione` 50 → 255 |
| `plessi` | `nome`, `indirizzo` 50 → 255 |

`fonte_dati = 'miur'` marca i record allineati all'anagrafe.
`codice_meccanografico` resta la chiave d'abbinamento (confronto
case-insensitive, trim).

Impostazioni (gruppo `scuole`, aggiunte a `ImpostazioniSeeder`):

- `miur_anagrafe_url` — default il link 2025/26
- `miur_comune_default` — filtro comune precompilato nella ricerca (vuoto = nessuno)
- `miur_anagrafe_scaricato_at` — sola lettura in UI
- `miur_anagrafe_url_scaricato` — URL del file effettivamente in uso
- `miur_anagrafe_stato` — `vuoto` · `in_corso` · `ok` · `errore: <motivo>`

## Componenti

### `App\Services\Scuole\AnagrafeMiur`

- `indicizza(string $percorsoJson): int` — legge il file grezzo, valida la
  struttura, scrive `storage/app/miur/indice.json`: una riga per sede con
  `codice`, `nome`, `codice_istituto`, `nome_istituto`, `grado`, `comune`,
  `provincia`, `indirizzo`, `cap`, `email`, `sede_amministrativa` (bool:
  `codice == codice_istituto` e grado che inizia con "ISTITUTO"). Scrittura
  su file temporaneo + rename: l'indice in uso resta valido se qualcosa
  fallisce.
- `cerca(string $q, ?string $comune): Collection` — sull'indice, filtra per
  testo (nome/codice sede o istituto) e comune, raggruppa per istituto.
  Restituisce per istituto tutte le sue sedi, anche fuori comune.
- `istituto(string $codice): ?array` — istituto + sedi.
- `emailIstituto(string $codice)` — email della riga sede amministrativa;
  in mancanza, l'email più frequente tra le sedi dell'istituto.

L'indice compatto (~5 MB a livello nazionale) evita di decodificare 50 MB a
ogni ricerca.

### `App\Services\Scuole\SincronizzaScuole`

- `applicaSelezione(string $codiceIstituto, array $codiciSedi): Esito` —
  upsert istituto (`fonte_dati = miur`, `tipo_ente = scuola`, `tipo =
  Scuola`, descrizione/email da MIUR), upsert dei plessi selezionati
  (`fonte_dati = miur`, nome/indirizzo/email/`id_istituto` da MIUR). Un
  record legacy con lo stesso codice viene adottato (passa a `miur`).
  Le sedi già presenti e non selezionate restano: l'esito le elenca.
  In transazione.
- `riallinea(): Esito` — per ogni istituto/plesso `miur` aggiorna i campi
  MIUR dall'indice (incluso `id_istituto` di un plesso passato a un altro
  istituto, se quell'istituto è presente in ProntoPA; altrimenti lo segnala).
- `nonPiuPresenti(): Collection` — record `miur` il cui codice non è
  nell'indice. Calcolato al volo, niente tabella di report.

### `App\Jobs\ScaricaAnagrafeMiur`

Scarica `miur_anagrafe_url` (`Http::timeout(300)->sink()` in
`storage/app/miur/anagrafe.download.json`), `indicizza`, sostituisce
l'indice, `riallinea`, aggiorna le impostazioni di stato. `memory_limit`
alzato solo nel job (`ini_set`, 1G). Coda di default, un solo tentativo
(`$tries = 1`), errore → stato `errore: <motivo>` + `failed_jobs` (già
monitorato da `jobs:check-failed`).

### Admin → Anagrafe MIUR (`Admin\AnagrafeMiurController`)

Voce nel menu admin accanto a Organizzazioni/Sedi. Solo ruolo `admin`.

1. **Riquadro dataset**: URL in uso, data download, stato; pulsante
   "Scarica" (dispatch del job; disabilitato con stato `in_corso`). Se
   `miur_anagrafe_url` ≠ URL in uso → avviso "Nuovo link configurato, non
   ancora scaricato".
2. **Riquadro "Non più in anagrafe MIUR"** (se non vuoto): codice, nome,
   numero segnalazioni collegate, link alla scheda Sedi/Organizzazioni.
3. **Ricerca**: testo + comune (default: comune dell'ente, impostazione
   `miur_comune_default`, vuota = nessun filtro). Risultati per istituto
   con indicatore "presente in ProntoPA".
4. **Pagina istituto** (`/admin/anagrafe-miur/{codice}`): tabella sedi con
   checkbox, codice, nome, grado, comune, indirizzo, email, stato
   (presente/nuova).
   - spuntate di default: sedi già presenti in ProntoPA;
   - non spuntate e con etichetta: sede amministrativa, comune diverso dal
     filtro;
   - "Salva selezione" → `applicaSelezione` → flash con l'esito (creati,
     aggiornati, adottati da legacy, presenti ma non selezionati).

### Form esistenti

`OrganizzazioniController` / `SediController`: su un record `miur` i campi
MIUR (descrizione/nome, codice meccanografico, indirizzo, email,
istituto di appartenenza) sono mostrati in sola lettura e **ignorati
lato server** (non solo `disabled` in HTML) con nota "Dati da anagrafe
MIUR". Restano modificabili referente, recapiti, dirigente, attivo,
domini email. Validazioni `max:50` allineate alle nuove lunghezze.

## Errori

| Caso | Admin vede | Sistema |
|---|---|---|
| Download fallito (rete, 404, timeout) | Stato "errore: download non riuscito (HTTP 404)"; indice precedente ancora in uso | Job fallito → `jobs:check-failed`, Sentry |
| Struttura JSON inattesa (manca `@graph` o i campi codice) | "errore: formato del file MIUR non riconosciuto" | Indice precedente intatto |
| Nessun indice | Ricerca disabilitata, invito a scaricare | — |
| Selezione con codice non più nell'indice (pagina vecchia) | "Sede non trovata nell'anagrafe in uso" | Nessuna scrittura |

## Test

**Unit** (`AnagrafeMiur`, fixture JSON ridotta in `tests/Fixtures/miur/`:
un comprensivo, una D.D., un omnicomprensivo senza riga sede direttivo, una
sede fuori comune):
- indicizzazione: campi, `sede_amministrativa` vero per IC e falso per la D.D.;
- formato errato rifiutato, indice esistente non toccato;
- ricerca per testo/comune, raggruppamento, sedi fuori comune incluse;
- `emailIstituto` con e senza riga sede.

**Feature**:
- job con `Http::fake`: percorso felice, 404, formato errato (stato e indice);
- `applicaSelezione`: crea istituto+plessi, adotta legacy con stesso codice
  (case diverso), deselezione non cancella, transazione;
- `riallinea`: aggiorna `miur`, non tocca `manuale`, segnala spariti,
  plesso passato ad altro istituto;
- form Organizzazioni/Sedi: campi MIUR ignorati su record `miur`, liberi
  su `manuale`;
- accesso: solo `admin` (403 per gestore).

## Deleghe — modifiche alla spec v1.2

La fase 2b si implementa come descritta nella spec v1.2 (§"Tabella
`deleghe`", §"Flussi — deleghe", §"Visibilità", §"Sicurezza", §"Test"),
con queste precisazioni:

1. **Destinatario** = `istituti.email`, che per gli istituti `miur` è la
   casella istituzionale da anagrafe. Il caso "istituto senza email"
   (controllo 2.2 della richiesta, riquadro admin, report cutover) resta
   per gli istituti `manuale`.
2. **Plessi richiedibili** = solo i plessi presenti in ProntoPA (quelli
   selezionati dall'admin), mai l'intera anagrafe MIUR. Un istituto senza
   plessi non compare nella ricerca della richiesta.
3. **Limiti anti-abuso**: default della spec confermati
   (`deleghe_max_pendenti=3`, `deleghe_giorni_stop_rifiuto=30`,
   `deleghe_giorni_scadenza_richiesta=30`, `deleghe_email_giorno_istituto=10`,
   `deleghe_mesi_validita=12`, `deleghe_giorni_avviso_delegato=7`).
4. **Gruppi AD**: le impostazioni `ldap_gruppo_*` elencate in §"impostazioni"
   della spec v1.2 sono superate (dalla 0.7.2 stanno in env `LDAP_GRUPPO_*`).

Prima dell'implementazione la spec v1.2 passa da "bozza" a "approvata"
con un rimando a questo documento.

## Ordine di consegna (un'unica release 0.8.0)

1. Migration + `AnagrafeMiur` + job + pagina admin + form (rilasciabile da
   sola, utile anche senza deleghe).
2. Deleghe secondo spec v1.2 + modifiche sopra.
3. Smoke SPID contro `pa-sso-proxy` reale, poi tag 0.8.0 (include anche
   `ldaprecord` 4 / `php-jwt` 7 già su `main`).

## Fuori scope

- Scuole paritarie (dataset MIUR separato).
- Import automatico/schedulato o sincronizzazione senza azione dell'admin.
- PEC degli istituti (non serve alle deleghe).
- Cancellazione automatica di sedi/istituti.
