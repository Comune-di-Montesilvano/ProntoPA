<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicHomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_home_can_be_rendered(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Monitoraggio pubblico aggregato');
        $response->assertSee('Accedi al sistema');
    }
}