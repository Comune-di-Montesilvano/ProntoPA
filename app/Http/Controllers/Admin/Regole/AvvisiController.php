<?php

namespace App\Http\Controllers\Admin\Regole;

use App\Http\Controllers\Controller;
use App\Services\Impostazioni\SalvaImpostazioni;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AvvisiController extends Controller
{
    public function index(): View
    {
        return view('admin.regole.avvisi');
    }

    public function update(Request $request, SalvaImpostazioni $salva): RedirectResponse
    {
        $salva->salva('AVVISI', $request->input('impostazioni', []));

        return back()->with('success', 'Avvisi salvati.');
    }
}
