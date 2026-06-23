<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Impresa>
 */
class ImpresaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ragione_sociale' => fake()->company(),
            'partita_iva'     => fake()->numerify('###########'),
            'referente'       => fake()->name(),
            'email'           => fake()->companyEmail(),
            'cellulare'       => fake()->phoneNumber(),
            'note'            => null,
        ];
    }
}
