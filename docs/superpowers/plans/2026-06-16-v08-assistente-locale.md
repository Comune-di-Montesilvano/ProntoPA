# ProntoPA v0.8 "Assistente Locale" — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** LLM on-premise opzionale (Ollama) genera titoli leggibili, suggerisce triage e potenzia il dedup semantico — tutto asincrono, dati mai fuori dall'ente, sistema funzionante anche con Ollama spento.

**Architecture:** Container `ollama` aggiunto a `docker-compose.yml` sotto profilo opzionale `ai`. `OllamaService` wrappa le chiamate HTTP. I job asincroni (`GeneraTitoloSegnalazione`, `SuggerisciTriageSegnalazione`, `CalcolaEmbeddingSegnalazione`) usano la coda Redis esistente — mai sul percorso sincrono. Flag `ai_enabled` in `impostazioni` controlla tutto; Ollama spento → sistema invariato, spariscono solo i suggerimenti. Contenuti AI sempre marcati nella UI.

**Tech Stack:** Laravel 13, PHP 8.4, Redis queue, Ollama REST API (HTTP), MariaDB (JSON column per embedding), PHPUnit + Http::fake().

**Spec:** `docs/superpowers/specs/2026-06-10-v06-adozione-design.md` §5

**Comandi ambiente:**

```powershell
docker compose exec php php artisan test --filter=NomeTest
docker compose exec php php artisan test
docker compose exec php php artisan migrate
docker compose --profile ai up -d   # avvia Ollama (dev/prod con AI)
```

**Branch:** creare `feature/v08-assistente-locale` da `main` (dopo merge v0.7):

```powershell
git checkout main; git pull; git checkout -b feature/v08-assistente-locale
```

---

### Task 1: Impostazioni AI nel seeder + OllamaService

**Files:**
- Modify: `database/seeders/ImpostazioniSeeder.php`
- Create: `app/Services/OllamaService.php`
- Test: `tests/Unit/OllamaServiceTest.php`

- [ ] **Step 1: Aggiungere le impostazioni AI al seeder**

In `database/seeders/ImpostazioniSeeder.php`, aggiungere nel blocco `insertOrIgnore`:

```php
            // v0.8 — Assistente locale
            [
                'chiave'      => 'ai_enabled',
                'valore'      => '0',
                'tipo'        => 'boolean',
                'gruppo'      => 'ai',
                'descrizione' => 'Abilita suggerimenti LLM locali (richiede Ollama)',
            ],
            [
                'chiave'      => 'ai_modello',
                'valore'      => 'qwen2.5:3b',
                'tipo'        => 'text',
                'gruppo'      => 'ai',
                'descrizione' => 'Modello Ollama per generazione testo (titoli, triage)',
            ],
            [
                'chiave'      => 'ai_embedding_modello',
                'valore'      => 'nomic-embed-text',
                'tipo'        => 'text',
                'gruppo'      => 'ai',
                'descrizione' => 'Modello Ollama per embeddings semantici (dedup)',
            ],
            [
                'chiave'      => 'ai_ollama_url',
                'valore'      => 'http://ollama:11434',
                'tipo'        => 'text',
                'gruppo'      => 'ai',
                'descrizione' => 'URL interno del servizio Ollama',
            ],
```

- [ ] **Step 2: Creare `app/Services/OllamaService.php`**

```php
<?php

namespace App\Services;

use App\Models\Impostazione;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OllamaService
{
    public function isEnabled(): bool
    {
        return (bool) Impostazione::get('ai_enabled', false);
    }

    /**
     * Genera testo con il modello di completamento.
     * Restituisce null se AI disabilitata o Ollama non raggiungibile.
     */
    public function generate(string $prompt): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        try {
            $response = Http::timeout(30)->post($this->baseUrl() . '/api/generate', [
                'model'  => Impostazione::get('ai_modello', 'qwen2.5:3b'),
                'prompt' => $prompt,
                'stream' => false,
            ]);

            if ($response->successful()) {
                return trim((string) $response->json('response', '')) ?: null;
            }
        } catch (\Throwable $e) {
            Log::warning('OllamaService::generate failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Genera un vettore embedding per il testo fornito.
     * Restituisce null se AI disabilitata o Ollama non raggiungibile.
     *
     * @return float[]|null
     */
    public function embed(string $text): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        try {
            $response = Http::timeout(30)->post($this->baseUrl() . '/api/embeddings', [
                'model'  => Impostazione::get('ai_embedding_modello', 'nomic-embed-text'),
                'prompt' => $text,
            ]);

            if ($response->successful()) {
                $embedding = $response->json('embedding');
                if (is_array($embedding) && count($embedding) > 0) {
                    return $embedding;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('OllamaService::embed failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    private function baseUrl(): string
    {
        return rtrim((string) Impostazione::get('ai_ollama_url', 'http://ollama:11434'), '/');
    }
}
```

- [ ] **Step 3: Test fallente**

