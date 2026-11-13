<?php

namespace Tests\Feature;

use App\Enums\SegnalazioneStato;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Chiudere senza obblighi; "Chiudi senza intervento" con motivo (spec §1). */
class ChiusuraSempliceTest extends TestCase
{
    use RefreshDatabase;

    private const CHIUDI = 9;
    private const SOSPENDI = 4;
    private const RIFIUTA = 8;
    private const SENZA_INTERVENTO = 12;

    private User $gestore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        Storage::fake('local');
        Notification::fake();
        $this->gestore = User::factory()->create(['supervisore_segnalazioni' => true]);
        $this->gestore->assignRole('gestore');
    }

    private function azione(Segnalazione $s, array $dati)
    {
        return $this->actingAs($this->gestore)->post(route('segnalazioni.azione', $s->id_segnalazione), $dati);
    }

    public function test_chiudi_senza_campi(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 3]);

        $this->azione($s, ['id_azione' => self::CHIUDI])->assertSessionHasNoErrors();

        $this->assertSame(SegnalazioneStato::COMPLETATA, $s->refresh()->statoEnum());
    }

    public function test_chiudi_con_foto_facoltativa_salva_allegato_dopo(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 2]);

        $this->azione($s, ['id_azione' => self::CHIUDI, 'nota' => 'Fatto', 'allegati' => [UploadedFile::fake()->image('dopo.jpg')]])
            ->assertSessionHasNoErrors();

        $this->assertSame(SegnalazioneStato::COMPLETATA, $s->refresh()->statoEnum());
        $this->assertTrue($s->allegati()->where('fase', 'dopo')->exists());
    }

    public function test_chiudi_senza_intervento_da_nuova_richiede_motivo(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 1]);

        $this->azione($s, ['id_azione' => self::SENZA_INTERVENTO])->assertSessionHasErrors('motivo');
        $this->assertSame(SegnalazioneStato::NUOVA, $s->refresh()->statoEnum());

        $this->azione($s, ['id_azione' => self::SENZA_INTERVENTO, 'motivo' => 'errata'])->assertSessionHasNoErrors();

        $s->refresh();
        $this->assertSame(SegnalazioneStato::ANNULLATA, $s->statoEnum());
        $nota = $s->note()->latest('id_nota')->first();
        $this->assertStringContainsString('Chiusa senza intervento: Segnalazione errata.', $nota->testo);
        $this->assertTrue((bool) $nota->visibile_web);
    }

    public function test_motivo_altro_richiede_nota(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 1]);

        $this->azione($s, ['id_azione' => self::SENZA_INTERVENTO, 'motivo' => 'altro'])->assertSessionHasErrors('nota');

        $this->azione($s, ['id_azione' => self::SENZA_INTERVENTO, 'motivo' => 'altro', 'nota' => 'Competenza della provincia'])
            ->assertSessionHasNoErrors();
        $this->assertStringContainsString('Competenza della provincia', $s->note()->latest('id_nota')->first()->testo);
    }

    public function test_sospendi_e_rifiuta_preventivo_richiedono_nota(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 3]);
        $this->azione($s, ['id_azione' => self::SOSPENDI])->assertSessionHasErrors('nota');

        $p = Segnalazione::factory()->create(['id_stato_segnalazione' => 5]);
        $this->azione($p, ['id_azione' => self::RIFIUTA])->assertSessionHasErrors('nota');
    }

    public function test_assegna_con_data_scadenza_dal_pannello(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 1]);
        $operaio = User::factory()->create();
        $operaio->assignRole('operaio');

        $this->azione($s, ['id_azione' => 2, 'id_operatore' => $operaio->id, 'data_scadenza' => now()->addDays(20)->format('Y-m-d')])
            ->assertSessionHasNoErrors();

        $this->assertTrue($s->refresh()->scadenza_manuale);
    }
}
