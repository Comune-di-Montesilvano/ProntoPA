<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Istituto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OrganizzazioniController extends Controller
{
    public function index(): View
    {
        $organizzazioni = Istituto::withCount('plessi')
            ->orderBy('tipo_ente')
            ->orderBy('descrizione')
            ->paginate(30);

        return view('admin.organizzazioni.index', compact('organizzazioni'));
    }

    public function create(): View
    {
        $tipiEsistenti = Istituto::select('tipo')
            ->distinct()
            ->whereNotNull('tipo')
            ->orderBy('tipo')
            ->pluck('tipo');

        return view('admin.organizzazioni.create', compact('tipiEsistenti'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'descrizione'           => ['required', 'string', 'max:50'],
            'tipo'                  => ['required', 'string', 'max:50'],
            'tipo_ente'             => ['required', Rule::in(['comune', 'scuola', 'asl', 'provincia', 'regione', 'altro'])],
            'codice_meccanografico' => ['nullable', 'string', 'max:50'],
            'partita_iva'           => ['nullable', 'digits:11'],
            'codice_fiscale'        => ['nullable', 'string', 'max:16'],
            'dirigente'             => ['nullable', 'string', 'max:50'],
            'email'                 => ['nullable', 'email', 'max:50'],
            'domini_email_istituzionali' => ['nullable', 'string', 'max:255'],
            'recapiti'              => ['nullable', 'string', 'max:50'],
            'attivo'                => ['nullable', 'boolean'],
        ]);

        $data['attivo'] = (bool) ($data['attivo'] ?? false);
        $data['fonte_dati'] = 'manuale';

        Istituto::create($data);

        return redirect()->route('admin.organizzazioni.index')
            ->with('success', "Organizzazione \"{$data['descrizione']}\" creata.");
    }

    public function edit(Istituto $organizzazione): View
    {
        $tipiEsistenti = Istituto::select('tipo')
            ->distinct()
            ->whereNotNull('tipo')
            ->orderBy('tipo')
            ->pluck('tipo');

        return view('admin.organizzazioni.edit', compact('organizzazione', 'tipiEsistenti'));
    }

    public function update(Request $request, Istituto $organizzazione): RedirectResponse
    {
        $data = $request->validate([
            'descrizione'           => ['required', 'string', 'max:50'],
            'tipo'                  => ['required', 'string', 'max:50'],
            'tipo_ente'             => ['required', Rule::in(['comune', 'scuola', 'asl', 'provincia', 'regione', 'altro'])],
            'codice_meccanografico' => ['nullable', 'string', 'max:50'],
            'partita_iva'           => ['nullable', 'digits:11'],
            'codice_fiscale'        => ['nullable', 'string', 'max:16'],
            'dirigente'             => ['nullable', 'string', 'max:50'],
            'email'                 => ['nullable', 'email', 'max:50'],
            'domini_email_istituzionali' => ['nullable', 'string', 'max:255'],
            'recapiti'              => ['nullable', 'string', 'max:50'],
            'attivo'                => ['nullable', 'boolean'],
        ]);

        $data['attivo'] = (bool) ($data['attivo'] ?? false);

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