Creare `tests/Unit/OllamaServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\Impostazione;
use App\Services\OllamaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OllamaServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_restituisce_null_se_ai_disabilitata(): void
    {
        Impostazione::set('ai_enabled', false);

        $service = new OllamaService();
        $result = $service->generate('test');

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    public function test_generate_chiama_ollama_e_restituisce_risposta(): void
    {
        Impostazione::set('ai_enabled', true);
        Impostazione::set('ai_ollama_url', 'http://ollama-test:11434');
        Impostazione::set('ai_modello', 'qwen2.5:3b');

        Http::fake([
            'http://ollama-test:11434/api/generate' => Http::response([
                'response' => 'Lampada fulminata corridoio piano primo',
            ]),
        ]);

        $service = new OllamaService();
        $result = $service->generate('Lampada non funziona nel corridoio');

        $this->assertSame('Lampada fulminata corridoio piano primo', $result);
    }

    public function test_generate_restituisce_null_su_errore_http(): void
    {
        Impostazione::set('ai_enabled', true);
        Impostazione::set('ai_ollama_url', 'http://ollama-test:11434');

        Http::fake([
            'http://ollama-test:11434/api/generate' => Http::response([], 500),
        ]);

        $service = new OllamaService();
        $result = $service->generate('test');

        $this->assertNull($result);
    }

    public function test_embed_restituisce_vettore(): void
    {
        Impostazione::set('ai_enabled', true);
        Impostazione::set('ai_ollama_url', 'http://ollama-test:11434');

        Http::fake([
            'http://ollama-test:11434/api/embeddings' => Http::response([
                'embedding' => [0.1, 0.2, 0.3],
            ]),
        ]);

        $service = new OllamaService();
        $result = $service->embed('testo di test');

        $this->assertSame([0.1, 0.2, 0.3], $result);
    }

    public function test_embed_restituisce_null_se_ai_disabilitata(): void
    {
        Impostazione::set('ai_enabled', false);

        $service = new OllamaService();
        $result = $service->embed('testo');

        $this->assertNull($result);
    }
}
```

- [ ] **Step 4: Eseguire e verificare il fallimento**

Run: `docker compose exec php php artisan test --filter=OllamaServiceTest`
Expected: FAIL — `OllamaService` non trovato.

- [ ] **Step 5: Eseguire il seeder e verificare**

Run: `docker compose exec php php artisan db:seed --class=ImpostazioniSeeder`
Run: `docker compose exec php php artisan test --filter=OllamaServiceTest`
Expected: PASS (5 test).

- [ ] **Step 6: Commit**

```powershell
git add database/seeders/ImpostazioniSeeder.php app/Services/OllamaService.php tests/Unit/OllamaServiceTest.php
git commit -m "feat(ai): OllamaService con generate/embed e impostazioni v0.8"
```

---

### Task 2: Container Ollama in docker-compose

**Files:**
- Modify: `docker-compose.yml`
- Modify: `docker-compose.override.yml`

- [ ] **Step 1: Aggiungere servizio ollama a docker-compose.yml**

In `docker-compose.yml`, aggiungere il servizio ollama (dopo `redis`):

```yaml
  ollama:
    image: ollama/ollama:latest
    volumes:
      - ollama_data:/root/.ollama
    networks:
      - prontoPA
    restart: unless-stopped
    profiles:
      - ai
```

e nel blocco `volumes:` aggiungere:

```yaml
  ollama_data:
```

e nel blocco `environment:` del servizio `php` aggiungere:

```yaml
      OLLAMA_URL: ${OLLAMA_URL:-http://ollama:11434}
```

- [ ] **Step 2: Dev override (CPU-only, bind port)**

In `docker-compose.override.yml`, aggiungere:

```yaml
  ollama:
    ports:
      - "11434:11434"
```

- [ ] **Step 3: Verifica**

Run: `docker compose --profile ai up -d ollama`
Run: `docker compose exec ollama ollama pull qwen2.5:3b` (scarica il modello — attende il download)
Run: `docker compose exec ollama ollama pull nomic-embed-text`
Expected: modelli disponibili.

- [ ] **Step 4: Commit**

```powershell
git add docker-compose.yml docker-compose.override.yml
git commit -m "feat(ai): container ollama sotto profilo opzionale ai"
```

---

### Task 3: Migration + colonne AI su segnalazioni

**Files:**
- Create: `database/migrations/2026_06_16_v08_add_ai_columns_to_segnalazioni.php`
- Modify: `app/Models/Segnalazione.php`

- [ ] **Step 1: Migration**

Creare `database/migrations/2026_06_16_v08_add_ai_columns_to_segnalazioni.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('segnalazioni', function (Blueprint $table) {
            // Titolo breve generato dal LLM (6-8 parole)
            $table->string('titolo_generato', 200)->nullable()->after('testo_segnalazione');

            // Suggerimento triage: JSON {id_tipologia_segnalazione, id_specializzazione, livello_priorita}
            $table->json('triage_suggerito')->nullable()->after('titolo_generato');

            // Vettore embedding del testo (array JSON di float)
            $table->mediumText('embedding')->nullable()->after('triage_suggerito');
        });
    }

    public function down(): void
    {
        Schema::table('segnalazioni', function (Blueprint $table) {
            $table->dropColumn(['titolo_generato', 'triage_suggerito', 'embedding']);
        });
    }
};
```

- [ ] **Step 2: Aggiungere al modello Segnalazione**

In `app/Models/Segnalazione.php`, aggiungere a `$fillable` (dopo `id_squadra_assegnata`):

