<?php

namespace Tests\Feature\Admin;

use App\Models\Impostazione;
use App\Models\User;
use App\Support\Brand;
use Database\Seeders\ImpostazioniSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AspettoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ImpostazioniSeeder::class);
        Storage::fake('local');
        $this->admin = User::factory()->create(['amministratore' => true]);
        $this->admin->assignRole('admin');
    }

    private function salva(array $impostazioni, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->patch(route('admin.aspetto.update'), ['impostazioni' => $impostazioni] + $extra);
    }

    public function test_salva_nome_e_colori(): void
    {
        $this->salva([
            'ente_nome' => 'Comune di Prova',
            'ente_colore_primario' => '#112233',
            'ente_colore_secondario' => '#445566',
            'osm_lat' => '42.1',
            'osm_lng' => '14.2',
            'osm_zoom' => '12',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Comune di Prova', Impostazione::get('ente_nome'));
        $this->assertSame('#112233', Impostazione::get('ente_colore_primario'));
        $this->assertSame('#445566', Impostazione::get('ente_colore_secondario'));
        $this->assertEquals('12', Impostazione::get('osm_zoom'));
    }

    public function test_logo_file_ha_precedenza(): void
    {
        Impostazione::set('ente_logo_url', 'https://x.test/a.png');
        $this->salva(['ente_nome' => 'X'], ['ente_logo_upload' => UploadedFile::fake()->image('logo.png')])
            ->assertSessionHasNoErrors();

        $this->assertSame('brand/logo-ente.png', Impostazione::get('ente_logo_file'));
        Storage::disk('local')->assertExists('brand/logo-ente.png');
        $this->assertStringStartsWith(route('brand.logo-ente'), (string) Brand::logoEnteUrl());
    }

    public function test_upload_rifiuta_svg_e_rimozione_cancella(): void
    {
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>1</script></svg>');
        $this->salva(['ente_nome' => 'X'], ['ente_logo_upload' => $svg])->assertSessionHasErrors('ente_logo_upload');

        $this->salva(['ente_nome' => 'X'], ['ente_logo_upload' => UploadedFile::fake()->image('logo.png')]);
        Storage::disk('local')->assertExists('brand/logo-ente.png');
        $this->salva(['ente_nome' => 'X'], ['rimuovi_logo_file' => '1'])->assertSessionHasNoErrors();

        $this->assertEmpty(Impostazione::get('ente_logo_file'));
        Storage::disk('local')->assertMissing('brand/logo-ente.png');
    }

    public function test_nessuna_chiave_tecnica_visibile(): void
    {
        $this->actingAs($this->admin)->get(route('admin.aspetto'))
            ->assertOk()
            ->assertDontSeeText('osm_zoom')
            ->assertDontSeeText('mail_from_address')
            ->assertSee('leaflet.js', false);
    }

    public function test_chiavi_interne_non_scrivibili(): void
    {
        $this->salva(['ente_logo_file' => '../../.env', 'ente_nome' => 'X']);
        $this->assertEmpty(Impostazione::get('ente_logo_file'));
    }

    public function test_colore_vuoto_non_diventa_nero(): void
    {
        Impostazione::set('ente_colore_primario', null);

        $html = $this->actingAs($this->admin)->get(route('admin.aspetto'))->assertOk()->getContent();

        $this->assertStringNotContainsString('#000000', $html);
    }
}
