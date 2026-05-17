<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Istituto;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        $istituti = Istituto::query()
            ->where('attivo', true)
            ->whereNotNull('partita_iva')
            ->orderBy('descrizione')
            ->get(['id_istituto', 'descrizione', 'tipo_ente', 'partita_iva']);

        return view('auth.register', compact('istituti'));
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'nome' => ['required', 'string', 'max:100'],
            'cognome' => ['required', 'string', 'max:100'],
            'partita_iva' => ['required', 'digits:11'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $istituto = Istituto::query()
            ->where('attivo', true)
            ->where('partita_iva', $request->string('partita_iva'))
            ->first();

        if (! $istituto) {
            throw ValidationException::withMessages([
                'partita_iva' => 'Partita IVA ente non trovata. Contatta l\'operatore per il censimento dell\'ente.',
            ]);
        }

        $email = Str::lower($request->string('email')->toString());

        if (! $this->isInstitutionalIndividualEmail($email, $istituto->domini_email_istituzionali)) {
            throw ValidationException::withMessages([
                'email' => 'Inserisci una email istituzionale individuale valida per l\'ente selezionato.',
            ]);
        }

        $fullName = trim($request->string('nome').' '.$request->string('cognome'));

        $user = User::create([
            'name' => $fullName,
            'username' => $this->generateUniqueUsername($request->string('nome')->toString(), $request->string('cognome')->toString()),
            'email' => $email,
            'password' => Hash::make($request->password),
            'id_istituto' => $istituto->id_istituto,
            'attivo' => false,
            'approval_status' => 'pending',
        ]);

        if (Role::where('name', 'segnalatore')->where('guard_name', 'web')->exists()) {
            $user->syncRoles(['segnalatore']);
        }

        return redirect()
            ->route('login')
            ->with('status', 'Registrazione ricevuta. Un operatore approvera il tuo account.');
    }

    private function isInstitutionalIndividualEmail(string $email, ?string $allowedDomains): bool
    {
        if ($allowedDomains === null || trim($allowedDomains) === '') {
            return false;
        }

        [$local, $domain] = array_pad(explode('@', $email, 2), 2, null);
        if (! $local || ! $domain) {
            return false;
        }

        $genericPrefixes = [
            'info', 'urp', 'protocollo', 'segreteria', 'amministrazione', 'staff', 'office', 'noreply', 'no-reply',
        ];
        foreach ($genericPrefixes as $prefix) {
            if (Str::startsWith($local, $prefix)) {
                return false;
            }
        }

        $domains = collect(preg_split('/[,;\s]+/', Str::lower($allowedDomains)))
            ->filter()
            ->values();

        return $domains->contains($domain);
    }

    private function generateUniqueUsername(string $nome, string $cognome): string
    {
        $base = Str::of($nome.'.'.$cognome)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9\.]/', '')
            ->trim('.')
            ->value();

        $base = $base !== '' ? $base : 'utente';
        $candidate = $base;
        $suffix = 1;

        while (User::where('username', $candidate)->exists()) {
            $suffix++;
            $candidate = $base.$suffix;
        }

        return $candidate;
    }
}
