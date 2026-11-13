<?php

namespace Tests\Feature;

use App\Enums\SegnalazioneStato;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\OperatoreAssegnatoNotification;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Azioni su più segnalazioni dall'elenco gestione (spec §4). */
class AzioniMultipleTest extends TestCase
{
    use RefreshDatabase;

    private User $gestore;

    private User $operaio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        Notification::fake();
        $this->gestore = User::factory()->create(['supervisore_segnalazioni' => true]);
        $this->gestore->assignRole('gestore');
        $this->operaio = User::factory()->create();
        $this->operaio->assignRole('operaio');
    }

    /** @return list<int> */
    private function crea(int $n, array $attr = []): array
    {
        return Segnalazione::factory()->count($n)->create(array_merge(['id_stato_segnalazione' => 1], $attr))
            ->pluck('id_segnalazione')->all();
    }

    private function invia(array $dati, ?User $u = null)
    {
        return $this->actingAs($u ?? $this->gestore)->post(route('gestione.azioni-multiple'), $dati);
    }

    public function test_assegna_multipla_a_operaio(): void
    {
        $ids = $this->crea(3);

        $this->invia(['ids' => $ids, 'operazione' => 'assegna', 'tipo' => 'operaio', 'id_operatore' => $this->operaio->id])
            ->assertRedirect()->assertSessionHas('success', fn ($m) => str_contains($m, '3 fatte'));

        foreach ($ids as $id) {
            $this->assertSame(SegnalazioneStato::ASSEGNATA_OPERATORE, Segnalazione::find($id)->statoEnum());
        }
        Notification::assertSentToTimes($this->operaio, OperatoreAssegnatoNotification::class, 3);
    }

    public function test_chiudi_multipla_salta_non_applicabili(): void
    {
        [$aperta] = $this->crea(1, ['id_stato_segnalazione' => 3]);
        [$nuova] = $this->crea(1);

        $this->invia(['ids' => [$aperta, $nuova], 'operazione' => 'chiudi'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, '1 fatte') && str_contains($m, '1 saltate') && str_contains($m, "#{$nuova}"));

        $this->assertSame(SegnalazioneStato::COMPLETATA, Segnalazione::find($aperta)->statoEnum());
        $this->assertSame(SegnalazioneStato::NUOVA, Segnalazione::find($nuova)->statoEnum());
    }

    public function test_non_visibili_ignorate(): void
    {
        $gestore = User::factory()->create(['supervisore_segnalazioni' => false, 'gestore_segnalazioni' => true, 'gestore_tutte_tipologie' => false]);
        $gestore->assignRole('gestore');
        $gestore->tipologieGestite()->sync([1]);
        [$mia] = $this->crea(1, ['id_tipologia_segnalazione' => 1]);
        [$altrui] = $this->crea(1, ['id_tipologia_segnalazione' => 2]);

        $this->invia(['ids' => [$mia, $altrui], 'operazione' => 'chiudi_senza_intervento', 'motivo' => 'errata'], $gestore);

        $this->assertSame(SegnalazioneStato::ANNULLATA, Segnalazione::find($mia)->statoEnum());
        $this->assertSame(SegnalazioneStato::NUOVA, Segnalazione::find($altrui)->statoEnum());
    }

    public function test_chiudi_senza_intervento_multipla_con_motivo(): void
    {
        $ids = $this->crea(2);

        $this->invia(['ids' => $ids, 'operazione' => 'chiudi_senza_intervento'])->assertSessionHasErrors('motivo');

        $this->invia(['ids' => $ids, 'operazione' => 'chiudi_senza_intervento', 'motivo' => 'gia_risolta'])->assertSessionHasNoErrors();
        foreach ($ids as $id) {
            $s = Segnalazione::find($id);
            $this->assertSame(SegnalazioneStato::ANNULLATA, $s->statoEnum());
            $this->assertStringContainsString('Già risolta', $s->note()->latest('id_nota')->first()->testo);
        }
    }

    public function test_assegna_multipla_con_scadenza_imposta_manuale(): void
    {
        $ids = $this->crea(2);

        $this->invia(['ids' => $ids, 'operazione' => 'assegna', 'tipo' => 'me', 'data_scadenza' => now()->addDays(15)->format('Y-m-d')])
            ->assertSessionHasNoErrors();

        foreach ($ids as $id) {
            $s = Segnalazione::find($id);
            $this->assertSame(SegnalazioneStato::IN_CARICO, $s->statoEnum());
            $this->assertTrue($s->scadenza_manuale);
        }
    }

    public function test_max_100_ids(): void
    {
        $this->invia(['ids' => range(1, 101), 'operazione' => 'chiudi'])->assertSessionHasErrors('ids');
    }

    public function test_stampa_schede_con_ids_solo_selezionate(): void
    {
        [$a, $b, $c] = $this->crea(3, ['testo_segnalazione' => 'x']);
        Segnalazione::whereKey($a)->update(['testo_segnalazione' => 'PRIMA-SCELTA']);
        Segnalazione::whereKey($b)->update(['testo_segnalazione' => 'SECONDA-SCELTA']);
        Segnalazione::whereKey($c)->update(['testo_segnalazione' => 'NON-SCELTA']);

        $this->actingAs($this->gestore)->get(route('gestione.stampa-schede', ['ids' => [$a, $b]]))
            ->assertOk()->assertSee('PRIMA-SCELTA')->assertSee('SECONDA-SCELTA')->assertDontSee('NON-SCELTA');
    }
}
