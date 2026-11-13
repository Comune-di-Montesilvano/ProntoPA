<?php

namespace Tests\Support;

use App\Models\CategoriaEnte;
use App\Models\Istituto;
use App\Models\Plesso;

/** 1.0.0: ogni segnalazione ha una sede; per i test che non ne curano una. */
final class SedeDiProva
{
    public static function id(): int
    {
        $esistente = Plesso::where('nome', 'Sede di prova')->value('id_plesso');
        if ($esistente) {
            return (int) $esistente;
        }

        $ente = Istituto::create(['descrizione' => 'Ente di prova', 'id_categoria' => CategoriaEnte::factory()->create()->id_categoria, 'attivo' => true]);

        return (int) Plesso::create(['id_istituto' => $ente->id_istituto, 'nome' => 'Sede di prova'])->id_plesso;
    }
}
