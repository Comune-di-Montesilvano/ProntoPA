<?php

namespace Tests\Feature;

use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TabelleRiferimentoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SegnalazionePerContoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->seed(TabelleRiferimentoSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_utente_con_permission_inserisce_per_conto_di(): void
    {
        $urp = User::factory()->create();
        $urp->assignRole('segnalatore');
        $urp->givePermissionTo('segnalazioni.per-conto');

        $this->actingAs($urp)->post(route('segnalazioni.store'), [
            'id_tipologia_segnalazione' => 1,
            'testo_segnalazione'        => 'Buca pericolosa in via Verdi, segnalata al telefono',
            'id_provenienza'            => 1,
            'segnalante_per_conto'      => 'Mario Rossi',
            'telefono_per_conto'        => '085 123456',
            'email_per_conto'           => 'mario.rossi@example.com',
        ]);

        $seg = Segnalazione::latest('id_segnalazione')->first();
        $this->assertSame('Mario Rossi', $seg->segnalante);
        $this->assertSame('085 123456', $seg->telefono);
        $this->assertSame('mario.rossi@example.com', $seg->email);
        // Tracciabilità: chi ha inserito resta l'operatore URP
        $this->assertSame($urp->id, $seg->id_utente_segnalazione);
    }

    public function test_senza_permission_i_campi_per_conto_sono_ignorati(): void
    {
        $segnalatore = User::factory()->create();
        $segnalatore->assignRole('segnalatore');

        $this->actingAs($segnalatore)->post(route('segnalazioni.store'), [
            'id_tipologia_segnalazione' => 1,
            'testo_segnalazione'        => 'Tentativo di spoofing segnalante',
            'id_provenienza'            => 1,
            'segnalante_per_conto'      => 'Falso Nome',
            'telefono_per_conto'        => '000',
        ]);

        $seg = Segnalazione::latest('id_segnalazione')->first();
        $this->assertSame($segnalatore->name, $seg->segnalante);
        $this->assertSame($segnalatore->email, $seg->email);
        $this->assertNotSame('000', $seg->telefono);
    }

    public function test_salva_e_nuova_redirige_al_form(): void
    {
        $urp = User::factory()->create();
        $urp->assignRole('segnalatore');
        $urp->givePermissionTo('segnalazioni.per-conto');

        $response = $this->actingAs($urp)->post(route('segnalazioni.store'), [
            'id_tipologia_segnalazione' => 1,
            'testo_segnalazione'        => 'Telefonata in serie',
            'id_provenienza'            => 1,
            'salva_e_nuova'             => '1',
        ]);

        $response->assertRedirect(route('segnalazioni.create'));
    }
}
