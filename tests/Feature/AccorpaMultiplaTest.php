<?php

namespace Tests\Feature;

use App\Enums\SegnalazioneStato;
use App\Models\CategoriaEnte;
use App\Models\Istituto;
use App\Models\Plesso;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** 1.0.4: accorpa dalle azioni multiple, solo stessa sede e tipologia; la più vecchia accorpa le altre. */
class AccorpaMultiplaTest extends TestCase
{
    use RefreshDatabase;

    private User $gestore;

    private Plesso $sede;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        Notification::fake();
        $this->gestore = User::factory()->create(['supervisore_segnalazioni' => true]);
        $this->gestore->assignRole('gestore');
        $this->sede = $this->nuovaSede('ALFA');
    }

    private function nuovaSede(string $nome): Plesso
    {
        $ente = Istituto::create(['descrizione' => 'IC '.$nome, 'id_categoria' => CategoriaEnte::factory()->create()->id_categoria, 'attivo' => true]);

        return Plesso::create(['id_istituto' => $ente->id_istituto, 'nome' => $nome, 'indirizzo' => 'Via '.$nome]);
    }

    private function crea(string $quando, array $attr = []): Segnalazione
    {
        return Segnalazione::factory()->create(array_merge([
            'id_stato_segnalazione' => 1, 'id_plesso' => $this->sede->id_plesso, 'id_tipologia_segnalazione' => 3,
            'data_segnalazione' => $quando,
        ], $attr));
    }

    private function accorpa(array $ids)
    {
        return $this->actingAs($this->gestore)->from(route('gestione.dashboard'))
            ->post(route('gestione.azioni-multiple'), ['ids' => $ids, 'operazione' => 'accorpa']);
    }

    public function test_la_piu_vecchia_accorpa_le_altre(): void
    {
        $nuova = $this->crea('2026-11-10 09:00');
        $vecchia = $this->crea('2026-10-01 09:00');
        $media = $this->crea('2026-10-20 09:00');

        $this->accorpa([$nuova->id_segnalazione, $vecchia->id_segnalazione, $media->id_segnalazione])
            ->assertRedirect()->assertSessionHas('success', fn ($m) => str_contains($m, '2 fatte') && str_contains($m, '#'.$vecchia->id_segnalazione));

        $this->assertNull($vecchia->fresh()->data_chiusura);
        foreach ([$nuova, $media] as $s) {
            $s->refresh();
            $this->assertSame(SegnalazioneStato::DUPLICATA, $s->statoEnum());
            $this->assertSame($vecchia->id_segnalazione, (int) $s->id_segnalazione_madre);
        }
    }

    public function test_sede_o_tipologia_diverse_niente(): void
    {
        $a = $this->crea('2026-10-01 09:00');
        $b = $this->crea('2026-10-02 09:00', ['id_tipologia_segnalazione' => 1]);
        $c = $this->crea('2026-10-03 09:00', ['id_plesso' => $this->nuovaSede('BETA')->id_plesso]);

        $this->accorpa([$a->id_segnalazione, $b->id_segnalazione])->assertSessionHasErrors('ids');
        $this->accorpa([$a->id_segnalazione, $c->id_segnalazione])->assertSessionHasErrors('ids');

        foreach ([$a, $b, $c] as $s) {
            $this->assertNull($s->fresh()->data_chiusura);
        }
    }

    public function test_senza_sede_o_una_sola_niente(): void
    {
        $a = $this->crea('2026-10-01 09:00', ['id_plesso' => 0]);
        $b = $this->crea('2026-10-02 09:00', ['id_plesso' => 0]);

        $this->accorpa([$a->id_segnalazione, $b->id_segnalazione])->assertSessionHasErrors('ids');
        $this->accorpa([$a->id_segnalazione])->assertSessionHasErrors('ids');
        $this->assertNull($b->fresh()->data_chiusura);
    }

    public function test_chiuse_saltate_e_destinazione_aperta(): void
    {
        $chiusa = $this->crea('2026-09-01 09:00', ['id_stato_segnalazione' => 7, 'data_chiusura' => now()]);
        $aperta1 = $this->crea('2026-10-01 09:00');
        $aperta2 = $this->crea('2026-10-05 09:00');

        $this->accorpa([$chiusa->id_segnalazione, $aperta1->id_segnalazione, $aperta2->id_segnalazione])
            ->assertSessionHas('success', fn ($m) => str_contains($m, '1 fatte') && str_contains($m, '1 saltate'));

        $this->assertSame($aperta1->id_segnalazione, (int) $aperta2->fresh()->id_segnalazione_madre);
        $this->assertSame(SegnalazioneStato::COMPLETATA, $chiusa->fresh()->statoEnum());
    }

    public function test_pulsante_accorpa_in_elenco(): void
    {
        $this->crea('2026-10-01 09:00');

        $this->actingAs($this->gestore)->get(route('gestione.dashboard'))
            ->assertOk()->assertSee('multi-accorpa', false);
    }
}
