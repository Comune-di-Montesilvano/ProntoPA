<?php

namespace Tests\Feature\Admin;

use App\Models\Impostazione;
use App\Support\Impostazioni\Sezioni;
use Database\Seeders\ImpostazioniSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoperturaChiaviTest extends TestCase
{
    use RefreshDatabase;

    public function test_ogni_chiave_del_seeder_ha_una_pagina(): void
    {
        $this->seed(ImpostazioniSeeder::class);

        $seeder = Impostazione::whereNotIn('chiave', Impostazione::CHIAVI_INTERNE)->pluck('chiave')->all();
        $sezioni = collect(Sezioni::tutte())->flatMap(fn ($m) => array_keys($m))->all();

        $this->assertEqualsCanonicalizing($seeder, $sezioni);
        $this->assertSame(count($sezioni), count(array_unique($sezioni)), 'Chiave in più sezioni');
    }
}
