<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CategoriaEnte;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CategorieEntiController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nome' => ['required', 'string', 'max:100', Rule::unique('categorie_enti', 'nome')],
            'ordine' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'import_miur' => ['nullable', 'boolean'],
        ]);

        $data['ordine'] = $data['ordine'] ?? ((int) CategoriaEnte::max('ordine') + 1);
        $data['import_miur'] = (bool) ($data['import_miur'] ?? false);

        $categoria = DB::transaction(function () use ($data) {
            if ($data['import_miur']) {
                CategoriaEnte::where('import_miur', true)->update(['import_miur' => false]);
            }

            return CategoriaEnte::create($data);
        });

        return redirect()->route('admin.enti.index')->with('success', "Categoria \"{$categoria->nome}\" creata.");
    }

    public function update(Request $request, CategoriaEnte $categoria): RedirectResponse
    {
        $data = $request->validate([
            'nome' => ['required', 'string', 'max:100', Rule::unique('categorie_enti', 'nome')->ignore($categoria->id_categoria, 'id_categoria')],
            'ordine' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'import_miur' => ['nullable', 'boolean'],
        ]);

        $data['ordine'] = $data['ordine'] ?? $categoria->ordine;
        $data['import_miur'] = (bool) ($data['import_miur'] ?? false);

        // Una sola categoria riceve gli enti importati dal MIUR.
        DB::transaction(function () use ($categoria, $data) {
            if ($data['import_miur']) {
                CategoriaEnte::where('import_miur', true)
                    ->whereKeyNot($categoria->id_categoria)
                    ->update(['import_miur' => false]);
            }
            $categoria->update($data);
        });

        return redirect()->route('admin.enti.index')->with('success', "Categoria \"{$categoria->nome}\" aggiornata.");
    }

    public function destroy(CategoriaEnte $categoria): RedirectResponse
    {
        $n = $categoria->istituti()->count();
        if ($n > 0) {
            return redirect()->route('admin.enti.index')->with('error', "La categoria contiene {$n} enti: spostali in un'altra categoria prima di eliminarla.");
        }

        $nome = $categoria->nome;
        $categoria->delete();

        return redirect()->route('admin.enti.index')->with('success', "Categoria \"{$nome}\" eliminata.");
    }
}
