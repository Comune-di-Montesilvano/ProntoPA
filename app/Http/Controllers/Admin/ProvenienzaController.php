<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Provenienza;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProvenienzaController extends Controller
{
    public function index(): View
    {
        $provenienze = Provenienza::orderBy('descrizione')->paginate(30);

        return view('admin.provenienze.index', compact('provenienze'));
    }

    public function create(): View
    {
        return view('admin.provenienze.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'descrizione' => ['required', 'string', 'max:50'],
        ]);

        Provenienza::create($data);

        return redirect()->route('admin.provenienze.index')
            ->with('success', "Provenienza \"{$data['descrizione']}\" creata.");
    }

    public function edit(Provenienza $provenienza): View
    {
        return view('admin.provenienze.edit', compact('provenienza'));
    }

    public function update(Request $request, Provenienza $provenienza): RedirectResponse
    {
        $data = $request->validate([
            'descrizione' => ['required', 'string', 'max:50'],
        ]);

        $provenienza->update($data);

        return redirect()->route('admin.provenienze.index')
            ->with('success', "Provenienza \"{$provenienza->descrizione}\" aggiornata.");
    }

    public function destroy(Provenienza $provenienza): RedirectResponse
    {
        $nome = $provenienza->descrizione;
        $provenienza->delete();

        return redirect()->route('admin.provenienze.index')
            ->with('success', "Provenienza \"{$nome}\" eliminata.");
    }
}
