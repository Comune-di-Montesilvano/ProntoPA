<?php

namespace Tests\Feature;

use App\Models\AdesioneSegnalazione;
use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdesioniTest extends TestCase
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
        Impostazione::set('adesioni_soglia_priorita', 2);
    }

    private function utente(): User
    {
        $user = User::factory()->create();
        $user->assignRole('segnalatore');

        return $user;
    }

    public function test_utente_aderisce_a_segnalazione(): void
    {
        $seg = Segnalazione::factory()->create(['livello_priorita' => 2]);
        $user = $this->utente();

        $response = $this->actingAs($user)->post(
            route('segnalazioni.adesioni.store', $seg)
        );

        $response->assertRedirect();
        $this->assertSame(1, $seg->adesioni()->count());
        $this->assertSame($user->id, $seg->adesioni()->first()->id_utente);
    }

    public function test_doppia_adesione_stesso_utente_rifiutata(): void
    {
        $seg = Segnalazione::factory()->create();
        $user = $this->utente();

        $this->actingAs($user)->post(route('segnalazioni.adesioni.store', $seg));
        $response = $this->actingAs($user)->post(route('segnalazioni.adesioni.store', $seg));

        $response->assertSessionHasErrors('adesione');
        $this->assertSame(1, $seg->adesioni()->count());
    }

    public function test_adesione_per_conto_consente_piu_chiamanti(): void
    {
        $seg = Segnalazione::factory()->create();
        $urp = $this->utente();
        $urp->givePermissionTo('segnalazioni.per-conto');

        $this->actingAs($urp)->post(route('segnalazioni.adesioni.store', $seg), [
            'segnalante' => 'Primo Chiamante',
            'telefono'   => '111',
        ]);
        $response = $this->actingAs($urp)->post(route('segnalazioni.adesioni.store', $seg), [
            'segnalante' => 'Secondo Chiamante',
            'telefono'   => '222',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame(2, $seg->adesioni()->count());
    }

    public function test_escalation_priorita_ogni_n_adesioni(): void
    {
        $seg = Segnalazione::factory()->create(['livello_priorita' => 2]);

        $this->actingAs($this->utente())->post(route('segnalazioni.adesioni.store', $seg));
        $this->assertSame(2, $seg->fresh()->livello_priorita);

        $this->actingAs($this->utente())->post(route('segnalazioni.adesioni.store', $seg));
        // soglia = 2: alla seconda adesione la priorità sale
        $this->assertSame(3, $seg->fresh()->livello_priorita);
    }

    public function test_adesione_negata_su_segnalazione_chiusa(): void
    {
        $seg = Segnalazione::factory()->create(['data_chiusura' => now()]);

        $response = $this->actingAs($this->utente())->post(
            route('segnalazioni.adesioni.store', $seg)
        );

        $response->assertSessionHasErrors('adesione');
        $this->assertSame(0, $seg->adesioni()->count());
    }

    public function test_flag_spento_blocca_adesioni(): void
    {
        Impostazione::set('adesioni_enabled', false);
        $seg = Segnalazione::factory()->create();

        $response = $this->actingAs($this->utente())->post(
            route('segnalazioni.adesioni.store', $seg)
        );

        $response->assertSessionHasErrors('adesione');
    }

    public function test_adesione_per_conto_stesso_chiamante_rifiutata(): void
    {
        $seg = Segnalazione::factory()->create();
        $urp = $this->utente();
        $urp->givePermissionTo('segnalazioni.per-conto');

        $this->actingAs($urp)->post(route('segnalazioni.adesioni.store', $seg), [
            'segnalante' => 'Mario Rossi',
        ]);
        $response = $this->actingAs($urp)->post(route('segnalazioni.adesioni.store', $seg), [
            'segnalante' => 'Mario Rossi',
        ]);

        $response->assertSessionHasErrors('adesione');
        $this->assertSame(1, $seg->adesioni()->count());
    }

    public function test_aderenti_notificati_alla_chiusura(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $admin = User::factory()->create([
            'amministratore' => true,
            'gestore_segnalazioni' => true,
            'supervisore_segnalazioni' => true,
        ]);
        $admin->assignRole('admin');

        $seg = Segnalazione::factory()->create(['id_stato_segnalazione' => \App\Enums\SegnalazioneStato::IN_CARICO]);
        $aderente = $this->utente();

        $this->actingAs($aderente)->post(route('segnalazioni.adesioni.store', $seg));

        $azioneChiusura = \App\Models\Azione::where('codice', 'chiudi')->first();
        $this->assertNotNull($azioneChiusura, 'Azione chiudi non trovata nei dati seed');

        Storage::fake('local');
        $this->actingAs($admin)->post(route('segnalazioni.azione', $seg), [
            'id_azione'  => $azioneChiusura->id_azione,
            'ore_lavoro' => 2,
            'materiali'  => 'Nessuno',
            'nota'       => 'Chiusura test.',
            'allegati'   => [UploadedFile::fake()->image('dopo.jpg')],
        ]);

        \Illuminate\Support\Facades\Notification::assertSentTo(
            $aderente,
            \App\Notifications\SegnalazioneChiusaNotification::class
        );
    }

    public function test_segnalatore_aderente_notificato_una_sola_volta(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $admin = User::factory()->create([
            'amministratore' => true,
            'gestore_segnalazioni' => true,
            'supervisore_segnalazioni' => true,
        ]);
        $admin->assignRole('admin');

        $segnalatore = $this->utente();
        $seg = Segnalazione::factory()->create([
            'id_utente_segnalazione' => $segnalatore->id,
            'id_stato_segnalazione'  => \App\Enums\SegnalazioneStato::IN_CARICO,
        ]);

        // Il segnalatore aderisce alla propria segnalazione
        $this->actingAs($segnalatore)->post(route('segnalazioni.adesioni.store', $seg));

        $azioneChiusura = \App\Models\Azione::where('codice', 'chiudi')->first();
        $this->assertNotNull($azioneChiusura, 'Azione chiudi non trovata nei dati seed');

        Storage::fake('local');
        $this->actingAs($admin)->post(route('segnalazioni.azione', $seg), [
            'id_azione'  => $azioneChiusura->id_azione,
            'ore_lavoro' => 2,
            'materiali'  => 'Nessuno',
            'nota'       => 'Chiusura test.',
            'allegati'   => [UploadedFile::fake()->image('dopo.jpg')],
        ]);

        \Illuminate\Support\Facades\Notification::assertSentToTimes(
            $segnalatore,
            \App\Notifications\SegnalazioneChiusaNotification::class,
            1
        );
    }
}
