<?php

namespace Tests\Feature;

use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\StoricoStatoSegnalazione;
use App\Models\User;
use App\Services\DedupService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DedupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        \Illuminate\Support\Facades\Cache::flush();
        Impostazione::set('adesioni_enabled', true);
    }

    private function utente(): User
    {
        $user = User::factory()->create();
        $user->assignRole('segnalatore');

        return $user;
    }

    public function test_trova_simile_per_stesso_plesso(): void
    {
        $aperta = Segnalazione::factory()->create([
            'id_tipologia_segnalazione' => 1,
            'id_plesso'                 => 5,
        ]);
        Segnalazione::factory()->create([
            'id_tipologia_segnalazione' => 1,
            'id_plesso'                 => 6,
        ]);
        Segnalazione::factory()->create([
            'id_tipologia_segnalazione' => 1,
            'id_plesso'                 => 5,
            'data_chiusura'             => now(),
        ]);

        $response = $this->actingAs($this->utente())->getJson(
            route('segnalazioni.simili', [
                'id_tipologia_segnalazione' => 1,
                'id_plesso'                 => 5,
            ])
        );

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['id' => $aperta->id_segnalazione]);
    }

    public function test_flag_spento_restituisce_lista_vuota(): void
    {
        Impostazione::set('adesioni_enabled', false);

        Segnalazione::factory()->create([
            'id_tipologia_segnalazione' => 1,
            'id_plesso'                 => 5,
        ]);

        $response = $this->actingAs($this->utente())->getJson(
            route('segnalazioni.simili', [
                'id_tipologia_segnalazione' => 1,
                'id_plesso'                 => 5,
            ])
        );

        $response->assertOk();
        $response->assertJsonCount(0);
    }

    public function test_senza_luogo_restituisce_lista_vuota(): void
    {
        Segnalazione::factory()->create(['id_tipologia_segnalazione' => 1]);

        $response = $this->actingAs($this->utente())->getJson(
            route('segnalazioni.simili', ['id_tipologia_segnalazione' => 1])
        );

        $response->assertOk();
        $response->assertJsonCount(0);
    }

    public function test_simili_non_espone_testo_di_segnalazioni_non_visibili(): void
    {
        $altrui = Segnalazione::factory()->create([
            'id_tipologia_segnalazione' => 1,
            'id_plesso'                 => 5,
            'testo_segnalazione'        => 'Dettagli riservati con dati personali',
        ]);

        $response = $this->actingAs($this->utente())->getJson(
            route('segnalazioni.simili', [
                'id_tipologia_segnalazione' => 1,
                'id_plesso'                 => 5,
            ])
        );

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['id' => $altrui->id_segnalazione, 'testo' => null, 'foto_url' => null]);
    }

    // ── apertaPerSedeETipologia: la segnalazione su cui scrivere invece di aprirne un'altra ──

    private function aperta(User $autore, array $attr = []): Segnalazione
    {
        return Segnalazione::factory()->create($attr + [
            'id_utente_segnalazione' => $autore->id, 'id_tipologia_segnalazione' => 1, 'id_plesso' => 5,
            'id_stato_segnalazione' => 2, 'data_chiusura' => null,
        ]);
    }

    private function cerca(User $u): ?Segnalazione
    {
        return app(DedupService::class)->apertaPerSedeETipologia($u, 5, 1);
    }

    public function test_aperta_trovata_anche_con_adesioni_spente_e_vecchia(): void
    {
        Impostazione::set('adesioni_enabled', false);
        $io = $this->utente();
        $s = $this->aperta($io, ['data_segnalazione' => now()->subDays(120)]);

        $this->assertSame($s->id_segnalazione, $this->cerca($io)?->id_segnalazione);
    }

    public function test_aperta_ignora_chiusa_altra_sede_e_altra_tipologia(): void
    {
        $io = $this->utente();
        $this->aperta($io, ['id_stato_segnalazione' => 7, 'data_chiusura' => now()]);
        $this->aperta($io, ['id_plesso' => 6]);
        $this->aperta($io, ['id_tipologia_segnalazione' => 2]);

        $this->assertNull($this->cerca($io));
    }

    public function test_aperta_ignora_quella_con_preventivo_salvo_richiesta(): void
    {
        $io = $this->utente();
        $s = $this->aperta($io, ['id_stato_segnalazione' => 4]);
        StoricoStatoSegnalazione::create([
            'id_segnalazione' => $s->id_segnalazione, 'id_stato_segnalazione' => 5,
            'id_utente' => $io->id, 'id_utente_collegato' => 0, 'id_appalto' => 0,
        ]);

        $this->assertNull($this->cerca($io));
        $this->assertSame($s->id_segnalazione,
            app(DedupService::class)->apertaPerSedeETipologia($io, 5, 1, ancheConPreventivo: true)?->id_segnalazione);
    }

    public function test_aperta_ignora_quella_che_non_vede(): void
    {
        $this->aperta($this->utente());

        $this->assertNull($this->cerca($this->utente()));
    }

    public function test_aperta_preferisce_la_propria(): void
    {
        // L'admin le vede tutte: tra una sua più vecchia e una altrui più recente vince la sua.
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $sua = $this->aperta($admin, ['data_segnalazione' => now()->subDays(10)]);
        $this->aperta($this->utente(), ['data_segnalazione' => now()->subDay()]);

        $this->assertSame($sua->id_segnalazione, $this->cerca($admin)?->id_segnalazione);
    }
}
