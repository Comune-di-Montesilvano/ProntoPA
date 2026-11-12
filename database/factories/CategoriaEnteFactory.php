<?php

namespace Database\Factories;

use App\Models\CategoriaEnte;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategoriaEnte>
 */
class CategoriaEnteFactory extends Factory
{
    protected $model = CategoriaEnte::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'nome' => fake()->unique()->words(2, true),
            'ordine' => 0,
            'import_miur' => false,
        ];
    }
}
