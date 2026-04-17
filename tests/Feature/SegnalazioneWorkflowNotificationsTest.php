<?php

namespace Tests\Feature;

use App\Models\Appalto;
use App\Models\Impresa;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\ImpresaAssegnataNotification;
use App\Notifications\OperatoreAssegnatoNotification;
use App\Notifications\SegnalazioneChiusaNotification;
use App\Services\SegnalazioneWorkflowService;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SegnalazioneWorkflowNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            TabelleRiferimentoSeeder::class,
            ImpostazioniSeeder::class,
            RolesAndPermissionsSeeder::class,
        ]);
    }

    public function test_notifica_operatore_quando_la_segnalazione_viene_assegnata(): void
    {
        Notification::fake();

        $attore = User::factory()->create();
        $attore->syncRoles(['admin']);

        $operatore = User::factory()->create();
        $operatore->syncRoles(['gestore']);

        $segnalatore = User::factory()->create();
        $segnalatore->syncRoles(['segnalatore']);

        $segnalazione = $this->makeSegnalazione($segnalatore->id);

        app(SegnalazioneWorkflowService::class)->eseguiAzione($segnalazione, 2, $attore, [
            'id_operatore' => $operatore->id,
        ]);

        Notification::assertSentTo($operatore, OperatoreAssegnatoNotification::class);
        Notification::assertNotSentTo($segnalatore, OperatoreAssegnatoNotification::class);
    }

    public function test_notifica_impresa_quando_viene_assegnato_un_appalto(): void
    {
        Notification::fake();

        $attore = User::factory()->create();
        $attore->syncRoles(['admin']);

        $segnalatore = User::factory()->create();
        $segnalatore->syncRoles(['segnalatore']);

        $impresa = Impresa::create([
            'ragione_sociale' => 'Impresa Test',
            'email' => 'impresa@example.test',
        ]);

        $utenteImpresa = User::factory()->create([
            'id_impresa' => $impresa->id_impresa,
        ]);
        $utenteImpresa->syncRoles(['impresa']);

        $appalto = Appalto::create([
            'id_gruppo' => 1,
            'descrizione' => 'Appalto test',
            'id_impresa' => $impresa->id_impresa,
            'valido' => true,
        ]);

        $segnalazione = $this->makeSegnalazione($segnalatore->id);

        app(SegnalazioneWorkflowService::class)->eseguiAzione($segnalazione, 1, $attore, [
            'id_appalto' => $appalto->id_appalto,
        ]);

        Notification::assertSentTo($utenteImpresa, ImpresaAssegnataNotification::class);
    }

    public function test_notifica_segnalatore_quando_la_segnalazione_viene_chiusa(): void
    {
        Notification::fake();

        $attore = User::factory()->create();
        $attore->syncRoles(['admin']);

        $segnalatore = User::factory()->create();
        $segnalatore->syncRoles(['segnalatore']);

        $segnalazione = $this->makeSegnalazione($segnalatore->id);

        app(SegnalazioneWorkflowService::class)->eseguiAzione($segnalazione, 3, $attore);

        Notification::assertSentTo($segnalatore, SegnalazioneChiusaNotification::class);
    }

    private function makeSegnalazione(int $segnalatoreId): Segnalazione
    {
        return Segnalazione::create([
            'id_tipologia_segnalazione' => 1,
            'id_utente_segnalazione' => $segnalatoreId,
            'testo_segnalazione' => 'Segnalazione di test',
            'flag_riservata' => false,
            'flag_pubblicata' => true,
            'id_stato_segnalazione' => 2,
            'id_provenienza' => 1,
        ]);
    }
}