```php
        'titolo_generato',
        'triage_suggerito',
        'embedding',
```

In `casts()`, aggiungere:

```php
            'triage_suggerito' => 'array',
            'embedding'        => 'array',
```

- [ ] **Step 3: Migrare**

Run: `docker compose exec php php artisan migrate`
Expected: exit 0.

- [ ] **Step 4: Commit**

```powershell
git add database/migrations/2026_06_16_v08_add_ai_columns_to_segnalazioni.php app/Models/Segnalazione.php
git commit -m "feat(ai): colonne titolo_generato, triage_suggerito, embedding su segnalazioni"
```

---

### Task 4: Job titolo automatico + dispatch + display

**Files:**
- Create: `app/Jobs/GeneraTitoloSegnalazione.php`
- Modify: `app/Http/Controllers/SegnalazioneController.php` (dispatch in `store`)
- Modify: `resources/views/segnalazioni/index.blade.php` (lista segnalatore)
- Modify: `resources/views/gestione/index.blade.php` (lista gestione)
- Modify: `resources/views/segnalazioni/show.blade.php` (scheda)
- Test: `tests/Feature/AI/TitoloAutomaticoTest.php`

- [ ] **Step 1: Test fallente**

Creare directory `tests/Feature/AI/` e il file `tests/Feature/AI/TitoloAutomaticoTest.php`:

```php
<?php

namespace Tests\Feature\AI;

use App\Jobs\GeneraTitoloSegnalazione;
use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Services\OllamaService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TitoloAutomaticoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_creazione_dispatcha_job_se_ai_abilitata(): void
    {
        Queue::fake();
        Impostazione::set('ai_enabled', true);

        $user = User::factory()->create();
        $user->assignRole('segnalatore');

        $this->actingAs($user)->post(route('segnalazioni.store'), [
            'id_tipologia_segnalazione' => 1,
            'testo_segnalazione'        => 'Perdita acqua dal rubinetto bagno docce piano terra',
            'id_provenienza'            => 1,
        ]);

        Queue::assertPushed(GeneraTitoloSegnalazione::class);
    }

    public function test_creazione_non_dispatcha_job_se_ai_disabilitata(): void
    {
        Queue::fake();
        Impostazione::set('ai_enabled', false);

        $user = User::factory()->create();
        $user->assignRole('segnalatore');

        $this->actingAs($user)->post(route('segnalazioni.store'), [
            'id_tipologia_segnalazione' => 1,
            'testo_segnalazione'        => 'Test disabilitato',
            'id_provenienza'            => 1,
        ]);

        Queue::assertNothingPushed();
    }

    public function test_job_salva_titolo_generato(): void
    {
        Impostazione::set('ai_enabled', true);
        Impostazione::set('ai_ollama_url', 'http://ollama-test:11434');

        $segnalazione = Segnalazione::factory()->create([
            'testo_segnalazione' => 'Perdita acqua dal rubinetto bagno docce piano terra',
        ]);

        $mockOllama = $this->createMock(OllamaService::class);
        $mockOllama->method('generate')->willReturn('Perdita rubinetto bagno piano terra');
        $this->app->instance(OllamaService::class, $mockOllama);

        (new GeneraTitoloSegnalazione($segnalazione->id_segnalazione))->handle($mockOllama);

        $this->assertSame('Perdita rubinetto bagno piano terra', $segnalazione->fresh()->titolo_generato);
    }

    public function test_job_non_sovrascrive_titolo_se_risposta_vuota(): void
    {
        Impostazione::set('ai_enabled', true);

        $segnalazione = Segnalazione::factory()->create([
            'testo_segnalazione' => 'Test testo',
            'titolo_generato'    => null,
        ]);

        $mockOllama = $this->createMock(OllamaService::class);
        $mockOllama->method('generate')->willReturn(null);

        (new GeneraTitoloSegnalazione($segnalazione->id_segnalazione))->handle($mockOllama);

        $this->assertNull($segnalazione->fresh()->titolo_generato);
    }
}
```

- [ ] **Step 2: Eseguire e verificare il fallimento**

Run: `docker compose exec php php artisan test --filter=TitoloAutomaticoTest`
Expected: FAIL — job non trovato.

- [ ] **Step 3: Creare `app/Jobs/GeneraTitoloSegnalazione.php`**

```php
<?php

namespace App\Jobs;

use App\Models\Segnalazione;
use App\Services\OllamaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class GeneraTitoloSegnalazione implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly int $idSegnalazione) {}

    public function handle(OllamaService $ollama): void
    {
        $segnalazione = Segnalazione::find($this->idSegnalazione);
        if (! $segnalazione) {
            return;
        }

        $testo = $segnalazione->testo_segnalazione;
        if (blank($testo)) {
            return;
        }

        $prompt = <<<PROMPT
Sei un assistente per la PA italiana. Genera un titolo brevissimo (massimo 8 parole) per questa segnalazione di manutenzione. Rispondi SOLO con il titolo, senza punteggiatura finale, senza virgolette.

Segnalazione: {$testo}

Titolo:
PROMPT;

        $titolo = $ollama->generate($prompt);
        if (blank($titolo)) {
            return;
        }

        // Limita a 200 caratteri e rimuove virgolette spurie
        $titolo = Str::limit(trim($titolo, " \n\"'"), 200, '');

        $segnalazione->update(['titolo_generato' => $titolo]);
    }
}
```

