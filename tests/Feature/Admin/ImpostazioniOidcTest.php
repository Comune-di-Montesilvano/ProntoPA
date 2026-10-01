<?php

namespace Tests\Feature\Admin;

use App\Models\Impostazione;
use App\Models\User;
use App\Services\Oidc\OidcConfig;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class ImpostazioniOidcTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ImpostazioniSeeder::class);
        $this->admin = User::factory()->create(['amministratore' => true]);
        $this->admin->assignRole('admin');
        config(['app.url' => 'https://prontopa.ente.it/']);
    }

    private function salva(array $valori): void
    {
        $this->actingAs($this->admin)->patch(route('admin.impostazioni.update'), ['impostazioni' => $valori])
            ->assertSessionHasNoErrors();
    }

    public function test_secret_salvato_cifrato_e_mai_mostrato_in_chiaro(): void
    {
        $this->salva(['oidc_issuer' => 'https://login.ente.it/', 'oidc_client_id' => 'prontopa', 'oidc_client_secret' => 'segreto-123']);

        $grezzo = Impostazione::find('oidc_client_secret')->valore;
        $this->assertNotSame('segreto-123', $grezzo);
        $this->assertSame('segreto-123', Crypt::decryptString($grezzo));

        $config = app(OidcConfig::class);
        $this->assertSame('https://login.ente.it', $config->issuer());
        $this->assertSame('segreto-123', $config->clientSecret());
        $this->assertTrue($config->configurato());

        $this->actingAs($this->admin)->get(route('admin.impostazioni.index'))
            ->assertOk()
            ->assertDontSee('segreto-123')
            ->assertSee('https://prontopa.ente.it/auth/spid/callback');
    }

    public function test_secret_vuoto_lascia_quello_esistente(): void
    {
        $this->salva(['oidc_client_secret' => 'primo']);
        $this->salva(['oidc_client_secret' => '', 'oidc_client_id' => 'altro']);

        $this->assertSame('primo', app(OidcConfig::class)->clientSecret());
    }

    public function test_non_configurato_se_manca_un_valore(): void
    {
        $this->salva(['oidc_issuer' => 'https://login.ente.it', 'oidc_client_id' => 'prontopa']);

        $this->assertFalse(app(OidcConfig::class)->configurato());
    }

    public function test_secret_non_decifrabile_vale_vuoto(): void
    {
        Impostazione::set('oidc_client_secret', 'valore-non-cifrato');

        $this->assertSame('', app(OidcConfig::class)->clientSecret());
    }
}
