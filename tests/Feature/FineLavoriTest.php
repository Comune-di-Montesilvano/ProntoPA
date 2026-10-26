<?php

namespace Tests\Feature;

use App\Models\AllegatoSegnalazione;
use App\Models\Appalto;
use App\Models\Impresa;
use App\Models\NotaSegnalazione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\FineLavoriNotification;
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

    public function test_ditta_comunica_fine_lavori(): void
    {
        $this->actingAs($this->ditta)->post(route('segnalazioni.fine-lavori', $this->s), $this->rapportino())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('segnalazioni.show', $this->s->id_segnalazione));

        $s = $this->s->fresh();
        $this->assertSame(4, $s->statoEnum()->value);
        $this->assertSame(1, AllegatoSegnalazione::where('id_segnalazione', $s->id_segnalazione)->where('fase', 'dopo')->count());
        $nota = NotaSegnalazione::where('id_segnalazione', $s->id_segnalazione)->firstOrFail();
        $this->assertStringContainsString('Fine lavori comunicata', $nota->testo);
        $this->assertStringContainsString('Ore impiegate: 3', $nota->testo);
        $this->assertTrue($nota->visibile_impresa);
        $this->assertTrue($s->haFineLavori());
        Notification::assertSentTo($this->gestore, FineLavoriNotification::class);
    }

    public function test_operaio_assegnato_comunica_fine_lavori(): void
    {
        $operaio = User::factory()->create();
        $operaio->assignRole('operaio');
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 3, 'id_operatore_assegnato' => $operaio->id]);

        $this->actingAs($operaio)->post(route('segnalazioni.fine-lavori', $s), $this->rapportino())
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertTrue($s->fresh()->haFineLavori());
        Notification::assertSentTo($this->gestore, FineLavoriNotification::class);
    }

    public function test_senza_foto_errore(): void
    {
        $dati = $this->rapportino();
        unset($dati['allegati']);

        $this->actingAs($this->ditta)->post(route('segnalazioni.fine-lavori', $this->s), $dati)
            ->assertSessionHasErrors('allegati');
    }

    public function test_ditta_su_segnalazione_chiusa_403(): void
    {
        $this->s->update(['id_stato_segnalazione' => 7, 'data_chiusura' => now()]);

        $this->actingAs($this->ditta)->post(route('segnalazioni.fine-lavori', $this->s), $this->rapportino())
            ->assertForbidden();
    }

    public function test_chiudi_dopo_fine_lavori_non_richiede_rapportino(): void
    {
        $this->actingAs($this->ditta)->post(route('segnalazioni.fine-lavori', $this->s), $this->rapportino());

        $this->actingAs($this->gestore)->post(route('segnalazioni.azione', $this->s), ['id_azione' => 9])
            ->assertSessionHasNoErrors();

        $this->assertSame(7, $this->s->fresh()->statoEnum()->value);
    }

    public function test_chiudi_senza_fine_lavori_richiede_rapportino(): void
    {
        $this->actingAs($this->gestore)->post(route('segnalazioni.azione', $this->s), ['id_azione' => 9])
            ->assertSessionHasErrors(['ore_lavoro', 'materiali', 'allegati']);

        $this->assertSame(4, $this->s->fresh()->statoEnum()->value);
    }
}