- [ ] **Step 4: Dispatch nel controller**

In `app/Http/Controllers/SegnalazioneController.php`, nel metodo `store()`, subito prima del `return redirect()` finale:

Aggiungere l'import:

```php
use App\Jobs\GeneraTitoloSegnalazione;
use App\Models\Impostazione;
```

(Se `Impostazione` non è già importato.)

Aggiungere il dispatch:

```php
        if (Impostazione::get('ai_enabled', false)) {
            GeneraTitoloSegnalazione::dispatch($segnalazione->id_segnalazione);
        }
```

- [ ] **Step 5: Display in show.blade.php**

In `resources/views/segnalazioni/show.blade.php`, nella testata della scheda (dove appare il testo), aggiungere sopra o accanto al testo segnalazione:

```blade
@if($segnalazione->titolo_generato)
    <p class="mt-1 text-lg font-semibold text-gray-800">
        {{ $segnalazione->titolo_generato }}
        <span class="ml-1 inline-flex items-center rounded-full bg-purple-100 px-2 py-0.5 text-xs font-medium text-purple-700">AI</span>
    </p>
@endif
```

- [ ] **Step 6: Display nelle liste**

In `resources/views/segnalazioni/index.blade.php` (lista segnalatore), nella cella del testo di ogni riga, sostituire il testo troncato con:

```blade
{{ $seg->titolo_generato ?? Str::limit($seg->testo_segnalazione, 80) }}
@if($seg->titolo_generato)
    <span class="ml-1 text-purple-400 text-xs">AI</span>
@endif
```

In `resources/views/gestione/index.blade.php` (lista gestione), identica sostituzione.

- [ ] **Step 7: Eseguire e verificare il successo**

Run: `docker compose exec php php artisan test --filter=TitoloAutomaticoTest`
Expected: PASS (4 test).

Run: `docker compose exec php php artisan test`
Expected: tutta la suite PASS.

- [ ] **Step 8: Commit**

```powershell
git add app/Jobs/GeneraTitoloSegnalazione.php app/Http/Controllers/SegnalazioneController.php resources/views/segnalazioni/show.blade.php resources/views/segnalazioni/index.blade.php resources/views/gestione/index.blade.php tests/Feature/AI/TitoloAutomaticoTest.php
git commit -m "feat(ai): titolo automatico post-creazione via job asincrono"
```

---

### Task 5: Job triage suggerito + conferma gestore

**Files:**
- Create: `app/Jobs/SuggerisciTriageSegnalazione.php`
- Create: `app/Http/Controllers/AiTriageController.php`
- Modify: `app/Http/Controllers/SegnalazioneController.php` (dispatch in `store`)
- Modify: `routes/web.php`
- Modify: `resources/views/segnalazioni/show.blade.php`
- Test: `tests/Feature/AI/TriageSuggeritoTest.php`

- [ ] **Step 1: Test fallente**

Creare `tests/Feature/AI/TriageSuggeritoTest.php`:

```php
<?php

namespace Tests\Feature\AI;

use App\Jobs\SuggerisciTriageSegnalazione;
use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Services\OllamaService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TriageSuggeritoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        Impostazione::set('ai_enabled', true);
    }

    private function gestore(): User
    {
        $user = User::factory()->create([
            'gestore_segnalazioni'     => true,
            'supervisore_segnalazioni' => true,
        ]);
        $user->assignRole('gestore');
        return $user;
    }

    public function test_creazione_dispatcha_job_triage(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $user->assignRole('segnalatore');

        $this->actingAs($user)->post(route('segnalazioni.store'), [
            'id_tipologia_segnalazione' => 1,
            'testo_segnalazione'        => 'Infiltrazione d\'acqua dal tetto aula 3',
            'id_provenienza'            => 1,
        ]);

        Queue::assertPushed(SuggerisciTriageSegnalazione::class);
    }

    public function test_job_salva_triage_suggerito(): void
    {
        $segnalazione = Segnalazione::factory()->create([
            'testo_segnalazione' => 'Infiltrazione d\'acqua dal tetto',
        ]);

        $mockOllama = $this->createMock(OllamaService::class);
        $mockOllama->method('generate')->willReturn(
            '{"id_tipologia_segnalazione":2,"id_specializzazione":1,"livello_priorita":3}'
        );

        (new SuggerisciTriageSegnalazione($segnalazione->id_segnalazione))->handle($mockOllama);

        $freshed = $segnalazione->fresh();
        $this->assertNotNull($freshed->triage_suggerito);
        $this->assertSame(2, $freshed->triage_suggerito['id_tipologia_segnalazione']);
        $this->assertSame(3, $freshed->triage_suggerito['livello_priorita']);
    }

    public function test_gestore_applica_triage_suggerito(): void
    {
        $segnalazione = Segnalazione::factory()->create([
            'id_tipologia_segnalazione' => 1,
            'livello_priorita'          => 2,
            'triage_suggerito'          => [
                'id_tipologia_segnalazione' => 3,
                'livello_priorita'          => 4,
                'id_specializzazione'       => null,
            ],
        ]);

        $response = $this->actingAs($this->gestore())->post(
            route('segnalazioni.applica-triage', $segnalazione)
        );

        $response->assertRedirect();

        $freshed = $segnalazione->fresh();
        $this->assertSame(3, $freshed->id_tipologia_segnalazione);
        $this->assertSame(4, $freshed->livello_priorita);
        $this->assertNull($freshed->triage_suggerito);
    }

    public function test_segnalatore_non_puo_applicare_triage(): void
    {
        $segnalazione = Segnalazione::factory()->create([
            'triage_suggerito' => ['id_tipologia_segnalazione' => 2, 'livello_priorita' => 3, 'id_specializzazione' => null],
        ]);

        $user = User::factory()->create();
        $user->assignRole('segnalatore');

        $response = $this->actingAs($user)->post(
            route('segnalazioni.applica-triage', $segnalazione)
        );

        $response->assertForbidden();
    }

    public function test_job_ignora_json_non_valido(): void
    {
        $segnalazione = Segnalazione::factory()->create();

        $mockOllama = $this->createMock(OllamaService::class);
        $mockOllama->method('generate')->willReturn('risposta non json valida');

        (new SuggerisciTriageSegnalazione($segnalazione->id_segnalazione))->handle($mockOllama);

        $this->assertNull($segnalazione->fresh()->triage_suggerito);
    }
}
```

