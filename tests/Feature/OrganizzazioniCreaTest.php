<?php

namespace Tests\Feature;

use App\Models\Istituto;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class OrganizzazioniCreaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['amministratore' => true]);
        $user->assignRole('admin');

        return $user;
    }

    /** Asili nido, uffici comunali ecc. non hanno codice meccanografico MIUR. */
    public function test_crea_organizzazione_senza_codice_meccanografico(): void
    {
        $this->actingAs($this->admin())->post(route('admin.enti.store'), [
            'descrizione'           => 'Asili Nido Comunali',
            'id_categoria'          => \App\Models\CategoriaEnte::perImportMiur()->id_categoria,
            'codice_meccanografico' => '',
            'partita_iva'           => '',
            'codice_fiscale'        => '',
            'dirigente'             => '',
            'email'                 => '',
            'recapiti'              => '',
            'domini_email_istituzionali' => '',
        ])->assertRedirect();

        $istituto = Istituto::where('descrizione', 'Asili Nido Comunali')->firstOrFail();
        $this->assertNull($istituto->codice_meccanografico);
    }
}
