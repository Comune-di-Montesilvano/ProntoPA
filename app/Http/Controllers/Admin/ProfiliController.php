<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Istituto;
use App\Models\Profilo;
use App\Models\TipologiaSegnalazione;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfiliController extends Controller
{
    public function index(): View
    {
        $profili = Profilo::with('istituto')->orderBy('descrizione')->paginate(30);

        return view('admin.profili.index', compact('profili'));
    }

    public function create(): View
    {
        $organizzazioni = Istituto::orderBy('tipo')->orderBy('descrizione')->get();
        $tipologie      = TipologiaSegnalazione::orderBy('descrizione')->get();

        return view('admin.profili.create', compact('organizzazioni', 'tipologie'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'descrizione'               => ['required', 'string', 'max:50'],
            'limita_istituto'           => ['boolean'],
            'id_istituto'               => ['nullable', 'integer', 'exists:istituti,id_istituto'],
            'limita_segnalazioni'       => ['nullable', 'integer'],
            'id_tipologia_segnalazione' => ['nullable', 'integer', 'exists:tipologie_segnalazioni,id_tipologia_segnalazione'],
        ]);

        Profilo::create([
            'descrizione'               => $data['descrizione'],
            'limita_istituto'           => $data['limita_istituto'] ?? false,
            'id_istituto'               => ($data['limita_istituto'] ?? false) ? ($data['id_istituto'] ?? null) : null,
            'limita_segnalazioni'       => $data['limita_segnalazioni'] ?? null,
            'id_tipologia_segnalazione' => $data['id_tipologia_segnalazione'] ?? null,
        ]);

        return redirect()->route('admin.profili.index')
            ->with('success', "Profilo \"{$data['descrizione']}\" creato.");
    }

    public function edit(Profilo $profilo): View
    {
        $organizzazioni = Istituto::orderBy('tipo')->orderBy('descrizione')->get();
        $tipologie      = TipologiaSegnalazione::orderBy('descrizione')->get();

        return view('admin.profili.edit', compact('profilo', 'organizzazioni', 'tipologie'));
    }

    public function update(Request $request, Profilo $profilo): RedirectResponse
    {
        $data = $request->validate([
            'descrizione'               => ['required', 'string', 'max:50'],
            'limita_istituto'           => ['boolean'],
            'id_istituto'               => ['nullable', 'integer', 'exists:istituti,id_istituto'],
            'limita_segnalazioni'       => ['nullable', 'integer'],
            'id_tipologia_segnalazione' => ['nullable', 'integer', 'exists:tipologie_segnalazioni,id_tipologia_segnalazione'],
        ]);

        $profilo->update([
            'descrizione'               => $data['descrizione'],
            'limita_istituto'           => $data['limita_istituto'] ?? false,
            'id_istituto'               => ($data['limita_istituto'] ?? false) ? ($data['id_istituto'] ?? null) : null,
            'limita_segnalazioni'       => $data['limita_segnalazioni'] ?? null,
            'id_tipologia_segnalazione' => $data['id_tipologia_segnalazione'] ?? null,
        ]);

        return redirect()->route('admin.profili.index')
            ->with('success', "Profilo \"{$profilo->descrizione}\" aggiornato.");
    }

    public function destroy(Profilo $profilo): RedirectResponse
    {
        $nome = $profilo->descrizione;
        $profilo->delete();

        return redirect()->route('admin.profili.index')
            ->with('success', "Profilo \"{$nome}\" eliminato.");
    }
}
