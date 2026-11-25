<?php

namespace Tests\Feature;

use App\Models\Istituto;
use App\Models\Plesso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** 1.0.19: referente della sede e dirigente dell'istituto non esistono più (il referente delle ditte resta). */
class ReferentiRimossiTest extends TestCase
{
    use RefreshDatabase;

    public function test_colonne_eliminate_da_sedi_e_istituti(): void
    {
        $this->assertFalse(Schema::hasColumn('plessi', 'referente'));
        $this->assertTrue(Schema::hasColumn('imprese', 'referente'));
        $this->assertFalse(Schema::hasColumn('istituti', 'dirigente'));
        $this->assertNotContains('referente', (new Plesso)->getFillable());
        $this->assertNotContains('dirigente', (new Istituto)->getFillable());
    }
}
