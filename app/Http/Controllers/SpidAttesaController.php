<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

/** Rotta storica (link nelle email 0.7.x): la pagina d'attesa è diventata "Le mie deleghe". */
class SpidAttesaController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        return redirect()->route('scuola.deleghe.index');
    }
}
