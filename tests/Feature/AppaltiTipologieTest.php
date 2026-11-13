<?php

namespace Tests\Feature;

use App\Models\Appalto;
use App\Models\Impresa;
use App\Models\Segnalazione;
use App\Models\User;
use App\Support\Segnalazioni\AppaltiPerTipologia;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 1.0.0: appalti legati alle tipologie, come i gestori. */
class AppaltiTipologieTest extends TestCase
{
    use RefreshDatabase;

    private User $gestore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        $this->gestore = User::factory()->create(['supervisore_segnalazioni' => true]);
        $this->gestore->assignRole('gestore');
    }

    private function appalto(string $ditta, array $attr = []): Appalto
    {
        $impresa = Impresa::create(['ragione_sociale' => $ditta]);

        return Appalto::create(array_merge(['descrizione' => 'Appalto '.$ditta, 'id_impresa' => $impresa->id_impresa, 'valido' => true], $attr));
    }

    private function converti(): void
    {
        (require database_path('migrations/2026_11_15_000002_appalti_tipologie.php'))->converti();
    }

    public function test_assegna_mostra_prima_chi_copre(): void
    {
        $altra = $this->appalto('Zeta Verde');
        $altra->tipologie()->sync([10]);
        $giusta = $this->appalto('Alfa Reti');
        $giusta->tipologie()->sync([7]);
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 2, 'id_tipologia_segnalazione' => 7]);

        $this->actingAs($this->gestore)->get(route('segnalazioni.show', $s))
            ->assertOk()
            ->assertSeeInOrder(['Alfa Reti', 'Zeta Verde'])
            ->assertSee('Altre ditte');
    }

    public function test_ordina_multipla_richiede_tutte(): void
    {
        $a = $this->appalto('Solo uno');
        $a->tipologie()->sync([1]);
        $b = $this->appalto('Tutte');
        $b->update(['tutte_tipologie' => true]);

        $esito = AppaltiPerTipologia::ordina(Appalto::with('tipologie')->get(), [1, 3]);

        $this->assertSame([$b->id_appalto], $esito['coprono']->pluck('id_appalto')->all());
        $this->assertSame([$a->id_appalto], $esito['altri']->pluck('id_appalto')->all());
    }

    public function test_form_appalto_salva_tipologie_e_tutte(): void
    {
        $impresa = Impresa::create(['ragione_sociale' => 'Nuova']);

        $this->actingAs($this->gestore)->post(route('appalti.store'), [
            'descrizione' => 'Manutenzione impianti', 'id_impresa' => $impresa->id_impresa, 'valido' => 1, 'tipologie' => [1, 6],
        ])->assertRedirect(route('appalti.index'));
        $a = Appalto::where('descrizione', 'Manutenzione impianti')->firstOrFail();
        $this->assertEqualsCanonicalizing([1, 6], $a->tipologie()->pluck('tipologie_segnalazioni.id_tipologia_segnalazione')->map(fn ($v) => (int) $v)->all());

        $this->actingAs($this->gestore)->put(route('appalti.update', $a), [
            'descrizione' => 'Manutenzione impianti', 'id_impresa' => $impresa->id_impresa, 'valido' => 1, 'tutte_tipologie' => 1,
        ])->assertRedirect(route('appalti.index'));
        $this->assertTrue((bool) $a->fresh()->tutte_tipologie);
        $this->assertSame(0, $a->tipologie()->count());
    }
}