- [ ] **Step 2: Eseguire e verificare il fallimento**

Run: `docker compose exec php php artisan test --filter=TriageSuggeritoTest`
Expected: FAIL — job non trovato.

- [ ] **Step 3: Creare `app/Jobs/SuggerisciTriageSegnalazione.php`**

```php
<?php

namespace App\Jobs;

use App\Models\Segnalazione;
use App\Services\OllamaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SuggerisciTriageSegnalazione implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly int $idSegnalazione) {}

    public function handle(OllamaService $ollama): void
    {
        $segnalazione = Segnalazione::with(['tipologia', 'specializzazione'])->find($this->idSegnalazione);
        if (! $segnalazione || filled($segnalazione->triage_suggerito)) {
            return;
        }

        $testo = $segnalazione->testo_segnalazione;
        if (blank($testo)) {
            return;
        }

        $prompt = <<<PROMPT
Sei un assistente tecnico per la PA italiana. Analizza questa segnalazione di manutenzione e suggerisci il triage.
Rispondi SOLO con JSON valido, senza spiegazioni, nel formato:
{"id_tipologia_segnalazione": <intero 1-3>, "id_specializzazione": <intero o null>, "livello_priorita": <intero 1-4>}

Livelli priorità: 1=Bassa, 2=Normale, 3=Alta, 4=Critica
Tipologie: 1=Impianti, 2=Edile, 3=Altro

Segnalazione: {$testo}

JSON:
PROMPT;

        $risposta = $ollama->generate($prompt);
        if (blank($risposta)) {
            return;
        }

        // Estrae il primo blocco JSON dalla risposta (il modello può aggiungere testo)
        if (preg_match('/\{[^}]+\}/', $risposta, $matches) !== 1) {
            return;
        }

        $triage = json_decode($matches[0], true);
        if (! is_array($triage)) {
            return;
        }

        // Valida i campi obbligatori e i range
        $idTipologia = filter_var($triage['id_tipologia_segnalazione'] ?? null, FILTER_VALIDATE_INT);
        $priorita    = filter_var($triage['livello_priorita'] ?? null, FILTER_VALIDATE_INT);

        if ($idTipologia === false || $priorita === false) {
            return;
        }

        $segnalazione->update([
            'triage_suggerito' => [
                'id_tipologia_segnalazione' => (int) $idTipologia,
                'id_specializzazione'       => isset($triage['id_specializzazione'])
                    ? (int) $triage['id_specializzazione']
                    : null,
                'livello_priorita'          => max(1, min(4, (int) $priorita)),
            ],
        ]);
    }
}
```

- [ ] **Step 4: Creare `app/Http/Controllers/AiTriageController.php`**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Segnalazione;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AiTriageController extends Controller
{
    public function applicaTriage(Request $request, Segnalazione $segnalazione): RedirectResponse
    {
        $this->authorize('update', $segnalazione);

        $triage = $segnalazione->triage_suggerito;

        if (! is_array($triage) || empty($triage)) {
            return back()->withErrors(['triage' => 'Nessun suggerimento triage disponibile.']);
        }

        $aggiornamento = [];

        if (isset($triage['id_tipologia_segnalazione'])) {
            $aggiornamento['id_tipologia_segnalazione'] = (int) $triage['id_tipologia_segnalazione'];
        }
        if (isset($triage['id_specializzazione'])) {
            $aggiornamento['id_specializzazione'] = (int) $triage['id_specializzazione'];
        }
        if (isset($triage['livello_priorita'])) {
            $aggiornamento['livello_priorita'] = (int) $triage['livello_priorita'];
        }

        $aggiornamento['triage_suggerito'] = null;

        $segnalazione->update($aggiornamento);

        return redirect()
            ->route('segnalazioni.show', $segnalazione->id_segnalazione)
            ->with('success', 'Triage AI applicato.');
    }
}
```

- [ ] **Step 5: Route**

In `routes/web.php`, nel gruppo auth, dopo la route del merge:

```php
use App\Http\Controllers\AiTriageController;
```

```php
    Route::post('segnalazioni/{segnalazione}/applica-triage', [AiTriageController::class, 'applicaTriage'])
        ->name('segnalazioni.applica-triage');
