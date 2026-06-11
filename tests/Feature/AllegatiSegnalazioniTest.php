<?php

namespace Tests\Feature;

use App\Models\AllegatoSegnalazione;
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

class AllegatiSegnalazioniTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        // Fake storage by default
        Storage::fake('local');
        Storage::fake('public');

        // Clear the Impostazione cache
        \Illuminate\Support\Facades\Cache::flush();
    }

    private function segnalatore(): User
    {
        $user = User::factory()->create();
        $user->assignRole('segnalatore');

        return $user;
    }

    private function admin(): User
    {
        $user = User::factory()->create([
            'amministratore' => true,
            'gestore_segnalazioni' => true,
            'supervisore_segnalazioni' => true,
        ]);
        $user->assignRole('admin');

        return $user;
    }

    public function test_creazione_segnalazione_rispetta_disk_configurato(): void
    {
        Impostazione::set('allegati_storage_disk', 'public');

        $user = $this->segnalatore();

        $this->actingAs($user)->post(route('segnalazioni.store'), [
            'id_tipologia_segnalazione' => 1,
            'testo_segnalazione'        => 'Lampione spento via Roma',
            'id_provenienza'            => 1,
            'allegati'                  => [UploadedFile::fake()->image('foto.jpg')],
        ]);

        $allegato = AllegatoSegnalazione::first();
        $this->assertNotNull($allegato);
        Storage::disk('public')->assertExists($allegato->percorso);
        Storage::disk('local')->assertMissing($allegato->percorso);
    }

    public function test_creazione_segnalazione_con_allegati_salva_file_e_record(): void
    {
        $user = $this->segnalatore();

        $response = $this->actingAs($user)->post(route('segnalazioni.store'), [
            'id_tipologia_segnalazione' => 1,
            'testo_segnalazione'        => 'Perdita acqua bagno secondo piano',
            'id_provenienza'            => 1,
            'allegati'                  => [
                UploadedFile::fake()->image('foto1.jpg'),
                UploadedFile::fake()->image('foto2.png'),
            ],
        ]);

        $response->assertRedirect(route('segnalazioni.index'));

        $segnalazione = Segnalazione::latest('id_segnalazione')->first();
        $this->assertNotNull($segnalazione);
        $this->assertSame(2, $segnalazione->allegati()->count());

        foreach ($segnalazione->allegati as $allegato) {
            Storage::disk('local')->assertExists($allegato->percorso);
            $this->assertSame($user->id, $allegato->id_utente_creazione);
        }
    }

    public function test_creazione_rifiuta_mime_non_consentito(): void
    {
        $user = $this->segnalatore();

        $response = $this->actingAs($user)->post(route('segnalazioni.store'), [
            'id_tipologia_segnalazione' => 1,
            'testo_segnalazione'        => 'Test mime',
            'id_provenienza'            => 1,
            'allegati'                  => [
                UploadedFile::fake()->create('script.exe', 100, 'application/x-msdownload'),
            ],
        ]);

        $response->assertSessionHasErrors('allegati.0');
        $this->assertSame(0, AllegatoSegnalazione::count());
    }

    public function test_upload_su_segnalazione_esistente(): void
    {
        $user = $this->segnalatore();
        $segnalazione = Segnalazione::factory()->create([
            'id_utente_segnalazione' => $user->id,
        ]);

        $response = $this->actingAs($user)->post(
            route('segnalazioni.allegati.store', $segnalazione),
            ['allegati' => [UploadedFile::fake()->image('dopo.jpg')]]
        );

        $response->assertRedirect();
        $this->assertSame(1, $segnalazione->allegati()->count());
        Storage::disk('local')->assertExists($segnalazione->allegati()->first()->percorso);
    }

    public function test_upload_negato_su_segnalazione_altrui(): void
    {
        $estraneo     = $this->segnalatore();
        $proprietario = $this->segnalatore();
        $segnalazione = Segnalazione::factory()->create([
            'id_utente_segnalazione' => $proprietario->id,
        ]);

        $response = $this->actingAs($estraneo)->post(
            route('segnalazioni.allegati.store', $segnalazione),
            ['allegati' => [UploadedFile::fake()->image('foto.jpg')]]
        );

        $response->assertForbidden();
        $this->assertSame(0, AllegatoSegnalazione::count());
    }

    public function test_upload_rifiuta_troppi_file(): void
    {
        Impostazione::set('allegati_max_per_request', 2);

        $user = $this->segnalatore();
        $segnalazione = Segnalazione::factory()->create([
            'id_utente_segnalazione' => $user->id,
        ]);

        $response = $this->actingAs($user)->post(
            route('segnalazioni.allegati.store', $segnalazione),
            ['allegati' => [
                UploadedFile::fake()->image('1.jpg'),
                UploadedFile::fake()->image('2.jpg'),
                UploadedFile::fake()->image('3.jpg'),
            ]]
        );

        $response->assertSessionHasErrors('allegati');
        $this->assertSame(0, AllegatoSegnalazione::count());
    }

    public function test_download_da_utente_autorizzato(): void
    {
        $user = $this->segnalatore();
        $segnalazione = Segnalazione::factory()->create([
            'id_utente_segnalazione' => $user->id,
        ]);

        $this->actingAs($user)->post(
            route('segnalazioni.allegati.store', $segnalazione),
            ['allegati' => [UploadedFile::fake()->image('foto.jpg')]]
        );

        $allegato = $segnalazione->allegati()->first();

        $response = $this->actingAs($user)->get(
            route('segnalazioni.allegati.download', [$segnalazione, $allegato])
        );

        $response->assertOk();
        $response->assertDownload('foto.jpg');
    }

    public function test_download_negato_a_utente_estraneo(): void
    {
        $proprietario = $this->segnalatore();
        $segnalazione = Segnalazione::factory()->create([
            'id_utente_segnalazione' => $proprietario->id,
        ]);

        $this->actingAs($proprietario)->post(
            route('segnalazioni.allegati.store', $segnalazione),
            ['allegati' => [UploadedFile::fake()->image('foto.jpg')]]
        );

        $allegato = $segnalazione->allegati()->first();

        $response = $this->actingAs($this->segnalatore())->get(
            route('segnalazioni.allegati.download', [$segnalazione, $allegato])
        );

        $response->assertForbidden();
    }

    public function test_download_404_se_allegato_di_altra_segnalazione(): void
    {
        $admin = $this->admin();
        $segnalazioneA = Segnalazione::factory()->create();
        $segnalazioneB = Segnalazione::factory()->create();

        $this->actingAs($admin)->post(
            route('segnalazioni.allegati.store', $segnalazioneA),
            ['allegati' => [UploadedFile::fake()->image('foto.jpg')]]
        );

        $allegato = $segnalazioneA->allegati()->first();

        $response = $this->actingAs($admin)->get(
            route('segnalazioni.allegati.download', [$segnalazioneB, $allegato])
        );

        $response->assertNotFound();
    }

    public function test_admin_elimina_allegato_e_file(): void
    {
        $admin = $this->admin();
        $segnalazione = Segnalazione::factory()->create();

        $this->actingAs($admin)->post(
            route('segnalazioni.allegati.store', $segnalazione),
            ['allegati' => [UploadedFile::fake()->image('foto.jpg')]]
        );

        $allegato = $segnalazione->allegati()->first();
        $percorso = $allegato->percorso;

        $response = $this->actingAs($admin)->delete(
            route('segnalazioni.allegati.destroy', [$segnalazione, $allegato])
        );

        $response->assertRedirect();
        $this->assertSame(0, AllegatoSegnalazione::count());
        Storage::disk('local')->assertMissing($percorso);
    }

    public function test_segnalatore_elimina_solo_da_propria_segnalazione(): void
    {
        $proprietario = $this->segnalatore();
        $segnalazione = Segnalazione::factory()->create([
            'id_utente_segnalazione' => $proprietario->id,
        ]);

        $this->actingAs($proprietario)->post(
            route('segnalazioni.allegati.store', $segnalazione),
            ['allegati' => [UploadedFile::fake()->image('foto.jpg')]]
        );

        $allegato = $segnalazione->allegati()->first();

        $response = $this->actingAs($this->segnalatore())->delete(
            route('segnalazioni.allegati.destroy', [$segnalazione, $allegato])
        );
        $response->assertForbidden();
        $this->assertSame(1, AllegatoSegnalazione::count());

        $response = $this->actingAs($proprietario)->delete(
            route('segnalazioni.allegati.destroy', [$segnalazione, $allegato])
        );
        $response->assertRedirect();
        $this->assertSame(0, AllegatoSegnalazione::count());
    }
}
