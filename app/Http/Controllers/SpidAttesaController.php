<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class SpidAttesaController extends Controller
{
    public function __invoke(): View
    {
        return view('spid.attesa');
    }
}
