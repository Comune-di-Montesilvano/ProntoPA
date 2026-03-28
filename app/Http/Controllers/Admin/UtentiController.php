<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Impresa;
use App\Models\Profilo;
use App\Models\Provenienza;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UtentiController extends Controller
{
    public function index(): View
    {
        $utenti = User::with('profilo')->orderBy('name')->paginate(25);

        return view('admin.utenti.index', compact('utenti'));
    }

    public function create(): View
    {
        $profili     = Profilo::with('istituto')->orderBy('descrizione')->get();
        $provenienze = Provenienza::orderBy('descrizione')->get();
        $imprese     = Impresa::orderBy('ragione_sociale')->get();

        return view('admin.utenti.create', compact('profili', 'provenienze', 'imprese'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:100'],
            'username'   => ['required', 'string', 'max:50', 'unique:users,username'],
            'email'      => ['required', 'email', 'max:100', 'unique:users,email'],
            'password'   => ['required', 'string', 'min:8'],
            'ruolo'      => ['required', Rule::in(['admin', 'gestore', 'segnalatore', 'impresa'])],
            'id_profilo'    => ['nullable', 'integer', 'exists:profili,id_profilo'],
            'id_provenienza'=> ['nullable', 'integer', 'exists:provenienze_segnalazioni,id_provenienza'],
            'id_impresa'    => ['nullable', 'integer', 'exists:imprese,id_impresa'],
            'supervisore'   => ['boolean'],
        ]);

        $user = User::create([
            'name'                     => $data['name'],
            'username'                 => $data['username'],
            'email'                    => $data['email'],
            'password'                 => Hash::make($data['password']),
            'id_profilo'               => $data['id_profilo'] ?? null,
            'id_provenienza'           => $data['id_provenienza'] ?? null,
            'id_impresa'               => $data['id_impresa'] ?? null,
            'amministratore'           => $data['ruolo'] === 'admin',
            'gestore_segnalazioni'     => $data['ruolo'] === 'gestore',
            'supervisore_segnalazioni' => ($data['ruolo'] === 'gestore') && ($data['supervisore'] ?? false),
        ]);

        $user->syncRoles([$data['ruolo']]);

        return redirect()->route('admin.utenti.index')
            ->with('success', "Utente {$user->username} creato.");
    }

    public function edit(User $utente): View
    {
        $profili     = Profilo::with('istituto')->orderBy('descrizione')->get();
        $provenienze = Provenienza::orderBy('descrizione')->get();
        $imprese     = Impresa::orderBy('ragione_sociale')->get();

        return view('admin.utenti.edit', compact('utente', 'profili', 'provenienze', 'imprese'));
    }

    public function update(Request $request, User $utente): RedirectResponse
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:100'],
            'username'   => ['required', 'string', 'max:50', Rule::unique('users', 'username')->ignore($utente->id)],
            'email'      => ['required', 'email', 'max:100', Rule::unique('users', 'email')->ignore($utente->id)],
            'password'   => ['nullable', 'string', 'min:8'],
            'ruolo'      => ['required', Rule::in(['admin', 'gestore', 'segnalatore', 'impresa'])],
            'id_profilo'    => ['nullable', 'integer', 'exists:profili,id_profilo'],
            'id_provenienza'=> ['nullable', 'integer', 'exists:provenienze_segnalazioni,id_provenienza'],
            'id_impresa'    => ['nullable', 'integer', 'exists:imprese,id_impresa'],
            'supervisore'   => ['boolean'],
        ]);

        $utente->name              = $data['name'];
        $utente->username          = $data['username'];
        $utente->email             = $data['email'];
        $utente->id_profilo        = $data['id_profilo'] ?? null;
        $utente->id_provenienza    = $data['id_provenienza'] ?? null;
        $utente->id_impresa        = $data['id_impresa'] ?? null;
        $utente->amministratore           = $data['ruolo'] === 'admin';
        $utente->gestore_segnalazioni     = $data['ruolo'] === 'gestore';
        $utente->supervisore_segnalazioni = ($data['ruolo'] === 'gestore') && ($data['supervisore'] ?? false);

        if (filled($data['password'])) {
            $utente->password = Hash::make($data['password']);
            $utente->password_legacy = null;
        }

        $utente->save();
        $utente->syncRoles([$data['ruolo']]);

        return redirect()->route('admin.utenti.index')
            ->with('success', "Utente {$utente->username} aggiornato.");
    }

    public function destroy(User $utente): RedirectResponse
    {
        $utente->delete();

        return back()->with('success', 'Utente eliminato.');
    }
}
