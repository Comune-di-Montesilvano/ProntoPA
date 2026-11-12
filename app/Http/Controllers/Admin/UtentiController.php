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
use Illuminate\Support\Facades\Cache;
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
            'syncInCorso' => $sync->inCorso() || Cache::has(SincronizzaUtentiAdJob::CHIAVE_IN_CODA),
        ]);
    }

    /** "Sincronizza ora": in coda, l'esito compare qui al termine. */
    public function sincronizza(Request $request, SincronizzaUtentiAd $sync): RedirectResponse
    {
        $data = $request->validate([
            'forza' => ['boolean'],
            'confermati' => ['required_if_accepted:forza', 'integer', 'min:0'],
        ]);
        $forza = (bool) ($data['forza'] ?? false);

        // Doppio clic o giro notturno in corso: la richiesta non va persa in silenzio.
        if ($sync->inCorso() || ! Cache::add(SincronizzaUtentiAdJob::CHIAVE_IN_CODA, true, 900)) {
            return back()->with('warning', 'Una sincronizzazione con Active Directory è già in corso: ricarica la pagina tra poco per vedere l\'esito, poi riprova se serve.');
        }

        SincronizzaUtentiAdJob::dispatch($forza, $forza ? (int) $data['confermati'] : null);

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
        // Utenti di dominio: nome, username, email, ruolo, supervisore, provenienza e attivo
        // li decide AD (sync e login), che li riscriverebbe al giro successivo.
        if ($utente->auth_source === 'ldap') {
            $data = $request->validate([
                'id_profilo' => ['nullable', 'integer', 'exists:profili,id_profilo'],
                'telefono' => ['nullable', 'string', 'max:50'],
            ]);
            $utente->id_profilo = $data['id_profilo'] ?? null;
            $utente->telefono = $data['telefono'] ?? null;
            $utente->save();

            return redirect()->route('admin.utenti.index')
                ->with('success', "Utente {$utente->username} aggiornato.");
        }

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
        $utente->telefono          = $data['telefono'] ?? null;
        if (! $ditta) {
            $utente->amministratore           = $data['ruolo'] === 'admin';
            $utente->gestore_segnalazioni     = $data['ruolo'] === 'gestore';
            $utente->supervisore_segnalazioni = ($data['ruolo'] === 'gestore') && ($data['supervisore'] ?? false);
        }
        $utente->attivo = (bool) ($data['attivo'] ?? false);

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
