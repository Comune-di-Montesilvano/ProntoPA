<?php

namespace Tests\Feature;

use App\Models\Impostazione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\DigestGestoreNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DigestGestoriTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        \Illuminate\Support\Facades\Cache::flush();
        Impostazione::set('digest_enabled', true);
        Impostazione::set('digest_skip_weekend', false);
        Notification::fake();
    }

    private function gestore(bool $supervisore = false): User
    {
        $user = User::factory()->create([
            'gestore_segnalazioni'     => true,
            'supervisore_segnalazioni' => $supervisore,
            'attivo'                   => true,
        ]);
        $user->assignRole('gestore');

        return $user;
    }

    public function test_gestore_riceve_digest_con_ritardi(): void
    {
        $gestore = $this->gestore();

        Segnalazione::factory()->create([
            'id_operatore_assegnato' => $gestore->id,
            'sla_violato'            => true,
            'data_scadenza_sla'      => now()->subDay(),
        ]);

        $this->artisan('digest:invia')->assertExitCode(0);

        Notification::assertSentTo($gestore, DigestGestoreNotification::class);
    }

    public function test_digest_vuoto_non_inviato(): void
    {
        $gestore = $this->gestore();

        $this->artisan('digest:invia')->assertExitCode(0);

        Notification::assertNothingSentTo($gestore);
    }

    public function test_supervisore_riceve_anche_non_assegnate(): void
    {
        $supervisore = $this->gestore(supervisore: true);

        Segnalazione::factory()->create([
            'id_operatore_assegnato' => 0,
            'id_stato_segnalazione'  => 1,
        ]);

        $this->artisan('digest:invia')->assertExitCode(0);

        Notification::assertSentTo(
            $supervisore,
            DigestGestoreNotification::class,
            fn (DigestGestoreNotification $n) => count($n->nonAssegnateIds) === 1
        );
    }

    public function test_flag_spento_nessun_invio(): void
    {
        Impostazione::set('digest_enabled', false);
        $gestore = $this->gestore();

        Segnalazione::factory()->create([
            'id_operatore_assegnato' => $gestore->id,
            'sla_violato'            => true,
        ]);

        $this->artisan('digest:invia')->assertExitCode(0);

        Notification::assertNothingSentTo($gestore);
    }

    public function test_weekend_saltato_quando_configurato(): void
    {
        Impostazione::set('digest_skip_weekend', true);
        $this->travelTo(now()->next(\Carbon\CarbonInterface::SATURDAY)->setTime(8, 0));

        $gestore = $this->gestore();
        Segnalazione::factory()->create([
            'id_operatore_assegnato' => $gestore->id,
            'sla_violato'            => true,
        ]);

        $this->artisan('digest:invia')->assertExitCode(0);

        Notification::assertNothingSentTo($gestore);
        $this->travelBack();
    }
}
