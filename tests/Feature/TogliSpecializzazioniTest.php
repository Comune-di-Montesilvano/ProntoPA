<?php

namespace Tests\Feature;

use App\Jobs\SuggerisciTriageSegnalazione;
use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\SlaConfigurazione;
use App\Models\User;
use App\Services\OllamaService;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** 1.0.0: le specializzazioni spariscono; le regole di scadenza sono tipologia + priorità. */
class TogliSpecializzazioniTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
    }

    public function test_schema_senza_specializzazioni(): void
    {
        $this->assertFalse(Schema::hasTable('db_specializzazioni'));
        $this->assertFalse(Schema::hasTable('imprese_specializzazioni'));
        $this->assertFalse(Schema::hasColumn('segnalazioni', 'id_specializzazione'));
        $this->assertFalse(Schema::hasColumn('sla_configurazioni', 'id_specializzazione'));
    }

    public function test_regola_per_tipologia_e_priorita_con_ripiego(): void
    {
        $base = SlaConfigurazione::create(['id_tipologia_segnalazione' => null, 'livello_priorita' => 2, 'ore_target' => 5, 'ore_warning' => 2, 'unita' => 'giorni']);
        $tip = SlaConfigurazione::create(['id_tipologia_segnalazione' => 3, 'livello_priorita' => 2, 'ore_target' => 2, 'ore_warning' => 1, 'unita' => 'giorni']);

        $this->assertTrue($tip->is(SlaConfigurazione::applicabileA(3, 2)));
        $this->assertTrue($base->is(SlaConfigurazione::applicabileA(1, 2)));
        $this->assertNull(SlaConfigurazione::applicabileA(3, 4));
    }

    public function test_triage_prompt_senza_specializzazione_con_tipologie_reali(): void
    {
        Impostazione::set('ai_enabled', '1');
        $prompt = null;
        $this->mock(OllamaService::class, function ($m) use (&$prompt) {
            $m->shouldReceive('generate')->once()->andReturnUsing(function ($p) use (&$prompt) {
                $prompt = $p;

                return '{"id_tipologia_segnalazione": 3, "livello_priorita": 3}';
            });
        });
        $s = Segnalazione::factory()->create(['testo_segnalazione' => 'Perde acqua dal lavandino del bagno', 'triage_suggerito' => null]);

        app()->call([new SuggerisciTriageSegnalazione($s->id_segnalazione), 'handle']);

        $this->assertStringContainsString('IMPIANTO IDRICO', (string) $prompt);
        $this->assertStringNotContainsStringIgnoringCase('specializzazion', (string) $prompt);
        $this->assertSame(['id_tipologia_segnalazione' => 3, 'livello_priorita' => 3], $s->fresh()->triage_suggerito);
    }

    public function test_triage_con_tipologia_inesistente_scartato(): void
    {
        $this->mock(OllamaService::class, fn ($m) => $m->shouldReceive('generate')->andReturn('{"id_tipologia_segnalazione": 999, "livello_priorita": 2}'));
        $s = Segnalazione::factory()->create(['testo_segnalazione' => 'Qualcosa di rotto in aula', 'triage_suggerito' => null]);

        app()->call([new SuggerisciTriageSegnalazione($s->id_segnalazione), 'handle']);

        $this->assertNull($s->fresh()->triage_suggerito);
    }

    public function test_triage_salvato_vecchio_con_specializzazione_non_rompe(): void
    {
        $admin = User::factory()->create(['amministratore' => true]);
        $admin->assignRole('admin');
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 1, 'triage_suggerito' => ['id_tipologia_segnalazione' => 3, 'id_specializzazione' => 2, 'livello_priorita' => 3]]);

        $this->actingAs($admin)->get(route('segnalazioni.show', $s))->assertOk();
        $this->actingAs($admin)->post(route('segnalazioni.applica-triage', $s))->assertRedirect();

        $s->refresh();
        $this->assertSame(3, (int) $s->id_tipologia_segnalazione);
        $this->assertSame(3, (int) $s->livello_priorita);
    }
}
