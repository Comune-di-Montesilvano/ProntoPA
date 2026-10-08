<?php

namespace Tests\Feature\Deleghe;

use App\Models\Delega;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\Support\DelegheFixture;
use Tests\TestCase;

class VisibilitaDelegheTest extends TestCase
{
    use DelegheFixture, RefreshDatabase;

    private User $user;

    /** @var list<int> */
    private array $plessi;

    private Segnalazione $suP1;

    private Segnalazione $suP2;

    private Segnalazione $altrove;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $ist = $this->istitutoConPlessi();
        $this->plessi = $this->idPlessi($ist);
        $altro = $this->istitutoConPlessi('PEIC999999');
        $this->user = $this->utenteSpid();
        $collega = User::factory()->create();

        // segnalazioni fatte da altri: la segnalazione appartiene alla scuola
        $this->suP1 = Segnalazione::factory()->create(['id_plesso' => $this->plessi[0], 'id_utente_segnalazione' => $collega->id]);
        $this->suP2 = Segnalazione::factory()->create(['id_plesso' => $this->plessi[1], 'id_utente_segnalazione' => $collega->id]);
        $this->altrove = Segnalazione::factory()->create(['id_plesso' => $this->idPlessi($altro)[0], 'id_utente_segnalazione' => $collega->id]);
    }

    /** @return list<int> */
    private function visibili(): array
    {
        return Segnalazione::visibileA($this->user)->orderBy('id_segnalazione')->pluck('id_segnalazione')->map(fn ($i) => (int) $i)->all();
    }

    public function test_delegato_plesso_vede_solo_quel_plesso(): void
    {
        $this->delega($this->user, \App\Models\Istituto::first(), $this->plessi[0]);

        $this->assertSame([$this->suP1->id_segnalazione], $this->visibili());
        $this->actingAs($this->user)->get(route('segnalazioni.show', $this->suP1))->assertOk();
        $this->actingAs($this->user)->get(route('segnalazioni.show', $this->suP2))->assertForbidden();
    }

    public function test_delegato_istituto_vede_tutti_i_plessi(): void
    {
        $this->delega($this->user, \App\Models\Istituto::first(), null);

        $this->assertSame([$this->suP1->id_segnalazione, $this->suP2->id_segnalazione], $this->visibili());
        $this->actingAs($this->user)->get(route('segnalazioni.show', $this->altrove))->assertForbidden();
    }

    public function test_revoca_toglie_visibilita_senza_logout(): void
    {
        $delega = $this->delega($this->user, \App\Models\Istituto::first(), null);
        $this->actingAs($this->user)->get(route('segnalazioni.show', $this->suP1))->assertOk();

        $delega->update(['stato' => Delega::REVOCATA]);

        $this->assertSame([], $this->visibili());
        $this->actingAs($this->user)->get(route('segnalazioni.show', $this->suP1))->assertRedirect(route('scuola.deleghe.index'));
    }

    public function test_form_creazione_limita_i_plessi(): void
    {
        $this->delega($this->user, \App\Models\Istituto::first(), $this->plessi[0]);

        $this->actingAs($this->user)->get(route('segnalazioni.create'))
            ->assertOk()
            ->assertSee('Plesso 1 PEIC828004')
            ->assertDontSee('Plesso 2 PEIC828004')
            ->assertDontSee('PEIC999999');
    }

    public function test_store_rifiuta_plesso_non_coperto(): void
    {
        $this->delega($this->user, \App\Models\Istituto::first(), $this->plessi[0]);

        $this->actingAs($this->user)->post(route('segnalazioni.store'), [
            'id_tipologia_segnalazione' => 1, 'testo_segnalazione' => 'Perdita acqua bagno',
            'id_provenienza' => 2, 'id_plesso' => $this->plessi[1],
        ])->assertSessionHasErrors('id_plesso');

        $this->actingAs($this->user)->post(route('segnalazioni.store'), [
            'id_tipologia_segnalazione' => 1, 'testo_segnalazione' => 'Perdita acqua bagno',
            'id_provenienza' => 2,
        ])->assertSessionHasErrors('id_plesso');
    }

    public function test_altri_ruoli_invariati(): void
    {
        $locale = User::factory()->create();
        $locale->assignRole('segnalatore');
        $propria = Segnalazione::factory()->create(['id_plesso' => $this->plessi[0], 'id_utente_segnalazione' => $locale->id]);

        $this->assertSame([$propria->id_segnalazione], Segnalazione::visibileA($locale)->pluck('id_segnalazione')->map(fn ($i) => (int) $i)->all());
    }
}
