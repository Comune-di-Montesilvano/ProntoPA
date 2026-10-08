# Anagrafe scuole MIUR Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** L'admin sceglie da Admin → Anagrafe MIUR quali istituti/sedi scolastiche esistono in ProntoPA; nome, indirizzo ed email dei record selezionati vengono dall'open data MIUR e si riallineano a ogni nuovo download.

**Architecture:** Un job in coda scarica il JSON MIUR (~50 MB) e lo riduce a un indice compatto su disco `local` (`miur/indice.json`), con lo stato del download in `miur/stato.json`. `AnagrafeMiur` legge l'indice (ricerca, lookup); `SincronizzaScuole` scrive su `istituti`/`plessi` (selezione dell'admin, riallineamento). I record allineati hanno `fonte_dati = 'miur'` e i form esistenti ignorano lato server i campi che vengono da MIUR.

**Tech Stack:** Laravel 13, PHP 8.4, Blade + Tailwind, PHPUnit (SQLite in memoria, queue `sync`), `Http::fake`, `Storage::fake`.

**Spec:** `docs/superpowers/specs/2026-10-08-v080-anagrafe-miur-deleghe-design.md` (parte "Anagrafe scuole MIUR"; le deleghe hanno un plan separato).

## Global Constraints

- Migrazioni solo additive (colonne nuove nullable/default, allargamenti): nessun drop, nessun rename.
- Mai cancellare istituti/plessi in automatico: sedi deselezionate o sparite dal dataset vengono solo segnalate.
- Abbinamento sempre per codice meccanografico, confronto case-insensitive e con trim (`UPPER(TRIM(codice_meccanografico)) = ?`): i test girano su SQLite, che confronta in modo case-sensitive.
- Record `fonte_dati = 'manuale'` mai toccati da `riallinea()`.
- Nuove impostazioni → `ImpostazioniSeeder` (rilanciato a ogni avvio, non tocca i valori esistenti).
- Default `miur_anagrafe_url`: `https://dati.istruzione.it/opendata/opendata/catalogo/elements1/SCUANAGRAFESTAT20252620250901.json`
- Lo stato del download (vuoto/in_corso/ok/errore, data, URL scaricato) NON va in `impostazioni` (la pagina Impostazioni rende editabili tutte le chiavi): sta in `storage/app/miur/stato.json`. **Deviazione dalla spec**, da riportare nella spec al Task 7.
- Solo ruolo `admin` (rotte nel gruppo `role:admin` esistente).
- Test: comando `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test --filter <Nome>`; analisi statica `docker compose exec php composer run analyse`.
- Commit con messaggi Conventional Commits brevi + trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Codice legacy in minuscolo o con spazi** (`peic828004 `): deve essere riconosciuto come già presente e adottato, non duplicato → test in Task 3 (`test_adotta_istituto_legacy_con_codice_minuscolo`).
2. **Download fallito con indice già presente**: l'admin deve poter continuare a cercare sul vecchio indice; l'indice non va troncato → test in Task 4 (`test_404_lascia_indice_precedente`).
3. **Pagina istituto vecchia dopo un nuovo download** (codice sede non più nell'indice nel POST): nessuna scrittura parziale → test in Task 3 (`test_codice_sede_non_dell_istituto_rifiuta_tutto`).
4. **Sede passata a un altro istituto non presente in ProntoPA**: non va spostata su un istituto inesistente né persa → test in Task 3 (`test_riallinea_plesso_passato_a_istituto_assente_segnala`).
5. **Admin che deseleziona una sede con segnalazioni**: la sede resta, con avviso → test in Task 3 (`test_deselezione_non_cancella`) e Task 5 (flash).

---

## File Structure

| File | Responsabilità |
|---|---|
| `database/migrations/2026_10_08_000001_add_miur_to_istituti_plessi.php` | `plessi.fonte_dati`, allargamento colonne |
| `app/Models/Plesso.php` | `fonte_dati` fillable, `isMiur()` |
| `app/Models/Istituto.php` | `isMiur()`, `scopeConCodice()` |
| `database/seeders/ImpostazioniSeeder.php` | chiavi `miur_anagrafe_url`, `miur_comune_default` |
| `app/Services/Scuole/AnagrafeMiur.php` | indice su disco: costruzione, lettura, ricerca, stato download |
| `app/Services/Scuole/FormatoAnagrafeNonValido.php` | eccezione per file MIUR non riconosciuto |
| `app/Services/Scuole/SincronizzaScuole.php` | scritture su `istituti`/`plessi` |
| `app/Services/Scuole/SedeNonTrovata.php` | eccezione per selezione non coerente con l'indice |
| `app/Jobs/ScaricaAnagrafeMiur.php` | download + indicizzazione + riallineamento |
| `app/Http/Controllers/Admin/AnagrafeMiurController.php` | pagina admin |
| `resources/views/admin/anagrafe-miur/{index,show}.blade.php` | viste |
| `app/Http/Controllers/Admin/{OrganizzazioniController,SediController}.php` | campi MIUR ignorati su record `miur` |
| `resources/views/admin/{organizzazioni,sedi}/edit.blade.php` | campi MIUR in sola lettura |
| `tests/Fixtures/miur/anagrafe.json` | dataset ridotto |
| `tests/Unit/Scuole/AnagrafeMiurTest.php`, `tests/Feature/Scuole/*Test.php` | test |

---

### Task 1: Schema, modelli, impostazioni

**Files:**
- Create: `database/migrations/2026_10_08_000001_add_miur_to_istituti_plessi.php`
- Modify: `app/Models/Plesso.php`, `app/Models/Istituto.php`, `database/seeders/ImpostazioniSeeder.php`
- Test: `tests/Feature/Scuole/SchemaMiurTest.php`

**Interfaces:**
- Produces: `Plesso::$fonte_dati` (default `'manuale'`), `Plesso::isMiur(): bool`, `Istituto::isMiur(): bool`, scope `Istituto::conCodice(string $codice)` e `Plesso::conCodice(string $codice)` (match `UPPER(TRIM(codice_meccanografico))` con `strtoupper(trim($codice))`); impostazioni `miur_anagrafe_url`, `miur_comune_default` (gruppo `scuole`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Scuole;

use App\Models\Impostazione;
use App\Models\Istituto;
use App\Models\Plesso;
use Database\Seeders\ImpostazioniSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchemaMiurTest extends TestCase
{
    use RefreshDatabase;

    public function test_plesso_ha_fonte_dati_manuale_di_default(): void
    {
        $istituto = Istituto::create(['descrizione' => 'X', 'codice_meccanografico' => 'PEIC000001']);
        $plesso = Plesso::create(['id_istituto' => $istituto->id_istituto, 'nome' => 'Sede']);

        $this->assertSame('manuale', $plesso->fresh()->fonte_dati);
        $this->assertFalse($plesso->fresh()->isMiur());
    }

    public function test_nomi_lunghi_accettati(): void
    {
        $lungo = str_repeat('A', 200);
        $istituto = Istituto::create(['descrizione' => $lungo, 'codice_meccanografico' => 'PEIC000001']);
        $plesso = Plesso::create(['id_istituto' => $istituto->id_istituto, 'nome' => $lungo, 'indirizzo' => $lungo]);

        $this->assertSame($lungo, $istituto->fresh()->descrizione);
        $this->assertSame($lungo, $plesso->fresh()->indirizzo);
    }

    public function test_scope_con_codice_ignora_maiuscole_e_spazi(): void
    {
        $istituto = Istituto::create(['descrizione' => 'X', 'codice_meccanografico' => ' peic828004 ']);
        Plesso::create(['id_istituto' => $istituto->id_istituto, 'nome' => 'S', 'codice_meccanografico' => 'peaa828033']);

        $this->assertTrue(Istituto::conCodice('PEIC828004')->exists());
        $this->assertTrue(Plesso::conCodice(' PEAA828033')->exists());
        $this->assertFalse(Istituto::conCodice('PEIC999999')->exists());
    }

    public function test_seeder_aggiunge_impostazioni_miur(): void
    {
        $this->seed(ImpostazioniSeeder::class);

        $this->assertStringContainsString('SCUANAGRAFESTAT', (string) Impostazione::get('miur_anagrafe_url'));
        $this->assertSame('scuole', Impostazione::find('miur_comune_default')->gruppo);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test --filter SchemaMiurTest`
Expected: FAIL (`fonte_dati` assente / scope `conCodice` non definito)

- [ ] **Step 3: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Anagrafe MIUR (0.8.0): i plessi selezionati dall'admin seguono l'open data
// MIUR (fonte_dati = 'miur'); nomi e indirizzi MIUR superano i 50 caratteri.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plessi', function (Blueprint $table) {
            $table->string('fonte_dati', 30)->default('manuale')->after('recapiti');
            $table->string('nome', 255)->nullable()->change();
            $table->string('indirizzo', 255)->nullable()->change();
        });

        Schema::table('istituti', function (Blueprint $table) {
            $table->string('descrizione', 255)->change();
        });
    }

    public function down(): void
    {
        Schema::table('plessi', function (Blueprint $table) {
            $table->dropColumn('fonte_dati');
        });
    }
};
```

- [ ] **Step 4: Modelli**

`app/Models/Plesso.php` — aggiungere `'fonte_dati'` a `$fillable`, `use Illuminate\Database\Eloquent\Builder;` e:

```php
    public function isMiur(): bool
    {
        return $this->fonte_dati === 'miur';
    }

    /** Abbinamento per codice meccanografico, indipendente da maiuscole e spazi. */
    public function scopeConCodice(Builder $query, string $codice): Builder
    {
        return $query->whereRaw('UPPER(TRIM(codice_meccanografico)) = ?', [strtoupper(trim($codice))]);
    }
```

`app/Models/Istituto.php` — stessi due metodi (stesso codice, `use Illuminate\Database\Eloquent\Builder;`).

- [ ] **Step 5: Impostazioni**

In `database/seeders/ImpostazioniSeeder.php`, nell'array `$impostazioni` (prima del `foreach`):

```php
            [
                'chiave'      => 'miur_anagrafe_url',
                'valore'      => 'https://dati.istruzione.it/opendata/opendata/catalogo/elements1/SCUANAGRAFESTAT20252620250901.json',
                'tipo'        => 'text',
                'gruppo'      => 'scuole',
                'descrizione' => 'Link al file JSON "Anagrafe scuole statali" (dati.istruzione.it). Cambia ogni anno scolastico: aggiornalo e riscarica da Admin → Anagrafe MIUR',
            ],
            [
                'chiave'      => 'miur_comune_default',
                'valore'      => null,
                'tipo'        => 'text',
                'gruppo'      => 'scuole',
                'descrizione' => 'Comune precompilato nella ricerca dell\'anagrafe MIUR (es. MONTESILVANO). Vuoto = nessun filtro',
            ],
```

- [ ] **Step 6: Run test to verify it passes**

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test --filter SchemaMiurTest`
Expected: PASS (4 test)

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_10_08_000001_add_miur_to_istituti_plessi.php app/Models/Plesso.php app/Models/Istituto.php database/seeders/ImpostazioniSeeder.php tests/Feature/Scuole/SchemaMiurTest.php
git commit -m "feat(scuole): schema e impostazioni anagrafe MIUR"
```

---

### Task 2: `AnagrafeMiur` — indice, ricerca, stato

**Files:**
- Create: `app/Services/Scuole/AnagrafeMiur.php`, `app/Services/Scuole/FormatoAnagrafeNonValido.php`, `tests/Fixtures/miur/anagrafe.json`
- Test: `tests/Unit/Scuole/AnagrafeMiurTest.php`

**Interfaces:**
- Produces (`App\Services\Scuole\AnagrafeMiur`, risolto dal container, usa `Storage::disk('local')`):
  - costanti `GREZZO = 'miur/anagrafe.json'`, `DOWNLOAD = 'miur/anagrafe.download.json'`, `INDICE = 'miur/indice.json'`, `STATO = 'miur/stato.json'`
  - `indicizza(string $percorso): int` — legge il file `$percorso` dal disco `local`, scrive `INDICE` (atomico), ritorna il numero di sedi; lancia `FormatoAnagrafeNonValido`
  - `haIndice(): bool`
  - `sede(string $codice): ?array` — riga sede
  - `istituto(string $codice): ?array` — `['codice','nome','email','sedi' => list<riga>]`
  - `cerca(string $q, ?string $comune, int $limite = 50): list<array>` — lista di istituti (stessa forma di `istituto()`)
  - `stato(): array` — `['stato' => 'vuoto'|'in_corso'|'ok'|'errore', 'messaggio' => ?string, 'url' => ?string, 'scaricato_at' => ?string (ISO 8601), 'sedi' => ?int]`
  - `salvaStato(array $stato): void` — merge con lo stato attuale
- Riga sede: `['codice','nome','codice_istituto','nome_istituto','grado','comune','provincia','indirizzo','cap','email','sede_amministrativa' => bool]` (stringhe maiuscole per codici e comune, `''` se assente).

- [ ] **Step 1: Fixture**

`tests/Fixtures/miur/anagrafe.json` — dataset ridotto con la struttura reale (campi `miur:*`, CAP numerico, email "Non Disponibile"):

```json
{
  "@graph": [
    {"miur:CODICESCUOLA": "PEIC828004", "miur:DENOMINAZIONESCUOLA": "I. C. I.SILONE-MONTESILVANO", "miur:CODICEISTITUTORIFERIMENTO": "PEIC828004", "miur:DENOMINAZIONEISTITUTORIFERIMENTO": "I. C. I.SILONE-MONTESILVANO", "miur:DESCRIZIONETIPOLOGIAGRADOISTRUZIONESCUOLA": "ISTITUTO COMPRENSIVO", "miur:DESCRIZIONECOMUNE": "MONTESILVANO", "miur:PROVINCIA": "PESCARA", "miur:INDIRIZZOSCUOLA": "VIA SAN GOTTARDO", "miur:CAPSCUOLA": 65015.0, "miur:INDIRIZZOEMAILSCUOLA": "PEIC828004@istruzione.it", "miur:INDICAZIONESEDEDIRETTIVO": "SI"},
    {"miur:CODICESCUOLA": "PEMM828015", "miur:DENOMINAZIONESCUOLA": "S.M. I.SILONE - MONTESILVANO", "miur:CODICEISTITUTORIFERIMENTO": "PEIC828004", "miur:DENOMINAZIONEISTITUTORIFERIMENTO": "I. C. I.SILONE-MONTESILVANO", "miur:DESCRIZIONETIPOLOGIAGRADOISTRUZIONESCUOLA": "SCUOLA PRIMO GRADO", "miur:DESCRIZIONECOMUNE": "MONTESILVANO", "miur:PROVINCIA": "PESCARA", "miur:INDIRIZZOSCUOLA": "VIA S.GOTTARDO", "miur:CAPSCUOLA": 65015.0, "miur:INDIRIZZOEMAILSCUOLA": "PEIC828004@istruzione.it", "miur:INDICAZIONESEDEDIRETTIVO": "NO"},
    {"miur:CODICESCUOLA": "PEAA828033", "miur:DENOMINAZIONESCUOLA": "MONTESILVANO-COLLEMARE", "miur:CODICEISTITUTORIFERIMENTO": "PEIC828004", "miur:DENOMINAZIONEISTITUTORIFERIMENTO": "I. C. I.SILONE-MONTESILVANO", "miur:DESCRIZIONETIPOLOGIAGRADOISTRUZIONESCUOLA": "SCUOLA INFANZIA", "miur:DESCRIZIONECOMUNE": "MONTESILVANO", "miur:PROVINCIA": "PESCARA", "miur:INDIRIZZOSCUOLA": "STRADA VICINALE AGOSTINONE", "miur:CAPSCUOLA": 65015.0, "miur:INDIRIZZOEMAILSCUOLA": "PEIC828004@istruzione.it", "miur:INDICAZIONESEDEDIRETTIVO": "NO"},
    {"miur:CODICESCUOLA": "PEEE037001", "miur:DENOMINAZIONESCUOLA": "D.D. MONTESILVANO", "miur:CODICEISTITUTORIFERIMENTO": "PEEE037001", "miur:DENOMINAZIONEISTITUTORIFERIMENTO": "D.D. MONTESILVANO", "miur:DESCRIZIONETIPOLOGIAGRADOISTRUZIONESCUOLA": "SCUOLA PRIMARIA", "miur:DESCRIZIONECOMUNE": "MONTESILVANO", "miur:PROVINCIA": "PESCARA", "miur:INDIRIZZOSCUOLA": "VIA CAMPO IMPERATORE", "miur:CAPSCUOLA": 65015.0, "miur:INDIRIZZOEMAILSCUOLA": "PEEE037001@istruzione.it", "miur:INDICAZIONESEDEDIRETTIVO": "SI"},
    {"miur:CODICESCUOLA": "PEEE83901L", "miur:DENOMINAZIONESCUOLA": "MONTESILVANO - SALINE IC RODARI", "miur:CODICEISTITUTORIFERIMENTO": "PEIC83900E", "miur:DENOMINAZIONEISTITUTORIFERIMENTO": "I. C. \"RODARI\" -MONTESILVANO", "miur:DESCRIZIONETIPOLOGIAGRADOISTRUZIONESCUOLA": "SCUOLA PRIMARIA", "miur:DESCRIZIONECOMUNE": "MONTESILVANO", "miur:PROVINCIA": "PESCARA", "miur:INDIRIZZOSCUOLA": "VIA COSTA", "miur:CAPSCUOLA": 65015.0, "miur:INDIRIZZOEMAILSCUOLA": "PEIC83900E@istruzione.it", "miur:INDICAZIONESEDEDIRETTIVO": "NO"},
    {"miur:CODICESCUOLA": "PEAA83901B", "miur:DENOMINAZIONESCUOLA": "CAPPELLE SUL TAVO", "miur:CODICEISTITUTORIFERIMENTO": "PEIC83900E", "miur:DENOMINAZIONEISTITUTORIFERIMENTO": "I. C. \"RODARI\" -MONTESILVANO", "miur:DESCRIZIONETIPOLOGIAGRADOISTRUZIONESCUOLA": "SCUOLA INFANZIA", "miur:DESCRIZIONECOMUNE": "CAPPELLE SUL TAVO", "miur:PROVINCIA": "PESCARA", "miur:INDIRIZZOSCUOLA": "VIA ROMA", "miur:CAPSCUOLA": 65010.0, "miur:INDIRIZZOEMAILSCUOLA": "Non Disponibile", "miur:INDICAZIONESEDEDIRETTIVO": "NO"},
    {"miur:CODICESCUOLA": "PEPS05000V", "miur:DENOMINAZIONESCUOLA": "L.SCIENTIFICO \"C.D'ASCANIO\" MONTESILVANO", "miur:CODICEISTITUTORIFERIMENTO": "PEPS05000V", "miur:DENOMINAZIONEISTITUTORIFERIMENTO": "L.SCIENTIFICO \"C.D'ASCANIO\" MONTESILVANO", "miur:DESCRIZIONETIPOLOGIAGRADOISTRUZIONESCUOLA": "LICEO SCIENTIFICO", "miur:DESCRIZIONECOMUNE": "MONTESILVANO", "miur:PROVINCIA": "PESCARA", "miur:INDIRIZZOSCUOLA": "VIA LUIGI POLACCHI S.N.", "miur:CAPSCUOLA": 65015.0, "miur:INDIRIZZOEMAILSCUOLA": "PEPS05000V@istruzione.it", "miur:INDICAZIONESEDEDIRETTIVO": "SI"},
    "riga non oggetto, va ignorata",
    {"miur:CODICESCUOLA": "", "miur:DENOMINAZIONESCUOLA": "SENZA CODICE"}
  ]
}
```

(IC Silone = comprensivo con sede amministrativa; D.D. = sede direttivo che è una primaria vera; IC Rodari = istituto **senza** riga sede direttivo, con una sede fuori comune ed email "Non Disponibile"; liceo = superiore.)

- [ ] **Step 2: Write the failing test**

```php
<?php

namespace Tests\Unit\Scuole;

use App\Services\Scuole\AnagrafeMiur;
use App\Services\Scuole\FormatoAnagrafeNonValido;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AnagrafeMiurTest extends TestCase
{
    private AnagrafeMiur $anagrafe;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::disk('local')->put(AnagrafeMiur::GREZZO, file_get_contents(base_path('tests/Fixtures/miur/anagrafe.json')));
        $this->anagrafe = app(AnagrafeMiur::class);
    }

    public function test_indicizza_righe_valide_e_normalizza(): void
    {
        $this->assertFalse($this->anagrafe->haIndice());

        $this->assertSame(7, $this->anagrafe->indicizza(AnagrafeMiur::GREZZO));
        $this->assertTrue($this->anagrafe->haIndice());

        $this->assertSame([
            'codice' => 'PEMM828015',
            'nome' => 'S.M. I.SILONE - MONTESILVANO',
            'codice_istituto' => 'PEIC828004',
            'nome_istituto' => 'I. C. I.SILONE-MONTESILVANO',
            'grado' => 'SCUOLA PRIMO GRADO',
            'comune' => 'MONTESILVANO',
            'provincia' => 'PESCARA',
            'indirizzo' => 'VIA S.GOTTARDO',
            'cap' => '65015',
            'email' => 'PEIC828004@istruzione.it',
            'sede_amministrativa' => false,
        ], $this->anagrafe->sede('pemm828015'));
    }

    public function test_sede_amministrativa_solo_per_istituti_non_per_dd(): void
    {
        $this->anagrafe->indicizza(AnagrafeMiur::GREZZO);

        $this->assertTrue($this->anagrafe->sede('PEIC828004')['sede_amministrativa']);
        $this->assertFalse($this->anagrafe->sede('PEEE037001')['sede_amministrativa']);
        $this->assertSame('', $this->anagrafe->sede('PEAA83901B')['email']);
    }

    public function test_istituto_con_email_da_sede_amministrativa_o_dalle_sedi(): void
    {
        $this->anagrafe->indicizza(AnagrafeMiur::GREZZO);

        $silone = $this->anagrafe->istituto('PEIC828004');
        $this->assertSame('PEIC828004@istruzione.it', $silone['email']);
        $this->assertCount(3, $silone['sedi']);

        // Rodari: nessuna riga sede direttivo nel dataset
        $rodari = $this->anagrafe->istituto('PEIC83900E');
        $this->assertSame('I. C. "RODARI" -MONTESILVANO', $rodari['nome']);
        $this->assertSame('PEIC83900E@istruzione.it', $rodari['email']);
        $this->assertCount(2, $rodari['sedi']);

        $this->assertNull($this->anagrafe->istituto('PEIC999999'));
    }

    public function test_cerca_per_comune_restituisce_istituti_con_tutte_le_sedi(): void
    {
        $this->anagrafe->indicizza(AnagrafeMiur::GREZZO);

        $risultati = $this->anagrafe->cerca('', 'montesilvano');
        $codici = array_column($risultati, 'codice');
        sort($codici);
        $this->assertSame(['PEEE037001', 'PEIC828004', 'PEIC83900E', 'PEPS05000V'], $codici);

        // le sedi fuori comune dell'istituto restano nel risultato
        $rodari = collect($risultati)->firstWhere('codice', 'PEIC83900E');
        $this->assertContains('PEAA83901B', array_column($rodari['sedi'], 'codice'));
    }

    public function test_cerca_per_testo_su_nome_o_codice(): void
    {
        $this->anagrafe->indicizza(AnagrafeMiur::GREZZO);

        $this->assertSame(['PEIC828004'], array_column($this->anagrafe->cerca('collemare', null), 'codice'));
        $this->assertSame(['PEPS05000V'], array_column($this->anagrafe->cerca('peps05', null), 'codice'));
        $this->assertSame([], $this->anagrafe->cerca('rodari', 'PESCARA'));
    }

    public function test_formato_non_valido_non_tocca_indice_esistente(): void
    {
        $this->anagrafe->indicizza(AnagrafeMiur::GREZZO);
        $prima = Storage::disk('local')->get(AnagrafeMiur::INDICE);

        Storage::disk('local')->put(AnagrafeMiur::DOWNLOAD, '{"data": [{"foo": "bar"}]}');

        try {
            $this->anagrafe->indicizza(AnagrafeMiur::DOWNLOAD);
            $this->fail('Eccezione attesa');
        } catch (FormatoAnagrafeNonValido) {
        }

        $this->assertSame($prima, Storage::disk('local')->get(AnagrafeMiur::INDICE));
    }

    public function test_json_corrotto_rifiutato(): void
    {
        Storage::disk('local')->put(AnagrafeMiur::DOWNLOAD, '<html>404</html>');

        $this->expectException(FormatoAnagrafeNonValido::class);
        $this->anagrafe->indicizza(AnagrafeMiur::DOWNLOAD);
    }

    public function test_stato_vuoto_poi_merge(): void
    {
        $this->assertSame('vuoto', $this->anagrafe->stato()['stato']);

        $this->anagrafe->salvaStato(['stato' => 'ok', 'url' => 'https://x', 'sedi' => 7]);
        $this->anagrafe->salvaStato(['stato' => 'errore', 'messaggio' => 'boom']);

        $stato = $this->anagrafe->stato();
        $this->assertSame('errore', $stato['stato']);
        $this->assertSame('boom', $stato['messaggio']);
        $this->assertSame('https://x', $stato['url']);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test --filter AnagrafeMiurTest`
Expected: FAIL ("Class App\Services\Scuole\AnagrafeMiur not found")

- [ ] **Step 4: Implementazione**

`app/Services/Scuole/FormatoAnagrafeNonValido.php`:

```php
<?php

namespace App\Services\Scuole;

use RuntimeException;

class FormatoAnagrafeNonValido extends RuntimeException {}
```

`app/Services/Scuole/AnagrafeMiur.php`:

```php
<?php

namespace App\Services\Scuole;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Anagrafe scuole statali MIUR (open data dati.istruzione.it) ridotta a un
 * indice compatto su disco: il file originale pesa ~50 MB e decodificarlo a
 * ogni ricerca costerebbe centinaia di MB di RAM.
 */
class AnagrafeMiur
{
    public const GREZZO = 'miur/anagrafe.json';
    public const DOWNLOAD = 'miur/anagrafe.download.json';
    public const INDICE = 'miur/indice.json';
    public const STATO = 'miur/stato.json';

    /** @var array<string, array<string, mixed>>|null codice sede → riga */
    private ?array $sedi = null;

    private function disco(): Filesystem
    {
        return Storage::disk('local');
    }

    public function indicizza(string $percorso): int
    {
        $dati = json_decode((string) $this->disco()->get($percorso), true);
        $righe = is_array($dati) ? ($dati['@graph'] ?? null) : null;

        if (! is_array($righe)) {
            throw new FormatoAnagrafeNonValido('formato del file MIUR non riconosciuto (manca @graph)');
        }

        $indice = [];
        foreach ($righe as $riga) {
            $sede = is_array($riga) ? $this->normalizza($riga) : null;
            if ($sede !== null) {
                $indice[$sede['codice']] = $sede;
            }
        }
        unset($dati, $righe);

        if ($indice === []) {
            throw new FormatoAnagrafeNonValido('formato del file MIUR non riconosciuto (nessuna scuola con codice)');
        }

        // Scrittura atomica: l'indice in uso resta valido se qualcosa fallisce.
        $tmp = self::INDICE.'.tmp';
        $this->disco()->put($tmp, json_encode(array_values($indice), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->disco()->delete(self::INDICE);
        $this->disco()->move($tmp, self::INDICE);
        $this->sedi = $indice;

        return count($indice);
    }

    public function haIndice(): bool
    {
        return $this->disco()->exists(self::INDICE);
    }

    /** @return array<string, mixed>|null */
    public function sede(string $codice): ?array
    {
        return $this->sedi()[strtoupper(trim($codice))] ?? null;
    }

    /** @return array{codice: string, nome: string, email: string, sedi: list<array<string, mixed>>}|null */
    public function istituto(string $codice): ?array
    {
        $codice = strtoupper(trim($codice));
        $sedi = array_values(array_filter($this->sedi(), fn ($s) => $s['codice_istituto'] === $codice));

        if ($sedi === []) {
            return null;
        }

        usort($sedi, fn ($a, $b) => [! $a['sede_amministrativa'], $a['nome']] <=> [! $b['sede_amministrativa'], $b['nome']]);

        return [
            'codice' => $codice,
            'nome' => $sedi[0]['nome_istituto'],
            'email' => $this->emailIstituto($codice, $sedi),
            'sedi' => $sedi,
        ];
    }

    /** @return list<array{codice: string, nome: string, email: string, sedi: list<array<string, mixed>>}> */
    public function cerca(string $q, ?string $comune, int $limite = 50): array
    {
        $q = mb_strtoupper(trim($q));
        $comune = mb_strtoupper(trim((string) $comune));
        $codici = [];

        foreach ($this->sedi() as $sede) {
            if ($comune !== '' && $sede['comune'] !== $comune) {
                continue;
            }
            if ($q !== '' && ! str_contains(mb_strtoupper(implode(' ', [$sede['codice'], $sede['nome'], $sede['codice_istituto'], $sede['nome_istituto']])), $q)) {
                continue;
            }
            $codici[$sede['codice_istituto']] = true;
            if (count($codici) >= $limite) {
                break;
            }
        }

        $risultati = array_values(array_filter(array_map(fn ($c) => $this->istituto($c), array_keys($codici))));
        usort($risultati, fn ($a, $b) => $a['nome'] <=> $b['nome']);

        return $risultati;
    }

    /** @return array{stato: string, messaggio: ?string, url: ?string, scaricato_at: ?string, sedi: ?int} */
    public function stato(): array
    {
        $salvato = $this->disco()->exists(self::STATO)
            ? (array) json_decode((string) $this->disco()->get(self::STATO), true)
            : [];

        return array_merge(['stato' => 'vuoto', 'messaggio' => null, 'url' => null, 'scaricato_at' => null, 'sedi' => null], $salvato);
    }

    /** @param array<string, mixed> $stato */
    public function salvaStato(array $stato): void
    {
        $this->disco()->put(self::STATO, json_encode(array_merge($this->stato(), $stato), JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, array<string, mixed>> */
    private function sedi(): array
    {
        if ($this->sedi === null) {
            $righe = $this->haIndice() ? (array) json_decode((string) $this->disco()->get(self::INDICE), true) : [];
            $this->sedi = array_column($righe, null, 'codice');
        }

        return $this->sedi;
    }

    /** @param list<array<string, mixed>> $sedi */
    private function emailIstituto(string $codice, array $sedi): string
    {
        foreach ($sedi as $sede) {
            if ($sede['codice'] === $codice && $sede['email'] !== '') {
                return $sede['email'];
            }
        }

        // Omnicomprensivi/convitti senza riga sede direttivo: le sedi
        // riportano l'email dell'istituto.
        $email = array_count_values(array_filter(array_column($sedi, 'email')));
        arsort($email);

        return (string) array_key_first($email);
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>|null
     */
    private function normalizza(array $r): ?array
    {
        $codice = strtoupper($this->testo($r['miur:CODICESCUOLA'] ?? ''));
        $codiceIstituto = strtoupper($this->testo($r['miur:CODICEISTITUTORIFERIMENTO'] ?? ''));
        $nome = $this->testo($r['miur:DENOMINAZIONESCUOLA'] ?? '');

        if ($codice === '' || $codiceIstituto === '' || $nome === '') {
            return null;
        }

        $grado = mb_strtoupper($this->testo($r['miur:DESCRIZIONETIPOLOGIAGRADOISTRUZIONESCUOLA'] ?? ''));
        $email = $this->testo($r['miur:INDIRIZZOEMAILSCUOLA'] ?? '');
        $cap = $r['miur:CAPSCUOLA'] ?? '';

        return [
            'codice' => $codice,
            'nome' => $nome,
            'codice_istituto' => $codiceIstituto,
            'nome_istituto' => $this->testo($r['miur:DENOMINAZIONEISTITUTORIFERIMENTO'] ?? '') ?: $nome,
            'grado' => $grado,
            'comune' => mb_strtoupper($this->testo($r['miur:DESCRIZIONECOMUNE'] ?? '')),
            'provincia' => mb_strtoupper($this->testo($r['miur:PROVINCIA'] ?? '')),
            'indirizzo' => $this->testo($r['miur:INDIRIZZOSCUOLA'] ?? ''),
            'cap' => is_numeric($cap) ? str_pad((string) (int) $cap, 5, '0', STR_PAD_LEFT) : $this->testo($cap),
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
            // Nei comprensivi/superiori la riga con codice = codice istituto è
            // la sede amministrativa; nella D.D. è una primaria vera.
            'sede_amministrativa' => $codice === $codiceIstituto && str_starts_with($grado, 'ISTITUTO'),
        ];
    }

    private function testo(mixed $valore): string
    {
        return is_scalar($valore) ? str_replace('\\', '', trim((string) $valore)) : '';
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test --filter AnagrafeMiurTest`
Expected: PASS (8 test)

- [ ] **Step 6: Commit**

```bash
git add app/Services/Scuole/AnagrafeMiur.php app/Services/Scuole/FormatoAnagrafeNonValido.php tests/Fixtures/miur/anagrafe.json tests/Unit/Scuole/AnagrafeMiurTest.php
git commit -m "feat(scuole): indice anagrafe MIUR"
```

---

### Task 3: `SincronizzaScuole` — selezione e riallineamento

**Files:**
- Create: `app/Services/Scuole/SincronizzaScuole.php`, `app/Services/Scuole/SedeNonTrovata.php`
- Test: `tests/Feature/Scuole/SincronizzaScuoleTest.php`

**Interfaces:**
- Consumes: `AnagrafeMiur::istituto()`, `::sede()`, `::haIndice()`; `Istituto::conCodice()`, `Plesso::conCodice()`
- Produces (`App\Services\Scuole\SincronizzaScuole`):
  - `applicaSelezione(string $codiceIstituto, array $codiciSedi): array` → `['istituto' => Istituto, 'creati' => list<string>, 'aggiornati' => list<string>, 'adottati' => list<string>, 'non_selezionati' => list<Plesso>]` (codici sede); lancia `SedeNonTrovata` prima di qualsiasi scrittura
  - `riallinea(): array` → `['aggiornati' => int, 'da_verificare' => list<string>]` (codici plesso il cui nuovo istituto non è in ProntoPA)
  - `nonPiuPresenti(): list<array{tipo: string, id: int, codice: string, nome: string, segnalazioni: int}>` (`tipo` = `istituto`|`sede`)
- Campi MIUR scritti: istituto → `descrizione`, `codice_meccanografico`, `email`, `tipo = 'Scuola'`, `tipo_ente = 'scuola'`, `fonte_dati = 'miur'`; plesso → `id_istituto`, `nome`, `codice_meccanografico`, `indirizzo` (`"<indirizzo>, <comune>"`), `email` (null se vuota), `fonte_dati = 'miur'`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Scuole;

use App\Models\Istituto;
use App\Models\Plesso;
use App\Models\Segnalazione;
use App\Services\Scuole\AnagrafeMiur;
use App\Services\Scuole\SedeNonTrovata;
use App\Services\Scuole\SincronizzaScuole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SincronizzaScuoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->usaFixture(file_get_contents(base_path('tests/Fixtures/miur/anagrafe.json')));
    }

    private function usaFixture(string $json): void
    {
        Storage::disk('local')->put(AnagrafeMiur::GREZZO, $json);
        app()->forgetInstance(AnagrafeMiur::class);
        app(AnagrafeMiur::class)->indicizza(AnagrafeMiur::GREZZO);
        app()->forgetInstance(AnagrafeMiur::class);
    }

    private function sync(): SincronizzaScuole
    {
        return app(SincronizzaScuole::class);
    }

    public function test_crea_istituto_e_sedi_selezionate(): void
    {
        $esito = $this->sync()->applicaSelezione('PEIC828004', ['PEMM828015', 'PEAA828033']);

        $istituto = Istituto::conCodice('PEIC828004')->sole();
        $this->assertSame('I. C. I.SILONE-MONTESILVANO', $istituto->descrizione);
        $this->assertSame('PEIC828004@istruzione.it', $istituto->email);
        $this->assertSame('miur', $istituto->fonte_dati);
        $this->assertSame('scuola', $istituto->tipo_ente);
        $this->assertTrue((bool) $istituto->attivo);

        $sm = Plesso::conCodice('PEMM828015')->sole();
        $this->assertSame($istituto->id_istituto, $sm->id_istituto);
        $this->assertSame('S.M. I.SILONE - MONTESILVANO', $sm->nome);
        $this->assertSame('VIA S.GOTTARDO, MONTESILVANO', $sm->indirizzo);
        $this->assertSame('miur', $sm->fonte_dati);

        $this->assertSame(['PEMM828015', 'PEAA828033'], $esito['creati']);
        $this->assertFalse(Plesso::conCodice('PEIC828004')->exists());
    }

    public function test_adotta_istituto_legacy_con_codice_minuscolo(): void
    {
        $legacy = Istituto::create(['descrizione' => 'SILONE vecchio', 'codice_meccanografico' => ' peic828004 ', 'fonte_dati' => 'manuale']);
        $plesso = Plesso::create(['id_istituto' => $legacy->id_istituto, 'nome' => 'Media', 'codice_meccanografico' => 'pemm828015']);

        $esito = $this->sync()->applicaSelezione('PEIC828004', ['PEMM828015']);

        $this->assertSame(1, Istituto::count());
        $this->assertSame('miur', $legacy->fresh()->fonte_dati);
        $this->assertSame('I. C. I.SILONE-MONTESILVANO', $legacy->fresh()->descrizione);
        $this->assertSame('PEMM828015', $plesso->fresh()->codice_meccanografico);
        $this->assertSame(['PEMM828015'], $esito['adottati']);
    }

    public function test_deselezione_non_cancella(): void
    {
        $this->sync()->applicaSelezione('PEIC828004', ['PEMM828015', 'PEAA828033']);
        $infanzia = Plesso::conCodice('PEAA828033')->sole();
        Segnalazione::factory()->create(['id_plesso' => $infanzia->id_plesso]);

        $esito = $this->sync()->applicaSelezione('PEIC828004', ['PEMM828015']);

        $this->assertTrue(Plesso::conCodice('PEAA828033')->exists());
        $this->assertSame([$infanzia->id_plesso], collect($esito['non_selezionati'])->pluck('id_plesso')->all());
        $this->assertSame(['PEMM828015'], $esito['aggiornati']);
    }

    public function test_codice_sede_non_dell_istituto_rifiuta_tutto(): void
    {
        try {
            $this->sync()->applicaSelezione('PEIC828004', ['PEMM828015', 'PEEE037001']);
            $this->fail('Eccezione attesa');
        } catch (SedeNonTrovata) {
        }

        $this->assertSame(0, Istituto::count());
        $this->assertSame(0, Plesso::count());
    }

    public function test_istituto_assente_dall_indice(): void
    {
        $this->expectException(SedeNonTrovata::class);
        $this->sync()->applicaSelezione('PEIC999999', []);
    }

    public function test_riallinea_aggiorna_miur_e_non_tocca_manuali(): void
    {
        $this->sync()->applicaSelezione('PEIC828004', ['PEMM828015']);
        $manuale = Istituto::create(['descrizione' => 'Mio nome', 'codice_meccanografico' => 'PEEE037001', 'fonte_dati' => 'manuale']);

        $nuovo = str_replace(['S.M. I.SILONE - MONTESILVANO', 'D.D. MONTESILVANO'], ['SCUOLA MEDIA SILONE', 'D.D. NUOVO NOME'], file_get_contents(base_path('tests/Fixtures/miur/anagrafe.json')));
        $this->usaFixture($nuovo);

        $esito = $this->sync()->riallinea();

        $this->assertSame('SCUOLA MEDIA SILONE', Plesso::conCodice('PEMM828015')->sole()->nome);
        $this->assertSame('Mio nome', $manuale->fresh()->descrizione);
        $this->assertGreaterThanOrEqual(1, $esito['aggiornati']);
    }

    public function test_riallinea_plesso_passato_a_istituto_assente_segnala(): void
    {
        $this->sync()->applicaSelezione('PEIC828004', ['PEMM828015']);
        $plesso = Plesso::conCodice('PEMM828015')->sole();
        $idPrima = $plesso->id_istituto;

        $this->usaFixture(str_replace(
            '"miur:CODICESCUOLA": "PEMM828015", "miur:DENOMINAZIONESCUOLA": "S.M. I.SILONE - MONTESILVANO", "miur:CODICEISTITUTORIFERIMENTO": "PEIC828004"',
            '"miur:CODICESCUOLA": "PEMM828015", "miur:DENOMINAZIONESCUOLA": "S.M. I.SILONE - MONTESILVANO", "miur:CODICEISTITUTORIFERIMENTO": "PEIC77777X"',
            file_get_contents(base_path('tests/Fixtures/miur/anagrafe.json'))
        ));

        $esito = $this->sync()->riallinea();

        $this->assertSame($idPrima, $plesso->fresh()->id_istituto);
        $this->assertSame(['PEMM828015'], $esito['da_verificare']);
    }

    public function test_riallinea_plesso_passato_a_istituto_presente_si_sposta(): void
    {
        $this->sync()->applicaSelezione('PEIC828004', ['PEMM828015']);
        $this->sync()->applicaSelezione('PEEE037001', ['PEEE037001']);
        $dd = Istituto::conCodice('PEEE037001')->sole();

        $this->usaFixture(str_replace(
            '"miur:CODICESCUOLA": "PEMM828015", "miur:DENOMINAZIONESCUOLA": "S.M. I.SILONE - MONTESILVANO", "miur:CODICEISTITUTORIFERIMENTO": "PEIC828004"',
            '"miur:CODICESCUOLA": "PEMM828015", "miur:DENOMINAZIONESCUOLA": "S.M. I.SILONE - MONTESILVANO", "miur:CODICEISTITUTORIFERIMENTO": "PEEE037001"',
            file_get_contents(base_path('tests/Fixtures/miur/anagrafe.json'))
        ));

        $this->sync()->riallinea();

        $this->assertSame($dd->id_istituto, Plesso::conCodice('PEMM828015')->sole()->id_istituto);
    }

    public function test_non_piu_presenti_con_conteggio_segnalazioni(): void
    {
        $this->sync()->applicaSelezione('PEIC828004', ['PEAA828033']);
        Segnalazione::factory()->count(2)->create(['id_plesso' => Plesso::conCodice('PEAA828033')->sole()->id_plesso]);

        $this->usaFixture(json_encode(['@graph' => [[
            'miur:CODICESCUOLA' => 'PEEE037001', 'miur:CODICEISTITUTORIFERIMENTO' => 'PEEE037001',
            'miur:DENOMINAZIONESCUOLA' => 'D.D. MONTESILVANO', 'miur:DESCRIZIONECOMUNE' => 'MONTESILVANO',
        ]]]));

        $mancanti = collect($this->sync()->nonPiuPresenti());

        $this->assertSame(['istituto', 'sede'], $mancanti->pluck('tipo')->sort()->values()->all());
        $this->assertSame(2, $mancanti->firstWhere('tipo', 'sede')['segnalazioni']);
        $this->assertSame(2, $mancanti->firstWhere('tipo', 'istituto')['segnalazioni']);
    }
}
```

Se `SegnalazioneFactory` richiede altri campi obbligatori oltre a `id_plesso`, leggere `database/factories/SegnalazioneFactory.php` e passarli nello stesso `create([...])`.

- [ ] **Step 2: Run test to verify it fails**

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test --filter SincronizzaScuoleTest`
Expected: FAIL ("Class App\Services\Scuole\SincronizzaScuole not found")

- [ ] **Step 3: Implementazione**

`app/Services/Scuole/SedeNonTrovata.php`:

```php
<?php

namespace App\Services\Scuole;

use InvalidArgumentException;

class SedeNonTrovata extends InvalidArgumentException {}
```

`app/Services/Scuole/SincronizzaScuole.php`:

```php
<?php

namespace App\Services\Scuole;

use App\Models\Istituto;
use App\Models\Plesso;
use App\Models\Segnalazione;
use Illuminate\Support\Facades\DB;

/**
 * Scritture su istituti/plessi a partire dall'anagrafe MIUR. La selezione la
 * decide l'admin; per i record selezionati (fonte_dati = miur) i campi MIUR
 * sono fonte di verità. Non cancella mai nulla.
 */
class SincronizzaScuole
{
    public function __construct(private AnagrafeMiur $anagrafe) {}

    /**
     * @param list<string> $codiciSedi
     * @return array{istituto: Istituto, creati: list<string>, aggiornati: list<string>, adottati: list<string>, non_selezionati: list<Plesso>}
     */
    public function applicaSelezione(string $codiceIstituto, array $codiciSedi): array
    {
        $dati = $this->anagrafe->istituto($codiceIstituto);
        if ($dati === null) {
            throw new SedeNonTrovata("Istituto {$codiceIstituto} non presente nell'anagrafe in uso");
        }

        $sediMiur = array_column($dati['sedi'], null, 'codice');
        $codiciSedi = array_values(array_unique(array_map(fn ($c) => strtoupper(trim((string) $c)), $codiciSedi)));
        foreach ($codiciSedi as $codice) {
            if (! isset($sediMiur[$codice])) {
                throw new SedeNonTrovata("Sede {$codice} non appartiene a {$dati['codice']} nell'anagrafe in uso");
            }
        }

        return DB::transaction(function () use ($dati, $sediMiur, $codiciSedi) {
            $esito = ['creati' => [], 'aggiornati' => [], 'adottati' => [], 'non_selezionati' => []];

            $istituto = Istituto::conCodice($dati['codice'])->first() ?? new Istituto(['attivo' => true]);
            $istituto->fill($this->campiIstituto($dati))->save();

            foreach ($codiciSedi as $codice) {
                $plesso = Plesso::conCodice($codice)->first();
                $chiave = match (true) {
                    $plesso === null => 'creati',
                    $plesso->isMiur() => 'aggiornati',
                    default => 'adottati',
                };
                $plesso ??= new Plesso;
                $plesso->fill($this->campiPlesso($sediMiur[$codice]) + ['id_istituto' => $istituto->id_istituto])->save();
                $esito[$chiave][] = $codice;
            }

            $esito['non_selezionati'] = Plesso::where('id_istituto', $istituto->id_istituto)
                ->get()
                ->reject(fn (Plesso $p) => in_array(strtoupper(trim((string) $p->codice_meccanografico)), $codiciSedi, true))
                ->values()
                ->all();

            return ['istituto' => $istituto] + $esito;
        });
    }

    /** @return array{aggiornati: int, da_verificare: list<string>} */
    public function riallinea(): array
    {
        $aggiornati = 0;
        $daVerificare = [];

        Istituto::where('fonte_dati', 'miur')->each(function (Istituto $istituto) use (&$aggiornati) {
            $dati = $this->anagrafe->istituto((string) $istituto->codice_meccanografico);
            if ($dati !== null) {
                $istituto->fill($this->campiIstituto($dati));
                if ($istituto->isDirty()) {
                    $istituto->save();
                    $aggiornati++;
                }
            }
        });

        Plesso::where('fonte_dati', 'miur')->with('istituto')->each(function (Plesso $plesso) use (&$aggiornati, &$daVerificare) {
            $sede = $this->anagrafe->sede((string) $plesso->codice_meccanografico);
            if ($sede === null) {
                return;
            }

            $plesso->fill($this->campiPlesso($sede));

            $codiceAttuale = strtoupper(trim((string) $plesso->istituto?->codice_meccanografico));
            if ($sede['codice_istituto'] !== $codiceAttuale) {
                $nuovo = Istituto::conCodice($sede['codice_istituto'])->first();
                if ($nuovo !== null) {
                    $plesso->id_istituto = $nuovo->id_istituto;
                } else {
                    $daVerificare[] = $sede['codice'];
                }
            }

            if ($plesso->isDirty()) {
                $plesso->save();
                $aggiornati++;
            }
        });

        return ['aggiornati' => $aggiornati, 'da_verificare' => $daVerificare];
    }

    /** @return list<array{tipo: string, id: int, codice: string, nome: string, segnalazioni: int}> */
    public function nonPiuPresenti(): array
    {
        $mancanti = [];

        foreach (Istituto::where('fonte_dati', 'miur')->get() as $istituto) {
            if ($this->anagrafe->istituto((string) $istituto->codice_meccanografico) === null) {
                $mancanti[] = [
                    'tipo' => 'istituto',
                    'id' => $istituto->id_istituto,
                    'codice' => (string) $istituto->codice_meccanografico,
                    'nome' => $istituto->descrizione,
                    'segnalazioni' => Segnalazione::whereIn('id_plesso', $istituto->plessi()->select('id_plesso'))->count(),
                ];
            }
        }

        foreach (Plesso::where('fonte_dati', 'miur')->get() as $plesso) {
            if ($this->anagrafe->sede((string) $plesso->codice_meccanografico) === null) {
                $mancanti[] = [
                    'tipo' => 'sede',
                    'id' => $plesso->id_plesso,
                    'codice' => (string) $plesso->codice_meccanografico,
                    'nome' => (string) $plesso->nome,
                    'segnalazioni' => Segnalazione::where('id_plesso', $plesso->id_plesso)->count(),
                ];
            }
        }

        return $mancanti;
    }

    /** @param array{codice: string, nome: string, email: string} $dati */
    private function campiIstituto(array $dati): array
    {
        return [
            'descrizione' => $dati['nome'],
            'codice_meccanografico' => $dati['codice'],
            'email' => $dati['email'] ?: null,
            'tipo' => 'Scuola',
            'tipo_ente' => 'scuola',
            'fonte_dati' => 'miur',
        ];
    }

    /** @param array<string, mixed> $sede */
    private function campiPlesso(array $sede): array
    {
        return [
            'nome' => $sede['nome'],
            'codice_meccanografico' => $sede['codice'],
            'indirizzo' => trim($sede['indirizzo'].', '.$sede['comune'], ', '),
            'email' => $sede['email'] ?: null,
            'fonte_dati' => 'miur',
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test --filter SincronizzaScuoleTest`
Expected: PASS (9 test)

- [ ] **Step 5: Commit**

```bash
git add app/Services/Scuole/SincronizzaScuole.php app/Services/Scuole/SedeNonTrovata.php tests/Feature/Scuole/SincronizzaScuoleTest.php
git commit -m "feat(scuole): selezione e riallineamento da anagrafe MIUR"
```

---

### Task 4: Job `ScaricaAnagrafeMiur`

**Files:**
- Create: `app/Jobs/ScaricaAnagrafeMiur.php`
- Test: `tests/Feature/Scuole/ScaricaAnagrafeMiurTest.php`

**Interfaces:**
- Consumes: `AnagrafeMiur::{indicizza, salvaStato, GREZZO, DOWNLOAD}`, `SincronizzaScuole::riallinea()`
- Produces: `new ScaricaAnagrafeMiur(string $url)`; a fine job `stato()` = `ok` con `url`, `scaricato_at`, `sedi`, `messaggio` (riepilogo riallineamento) oppure `errore` con `messaggio`; in errore rilancia l'eccezione (finisce in `failed_jobs`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Scuole;

use App\Jobs\ScaricaAnagrafeMiur;
use App\Models\Plesso;
use App\Services\Scuole\AnagrafeMiur;
use App\Services\Scuole\SincronizzaScuole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Throwable;

class ScaricaAnagrafeMiurTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://dati.istruzione.it/test/anagrafe.json';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function esegui(): ?Throwable
    {
        try {
            (new ScaricaAnagrafeMiur(self::URL))->handle(app(AnagrafeMiur::class), app(SincronizzaScuole::class));
        } catch (Throwable $e) {
            return $e;
        } finally {
            app()->forgetInstance(AnagrafeMiur::class);
        }

        return null;
    }

    public function test_scarica_indicizza_e_riallinea(): void
    {
        Http::fake([self::URL => Http::response(file_get_contents(base_path('tests/Fixtures/miur/anagrafe.json')))]);

        $this->assertNull($this->esegui());

        $anagrafe = app(AnagrafeMiur::class);
        $stato = $anagrafe->stato();
        $this->assertSame('ok', $stato['stato']);
        $this->assertSame(self::URL, $stato['url']);
        $this->assertSame(7, $stato['sedi']);
        $this->assertNotNull($stato['scaricato_at']);
        $this->assertNotNull($anagrafe->sede('PEMM828015'));
        Storage::disk('local')->assertExists(AnagrafeMiur::GREZZO);
        Storage::disk('local')->assertMissing(AnagrafeMiur::DOWNLOAD);
    }

    public function test_riallinea_record_miur_dopo_download(): void
    {
        Http::fake([self::URL => Http::response(file_get_contents(base_path('tests/Fixtures/miur/anagrafe.json')))]);
        $this->esegui();
        app(SincronizzaScuole::class)->applicaSelezione('PEIC828004', ['PEMM828015']);
        app()->forgetInstance(AnagrafeMiur::class);

        Http::fake([self::URL => Http::response(str_replace('S.M. I.SILONE - MONTESILVANO', 'MEDIA SILONE', file_get_contents(base_path('tests/Fixtures/miur/anagrafe.json'))))]);
        $this->esegui();

        $this->assertSame('MEDIA SILONE', Plesso::conCodice('PEMM828015')->sole()->nome);
    }

    public function test_404_lascia_indice_precedente(): void
    {
        Http::fake([self::URL => Http::sequence()
            ->push(file_get_contents(base_path('tests/Fixtures/miur/anagrafe.json')))
            ->push('Not Found', 404)]);
        $this->esegui();
        $indice = Storage::disk('local')->get(AnagrafeMiur::INDICE);

        $this->assertNotNull($this->esegui());

        $stato = app(AnagrafeMiur::class)->stato();
        $this->assertSame('errore', $stato['stato']);
        $this->assertStringContainsString('HTTP 404', $stato['messaggio']);
        $this->assertSame(self::URL, $stato['url']); // URL dell'indice ancora in uso
        $this->assertSame($indice, Storage::disk('local')->get(AnagrafeMiur::INDICE));
    }

    public function test_formato_non_valido_segnala_errore(): void
    {
        Http::fake([self::URL => Http::response('<html>manutenzione</html>')]);

        $this->assertNotNull($this->esegui());

        $stato = app(AnagrafeMiur::class)->stato();
        $this->assertSame('errore', $stato['stato']);
        $this->assertStringContainsString('formato del file MIUR', $stato['messaggio']);
        $this->assertFalse(app(AnagrafeMiur::class)->haIndice());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test --filter ScaricaAnagrafeMiurTest`
Expected: FAIL ("Class App\Jobs\ScaricaAnagrafeMiur not found")

- [ ] **Step 3: Implementazione**

```php
<?php

namespace App\Jobs;

use App\Services\Scuole\AnagrafeMiur;
use App\Services\Scuole\SincronizzaScuole;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Scarica l'anagrafe scuole MIUR (~50 MB), la riduce a indice e riallinea
 * istituti/plessi con fonte_dati = miur. In errore l'indice precedente resta
 * in uso e il job finisce in failed_jobs (alert jobs:check-failed).
 */
class ScaricaAnagrafeMiur implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly string $url) {}

    public function handle(AnagrafeMiur $anagrafe, SincronizzaScuole $sincronizza): void
    {
        // json_decode del file intero: ~10x la dimensione in RAM.
        ini_set('memory_limit', '1G');

        $anagrafe->salvaStato(['stato' => 'in_corso', 'messaggio' => null]);

        try {
            $risposta = Http::timeout(300)->get($this->url);
            if ($risposta->failed()) {
                throw new RuntimeException("download non riuscito (HTTP {$risposta->status()})");
            }

            Storage::disk('local')->put(AnagrafeMiur::DOWNLOAD, $risposta->body());
            unset($risposta);

            $sedi = $anagrafe->indicizza(AnagrafeMiur::DOWNLOAD);

            Storage::disk('local')->delete(AnagrafeMiur::GREZZO);
            Storage::disk('local')->move(AnagrafeMiur::DOWNLOAD, AnagrafeMiur::GREZZO);

            $esito = $sincronizza->riallinea();

            $anagrafe->salvaStato([
                'stato' => 'ok',
                'url' => $this->url,
                'scaricato_at' => now()->toIso8601String(),
                'sedi' => $sedi,
                'messaggio' => "Record aggiornati: {$esito['aggiornati']}"
                    .($esito['da_verificare'] ? '. Sedi passate a istituti non presenti: '.implode(', ', $esito['da_verificare']) : ''),
            ]);
        } catch (Throwable $e) {
            Storage::disk('local')->delete(AnagrafeMiur::DOWNLOAD);
            $anagrafe->salvaStato(['stato' => 'errore', 'messaggio' => $e->getMessage()]);

            throw $e;
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test --filter ScaricaAnagrafeMiurTest`
Expected: PASS (4 test)

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/ScaricaAnagrafeMiur.php tests/Feature/Scuole/ScaricaAnagrafeMiurTest.php
git commit -m "feat(scuole): job download anagrafe MIUR"
```

---

### Task 5: Pagina Admin → Anagrafe MIUR

**Files:**
- Create: `app/Http/Controllers/Admin/AnagrafeMiurController.php`, `resources/views/admin/anagrafe-miur/index.blade.php`, `resources/views/admin/anagrafe-miur/show.blade.php`
- Modify: `routes/web.php` (gruppo admin, dopo `Route::resource('sedi', ...)`), `resources/views/layouts/app.blade.php:130-132` (voce sidebar dopo "Sedi")
- Test: `tests/Feature/Scuole/AnagrafeMiurAdminTest.php`

**Interfaces:**
- Consumes: `AnagrafeMiur::{stato, salvaStato, haIndice, cerca, istituto}`, `SincronizzaScuole::{applicaSelezione, nonPiuPresenti}`, `ScaricaAnagrafeMiur`, `Impostazione::get('miur_anagrafe_url' | 'miur_comune_default')`
- Produces: rotte `admin.anagrafe-miur.index` (GET `?q=&comune=`), `admin.anagrafe-miur.scarica` (POST), `admin.anagrafe-miur.show` (GET `{codice}`), `admin.anagrafe-miur.salva` (POST `{codice}`, campo `sedi[]`)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Scuole;

use App\Jobs\ScaricaAnagrafeMiur;
use App\Models\Impostazione;
use App\Models\Istituto;
use App\Models\Plesso;
use App\Models\User;
use App\Services\Scuole\AnagrafeMiur;
use Database\Seeders\ImpostazioniSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AnagrafeMiurAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        Storage::fake('local');
        $this->seed(ImpostazioniSeeder::class);
    }

    private function utente(string $ruolo): User
    {
        Role::firstOrCreate(['name' => $ruolo, 'guard_name' => 'web']);
        $user = User::factory()->create(['attivo' => true, 'approval_status' => 'approved']);
        $user->syncRoles([$ruolo]);

        return $user;
    }

    private function conIndice(): void
    {
        Storage::disk('local')->put(AnagrafeMiur::GREZZO, file_get_contents(base_path('tests/Fixtures/miur/anagrafe.json')));
        app(AnagrafeMiur::class)->indicizza(AnagrafeMiur::GREZZO);
        app(AnagrafeMiur::class)->salvaStato(['stato' => 'ok', 'url' => 'https://vecchio', 'scaricato_at' => now()->toIso8601String(), 'sedi' => 7]);
        app()->forgetInstance(AnagrafeMiur::class);
    }

    public function test_solo_admin(): void
    {
        $this->actingAs($this->utente('gestore'))
            ->get(route('admin.anagrafe-miur.index'))
            ->assertForbidden();
    }

    public function test_senza_indice_invita_a_scaricare(): void
    {
        $this->actingAs($this->utente('admin'))
            ->get(route('admin.anagrafe-miur.index'))
            ->assertOk()
            ->assertSee('Nessuna anagrafe scaricata');
    }

    public function test_scarica_accoda_job_con_url_impostazioni(): void
    {
        Queue::fake();
        Impostazione::set('miur_anagrafe_url', 'https://dati.istruzione.it/nuovo.json');

        $this->actingAs($this->utente('admin'))
            ->post(route('admin.anagrafe-miur.scarica'))
            ->assertRedirect();

        Queue::assertPushed(ScaricaAnagrafeMiur::class, fn ($job) => $job->url === 'https://dati.istruzione.it/nuovo.json');
        $this->assertSame('in_corso', app(AnagrafeMiur::class)->stato()['stato']);
    }

    public function test_scarica_rifiutato_se_in_corso(): void
    {
        Queue::fake();
        app(AnagrafeMiur::class)->salvaStato(['stato' => 'in_corso']);

        $this->actingAs($this->utente('admin'))
            ->post(route('admin.anagrafe-miur.scarica'))
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    public function test_avviso_nuovo_link_e_ricerca_per_comune_default(): void
    {
        $this->conIndice();
        Impostazione::set('miur_anagrafe_url', 'https://nuovo');
        Impostazione::set('miur_comune_default', 'MONTESILVANO');

        $this->actingAs($this->utente('admin'))
            ->get(route('admin.anagrafe-miur.index'))
            ->assertOk()
            ->assertSee('Nuovo link configurato')
            ->assertSee('I. C. I.SILONE-MONTESILVANO')
            ->assertSee('D.D. MONTESILVANO');
    }

    public function test_pagina_istituto_preseleziona_sedi_presenti(): void
    {
        $this->conIndice();
        $ist = Istituto::create(['descrizione' => 'X', 'codice_meccanografico' => 'PEIC828004']);
        Plesso::create(['id_istituto' => $ist->id_istituto, 'nome' => 'Media', 'codice_meccanografico' => 'PEMM828015']);

        $this->actingAs($this->utente('admin'))
            ->get(route('admin.anagrafe-miur.show', 'PEIC828004'))
            ->assertOk()
            ->assertSee('value="PEMM828015" checked', false)
            ->assertDontSee('value="PEAA828033" checked', false)
            ->assertSee('Sede amministrativa');
    }

    public function test_pagina_istituto_inesistente_404(): void
    {
        $this->conIndice();

        $this->actingAs($this->utente('admin'))
            ->get(route('admin.anagrafe-miur.show', 'PEIC999999'))
            ->assertNotFound();
    }

    public function test_salva_selezione_e_flash_con_non_selezionate(): void
    {
        $this->conIndice();
        $admin = $this->utente('admin');

        $this->actingAs($admin)->post(route('admin.anagrafe-miur.salva', 'PEIC828004'), ['sedi' => ['PEMM828015', 'PEAA828033']])
            ->assertRedirect(route('admin.anagrafe-miur.show', 'PEIC828004'));
        $this->assertSame(2, Plesso::where('fonte_dati', 'miur')->count());

        $this->actingAs($admin)->post(route('admin.anagrafe-miur.salva', 'PEIC828004'), ['sedi' => ['PEMM828015']])
            ->assertSessionHas('warning', fn ($msg) => str_contains($msg, 'MONTESILVANO-COLLEMARE'));
        $this->assertSame(2, Plesso::count());
    }

    public function test_salva_con_sede_estranea_non_scrive(): void
    {
        $this->conIndice();

        $this->actingAs($this->utente('admin'))
            ->post(route('admin.anagrafe-miur.salva', 'PEIC828004'), ['sedi' => ['PEEE037001']])
            ->assertSessionHas('error');

        $this->assertSame(0, Istituto::count());
    }

    public function test_riquadro_non_piu_presenti(): void
    {
        $this->conIndice();
        Istituto::create(['descrizione' => 'Scuola chiusa', 'codice_meccanografico' => 'PEIC000000', 'fonte_dati' => 'miur']);

        $this->actingAs($this->utente('admin'))
            ->get(route('admin.anagrafe-miur.index'))
            ->assertSee('Non più in anagrafe MIUR')
            ->assertSee('Scuola chiusa');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test --filter AnagrafeMiurAdminTest`
Expected: FAIL (`Route [admin.anagrafe-miur.index] not defined`)

- [ ] **Step 3: Rotte**

In `routes/web.php`, `use App\Http\Controllers\Admin\AnagrafeMiurController;` in testa, e nel gruppo admin dopo `Route::resource('sedi', ...)`:

```php
    Route::get('anagrafe-miur', [AnagrafeMiurController::class, 'index'])->name('anagrafe-miur.index');
    Route::post('anagrafe-miur/scarica', [AnagrafeMiurController::class, 'scarica'])->name('anagrafe-miur.scarica');
    Route::get('anagrafe-miur/{codice}', [AnagrafeMiurController::class, 'show'])
        ->where('codice', '[A-Za-z0-9]{10}')->name('anagrafe-miur.show');
    Route::post('anagrafe-miur/{codice}', [AnagrafeMiurController::class, 'salva'])
        ->where('codice', '[A-Za-z0-9]{10}')->name('anagrafe-miur.salva');
```

- [ ] **Step 4: Controller**

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ScaricaAnagrafeMiur;
use App\Models\Impostazione;
use App\Models\Istituto;
use App\Models\Plesso;
use App\Services\Scuole\AnagrafeMiur;
use App\Services\Scuole\SedeNonTrovata;
use App\Services\Scuole\SincronizzaScuole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AnagrafeMiurController extends Controller
{
    public function __construct(private AnagrafeMiur $anagrafe, private SincronizzaScuole $sincronizza) {}

    public function index(Request $request): View
    {
        $stato = $this->anagrafe->stato();
        $url = (string) Impostazione::get('miur_anagrafe_url', '');
        $q = trim((string) $request->query('q', ''));
        $comune = trim((string) $request->query('comune', Impostazione::get('miur_comune_default', '')));

        $risultati = $this->anagrafe->haIndice() && ($q !== '' || $comune !== '')
            ? $this->anagrafe->cerca($q, $comune)
            : null;

        $presenti = $risultati === null ? [] : Istituto::query()
            ->whereNotNull('codice_meccanografico')
            ->pluck('codice_meccanografico')
            ->map(fn ($c) => strtoupper(trim((string) $c)))
            ->flip()
            ->all();

        return view('admin.anagrafe-miur.index', [
            'stato' => $stato,
            'url' => $url,
            'nuovoLink' => $stato['url'] !== null && $url !== '' && $url !== $stato['url'],
            'haIndice' => $this->anagrafe->haIndice(),
            'q' => $q,
            'comune' => $comune,
            'risultati' => $risultati,
            'presenti' => $presenti,
            'mancanti' => $this->anagrafe->haIndice() ? $this->sincronizza->nonPiuPresenti() : [],
        ]);
    }

    public function scarica(): RedirectResponse
    {
        if ($this->anagrafe->stato()['stato'] === 'in_corso') {
            return back()->with('error', 'Download già in corso.');
        }

        $url = (string) Impostazione::get('miur_anagrafe_url', '');
        if ($url === '') {
            return back()->with('error', 'Configura il link in Impostazioni → scuole.');
        }

        $this->anagrafe->salvaStato(['stato' => 'in_corso', 'messaggio' => null]);
        ScaricaAnagrafeMiur::dispatch($url);

        return back()->with('success', 'Download avviato: può richiedere qualche minuto. Ricarica la pagina per vedere lo stato.');
    }

    public function show(string $codice): View
    {
        $istituto = $this->anagrafe->istituto($codice);
        abort_if($istituto === null, 404);

        $presenti = Plesso::query()
            ->whereIn(DB::raw('UPPER(TRIM(codice_meccanografico))'), array_column($istituto['sedi'], 'codice'))
            ->pluck('codice_meccanografico')
            ->map(fn ($c) => strtoupper(trim((string) $c)))
            ->flip()
            ->all();

        return view('admin.anagrafe-miur.show', [
            'istituto' => $istituto,
            'presenti' => $presenti,
            'locale' => Istituto::conCodice($istituto['codice'])->first(),
            'comune' => mb_strtoupper((string) Impostazione::get('miur_comune_default', '')),
        ]);
    }

    public function salva(Request $request, string $codice): RedirectResponse
    {
        $data = $request->validate([
            'sedi' => ['nullable', 'array'],
            'sedi.*' => ['string', 'size:10'],
        ]);

        try {
            $esito = $this->sincronizza->applicaSelezione($codice, $data['sedi'] ?? []);
        } catch (SedeNonTrovata $e) {
            return back()->with('error', $e->getMessage().'. Ricarica la pagina.');
        }

        $redirect = redirect()->route('admin.anagrafe-miur.show', strtoupper($codice))
            ->with('success', sprintf(
                'Salvato. Sedi create: %d, aggiornate: %d, adottate da anagrafica esistente: %d.',
                count($esito['creati']), count($esito['aggiornati']), count($esito['adottati'])
            ));

        if ($esito['non_selezionati'] !== []) {
            $redirect->with('warning', 'Sedi presenti in ProntoPA ma non selezionate (non rimosse, eliminale da Sedi se serve): '
                .implode(', ', array_map(fn (Plesso $p) => $p->nome, $esito['non_selezionati'])));
        }

        return $redirect;
    }
}
```

- [ ] **Step 5: Vista `index`**

Verificare prima come `layouts/app.blade.php` mostra i flash `success`/`error`/`warning` (`grep -n "session('" resources/views/layouts/app.blade.php`): se `warning` non è gestito, aggiungerlo accanto a `error` con classi `bg-amber-50 text-amber-800`.

`resources/views/admin/anagrafe-miur/index.blade.php`:

```blade
<x-app-layout>
    <x-slot name="header">Anagrafe scuole MIUR</x-slot>

    <div class="space-y-6">
        <div class="bg-white shadow-sm rounded-xl p-6 space-y-3">
            <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wider">Dataset</h2>

            @if(! $haIndice)
                <p class="text-sm text-gray-600">Nessuna anagrafe scaricata. Premi "Scarica" per importare il file indicato in Impostazioni.</p>
            @else
                <dl class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
                    <div><dt class="text-gray-500">File in uso</dt><dd class="break-all">{{ $stato['url'] }}</dd></div>
                    <div><dt class="text-gray-500">Scaricato il</dt><dd>{{ $stato['scaricato_at'] ? \Illuminate\Support\Carbon::parse($stato['scaricato_at'])->format('d/m/Y H:i') : '—' }}</dd></div>
                    <div><dt class="text-gray-500">Scuole in anagrafe</dt><dd>{{ $stato['sedi'] ?? '—' }}</dd></div>
                </dl>
            @endif

            @if($stato['stato'] === 'in_corso')
                <p class="text-sm text-blue-700">Download in corso…</p>
            @elseif($stato['stato'] === 'errore')
                <p class="text-sm text-red-700">Ultimo download non riuscito: {{ $stato['messaggio'] }}</p>
            @elseif($stato['messaggio'])
                <p class="text-sm text-gray-600">{{ $stato['messaggio'] }}</p>
            @endif

            @if($nuovoLink)
                <p class="text-sm text-amber-700">Nuovo link configurato, non ancora scaricato: {{ $url }}</p>
            @endif

            <form method="POST" action="{{ route('admin.anagrafe-miur.scarica') }}">
                @csrf
                <button type="submit" @disabled($stato['stato'] === 'in_corso')
                        class="inline-flex items-center px-3 py-1.5 bg-blue-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 disabled:opacity-50 transition">
                    Scarica
                </button>
                <a href="{{ route('admin.impostazioni.index') }}" class="ml-3 text-sm text-blue-700 hover:underline">Cambia link</a>
            </form>
        </div>

        @if($mancanti !== [])
            <div class="bg-white shadow-sm rounded-xl p-6">
                <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wider mb-3">Non più in anagrafe MIUR</h2>
                <p class="text-sm text-gray-600 mb-3">Record allineati al MIUR il cui codice non c'è più nel file in uso (accorpamenti, dimensionamento). Non vengono rimossi: decidi tu.</p>
                <table class="min-w-full text-sm">
                    <thead class="text-xs text-gray-500 uppercase"><tr><th class="text-left py-2">Tipo</th><th class="text-left">Codice</th><th class="text-left">Nome</th><th class="text-right">Segnalazioni</th><th></th></tr></thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach($mancanti as $m)
                            <tr>
                                <td class="py-2">{{ $m['tipo'] === 'istituto' ? 'Istituto' : 'Sede' }}</td>
                                <td>{{ $m['codice'] }}</td>
                                <td>{{ $m['nome'] }}</td>
                                <td class="text-right">{{ $m['segnalazioni'] }}</td>
                                <td class="text-right">
                                    <a class="text-blue-700 hover:underline" href="{{ $m['tipo'] === 'istituto' ? route('admin.organizzazioni.edit', $m['id']) : route('admin.sedi.edit', $m['id']) }}">Apri</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if($haIndice)
            <div class="bg-white shadow-sm rounded-xl p-6 space-y-4">
                <form method="GET" class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <x-text-input name="q" :value="$q" placeholder="Nome o codice meccanografico" />
                    <x-text-input name="comune" :value="$comune" placeholder="Comune (es. MONTESILVANO)" />
                    <x-primary-button>Cerca</x-primary-button>
                </form>

                @if($risultati !== null)
                    @forelse($risultati as $ist)
                        <a href="{{ route('admin.anagrafe-miur.show', $ist['codice']) }}"
                           class="flex items-center justify-between border border-gray-200 rounded-lg p-3 hover:bg-gray-50">
                            <span>
                                <span class="font-medium text-gray-800">{{ $ist['nome'] }}</span>
                                <span class="text-xs text-gray-500">{{ $ist['codice'] }} · {{ count($ist['sedi']) }} sedi</span>
                            </span>
                            @isset($presenti[$ist['codice']])
                                <span class="text-xs px-2 py-0.5 rounded-full bg-green-50 text-green-700">presente in ProntoPA</span>
                            @endisset
                        </a>
                    @empty
                        <p class="text-sm text-gray-500">Nessun istituto trovato.</p>
                    @endforelse
                @endif
            </div>
        @endif
    </div>
</x-app-layout>
```

Se `x-primary-button` non esiste in `resources/views/components/`, usare un `<button type="submit">` con le stesse classi del pulsante "Scarica".

- [ ] **Step 6: Vista `show`**

`resources/views/admin/anagrafe-miur/show.blade.php`:

```blade
<x-app-layout>
    <x-slot name="header">{{ $istituto['nome'] }}</x-slot>
    <x-slot name="actions">
        <a href="{{ route('admin.anagrafe-miur.index') }}"
           class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 transition">
            Indietro
        </a>
    </x-slot>

    <div class="bg-white shadow-sm rounded-xl p-6 space-y-4">
        <dl class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
            <div><dt class="text-gray-500">Codice</dt><dd>{{ $istituto['codice'] }}</dd></div>
            <div><dt class="text-gray-500">Email segreteria</dt><dd>{{ $istituto['email'] ?: '—' }}</dd></div>
            <div><dt class="text-gray-500">In ProntoPA</dt><dd>{{ $locale ? ($locale->isMiur() ? 'Sì, allineato al MIUR' : 'Sì, anagrafica manuale (verrà allineata al salvataggio)') : 'No' }}</dd></div>
        </dl>

        <form method="POST" action="{{ route('admin.anagrafe-miur.salva', $istituto['codice']) }}">
            @csrf
            <p class="text-sm text-gray-600 mb-3">Seleziona le sedi da gestire in ProntoPA. Nome, indirizzo ed email arrivano dal MIUR. Togliere la spunta non elimina una sede già presente.</p>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-100 text-sm">
                    <thead class="bg-gray-50 text-xs font-medium text-gray-500 uppercase tracking-wider">
                        <tr>
                            <th class="px-3 py-2"></th>
                            <th class="px-3 py-2 text-left">Codice</th>
                            <th class="px-3 py-2 text-left">Nome</th>
                            <th class="px-3 py-2 text-left">Grado</th>
                            <th class="px-3 py-2 text-left">Comune</th>
                            <th class="px-3 py-2 text-left">Indirizzo</th>
                            <th class="px-3 py-2 text-left">Stato</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach($istituto['sedi'] as $sede)
                            @php($presente = isset($presenti[$sede['codice']]))
                            <tr>
                                <td class="px-3 py-2">
                                    <input type="checkbox" name="sedi[]" id="sede-{{ $sede['codice'] }}" value="{{ $sede['codice'] }}"{{ $presente ? ' checked' : '' }}
                                           class="rounded border-gray-300 text-blue-600">
                                </td>
                                <td class="px-3 py-2"><label for="sede-{{ $sede['codice'] }}">{{ $sede['codice'] }}</label></td>
                                <td class="px-3 py-2">
                                    {{ $sede['nome'] }}
                                    @if($sede['sede_amministrativa'])
                                        <span class="ml-1 text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">Sede amministrativa</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">{{ $sede['grado'] }}</td>
                                <td class="px-3 py-2">
                                    {{ $sede['comune'] }}
                                    @if($comune !== '' && $sede['comune'] !== $comune)
                                        <span class="ml-1 text-xs px-2 py-0.5 rounded-full bg-amber-50 text-amber-700">Fuori comune</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">{{ $sede['indirizzo'] }}</td>
                                <td class="px-3 py-2">{{ $presente ? 'Presente' : 'Nuova' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <button type="submit" class="mt-4 inline-flex items-center px-3 py-1.5 bg-blue-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 transition">
                Salva selezione
            </button>
        </form>
    </div>
</x-app-layout>
```

- [ ] **Step 7: Sidebar**

In `resources/views/layouts/app.blade.php`, dopo il `<x-sidebar-link>` di "Sedi":

```blade
                    <x-sidebar-link href="{{ route('admin.anagrafe-miur.index') }}" :active="request()->routeIs('admin.anagrafe-miur.*')" icon="office-building">
                        Anagrafe MIUR
                    </x-sidebar-link>
```

- [ ] **Step 8: Run test to verify it passes**

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test --filter AnagrafeMiurAdminTest`
Expected: PASS (10 test)

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Admin/AnagrafeMiurController.php resources/views/admin/anagrafe-miur routes/web.php resources/views/layouts/app.blade.php tests/Feature/Scuole/AnagrafeMiurAdminTest.php
git commit -m "feat(admin): pagina anagrafe scuole MIUR"
```

---

### Task 6: Campi MIUR in sola lettura in Organizzazioni e Sedi

**Files:**
- Modify: `app/Http/Controllers/Admin/OrganizzazioniController.php` (`store`, `update`), `app/Http/Controllers/Admin/SediController.php` (`store`, `update`), `resources/views/admin/organizzazioni/edit.blade.php`, `resources/views/admin/sedi/edit.blade.php`, `resources/views/admin/organizzazioni/create.blade.php`, `resources/views/admin/sedi/create.blade.php` (solo `maxlength`)
- Test: `tests/Feature/Scuole/FormMiurTest.php`

**Interfaces:**
- Consumes: `Istituto::isMiur()`, `Plesso::isMiur()`
- Produces: costanti `OrganizzazioniController::CAMPI_MIUR = ['descrizione', 'codice_meccanografico', 'email']`, `SediController::CAMPI_MIUR = ['id_istituto', 'nome', 'codice_meccanografico', 'indirizzo', 'email']`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Scuole;

use App\Models\Istituto;
use App\Models\Plesso;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FormMiurTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create(['attivo' => true, 'approval_status' => 'approved']);
        $this->admin->syncRoles(['admin']);
    }

    private function istituto(string $fonte): Istituto
    {
        return Istituto::create([
            'descrizione' => 'IC SILONE', 'codice_meccanografico' => 'PEIC828004', 'email' => 'peic828004@istruzione.it',
            'tipo' => 'Scuola', 'tipo_ente' => 'scuola', 'fonte_dati' => $fonte, 'attivo' => true,
        ]);
    }

    private function payloadIstituto(): array
    {
        return [
            'descrizione' => 'NOME CAMBIATO', 'tipo' => 'Scuola', 'tipo_ente' => 'scuola',
            'codice_meccanografico' => 'XXXX', 'email' => 'altro@example.it',
            'dirigente' => 'Dott.ssa Rossi', 'recapiti' => '085 123', 'attivo' => '1',
        ];
    }

    public function test_istituto_miur_ignora_campi_miur_ma_salva_gli_altri(): void
    {
        $ist = $this->istituto('miur');

        $this->actingAs($this->admin)->patch(route('admin.organizzazioni.update', $ist), $this->payloadIstituto())->assertRedirect();

        $ist->refresh();
        $this->assertSame('IC SILONE', $ist->descrizione);
        $this->assertSame('PEIC828004', $ist->codice_meccanografico);
        $this->assertSame('peic828004@istruzione.it', $ist->email);
        $this->assertSame('Dott.ssa Rossi', $ist->dirigente);
    }

    public function test_istituto_manuale_modificabile(): void
    {
        $ist = $this->istituto('manuale');

        $this->actingAs($this->admin)->patch(route('admin.organizzazioni.update', $ist), $this->payloadIstituto())->assertRedirect();

        $this->assertSame('NOME CAMBIATO', $ist->fresh()->descrizione);
    }

    public function test_sede_miur_ignora_campi_miur(): void
    {
        $ist = $this->istituto('miur');
        $altro = Istituto::create(['descrizione' => 'Altro', 'codice_meccanografico' => '']);
        $sede = Plesso::create(['id_istituto' => $ist->id_istituto, 'nome' => 'MEDIA', 'codice_meccanografico' => 'PEMM828015', 'indirizzo' => 'VIA S.GOTTARDO', 'fonte_dati' => 'miur']);

        $this->actingAs($this->admin)->patch(route('admin.sedi.update', $sede), [
            'id_istituto' => $altro->id_istituto, 'nome' => 'X', 'codice_meccanografico' => 'Y',
            'indirizzo' => 'Z', 'email' => 'z@example.it', 'referente' => 'Bidello Mario', 'recapiti' => '085 1',
        ])->assertRedirect();

        $sede->refresh();
        $this->assertSame($ist->id_istituto, $sede->id_istituto);
        $this->assertSame('MEDIA', $sede->nome);
        $this->assertSame('Bidello Mario', $sede->referente);
    }

    public function test_sede_manuale_accetta_nome_lungo(): void
    {
        $ist = $this->istituto('manuale');
        $sede = Plesso::create(['id_istituto' => $ist->id_istituto, 'nome' => 'Palestra']);

        $this->actingAs($this->admin)->patch(route('admin.sedi.update', $sede), [
            'id_istituto' => $ist->id_istituto, 'nome' => str_repeat('N', 120),
        ])->assertSessionHasNoErrors();

        $this->assertSame(120, strlen($sede->fresh()->nome));
    }

    public function test_form_modifica_miur_mostra_sola_lettura(): void
    {
        $ist = $this->istituto('miur');

        $this->actingAs($this->admin)->get(route('admin.organizzazioni.edit', $ist))
            ->assertOk()
            ->assertSee('Dati da anagrafe MIUR')
            ->assertSee('readonly', false);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test --filter FormMiurTest`
Expected: FAIL (`IC SILONE` sovrascritto con `NOME CAMBIATO`; nome lungo rifiutato da `max:50`)

- [ ] **Step 3: Controller**

`OrganizzazioniController`: aggiungere `use Illuminate\Support\Arr;` e

```php
    /** Campi che per un istituto fonte_dati = miur vengono dall'anagrafe MIUR. */
    public const CAMPI_MIUR = ['descrizione', 'codice_meccanografico', 'email'];
```

In `store` e `update` cambiare `'descrizione' => ['required', 'string', 'max:50']` in `max:255`. In `update` sostituire `$data = $request->validate([...]);` con:

```php
        $regole = [ /* stesso array di oggi, descrizione max:255 */ ];

        if ($organizzazione->isMiur()) {
            $regole = Arr::except($regole, self::CAMPI_MIUR);
        }

        $data = $request->validate($regole);
```

(`validate()` restituisce solo le chiavi validate: i campi MIUR inviati vengono scartati.)

`SediController`: stessa modifica con

```php
    /** Campi che per una sede fonte_dati = miur vengono dall'anagrafe MIUR. */
    public const CAMPI_MIUR = ['id_istituto', 'nome', 'codice_meccanografico', 'indirizzo', 'email'];
```

`nome` e `indirizzo` → `max:255` in `store` e `update`; in `update` `if ($sede->isMiur()) { $regole = Arr::except($regole, self::CAMPI_MIUR); }`. Il redirect finale di `update` usa già `$sede->id_istituto`: nessuna modifica.

- [ ] **Step 4: Viste**

`resources/views/admin/organizzazioni/edit.blade.php`: subito dopo `@csrf @method('PATCH')`:

```blade
            @php($miur = $organizzazione->isMiur())
            @if($miur)
                <p class="text-sm bg-blue-50 text-blue-800 rounded-md p-3">
                    Dati da anagrafe MIUR: nome, codice ed email si aggiornano da
                    <a href="{{ route('admin.anagrafe-miur.show', $organizzazione->codice_meccanografico) }}" class="underline">Admin → Anagrafe MIUR</a>.
                </p>
            @endif
```

Sui `<x-text-input>` di `descrizione`, `codice_meccanografico`, `email` aggiungere `:readonly="$miur"` e portare `maxlength="50"` di `descrizione` a `255`.

`resources/views/admin/sedi/edit.blade.php`: stesso blocco con `@php($miur = $sede->isMiur())` (link a `route('admin.anagrafe-miur.index')`); `:readonly="$miur"` su `nome`, `codice_meccanografico`, `indirizzo`, `email`; sul `<select id="id_istituto">` aggiungere `@disabled($miur)` e togliere `required` quando `$miur` (`@if(! $miur) required @endif`); `maxlength` di `nome`/`indirizzo` a `255`.

`resources/views/admin/{organizzazioni,sedi}/create.blade.php`: solo `maxlength="255"` su `descrizione` / `nome`, `indirizzo`.

- [ ] **Step 5: Run test to verify it passes**

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test --filter FormMiurTest`
Expected: PASS (5 test)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/OrganizzazioniController.php app/Http/Controllers/Admin/SediController.php resources/views/admin/organizzazioni resources/views/admin/sedi tests/Feature/Scuole/FormMiurTest.php
git commit -m "feat(admin): campi MIUR in sola lettura su organizzazioni e sedi"
```

---

### Task 7: Verifica completa, documentazione

**Files:**
- Modify: `CHANGELOG.md` (`[Unreleased]`), `CLAUDE.md` (Architettura: controller/servizi/job nuovi; Brandizzazione: gruppo `scuole`), `TODO.md` (sezione v1.2/0.8.0), `docs/superpowers/specs/2026-10-08-v080-anagrafe-miur-deleghe-design.md` (stato download in file, non in impostazioni)

- [ ] **Step 1: Suite completa + analisi statica**

Run: `MSYS_NO_PATHCONV=1 docker compose exec php php artisan test`
Expected: tutti PASS (nessuna regressione in Admin/Organizzazioni/Sedi)

Run: `docker compose exec php composer run analyse`
Expected: `[OK] No errors`. Errori larastan sui nuovi file → correggere il codice (tipi PHPDoc), **non** aggiungerli al baseline.

- [ ] **Step 2: Smoke manuale in dev**

```bash
MSYS_NO_PATHCONV=1 docker compose exec php php artisan migrate
MSYS_NO_PATHCONV=1 docker compose exec php php artisan db:seed --class=ImpostazioniSeeder
```

Login `mock.admin`, Admin → Anagrafe MIUR → "Scarica" (il worker `queue` deve girare: `docker compose ps`). Attendere lo stato `ok` (~1 min), impostare `miur_comune_default = MONTESILVANO`, cercare, aprire "I.C. DELFICO - MONTESILVANO", spuntare le sedi, salvare; verificare in Admin → Sedi i nomi MIUR e il form in sola lettura.

- [ ] **Step 3: Documentazione**

`CHANGELOG.md`, sotto `## [Unreleased]`:

```markdown
### Added
- Admin → Anagrafe MIUR: ricerca nell'open data "Anagrafe scuole statali",
  selezione di istituti e sedi da gestire; nome, indirizzo ed email dei
  record selezionati arrivano dal MIUR e si riallineano a ogni nuovo
  download (link in Impostazioni → scuole). Nessuna cancellazione
  automatica: le scuole sparite dal dataset vengono segnalate
```

`CLAUDE.md`: aggiungere `AnagrafeMiurController` in `Admin/{...}`, `Scuole/  AnagrafeMiur (indice su disco) · SincronizzaScuole` in Services, `ScaricaAnagrafeMiur` in Jobs; nella tabella Brandizzazione una riga `` `miur_anagrafe_url` `miur_comune_default` | scuole ``.

Spec, sezione "Impostazioni (gruppo `scuole`...)": lasciare solo `miur_anagrafe_url` e `miur_comune_default` e aggiungere: "Stato del download (stato, data, URL in uso, numero sedi) in `storage/app/miur/stato.json`: in `impostazioni` sarebbe modificabile dalla pagina Impostazioni."

`TODO.md`, sezione v1.2: aggiungere sotto Fase 2b `- Anagrafe scuole MIUR (prerequisito deleghe) ✅`.

- [ ] **Step 4: Commit**

```bash
git add CHANGELOG.md CLAUDE.md TODO.md docs/superpowers/specs/2026-10-08-v080-anagrafe-miur-deleghe-design.md
git commit -m "docs: anagrafe scuole MIUR"
```
