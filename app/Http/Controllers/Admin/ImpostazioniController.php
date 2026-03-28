<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Impostazione;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ImpostazioniController extends Controller
{
    public function index(): View
    {
        $impostazioni = Impostazione::orderBy('gruppo')->orderBy('chiave')->get()
            ->groupBy('gruppo');

        return view('admin.impostazioni', compact('impostazioni'));
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'impostazioni'          => ['required', 'array'],
            'impostazioni.*'        => ['nullable', 'string', 'max:500'],
        ]);

        foreach ($data['impostazioni'] as $chiave => $valore) {
            Impostazione::set($chiave, $valore);
        }

        return back()->with('success', 'Impostazioni salvate.');
    }
}