```

- [ ] **Step 6: Dispatch nel controller**

In `app/Http/Controllers/SegnalazioneController.php`, nel blocco dispatch AI (dopo `GeneraTitoloSegnalazione`):

Aggiungere l'import:

```php
use App\Jobs\SuggerisciTriageSegnalazione;
```

e il dispatch:

```php
        if (Impostazione::get('ai_enabled', false)) {
            GeneraTitoloSegnalazione::dispatch($segnalazione->id_segnalazione);
            SuggerisciTriageSegnalazione::dispatch($segnalazione->id_segnalazione);
        }
```

- [ ] **Step 7: Badge triage in show.blade.php**

In `resources/views/segnalazioni/show.blade.php`, nella sezione dettagli (vicino a tipologia e priorità), aggiungere dopo i campi esistenti:

```blade
@if($segnalazione->triage_suggerito && auth()->user()?->can('update', $segnalazione))
    <div class="mt-3 rounded-lg border border-purple-200 bg-purple-50 p-3 text-sm">
        <p class="font-semibold text-purple-800 mb-2">
            Suggerimento AI (conferma con un tap o ignora):
        </p>
        <ul class="text-purple-700 space-y-1 text-xs mb-3">
            @if(isset($segnalazione->triage_suggerito['id_tipologia_segnalazione']))
                <li>Tipologia → ID {{ $segnalazione->triage_suggerito['id_tipologia_segnalazione'] }}</li>
            @endif
            @if(isset($segnalazione->triage_suggerito['livello_priorita']))
                <li>Priorità → {{ $segnalazione->triage_suggerito['livello_priorita'] }}</li>
            @endif
        </ul>
        <form method="POST" action="{{ route('segnalazioni.applica-triage', $segnalazione) }}">
            @csrf
            <button type="submit"
                    class="rounded bg-purple-600 px-3 py-1 text-xs font-medium text-white hover:bg-purple-700">
                Applica suggerimento AI
            </button>
        </form>
    </div>
@endif
```

- [ ] **Step 8: Eseguire e verificare**

Run: `docker compose exec php php artisan test --filter=TriageSuggeritoTest`
Expected: PASS (5 test).

Run: `docker compose exec php php artisan test`
Expected: tutta la suite PASS.

- [ ] **Step 9: Commit**

```powershell
git add app/Jobs/SuggerisciTriageSegnalazione.php app/Http/Controllers/AiTriageController.php app/Http/Controllers/SegnalazioneController.php routes/web.php resources/views/segnalazioni/show.blade.php tests/Feature/AI/TriageSuggeritoTest.php
git commit -m "feat(ai): triage suggerito post-creazione con conferma gestore"
```

---

### Task 6: Dedup semantico (embeddings + cosine similarity)

**Files:**
- Create: `app/Jobs/CalcolaEmbeddingSegnalazione.php`
- Create: `app/Support/CosineSimilarity.php`
- Modify: `app/Services/DedupService.php`
- Modify: `app/Http/Controllers/SegnalazioneController.php` (dispatch in `store`, passa testo a `simili`)
- Modify: `routes/web.php` (`simili` accetta parametro `testo`)
- Test: `tests/Feature/AI/DedupSemanticoTest.php`

- [ ] **Step 1: Test fallente**

Creare `tests/Feature/AI/DedupSemanticoTest.php`:

```php
<?php

namespace Tests\Feature\AI;

