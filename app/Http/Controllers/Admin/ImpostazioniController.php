<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Impostazione;
use App\Models\StatoSegnalazione;
use App\Services\Oidc\OidcConfig;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ImpostazioniController extends Controller
{
    public function index(): View
    {
        $impostazioni = Impostazione::orderBy('gruppo')->orderBy('chiave')->get()
            ->groupBy('gruppo');

        $statiSegnalazioni = StatoSegnalazione::orderBy('id_stato')->get();

        $oidcRedirectUri = app(OidcConfig::class)->redirectUri();

        return view('admin.impostazioni', compact('impostazioni', 'statiSegnalazioni', 'oidcRedirectUri'));
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'impostazioni'          => ['required', 'array'],
            'impostazioni.*'        => ['nullable', 'string', 'max:500'],
        ]);

        foreach ($data['impostazioni'] as $chiave => $valore) {
            // Cast specifici per certi campi
            if ($chiave === 'publication_auto_state_id' && $valore !== '' && $valore !== null) {
                $valore = (int) $valore;
            }

            if ($chiave === 'oidc_client_secret') {
                if (blank($valore)) {
                    continue;
                }
                $valore = Crypt::encryptString((string) $valore);
            }

            if ($chiave === 'oidc_issuer' && filled($valore)) {
                $valore = rtrim(trim((string) $valore), '/');
            }

            Impostazione::set($chiave, $valore);
        }

        return back()->with('success', 'Impostazioni salvate.');
    }
}
