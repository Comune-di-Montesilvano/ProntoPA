<?php

namespace Tests\Feature;

use App\Enums\SegnalazioneStato;
use App\Models\Appalto;
use App\Models\Impresa;
use App\Models\NotaSegnalazione;
use App\Models\Segnalazione;
use App\Models\StoricoStatoSegnalazione;
use App\Models\User;
use App\Notifications\FineLavoriNotification;
use App\Notifications\SegnalazioneChiusaNotification;
use App\Support\Segnalazioni\ProssimoPasso;
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

/** Ditta e operaio comunicano la fine dei lavori con foto; il comune poi chiude senza rifare il rapportino. */
class FineLavoriTest extends TestCase
{
    use RefreshDatabase;

    private User $gestore;

    private User $ditta;

    private Segnalazione $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        Storage::fake('local');
        Notification::fake();

        $this->gestore = User::factory()->create(['supervisore_segnalazioni' => true]);
        $this->gestore->assignRole('gestore');

        $impresa = Impresa::create(['ragione_sociale' => 'Edil']);
        $appalto = Appalto::create(['id_gruppo' => 1, 'descrizione' => 'A', 'id_impresa' => $impresa->id_impresa, 'valido' => true]);
        $this->ditta = User::factory()->create(['auth_source' => 'spid', 'id_impresa' => $impresa->id_impresa, 'email_verified_at' => now()]);
        $this->ditta->assignRole('impresa');

        $this->s = Segnalazione::factory()->create(['id_stato_segnalazione' => 4, 'id_appalto' => $appalto->id_appalto]);
    }

    /** @return array<string, mixed> */
    private function rapportino(): array
    {
        return [
            'ore_lavoro' => 3,
            'materiali' => 'Guarnizione',
            'nota' => 'Sostituita la guarnizione',
            'allegati' => [UploadedFile::fake()->image('dopo.jpg')],
        ];
    }

    private function conPreventivo(Segnalazione $s): void
    {
        StoricoStatoSegnalazione::create(['id_segnalazione' => $s->id_segnalazione, 'id_stato_segnalazione' => 5, 'id_utente' => $this->ditta->id, 'id_utente_collegato' => 0, 'id_appalto' => 0]);
    }

    public function test_ditta_senza_preventivo_chiude(): void
    {
        $segnalante = User::factory()->create();
        $segnalante->assignRole('segnalatore');
        $this->s->update(['id_utente_segnalazione' => $segnalante->id]);

        $this->actingAs($this->ditta)->post(route('segnalazioni.fine-lavori', $this->s), $this->rapportino())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('segnalazioni.show', $this->s->id_segnalazione));

        $s = $this->s->fresh();
        $this->assertSame(SegnalazioneStato::COMPLETATA, $s->statoEnum());
        $this->assertNotNull($s->fine_lavori_at);
        $nota = NotaSegnalazione::where('id_segnalazione', $s->id_segnalazione)->oldest('id_nota')->firstOrFail();
        $this->assertStringContainsString('Fine lavori comunicata', $nota->testo);
        $this->assertStringContainsString('Ore impiegate: 3', $nota->testo);
        Notification::assertSentTo($this->gestore, FineLavoriNotification::class);
        Notification::assertSentTo($segnalante, SegnalazioneChiusaNotification::class);
    }

    public function test_ditta_con_preventivo_resta_aperta(): void
    {
        $this->conPreventivo($this->s);

        $this->actingAs($this->ditta)->post(route('segnalazioni.fine-lavori', $this->s), $this->rapportino())
            ->assertSessionHasNoErrors();

        $s = $this->s->fresh();
        $this->assertSame(SegnalazioneStato::ASSEGNATA_IMPRESA, $s->statoEnum());
        $this->assertNotNull($s->fine_lavori_at);
        $this->assertTrue($s->haFineLavori());
        $this->assertStringContainsString('verifica e chiudi', ProssimoPasso::per($s, $this->gestore)['titolo']);
    }

    public function test_operaio_fine_lavori_chiude(): void
    {
        $operaio = User::factory()->create();
        $operaio->assignRole('operaio');
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 3, 'id_operatore_assegnato' => $operaio->id]);

        $this->actingAs($operaio)->post(route('segnalazioni.fine-lavori', $s), $this->rapportino())
            ->assertSessionHasNoErrors()->assertRedirect()->assertSessionHas('success', 'Lavoro chiuso. Grazie!');

        $this->assertSame(SegnalazioneStato::COMPLETATA, $s->fresh()->statoEnum());
        Notification::assertSentTo($this->gestore, FineLavoriNotification::class);
    }

    public function test_fine_lavori_senza_foto_accettata(): void
    {
        $dati = $this->rapportino();
        unset($dati['allegati']);

        $this->actingAs($this->ditta)->post(route('segnalazioni.fine-lavori', $this->s), $dati)
            ->assertSessionHasNoErrors();

        $this->assertSame(SegnalazioneStato::COMPLETATA, $this->s->fresh()->statoEnum());
    }

    public function test_ditta_su_segnalazione_chiusa_403(): void
    {
        $this->s->update(['id_stato_segnalazione' => 7, 'data_chiusura' => now()]);

        $this->actingAs($this->ditta)->post(route('segnalazioni.fine-lavori', $this->s), $this->rapportino())
            ->assertForbidden();
    }

    public function test_chiudi_dopo_fine_lavori_con_preventivo(): void
    {
        $this->conPreventivo($this->s);
        $this->actingAs($this->ditta)->post(route('segnalazioni.fine-lavori', $this->s), $this->rapportino());

        $this->actingAs($this->gestore)->post(route('segnalazioni.azione', $this->s), ['id_azione' => 9])
            ->assertSessionHasNoErrors();

        $this->assertSame(SegnalazioneStato::COMPLETATA, $this->s->fresh()->statoEnum());
    }
}