use App\Jobs\CalcolaEmbeddingSegnalazione;
use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Services\DedupService;
use App\Services\OllamaService;
use App\Support\CosineSimilarity;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DedupSemanticoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        Impostazione::set('ai_enabled', true);
        Impostazione::set('adesioni_enabled', true);
    }

    private function utente(): User
    {
        $user = User::factory()->create();
        $user->assignRole('segnalatore');
        return $user;
    }

    public function test_cosine_similarity_identici(): void
    {
        $v = [1.0, 0.0, 0.0];
        $this->assertEqualsWithDelta(1.0, CosineSimilarity::compute($v, $v), 0.0001);
    }

    public function test_cosine_similarity_ortogonali(): void
    {
        $this->assertEqualsWithDelta(0.0, CosineSimilarity::compute([1.0, 0.0], [0.0, 1.0]), 0.0001);
    }

    public function test_job_salva_embedding(): void
    {
        $segnalazione = Segnalazione::factory()->create([
            'testo_segnalazione' => 'Perdita rubinetto bagno',
        ]);

        $mockOllama = $this->createMock(OllamaService::class);
        $mockOllama->method('embed')->willReturn([0.1, 0.2, 0.3]);

        (new CalcolaEmbeddingSegnalazione($segnalazione->id_segnalazione))->handle($mockOllama);

        $this->assertSame([0.1, 0.2, 0.3], $segnalazione->fresh()->embedding);
    }

    public function test_creazione_dispatcha_job_embedding(): void
    {
        Queue::fake();

        $user = $this->utente();

        $this->actingAs($user)->post(route('segnalazioni.store'), [
            'id_tipologia_segnalazione' => 1,
            'testo_segnalazione'        => 'Testo con embedding',
            'id_provenienza'            => 1,
        ]);

        Queue::assertPushed(CalcolaEmbeddingSegnalazione::class);
    }

    public function test_endpoint_simili_accetta_testo_per_dedup_semantico(): void
    {
        // Segnalazione esistente con embedding pre-calcolato
        $aperta = Segnalazione::factory()->create([
            'id_tipologia_segnalazione' => 1,
            'id_plesso'                 => 5,
            'embedding'                 => [1.0, 0.0, 0.0],
        ]);

        $mockOllama = $this->createMock(OllamaService::class);
        $mockOllama->method('isEnabled')->willReturn(true);
        $mockOllama->method('embed')->willReturn([0.99, 0.1, 0.0]); // Alta similarità
        $this->app->instance(OllamaService::class, $mockOllama);

        $response = $this->actingAs($this->utente())->getJson(
            route('segnalazioni.simili', [
                'id_tipologia_segnalazione' => 1,
                'id_plesso'                 => 5,
                'testo'                     => 'Rubinetto che perde acqua',
            ])
        );

        $response->assertOk();
        // La segnalazione appare (trovata per plesso E confermata per similarità)
        $response->assertJsonFragment(['id' => $aperta->id_segnalazione]);
    }
}
```

- [ ] **Step 2: Eseguire e verificare il fallimento**

Run: `docker compose exec php php artisan test --filter=DedupSemanticoTest`
Expected: FAIL — classi non trovate.

- [ ] **Step 3: Creare `app/Support/CosineSimilarity.php`**

```php
<?php

namespace App\Support;

