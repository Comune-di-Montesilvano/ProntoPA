<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Istituto;
use App\Models\Plesso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\View\View;

class SediController extends Controller
{
    /** Campi che per una sede fonte_dati = miur vengono dall'anagrafe MIUR. */
    public const CAMPI_MIUR = ['id_istituto', 'nome', 'codice_meccanografico', 'indirizzo', 'email'];

    public function index(Request $request): View
    {
        $query = Plesso::with('istituto')->orderBy('id_istituto')->orderBy('nome');

        if ($request->filled('id_istituto')) {
            $query->where('id_istituto', $request->id_istituto);
        }

        $sedi           = $query->paginate(30);
        $organizzazioni = Istituto::orderBy('tipo')->orderBy('descrizione')->get();
        $filtroIstituto = $request->id_istituto;

        return view('admin.sedi.index', compact('sedi', 'organizzazioni', 'filtroIstituto'));
    }

    public function create(Request $request): View
    {
        $organizzazioni = Istituto::orderBy('tipo')->orderBy('descrizione')->get();
        $preseleziona   = $request->id_istituto;

        return view('admin.sedi.create', compact('organizzazioni', 'preseleziona'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id_istituto'           => ['required', 'integer', 'exists:istituti,id_istituto'],
            'nome'                  => ['required', 'string', 'max:255'],
            'codice_meccanografico' => ['nullable', 'string', 'max:50'],
            'indirizzo'             => ['nullable', 'string', 'max:255'],
            'referente'             => ['nullable', 'string', 'max:50'],
            'email'                 => ['nullable', 'email', 'max:50'],
            'recapiti'              => ['nullable', 'string', 'max:50'],
        ]);

        Plesso::create($data);

        return redirect()->route('admin.sedi.index', ['id_istituto' => $data['id_istituto']])
            ->with('success', "Sede \"{$data['nome']}\" creata.");
    }

    public function edit(Plesso $sede): View
    {
        $organizzazioni = Istituto::orderBy('tipo')->orderBy('descrizione')->get();

        return view('admin.sedi.edit', compact('sede', 'organizzazioni'));
    }

    public function update(Request $request, Plesso $sede): RedirectResponse
    {
        $regole = [
            'id_istituto'           => ['required', 'integer', 'exists:istituti,id_istituto'],
            'nome'                  => ['required', 'string', 'max:255'],
            'codice_meccanografico' => ['nullable', 'string', 'max:50'],
            'indirizzo'             => ['nullable', 'string', 'max:255'],
            'referente'             => ['nullable', 'string', 'max:50'],
            'email'                 => ['nullable', 'email', 'max:50'],
            'recapiti'              => ['nullable', 'string', 'max:50'],
        ];

        // validate() restituisce solo le chiavi validate: i campi MIUR inviati vengono scartati.
        if ($sede->isMiur()) {
            $regole = Arr::except($regole, self::CAMPI_MIUR);
        }

        $data = $request->validate($regole);

        $sede->update($data);

        return redirect()->route('admin.sedi.index', ['id_istituto' => $sede->id_istituto])
            ->with('success', "Sede \"{$sede->nome}\" aggiornata.");
    }

    public function destroy(Plesso $sede): RedirectResponse
    {
        $nome = $sede->nome;
        $idIstituto = $sede->id_istituto;
        $sede->delete();

        return redirect()->route('admin.sedi.index', ['id_istituto' => $idIstituto])
            ->with('success', "Sede \"{$nome}\" eliminata.");
    }
}
