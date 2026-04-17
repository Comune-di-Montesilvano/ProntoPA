<?php

namespace Database\Factories;

use App\Models\Segnalazione;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Segnalazione>
 */
class SegnalazioneFactory extends Factory
{
    protected $model = Segnalazione::class;

    public function definition(): array
    {
        return [
            'id_tipologia_segnalazione' => 1,
            'id_plesso' => 0,
            'id_utente_segnalazione' => 0,
            'id_cittadino_segnalazione' => 0,
            'id_stradario' => 0,
            'id_area' => 0,
            'id_immobile' => 0,
            'latitudine' => 0,
            'longitudine' => 0,
            'zoom' => 18,
            'testo_segnalazione' => $this->faker->sentence(10),
            'flag_riservata' => true,
            'flag_pubblicata' => false,
            'flag_evidenza' => false,
            'id_stato_segnalazione' => 1,
            'id_provenienza' => 1,
            'id_appalto' => null,
            'id_operatore_assegnato' => 0,
            'segnalante' => $this->faker->name(),
            'email' => $this->faker->safeEmail(),
            'telefono' => $this->faker->phoneNumber(),
            'importo_preventivo' => 0,
            'importo_liquidato' => 0,
            'external_id' => null,
        ];
    }
}
