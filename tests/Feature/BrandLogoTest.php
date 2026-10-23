<?php

namespace Tests\Feature;

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

class BrandLogoTest extends TestCase
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

    private function carica(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->patch(route('admin.impostazioni.update'), [
            'impostazioni' => ['ente_nome' => 'Comune di Prova'],
        ] + $extra);
    }

    public function test_admin_carica_logo_png_e_viene_servito(): void
    {
        $this->carica(['ente_logo_upload' => UploadedFile::fake()->image('logo.png', 200, 80)])
            ->assertSessionHasNoErrors();

        $this->assertSame('brand/logo-ente.png', Impostazione::get('ente_logo_file'));
        Storage::disk('local')->assertExists('brand/logo-ente.png');

        $this->get('/brand/logo-ente')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_logo_caricato_ha_precedenza_su_url(): void
    {
        Impostazione::set('ente_logo_url', 'https://x.test/a.png');
        $this->carica(['ente_logo_upload' => UploadedFile::fake()->image('logo.png')]);

        $this->assertStringStartsWith(route('brand.logo-ente'), (string) Brand::logoEnteUrl());
    }

    public function test_senza_file_usa_url_e_senza_nulla_null(): void
    {
        $this->assertNull(Brand::logoEnteUrl());

        Impostazione::set('ente_logo_url', 'https://x.test/a.png');
        $this->assertSame('https://x.test/a.png', Brand::logoEnteUrl());
    }

    public function test_upload_rifiuta_svg(): void
    {
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->carica(['ente_logo_upload' => $svg])->assertSessionHasErrors('ente_logo_upload');
        $this->assertEmpty(Impostazione::get('ente_logo_file'));
    }

    public function test_upload_rifiuta_file_oltre_1mb(): void
    {
        $this->carica(['ente_logo_upload' => UploadedFile::fake()->image('x.png')->size(1025)])
            ->assertSessionHasErrors('ente_logo_upload');
    }

    public function test_ente_logo_file_non_scrivibile_dal_form_generico(): void
    {
        $this->carica(['impostazioni' => ['ente_logo_file' => '../../.env']]);

        $this->assertEmpty(Impostazione::get('ente_logo_file'));
    }

    public function test_rimuovi_logo_cancella_file_e_impostazione(): void
    {
        $this->carica(['ente_logo_upload' => UploadedFile::fake()->image('logo.png')]);
        $this->carica(['rimuovi_logo_file' => '1'])->assertSessionHasNoErrors();

        $this->assertEmpty(Impostazione::get('ente_logo_file'));
        Storage::disk('local')->assertMissing('brand/logo-ente.png');
        $this->get('/brand/logo-ente')->assertNotFound();
    }

    public function test_nuovo_upload_sostituisce_il_precedente(): void
    {
        $this->carica(['ente_logo_upload' => UploadedFile::fake()->image('logo.png')]);
        $this->carica(['ente_logo_upload' => UploadedFile::fake()->image('logo.jpg')]);

        $this->assertSame('brand/logo-ente.jpg', Impostazione::get('ente_logo_file'));
        Storage::disk('local')->assertMissing('brand/logo-ente.png');
    }

    public function test_pagine_mostrano_favicon_e_co_branding(): void
    {
        $this->carica(['ente_logo_upload' => UploadedFile::fake()->image('logo.png')]);
        auth()->logout();

        foreach (['/login', '/'] as $pagina) {
            $this->get($pagina)->assertOk()
                ->assertSee('favicon.svg', false)
                ->assertSee('brand/logo-ente', false)
                ->assertSee('aria-label="ProntoPA"', false);
        }
    }
}
