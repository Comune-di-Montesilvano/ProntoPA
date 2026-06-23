<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Squadra;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SquadreController extends Controller
{
    public function index(): View
    {
        $squadre = Squadra::with(['caposquadra', 'membri'])->orderBy('nome')->get();

        return view('admin.squadre.index', compact('squadre'));
    }

    public function create(): View
    {
        return view('admin.squadre.form', [
            'squadra' => new Squadra(),
            'operai'  => User::role('operaio')->where('attivo', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateSquadra($request);

        $squadra = Squadra::create([
            'nome'           => $data['nome'],
            'id_caposquadra' => $data['id_caposquadra'],
            'attiva'         => true,
        ]);
        $squadra->membri()->sync($data['membri'] ?? []);

        return redirect()->route('admin.squadre.index')
            ->with('success', 'Squadra creata.');
    }

    public function edit(Squadra $squadra): View
    {
        return view('admin.squadre.form', [
            'squadra' => $squadra->load('membri'),
            'operai'  => User::role('operaio')->where('attivo', true)->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Squadra $squadra): RedirectResponse
    {
        $data = $this->validateSquadra($request);

        $squadra->update([
            'nome'           => $data['nome'],
            'id_caposquadra' => $data['id_caposquadra'],
            'attiva'         => $request->boolean('attiva', true),
        ]);
        $squadra->membri()->sync($data['membri'] ?? []);

        return redirect()->route('admin.squadre.index')
            ->with('success', 'Squadra aggiornata.');
    }

    public function destroy(Squadra $squadra): RedirectResponse
    {
        $squadra->update(['attiva' => false]);

        return redirect()->route('admin.squadre.index')
            ->with('success', 'Squadra disattivata.');
    }

    private function validateSquadra(Request $request): array
    {
        return $request->validate([
            'nome'           => ['required', 'string', 'max:100'],
            'id_caposquadra' => ['required', 'integer', 'exists:users,id'],
            'membri'         => ['nullable', 'array'],
            'membri.*'       => ['integer', 'exists:users,id'],
        ]);
    }
}
