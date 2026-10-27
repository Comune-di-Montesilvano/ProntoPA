<?php

namespace Tests\Feature;

use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\SegnalazioneStatoCambiato;
use App\Services\SegnalazioneWorkflowService;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Gli account disattivati (segnalatori legacy) non ricevono le email di cambio stato: non potrebbero aprire il link. */
class NotificheAccountDisattivatiTest extends TestCase
{
    use RefreshDatabase;

    private User $gestore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TabelleRiferimentoSeeder::class, ImpostazioniSeeder::class, RolesAndPermissionsSeeder::class]);
        Notification::fake();
        $this->gestore = User::factory()->create(['supervisore_segnalazioni' => true]);
        $this->gestore->assignRole('gestore');
    }

    private function accettaPreventivo(User $autore): void
    {
        $s = Segnalazione::factory()->create([
            'id_stato_segnalazione' => 5, 'id_utente_segnalazione' => $autore->id, 'importo_preventivo' => 100,
        ]);

        app(SegnalazioneWorkflowService::class)->eseguiAzione($s, 7, $this->gestore, []);
    }

    public function test_autore_disattivato_non_riceve_cambio_stato(): void
    {
        $legacy = User::factory()->create(['auth_source' => 'locale', 'attivo' => false]);
        $legacy->assignRole('segnalatore');

        $this->accettaPreventivo($legacy);

        Notification::assertNotSentTo($legacy, SegnalazioneStatoCambiato::class);
    }

    public function test_autore_attivo_riceve_cambio_stato(): void
    {
        $autore = User::factory()->create();
        $autore->assignRole('segnalatore');

        $this->accettaPreventivo($autore);

        Notification::assertSentTo($autore, SegnalazioneStatoCambiato::class);
    }
}
