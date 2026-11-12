<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CategoriaEnte;
use App\Models\Istituto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\View\View;

class OrganizzazioniController extends Controller
{
    /** Campi che per un istituto fonte_dati = miur vengono dall'anagrafe MIUR. */
    public const CAMPI_MIUR = ['descrizione', 'codice_meccanografico', 'email'];

    public function index(): View
    {
        $organizzazioni = Istituto::withCount('plessi')
            ->orderBy('id_categoria')
            ->orderBy('descrizione')
            ->paginate(30);

        return view('admin.organizzazioni.index', compact('organizzazioni'));
    }

    public function create(): View
    {
        $categorie = CategoriaEnte::orderBy('ordine')->orderBy('nome')->get();

        return view('admin.organizzazioni.create', compact('categorie'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'descrizione'           => ['required', 'string', 'max:255'],
            'id_categoria'          => ['required', 'integer', 'exists:categorie_enti,id_categoria'],
            'codice_meccanografico' => ['nullable', 'string', 'max:50'],
            'partita_iva'           => ['nullable', 'digits:11'],
            'codice_fiscale'        => ['nullable', 'string', 'max:16'],
            'dirigente'             => ['nullable', 'string', 'max:50'],
            'email'                 => ['nullable', 'email', 'max:50'],
            'domini_email_istituzionali' => ['nullable', 'string', 'max:255'],
            'recapiti'              => ['nullable', 'string', 'max:50'],
            'attivo'                => ['nullable', 'boolean'],
            'richieste_delega_spid' => ['nullable', 'boolean'],
        ]);

        $data['attivo'] = (bool) ($data['attivo'] ?? false);
        $data['richieste_delega_spid'] = (bool) ($data['richieste_delega_spid'] ?? false);
        $data['fonte_dati'] = 'manuale';

        Istituto::create($data);

        return redirect()->route('admin.organizzazioni.index')
            ->with('success', "Organizzazione \"{$data['descrizione']}\" creata.");
    }

    public function edit(Istituto $organizzazione): View
    {
        $categorie = CategoriaEnte::orderBy('ordine')->orderBy('nome')->get();

        return view('admin.organizzazioni.edit', compact('organizzazione', 'categorie'));
    }

    public function update(Request $request, Istituto $organizzazione): RedirectResponse
    {
        $regole = [
            'descrizione'           => ['required', 'string', 'max:255'],
            'id_categoria'          => ['required', 'integer', 'exists:categorie_enti,id_categoria'],
            'codice_meccanografico' => ['nullable', 'string', 'max:50'],
            'partita_iva'           => ['nullable', 'digits:11'],
            'codice_fiscale'        => ['nullable', 'string', 'max:16'],
            'dirigente'             => ['nullable', 'string', 'max:50'],
            'email'                 => ['nullable', 'email', 'max:50'],
            'domini_email_istituzionali' => ['nullable', 'string', 'max:255'],
            'recapiti'              => ['nullable', 'string', 'max:50'],
            'attivo'                => ['nullable', 'boolean'],
            'richieste_delega_spid' => ['nullable', 'boolean'],
        ];

        // validate() restituisce solo le chiavi validate: i campi MIUR inviati vengono scartati.
        if ($organizzazione->isMiur()) {
            $regole = Arr::except($regole, self::CAMPI_MIUR);
        }

        $data = $request->validate($regole);

        $data['attivo'] = (bool) ($data['attivo'] ?? false);
        $data['richieste_delega_spid'] = (bool) ($data['richieste_delega_spid'] ?? false);

        $organizzazione->update($data);

        return redirect()->route('admin.organizzazioni.index')
            ->with('success', "Organizzazione \"{$organizzazione->descrizione}\" aggiornata.");
    }

    public function destroy(Istituto $organizzazione): RedirectResponse
    {
        if ($organizzazione->plessi()->exists()) {
            return back()->with('error', "Impossibile eliminare: l'organizzazione ha sedi associate. Eliminale prima.");
        }

        $nome = $organizzazione->descrizione;
        $organizzazione->delete();

        return redirect()->route('admin.organizzazioni.index')
            ->with('success', "Organizzazione \"{$nome}\" eliminata.");
    }
}
