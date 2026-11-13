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
        $appalto = Appalto::create(['descrizione' => 'A', 'id_impresa' => $impresa->id_impresa, 'valido' => true]);
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

    public function test_riassegna_dopo_fine_lavori_riparte_da_capo(): void
    {
        $this->conPreventivo($this->s);
        $this->actingAs($this->ditta)->post(route('segnalazioni.fine-lavori', $this->s), $this->rapportino());
        $operaio = User::factory()->create();
        $operaio->assignRole('operaio');

        $this->actingAs($this->gestore)->post(route('segnalazioni.azione', $this->s), ['id_azione' => 2, 'id_operatore' => $operaio->id])
            ->assertSessionHasNoErrors();

        $s = $this->s->fresh();
        $this->assertTrue($s->allegati()->where('fase', 'dopo')->exists());
        $this->assertFalse($s->haFineLavori());
        $this->assertStringNotContainsString('fine dei lavori', ProssimoPasso::per($s, $this->gestore)['titolo']);
    }

    public function test_migration_ricava_fine_lavori_dalle_foto_delle_aperte(): void
    {
        $this->s->forceFill(['fine_lavori_at' => null])->save();
        $foto = $this->s->allegati()->create(['percorso' => 'x.jpg', 'tipo' => 'image/jpeg', 'nome_originale' => 'x.jpg', 'dimensione' => 1, 'id_utente_creazione' => $this->ditta->id, 'fase' => 'dopo']);
        $chiusa = Segnalazione::factory()->create(['id_stato_segnalazione' => 7, 'data_chiusura' => now()]);
        $chiusa->allegati()->create(['percorso' => 'y.jpg', 'tipo' => 'image/jpeg', 'nome_originale' => 'y.jpg', 'dimensione' => 1, 'id_utente_creazione' => $this->ditta->id, 'fase' => 'dopo']);

        (require database_path('migrations/2026_11_13_000002_fine_lavori_da_foto.php'))->up();

        $this->assertTrue($this->s->fresh()->fine_lavori_at->equalTo($foto->fresh()->created_at));
        $this->assertNull($chiusa->fresh()->fine_lavori_at);
    }

    public function test_migration_ignora_foto_precedenti_alla_riassegnazione(): void
    {
        $s = Segnalazione::factory()->create(['id_stato_segnalazione' => 3]);
        $s->allegati()->create(['percorso' => 'x.jpg', 'tipo' => 'image/jpeg', 'nome_originale' => 'x.jpg', 'dimensione' => 1, 'id_utente_creazione' => $this->ditta->id, 'fase' => 'dopo']);
        StoricoStatoSegnalazione::forceCreate(['id_segnalazione' => $s->id_segnalazione, 'id_stato_segnalazione' => 3, 'id_utente' => $this->gestore->id, 'id_utente_collegato' => 0, 'id_appalto' => 0, 'data_registrazione' => now()->addMinute()]);

        (require database_path('migrations/2026_11_13_000002_fine_lavori_da_foto.php'))->up();

        $this->assertNull($s->fresh()->fine_lavori_at);
    }
}
