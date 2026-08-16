# ProntoPA — Documentazione API REST

Spec machine-readable (OpenAPI 3.1, importabile in Swagger UI/Postman/Insomnia): [`openapi.yaml`](openapi.yaml).

## Autenticazione

Tutti gli endpoint richiedono un **Laravel Sanctum token** inviato come Bearer:

```
Authorization: Bearer <token>
```

Per generare un token: Admin → Utenti → seleziona utente → crea Personal Access Token tramite Tinker o direttamente nel DB.

```bash
docker compose exec php php artisan tinker
>>> $user = App\Models\User::where('email', 'admin@example.com')->first();
>>> $user->createToken('portale-cittadini')->plainTextToken;
```

---

## Endpoint

### `POST /api/segnalazioni`

Crea una nuova segnalazione da portale cittadini esterno.

**Request body (JSON):**

| Campo | Tipo | Obbligatorio | Note |
|---|---|---|---|
| `id_tipologia_segnalazione` | integer | ✅ | Deve esistere in `tipologie_segnalazioni` |
| `testo_segnalazione` | string | ✅ | Max 2000 caratteri |
| `id_provenienza` | integer | ✅ | Deve esistere in `provenienze_segnalazioni` |
| `id_plesso` | integer | ❌ | Sede/plesso scolastico |
| `segnalante` | string | ❌ | Nome del segnalante, max 100 |
| `email` | string | ❌ | Email di contatto, max 100 |
| `telefono` | string | ❌ | Telefono di contatto, max 50 |
| `latitudine` | numeric | ❌ | Coordinate GPS |
| `longitudine` | numeric | ❌ | Coordinate GPS |
| `segnalazione_urgente` | boolean | ❌ | |
| `livello_priorita` | integer | ❌ | 1–4 |
| `id_specializzazione` | integer | ❌ | Deve esistere in `db_specializzazioni` |
| `ubicazione_tipo` | integer | ❌ | 0–4 |
| `force` | boolean | ❌ | `true` per creare comunque nonostante segnalazioni simili aperte (vedi 409 sotto) |

**Risposta 201 Created:**

```json
{
    "id_segnalazione": 42,
    "stato": "In attesa di esame"
}
```

**Risposta 422 Unprocessable Entity:**

```json
{
    "message": "The id_tipologia_segnalazione field is required.",
    "errors": {
        "id_tipologia_segnalazione": ["The id_tipologia_segnalazione field is required."]
    }
}
```

**Risposta 409 Conflict** (segnalazioni simili già aperte — stessa tipologia,
stesso plesso o entro ~150m, ultimi 90 giorni; salta questo controllo con
`force: true` nel body):

```json
{
    "message": "Esistono segnalazioni simili già aperte. Ripeti con force=true per creare comunque.",
    "simili": [
        { "id": 17, "stato": "In carico", "data": "2026-05-20" }
    ]
}
```

**Esempio curl:**

```bash
curl -X POST https://your-instance.example.it/api/segnalazioni \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{
    "id_tipologia_segnalazione": 3,
    "testo_segnalazione": "Infiltrazione d'\''acqua nel tetto palestra",
    "id_provenienza": 1,
    "segnalante": "Mario Rossi",
    "email": "mario@example.it",
    "latitudine": 42.461,
    "longitudine": 14.214
  }'
```

---

### `GET /api/segnalazioni/{id}/stato`

Restituisce lo stato corrente di una segnalazione.

**Parametri URL:**

| Parametro | Tipo | Note |
|---|---|---|
| `id` | integer | `id_segnalazione` della segnalazione |

**Risposta 200 OK:**

```json
{
    "id_segnalazione": 42,
    "stato": {
        "id": 3,
        "descrizione": "Assegnata a operatore"
    },
    "data_segnalazione": "2026-05-01T10:00:00+02:00",
    "data_chiusura": null,
    "flag_evidenza": false
}
```

**Risposta 404 Not Found:** la segnalazione non esiste.

**Esempio curl:**

```bash
curl https://your-instance.example.it/api/segnalazioni/42/stato \
  -H "Authorization: Bearer <token>"
```

---

## Webhook outbound

ProntoPA invia una notifica HTTP POST al cambio di stato di ogni segnalazione,
se configurato in **Admin → Impostazioni → Webhook cittadini**.

### Formato payload

```json
{
    "evento": "stato_cambiato",
    "id_segnalazione": 42,
    "stato": {
        "id": 3,
        "descrizione": "Assegnata a operatore"
    },
    "data_aggiornamento": "2026-05-13T14:30:00+02:00"
}
```

### Header inviati

| Header | Valore |
|---|---|
| `Content-Type` | `application/json` |
| `X-Signature` | `sha256=<hmac-hex>` |
| `User-Agent` | `ProntoPA/<versione>` |

### Verifica firma (lato ricevente)

```php
$body      = file_get_contents('php://input');
$secret    = 'il-tuo-secret-configurato';
$expected  = 'sha256=' . hash_hmac('sha256', $body, $secret);
$received  = $_SERVER['HTTP_X_SIGNATURE'] ?? '';

if (!hash_equals($expected, $received)) {
    http_response_code(401);
    exit;
}
```

### Retry

In caso di errore (timeout, risposta non 2xx), ProntoPA ritenta fino a **3 volte**
con backoff di 30 secondi e poi 120 secondi. I tentativi sono registrati nella
tabella `api_logs` con campo `status = 0` in caso di fallimento.

> Il retry richiede che il **queue worker** sia attivo:
> ```bash
> docker compose exec php php artisan queue:work
> ```
