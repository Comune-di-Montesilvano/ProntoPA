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
        $mostraDisattivati = request()->boolean('mostra_disattivati');
        $mostraSoloPending = request()->boolean('solo_pending');

        $utenti = User::with(['profilo', 'istituto'])
            ->when($mostraSoloPending, fn ($query) => $query->where('approval_status', 'pending'))
            ->when(! $mostraDisattivati, fn ($query) => $query->where(function ($subQuery) {
                $subQuery->where('attivo', true)->orWhereNull('attivo');
            }))
            ->orderByRaw("CASE WHEN approval_status = 'pending' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('admin.utenti.index', compact('utenti', 'mostraDisattivati', 'mostraSoloPending'));
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
            'telefono'      => ['nullable', 'string', 'max:50'],
            'supervisore'   => ['boolean'],
            'attivo'        => ['nullable', 'boolean'],
        ]);

        $user = User::create([
            'name'                     => $data['name'],
            'username'                 => $data['username'],
            'email'                    => $data['email'],
            'password'                 => Hash::make($data['password']),
            'id_profilo'               => $data['id_profilo'] ?? null,
            'id_provenienza'           => $data['id_provenienza'] ?? null,
            'id_impresa'               => $data['id_impresa'] ?? null,
            'telefono'                 => $data['telefono'] ?? null,
            'amministratore'           => $data['ruolo'] === 'admin',
            'gestore_segnalazioni'     => $data['ruolo'] === 'gestore',
            'supervisore_segnalazioni' => ($data['ruolo'] === 'gestore') && ($data['supervisore'] ?? false),
            'attivo'                   => (bool) ($data['attivo'] ?? true),
            'approval_status'          => 'approved',
            'approved_at'              => now(),
            'approved_by'              => auth()->id(),
            'prossima_verifica_annuale_at' => now()->addYear(),
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
            'telefono'      => ['nullable', 'string', 'max:50'],
            'supervisore'   => ['boolean'],
            'attivo'        => ['nullable', 'boolean'],
        ]);

        if ($utente->is(auth()->user()) && ! ($data['attivo'] ?? false)) {
            return back()
                ->withErrors(['attivo' => 'Non puoi disattivare il tuo account.'])
                ->withInput();
        }

        $utente->name              = $data['name'];
        $utente->username          = $data['username'];
        $utente->email             = $data['email'];
        $utente->id_profilo        = $data['id_profilo'] ?? null;
        $utente->id_provenienza    = $data['id_provenienza'] ?? null;
        $utente->id_impresa        = $data['id_impresa'] ?? null;
        $utente->telefono          = $data['telefono'] ?? null;
        $utente->amministratore           = $data['ruolo'] === 'admin';
        $utente->gestore_segnalazioni     = $data['ruolo'] === 'gestore';
        $utente->supervisore_segnalazioni = ($data['ruolo'] === 'gestore') && ($data['supervisore'] ?? false);
        $utente->attivo                    = (bool) ($data['attivo'] ?? false);

        if (($utente->approval_status ?? 'approved') === 'pending' && $utente->attivo) {
            $utente->approval_status = 'approved';
            $utente->approved_at = now();
            $utente->approved_by = auth()->id();
            $utente->prossima_verifica_annuale_at = $utente->prossima_verifica_annuale_at ?? now()->addYear();
        }

        if (filled($data['password'])) {
            $utente->password = Hash::make($data['password']);
            $utente->password_legacy = null;
        }

        $utente->save();
        $utente->syncRoles([$data['ruolo']]);

        return redirect()->route('admin.utenti.index')
            ->with('success', "Utente {$utente->username} aggiornato.");
    }

    public function toggleAttivo(User $utente): RedirectResponse
    {
        if ($utente->is(auth()->user())) {
            return back()->with('error', 'Non puoi modificare lo stato del tuo account.');
        }

        if (($utente->approval_status ?? 'approved') === 'pending') {
            return back()->with('error', 'Utente in attesa: usa Approva o Rifiuta.');
        }

        $utente->update(['attivo' => ! $utente->attivo]);

        return back()->with(
            'success',
            $utente->attivo
                ? "Utente {$utente->username} riattivato."
                : "Utente {$utente->username} disattivato."
        );
    }

    public function destroy(User $utente): RedirectResponse
    {
        $utente->delete();

        return back()->with('success', 'Utente eliminato.');
    }

    public function approve(User $utente): RedirectResponse
    {
        if ($utente->is(auth()->user())) {
            return back()->with('error', 'Non puoi approvare il tuo account.');
        }

        $utente->update([
            'approval_status' => 'approved',
            'approved_at' => now(),
            'approved_by' => auth()->id(),
            'attivo' => true,
            'prossima_verifica_annuale_at' => $utente->prossima_verifica_annuale_at ?? now()->addYear(),
        ]);

        if (! $utente->getRoleNames()->isNotEmpty()) {
            $utente->syncRoles(['segnalatore']);
        }

        return back()->with('success', "Utente {$utente->username} approvato.");
    }

    public function reject(User $utente): RedirectResponse
    {
        if ($utente->is(auth()->user())) {
            return back()->with('error', 'Non puoi rifiutare il tuo account.');
        }

        $utente->update([
            'approval_status' => 'rejected',
            'approved_at' => null,
            'approved_by' => auth()->id(),
            'attivo' => false,
        ]);

        return back()->with('success', "Utente {$utente->username} rifiutato.");
    }
}
