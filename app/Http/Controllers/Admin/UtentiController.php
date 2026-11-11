<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SincronizzaUtentiAdJob;
use App\Models\Profilo;
use App\Models\Provenienza;
use App\Models\User;
use App\Services\Auth\SincronizzaUtentiAd;
use App\Services\Directory\Directory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UtentiController extends Controller
{
    public function index(Directory $directory, SincronizzaUtentiAd $sync): View
    {
        $mostraDisattivati = request()->boolean('mostra_disattivati');

        $utenti = User::with(['profilo', 'istituto'])
            ->when(! $mostraDisattivati, fn ($query) => $query->where(function ($subQuery) {
                $subQuery->where('attivo', true)->orWhereNull('attivo');
            }))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('admin.utenti.index', [
            'utenti' => $utenti,
            'mostraDisattivati' => $mostraDisattivati,
            'syncConfigurata' => $directory->syncConfigurata(),
            'esitoSync' => $sync->ultimoEsito(),
        ]);
    }

    /** "Sincronizza ora": in coda, l'esito compare qui al termine. */
    public function sincronizza(Request $request): RedirectResponse
    {
        SincronizzaUtentiAdJob::dispatch($request->boolean('forza'));

        return back()->with('success', 'Sincronizzazione con Active Directory avviata: ricarica la pagina tra poco per vedere l\'esito.');
    }

    /*
     * Niente creazione da qui (v0.8.19): gli utenti arrivano solo dal primo
     * login AD o SPID. Il ruolo ditta lo decide solo la delega impresa.
     */

    public function edit(User $utente): View
    {
        $profili     = Profilo::with('istituto')->orderBy('descrizione')->get();
        $provenienze = Provenienza::orderBy('descrizione')->get();

        return view('admin.utenti.edit', compact('utente', 'profili', 'provenienze'));
    }

    public function update(Request $request, User $utente): RedirectResponse
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:100'],
            'username'   => ['required', 'string', 'max:50', Rule::unique('users', 'username')->ignore($utente->id)],
            'email'      => ['required', 'email', 'max:100', Rule::unique('users', 'email')
                ->where(fn ($q) => $q->where('auth_source', 'locale')->where('attivo', true))
                ->ignore($utente->id)],
            'ruolo'      => ['required', Rule::in(['admin', 'gestore', 'segnalatore'])],
            'id_profilo'    => ['nullable', 'integer', 'exists:profili,id_profilo'],
            'id_provenienza'=> ['nullable', 'integer', 'exists:provenienze_segnalazioni,id_provenienza'],
            'telefono'      => ['nullable', 'string', 'max:50'],
            'supervisore'   => ['boolean'],
            'attivo'        => ['nullable', 'boolean'],
        ]);

        // Persona di una ditta: ruolo e impresa li governa DelegaImpresaService.
        $ditta = $utente->hasRole('impresa');

        // Utenti di dominio: attivo lo decide AD (sync e login).
        $attivoDaAd = $utente->auth_source === 'ldap';

        if (! $attivoDaAd && $utente->is(auth()->user()) && ! ($data['attivo'] ?? false)) {
            return back()
                ->withErrors(['attivo' => 'Non puoi disattivare il tuo account.'])
                ->withInput();
        }

        $utente->name              = $data['name'];
        $utente->username          = $data['username'];
        $utente->email             = $data['email'];
        $utente->id_profilo        = $data['id_profilo'] ?? null;
        $utente->id_provenienza    = $data['id_provenienza'] ?? null;
        $utente->telefono          = $data['telefono'] ?? null;
        if (! $ditta) {
            $utente->amministratore           = $data['ruolo'] === 'admin';
            $utente->gestore_segnalazioni     = $data['ruolo'] === 'gestore';
            $utente->supervisore_segnalazioni = ($data['ruolo'] === 'gestore') && ($data['supervisore'] ?? false);
        }
        if (! $attivoDaAd) {
            $utente->attivo = (bool) ($data['attivo'] ?? false);
        }

        $utente->save();
        if (! $ditta) {
            $utente->syncRoles([$data['ruolo']]);
        }

        return redirect()->route('admin.utenti.index')
            ->with('success', "Utente {$utente->username} aggiornato.");
    }

    public function toggleAttivo(User $utente): RedirectResponse
    {
        if ($utente->is(auth()->user())) {
            return back()->with('error', 'Non puoi modificare lo stato del tuo account.');
        }
        Gate::authorize('cambia-attivo-utente', $utente);

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
}