class CosineSimilarity
{
    /**
     * @param float[] $a
     * @param float[] $b
     */
    public static function compute(array $a, array $b): float
    {
        $dot    = 0.0;
        $normA  = 0.0;
        $normB  = 0.0;
        $length = min(count($a), count($b));

        for ($i = 0; $i < $length; $i++) {
            $dot   += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $denom = sqrt($normA) * sqrt($normB);

        return $denom > 0.0 ? $dot / $denom : 0.0;
    }
}
```

- [ ] **Step 4: Creare `app/Jobs/CalcolaEmbeddingSegnalazione.php`**

```php
<?php

namespace App\Jobs;

use App\Models\Segnalazione;
use App\Services\OllamaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CalcolaEmbeddingSegnalazione implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly int $idSegnalazione) {}

    public function handle(OllamaService $ollama): void
    {
        $segnalazione = Segnalazione::find($this->idSegnalazione);
        if (! $segnalazione || filled($segnalazione->embedding)) {
            return;
        }

        $testo = $segnalazione->testo_segnalazione;
        if (blank($testo)) {
            return;
        }

        $embedding = $ollama->embed($testo);
        if ($embedding === null) {
            return;
        }

        $segnalazione->update(['embedding' => $embedding]);
    }
}
```

- [ ] **Step 5: Modificare `DedupService::trovaSimili()` con cosine**

In `app/Services/DedupService.php`, aggiungere i seguenti import:

```php
use App\Services\OllamaService;
use App\Support\CosineSimilarity;
```

Aggiornare la firma del metodo e aggiungere il parametro `$testo`:

```php
    public function trovaSimili(
        int    $idTipologia,
        ?int   $idPlesso = null,
        ?float $lat = null,
        ?float $lng = null,
        ?string $testo = null,
        int    $limite = 5,
    ): Collection {
```

Aggiungere alla fine del metodo (prima del `return`), tra il `->get()` e il `return`:

```php
        $candidati = $query->with(['stato', 'tipologia', 'allegati'])
            ->orderByDesc('data_segnalazione')
            ->limit($limite * 3) // pre-fetch più candidati per il reranking semantico
            ->get();

        // Reranking semantico se disponibile
        $ollama = app(OllamaService::class);
        if (filled($testo) && $ollama->isEnabled()) {
            $embeddingQuery = $ollama->embed($testo);
            if ($embeddingQuery !== null) {
                $candidati = $candidati
                    ->filter(function (Segnalazione $s) use ($embeddingQuery): bool {
                        if (! is_array($s->embedding) || empty($s->embedding)) {
                            return true; // senza embedding: includi comunque (fallback)
                        }
                        return CosineSimilarity::compute($embeddingQuery, $s->embedding) >= 0.80;
                    })
                    ->values();
            }
        }

        return $candidati->take($limite);
```

Nota: sostituire anche il blocco `return $query->with(...)...->get();` esistente con il blocco di `$candidati` sopra — la versione aggiornata non chiama più `->get()` direttamente nel return.

- [ ] **Step 6: Accettare `testo` nell'endpoint simili**

In `app/Http/Controllers/SegnalazioneController.php`, nel metodo `simili()`, aggiungere `testo` alla validazione:

```php
        $data = $request->validate([
            'id_tipologia_segnalazione' => ['required', 'integer'],
            'id_plesso'                 => ['nullable', 'integer'],
            'latitudine'                => ['nullable', 'numeric'],
            'longitudine'               => ['nullable', 'numeric'],
            'testo'                     => ['nullable', 'string', 'max:2000'],
        ]);
```

e passarlo a `trovaSimili`:

```php
        $simili = $this->dedup->trovaSimili(
            (int) $data['id_tipologia_segnalazione'],
            isset($data['id_plesso']) ? (int) $data['id_plesso'] : null,
            isset($data['latitudine']) ? (float) $data['latitudine'] : null,
            isset($data['longitudine']) ? (float) $data['longitudine'] : null,
            $data['testo'] ?? null,
        );
```

- [ ] **Step 7: Passare testo dal form Alpine**

In `resources/views/segnalazioni/create.blade.php`, nel blocco Alpine `similiChecker()`, nel metodo `controlla()`:

```js
        async controlla() {
            this.ignora = false;
            const tip = document.querySelector('[name=id_tipologia_segnalazione]')?.value;
            if (!tip) { this.simili = []; return; }
            const params = new URLSearchParams({ id_tipologia_segnalazione: tip });
            const ple = document.querySelector('[name=id_plesso]')?.value;
            const lat = document.querySelector('[name=latitudine]')?.value;
            const lng = document.querySelector('[name=longitudine]')?.value;
            const txt = document.querySelector('[name=testo_segnalazione]')?.value;
            if (ple) params.set('id_plesso', ple);
            if (lat && lng) { params.set('latitudine', lat); params.set('longitudine', lng); }
            if (txt && txt.length > 10) params.set('testo', txt.substring(0, 500));
            // ...resto invariato
```

- [ ] **Step 8: Dispatch job embedding in store()**

In `app/Http/Controllers/SegnalazioneController.php`, aggiungere l'import:

```php
use App\Jobs\CalcolaEmbeddingSegnalazione;
```

e nel blocco dispatch AI:

```php
        if (Impostazione::get('ai_enabled', false)) {
            GeneraTitoloSegnalazione::dispatch($segnalazione->id_segnalazione);
            SuggerisciTriageSegnalazione::dispatch($segnalazione->id_segnalazione);
            CalcolaEmbeddingSegnalazione::dispatch($segnalazione->id_segnalazione);
        }
```

- [ ] **Step 9: Eseguire e verificare**

Run: `docker compose exec php php artisan test --filter=DedupSemanticoTest`
Expected: PASS (5 test).

Run: `docker compose exec php php artisan test`
Expected: tutta la suite PASS.

- [ ] **Step 10: Commit**

```powershell
git add app/Jobs/CalcolaEmbeddingSegnalazione.php app/Support/CosineSimilarity.php app/Services/DedupService.php app/Http/Controllers/SegnalazioneController.php resources/views/segnalazioni/create.blade.php tests/Feature/AI/DedupSemanticoTest.php
git commit -m "feat(ai): dedup semantico con embeddings e cosine similarity"
```

---

### Task 7: Suite completa + build assets

- [ ] **Step 1: Eseguire la suite completa**

Run: `docker compose exec php php artisan test`
Expected: tutta la suite PASS (inclusi Unit/OllamaServiceTest + Feature/AI/*).

- [ ] **Step 2: Build assets**

Run: `docker compose exec php npm run build`
Expected: exit 0, nessun errore Blade/Alpine.

- [ ] **Step 3: Verifica visuale (manuale)**

Con AI disabilitata (`ai_enabled=0`): creare una segnalazione → nessun badge AI, nessun triage, nessun titolo.
Con AI abilitata e Ollama up (`docker compose --profile ai up -d`): creare segnalazione → dopo qualche secondo comparirà il titolo nella scheda; il triage badge apparirà se il modello risponde JSON valido.

- [ ] **Step 4: Commit finale**

```powershell
git add -A
git commit -m "chore(v0.8): suite verde, assets build"
```

---

## Self-Review

**Copertura spec:**
- §5.1 Titolo automatico → Task 4 ✓
- §5.2 Triage suggerito → Task 5 ✓
- §5.3 Dedup semantico → Task 6 ✓
- Architettura Ollama compose → Task 2 ✓
- Flag `ai_enabled` disattivabile → Task 1 + ogni job verifica `isEnabled()` ✓
- Contenuti AI marcati → label `AI` in view ✓
- Solo job asincroni → tutti i job implementano `ShouldQueue` ✓
- Degradazione elegante → `generate()` e `embed()` restituiscono `null` se Ollama down, ogni job fa `return` ✓

**Placeholder check:** nessuno.

**Type consistency:**
- `OllamaService::generate()` → `?string` — usato in `GeneraTitoloSegnalazione` e `SuggerisciTriageSegnalazione` ✓
- `OllamaService::embed()` → `?array` (float[]) — usato in `CalcolaEmbeddingSegnalazione` e `DedupService` ✓
- `CosineSimilarity::compute(float[], float[])` → `float` — usato in `DedupService` ✓
- `CalcolaEmbeddingSegnalazione::handle(OllamaService)` — firma coerente con gli altri job ✓
- `DedupService::trovaSimili()` ha nuovo parametro `?string $testo = null` — opzionale, non rompe chiamate esistenti ✓
